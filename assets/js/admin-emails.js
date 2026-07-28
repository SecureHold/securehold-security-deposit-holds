/**
 * SecureHold Email Editor
 * Version: 7.0.0
 *
 * Depends on: secureholdEmailParams (localized via wp_localize_script on the
 * 'securehold-admin-emails' handle in class-securehold-wp-admin.php).
 * Fields: ajaxurl, nonce, i18n.{saved, saving, toggleSaved, saveFailed,
 *          serverError, resetConfirm, resetting, resetDone, resetFailed}
 *
 * Task 1: Dynamic sidebar status icons after toggle (enabled_effective / effective_map).
 * Task 3: CodeMirror HTML code editor + live WC-wrapped preview in iframe.
 */
/* global secureholdEmailParams, secureholdCMSettings, ajaxurl, wp */
(function($) {
    'use strict';

    // ── Resolve localized config ──────────────────────────────────────────
    var params  = (typeof secureholdEmailParams !== 'undefined') ? secureholdEmailParams : {};
    var AJAXURL = params.ajaxurl || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var NONCE   = params.nonce   || '';
    var I18N    = params.i18n    || {};

    // Fallbacks so strings always resolve even if localization is missing.
    I18N.saved        = I18N.saved        || 'Settings saved successfully.';
    I18N.saving       = I18N.saving       || 'Saving...';
    I18N.toggleSaved  = I18N.toggleSaved  || 'Toggle saved.';
    I18N.saveFailed   = I18N.saveFailed   || 'Could not save - please reload and try again.';
    I18N.serverError  = I18N.serverError  || 'Server error. Please try again.';
    I18N.resetConfirm = I18N.resetConfirm || 'Reset this template to defaults? This cannot be undone.';
    I18N.resetting    = I18N.resetting    || 'Resetting...';
    I18N.resetDone    = I18N.resetDone    || 'Template reset to defaults.';
    I18N.resetFailed  = I18N.resetFailed  || 'Reset failed. Please try again.';

    // ── Inject spin animation (self-contained) ───────────────────────────
    $('<style>.sh-spin{animation:sh-spin 1s linear infinite}' +
      '@keyframes sh-spin{100%{transform:rotate(360deg)}}</style>').appendTo('head');

    // ── CodeMirror state (Task 3) ─────────────────────────────────────────
    var cmEditor   = null;   // CodeMirror instance (null until initialized)
    var editorMode = 'simple'; // 'simple' | 'code'

    // ── HTML draft buffer ─────────────────────────────────────────────────
    // Stores the last authoritative HTML string so it survives a round-trip
    // through Simple mode without being rebuilt from (lossy) plain text.
    //
    // Lifecycle:
    //   HTML → Simple : shHtmlDraft = cmEditor.getValue()   (capture before stripping)
    //   Simple → HTML : cmEditor.setValue(shHtmlDraft)      (restore, never re-derive)
    //   Page load     : initialized from the CodeMirror textarea's server value.
    //   Toolbar edit  : applyInlineFormat/applyListFormat update directly.
    //
    // Save reads the ACTIVE editor directly; shHtmlDraft stays in sync.
    var shHtmlDraft = '';

    // ── Task 5: Client-side XSS sanitizer ────────────────────────────────
    /**
     * Remove script elements, on* event attributes, and javascript: URLs.
     * Server sanitization (sanitize_email_content PHP method) is authoritative;
     * this is a defense-in-depth pass before sending to the server.
     *
     * NOTE: Reading doc.body.innerHTML from an ISOLATED DOMParser document
     * is acceptable here — we are serializing, not injecting into the live DOM.
     *
     * @param  {string} html  Raw HTML string.
     * @returns {string}      Sanitized HTML string.
     */
    function clientSanitize( html ) {
        if ( !html ) { return ''; }
        var doc = ( new DOMParser() ).parseFromString( html, 'text/html' );
        if ( !doc.body ) { return ''; }

        // Remove dangerous element types.
        var dangerous = doc.querySelectorAll(
            'script,noscript,style,link,meta,iframe,object,embed,form,base'
        );
        Array.prototype.forEach.call( dangerous, function( el ) {
            if ( el.parentNode ) { el.parentNode.removeChild( el ); }
        } );

        // Strip on* event attributes and javascript: href/src/action.
        var all = doc.body.querySelectorAll( '*' );
        Array.prototype.forEach.call( all, function( el ) {
            var toRemove = [];
            for ( var i = 0; i < el.attributes.length; i++ ) {
                var attr = el.attributes[ i ];
                if ( /^on/i.test( attr.name ) ) {
                    toRemove.push( attr.name );
                } else if (
                    ( attr.name === 'href' || attr.name === 'src' || attr.name === 'action' ) &&
                    /^\s*javascript:/i.test( attr.value )
                ) {
                    toRemove.push( attr.name );
                }
            }
            toRemove.forEach( function( name ) { el.removeAttribute( name ); } );
        } );

        return doc.body.innerHTML;
    }

    // ── Task 1: Simple view renderer ──────────────────────────────────────

    /**
     * Render shHtmlDraft as structured blocks in the Simple editor.
     * Only safe DOM APIs — never innerHTML or .html() on admin DOM elements.
     *
     * @param {string}      htmlStr  HTML source (shHtmlDraft).
     * @param {HTMLElement} targetEl #sh-email-body contenteditable container.
     */
    function renderSimpleFromDraft( htmlStr, targetEl ) {
        if ( !targetEl ) { return; }
        // Clear existing content safely.
        while ( targetEl.firstChild ) { targetEl.removeChild( targetEl.firstChild ); }
        if ( !htmlStr ) { return; }

        var doc = ( new DOMParser() ).parseFromString( htmlStr, 'text/html' );
        if ( !doc.body ) { return; }

        Array.prototype.forEach.call( doc.body.childNodes, function( node ) {
            var el = domFromNode( node );
            if ( el ) { targetEl.appendChild( el ); }
        } );
    }

    /**
     * Convert a parsed-DOM node into a safe admin-DOM element or fragment.
     * Block-level tags become p/h1-h6/ul/ol; unknown blocks become p.
     *
     * @param  {Node} node  Source node from DOMParser document.
     * @returns {Node|null}
     */
    function domFromNode( node ) {
        if ( node.nodeType === Node.TEXT_NODE ) {
            var txt = node.textContent.trim();
            if ( !txt ) { return null; }
            var p = document.createElement( 'p' );
            p.appendChild( document.createTextNode( txt ) );
            return p;
        }
        if ( node.nodeType !== Node.ELEMENT_NODE ) { return null; }

        var tag = node.tagName.toLowerCase();

        // Headings
        if ( /^h[1-6]$/.test( tag ) ) {
            var heading = document.createElement( tag );
            appendInline( heading, node );
            return heading;
        }

        // Lists — ul/ol with li children only
        if ( tag === 'ul' || tag === 'ol' ) {
            var list = document.createElement( tag );
            Array.prototype.forEach.call( node.childNodes, function( child ) {
                if ( child.nodeType === Node.ELEMENT_NODE &&
                     child.tagName.toLowerCase() === 'li' ) {
                    var li = document.createElement( 'li' );
                    appendInline( li, child );
                    list.appendChild( li );
                }
            } );
            return list;
        }

        // Paragraph / div → paragraph
        if ( tag === 'p' || tag === 'div' ) {
            var para = document.createElement( 'p' );
            appendInline( para, node );
            return para;
        }

        // Standalone <br>
        if ( tag === 'br' ) { return document.createElement( 'br' ); }

        // Unknown block — render children individually as a fragment.
        var frag = document.createDocumentFragment();
        Array.prototype.forEach.call( node.childNodes, function( child ) {
            var childEl = domFromNode( child );
            if ( childEl ) { frag.appendChild( childEl ); }
        } );
        return frag.childNodes.length ? frag : null;
    }

    /**
     * Append inline child nodes from a DOMParser-document element into a
     * live admin-DOM element. Preserves safe inline tags and <br>.
     *
     * @param {HTMLElement} target  Destination element in the admin DOM.
     * @param {Node}        source  Source node from DOMParser document.
     */
    function appendInline( target, source ) {
        Array.prototype.forEach.call( source.childNodes, function( child ) {
            var inEl = inlineFromNode( child );
            if ( inEl ) { target.appendChild( inEl ); }
        } );
    }

    /**
     * Convert a parsed inline node to a safe admin-DOM node.
     * Allowed: strong b em i u a span br.  Normalises b→strong, i→em.
     * Unknown inline tags → plain text node.
     *
     * @param  {Node} node  Source node from DOMParser document.
     * @returns {Node|null}
     */
    function inlineFromNode( node ) {
        if ( node.nodeType === Node.TEXT_NODE ) {
            return document.createTextNode( node.textContent );
        }
        if ( node.nodeType !== Node.ELEMENT_NODE ) { return null; }

        var tag = node.tagName.toLowerCase();
        if ( tag === 'br' ) { return document.createElement( 'br' ); }

        var safeInline = { strong:1, b:1, em:1, i:1, u:1, a:1, span:1 };
        if ( !safeInline[ tag ] ) {
            return document.createTextNode( node.textContent );
        }

        // Normalise legacy aliases
        var outTag = ( tag === 'b' ) ? 'strong' : ( tag === 'i' ? 'em' : tag );
        var el = document.createElement( outTag );

        if ( outTag === 'a' ) {
            var href = ( node.getAttribute( 'href' ) || '' ).trim();
            if ( href && !/^javascript:/i.test( href ) ) {
                el.setAttribute( 'href', href );
                var rel = node.getAttribute( 'rel' )    || 'noopener noreferrer';
                var tgt = node.getAttribute( 'target' ) || '';
                el.setAttribute( 'rel', rel );
                if ( /^_(blank|self|parent|top)$/.test( tgt ) ) {
                    el.setAttribute( 'target', tgt );
                }
            }
        }

        // Preserve filtered inline style on <span> only.
        // (Other inline tags — strong, em, u, a — carry no style in Simple mode.)
        if ( outTag === 'span' ) {
            var rawStyle = ( node.getAttribute( 'style' ) || '' ).trim();
            if ( rawStyle ) {
                var safeStyle = filterCssProps( rawStyle );
                if ( safeStyle ) { el.setAttribute( 'style', safeStyle ); }
            }
        }

        appendInline( el, node );
        return el;
    }

    // ── Task 2: Serialize Simple editor → HTML string ─────────────────────

    /**
     * Walk the admin-DOM contenteditable and produce a safe HTML string.
     * This is the inverse of renderSimpleFromDraft.
     * Allowed tags: p h1-h6 ul ol li strong em u a span br.
     * No style attributes — Simple mode is unstyled.
     *
     * @param  {HTMLElement} el  The contenteditable #sh-email-body element.
     * @returns {string}  Safe HTML string.
     */
    function serializeSimple( el ) {
        return serializeChildren( el );
    }

    function serializeChildren( node ) {
        var html = '';
        Array.prototype.forEach.call( node.childNodes, function( child ) {
            html += serializeNode( child );
        } );
        return html;
    }

    function serializeNode( node ) {
        if ( node.nodeType === Node.TEXT_NODE ) {
            return escHtml( node.textContent );
        }
        if ( node.nodeType !== Node.ELEMENT_NODE ) { return ''; }

        var tag = node.tagName.toLowerCase();

        if ( tag === 'br' ) { return '<br>'; }

        // contenteditable inserts <div> for new paragraphs in some browsers.
        if ( tag === 'div' ) {
            return '<p>' + serializeChildren( node ) + '</p>';
        }

        var blockTags  = { p:1, h1:1, h2:1, h3:1, h4:1, h5:1, h6:1, ul:1, ol:1, li:1 };
        var inlineTags = { strong:1, em:1, u:1, a:1, span:1 };

        if ( blockTags[ tag ] ) {
            return '<' + tag + '>' + serializeChildren( node ) + '</' + tag + '>';
        }

        if ( inlineTags[ tag ] ) {
            var attrs = '';
            if ( tag === 'a' ) {
                var href = ( node.getAttribute( 'href' ) || '' ).trim();
                if ( href && !/^javascript:/i.test( href ) ) {
                    attrs += ' href="' + escAttr( href ) + '"';
                    var rel = node.getAttribute( 'rel' )    || 'noopener noreferrer';
                    var tgt = node.getAttribute( 'target' ) || '';
                    attrs  += ' rel="' + escAttr( rel ) + '"';
                    if ( /^_(blank|self|parent|top)$/.test( tgt ) ) {
                        attrs += ' target="' + escAttr( tgt ) + '"';
                    }
                }
            }
            // Preserve filtered inline style on <span> only.
            if ( tag === 'span' ) {
                var rawStyle = ( node.getAttribute( 'style' ) || '' ).trim();
                if ( rawStyle ) {
                    var safeStyle = filterCssProps( rawStyle );
                    if ( safeStyle ) { attrs += ' style="' + escAttr( safeStyle ) + '"'; }
                }
            }
            return '<' + tag + attrs + '>' + serializeChildren( node ) + '</' + tag + '>';
        }

        // Unknown/unsafe tag — text content only.
        return escHtml( node.textContent );
    }

    function escHtml( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;'  )
            .replace( />/g, '&gt;'  );
    }

    function escAttr( s ) {
        return String( s )
            .replace( /&/g, '&amp;'  )
            .replace( /"/g, '&quot;' )
            .replace( /</g, '&lt;'   )
            .replace( />/g, '&gt;'   );
    }

    // ── CSS property allowlist filter ─────────────────────────────────────
    /**
     * Filter a raw style="…" value, keeping only allowed CSS declarations.
     * Allowed properties: color, font-weight, font-style, text-decoration,
     *   text-align.
     * Rejected value patterns: url(), expression(), javascript:.
     *
     * Used both in renderSimpleFromDraft (incoming) and serializeSimple
     * (outgoing) so the style round-trip is deterministic.
     *
     * @param  {string} css  Raw CSS declarations.
     * @returns {string}     Safe semicolon-separated CSS string, or ''.
     */
    function filterCssProps( css ) {
        var allowed = ['color', 'font-weight', 'font-style', 'text-decoration', 'text-align'];
        var safe    = [];
        ( css || '' ).split( ';' ).forEach( function( decl ) {
            decl = decl.trim();
            if ( !decl ) { return; }
            var idx   = decl.indexOf( ':' );
            if ( idx < 1 ) { return; }
            var prop  = decl.slice( 0, idx ).trim().toLowerCase();
            var value = decl.slice( idx + 1 ).trim();
            if ( allowed.indexOf( prop ) === -1 ) { return; }
            if ( /\burl\s*\(|\bexpression\s*\(|\bjavascript\s*:/i.test( value ) ) { return; }
            safe.push( prop + ':' + value );
        } );
        return safe.join( ';' );
    }

    // ── Task 2: Toolbar formatting via Selection/Range API ────────────────

    /**
     * Display-only HTML formatter for the CodeMirror panel.
     * Adds newlines around block tags and indents list structure.
     * The compact shHtmlDraft remains the internal source of truth;
     * when switching HTML → Simple the CodeMirror value is captured and
     * run through clientSanitize(), which normalises any added whitespace.
     *
     * @param  {string} html  Compact HTML string.
     * @return {string}       Human-readable HTML string.
     */
    function prettyHtml( html ) {
        var out = html
            .replace( /<(p|h[1-6]|ul|ol|li|div|blockquote)(\s[^>]*)?>/gi, '\n<$1$2>' )
            .replace( /<\/(p|h[1-6]|ul|ol|li|div|blockquote)>/gi,          '</$1>\n' )
            .replace( /\n{2,}/g, '\n' )
            .trim();

        var indent = 0;
        return out.split( '\n' ).map( function( line ) {
            line = line.trim();
            if ( !line ) { return ''; }
            if ( /^<\/(ul|ol|li)>/i.test( line ) ) { indent = Math.max( 0, indent - 1 ); }
            var result = ( new Array( indent + 1 ) ).join( '  ' ) + line;
            if ( /^<(ul|ol|li)[\s>]/i.test( line ) && !/<\/(?:ul|ol|li)>/.test( line ) ) { indent++; }
            return result;
        } ).filter( Boolean ).join( '\n' );
    }

    /**
     * Walk up the DOM from range.commonAncestorContainer to boundary,
     * returning the first element whose tagName matches tag, or null.
     *
     * @param {Range}   range    Current selection range.
     * @param {string}  tag      Tag name to look for, case-insensitive.
     * @param {Element} boundary Stop node (exclusive); typically #sh-email-body.
     * @return {Element|null}
     */
    function findAncestorWithTag( range, tag, boundary ) {
        var node = range.commonAncestorContainer;
        if ( node.nodeType !== Node.ELEMENT_NODE ) { node = node.parentNode; }
        tag = tag.toUpperCase();
        while ( node && node !== boundary ) {
            if ( node.nodeType === Node.ELEMENT_NODE && node.tagName === tag ) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    /**
     * Walk up the DOM from node to boundary, returning the first element
     * whose tagName equals tagUC (already uppercased), or null.
     * Used by updateToolbarState() to find the formatting ancestor of each
     * selection boundary independently.
     *
     * @param  {Node}    node     Starting node.
     * @param  {string}  tagUC    Tag name, already uppercased (e.g. 'STRONG').
     * @param  {Element} boundary Stop node (exclusive); typically #sh-email-body.
     * @return {Element|null}
     */
    function closestWrapper( node, tagUC, boundary ) {
        while ( node && node !== boundary ) {
            if ( node.nodeType === Node.ELEMENT_NODE && node.tagName === tagUC ) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    /**
     * Remove el from the DOM, moving all its children before it in its parent.
     * Pure DOM — no innerHTML.
     *
     * @param {Element} el
     */
    function unwrapNode( el ) {
        var parent = el.parentNode;
        if ( !parent ) { return; }
        while ( el.firstChild ) { parent.insertBefore( el.firstChild, el ); }
        parent.removeChild( el );
    }

    /**
     * Apply an inline format to the current selection.
     * Uses Selection/Range API — no deprecated execCommand.
     * After wrapping, syncs contenteditable DOM → shHtmlDraft.
     *
     * @param {string}  tag    e.g. 'strong', 'em', 'u', 'a'.
     * @param {Object} [attrs] Optional attribute map for the new element.
     */
    function applyInlineFormat( tag, attrs ) {
        var sel = window.getSelection();
        if ( !sel || !sel.rangeCount ) { return; }
        var range = sel.getRangeAt( 0 );

        var body  = document.getElementById( 'sh-email-body' );
        var tagUC = tag.toUpperCase();

        // Locate an existing wrapper robustly.  findAncestorWithTag() uses
        // commonAncestorContainer which is unreliable for full-line selections where
        // the browser sets element-level boundaries (container = <p>, not the inner
        // text node).  Instead:
        //   • Descend into childNodes[offset] when the container is an element, to
        //     get the node actually at the selection boundary.
        //   • Walk up from that node to find the target tag ancestor.
        //   • Confirm endNode is also within the found ancestor (contains() check);
        //     if not, only part of the selection is wrapped → treat as toggle-on.
        var existing = null;
        if ( !attrs ) {
            var startNode = range.startContainer;
            if ( startNode.nodeType === Node.ELEMENT_NODE ) {
                startNode = startNode.childNodes[ range.startOffset ] || startNode;
            }
            if ( startNode.nodeType !== Node.ELEMENT_NODE ) { startNode = startNode.parentNode; }

            var endNode = range.endContainer;
            if ( endNode.nodeType === Node.ELEMENT_NODE && range.endOffset > 0 ) {
                endNode = endNode.childNodes[ range.endOffset - 1 ] || endNode;
            }
            if ( endNode.nodeType !== Node.ELEMENT_NODE ) { endNode = endNode.parentNode; }

            var candidate = startNode;
            while ( candidate && candidate !== body ) {
                if ( candidate.nodeType === Node.ELEMENT_NODE && candidate.tagName === tagUC ) {
                    existing = candidate;
                    break;
                }
                candidate = candidate.parentNode;
            }
            // Verify the end of the selection is also inside the found ancestor.
            // If not, the selection spans beyond the wrapper → toggle ON, not OFF.
            if ( existing && !existing.contains( endNode ) ) {
                existing = null;
            }
        }

        if ( range.collapsed ) {
            // Collapsed cursor: toggle-off if inside a matching ancestor; otherwise no-op.
            if ( existing ) {
                unwrapNode( existing );
                shHtmlDraft = serializeSimple( body );
                $( '#sh-email-body-hidden' ).val( shHtmlDraft );
                updateToolbarState();
            }
            return;
        }

        if ( existing ) {
            // Toggle OFF: remove the wrapper and any nested wrappers of the same type.
            // querySelectorAll returns a static NodeList, so DOM mutations during
            // forEach are safe and do not affect iteration.
            var innerSameTag = existing.querySelectorAll( tag );
            Array.prototype.forEach.call( innerSameTag, function( inner ) {
                unwrapNode( inner );
            } );
            unwrapNode( existing );
        } else {
            // Toggle ON: wrap the selected content.
            // Use extractContents() directly — it handles partial-node selections
            // gracefully and never throws, unlike surroundContents().
            var wrapper = document.createElement( tag );
            if ( attrs ) {
                Object.keys( attrs ).forEach( function ( k ) {
                    wrapper.setAttribute( k, attrs[ k ] );
                } );
            }
            // Clamp range to the block element containing startContainer.
            // Prevents extractContents() from crossing block boundaries and accidentally
            // pulling <li> / <ul> structure into the inline wrapper, which would create
            // invalid HTML (em wrapping li), empty stray bullets, and italic that
            // disappears in preview because email-client CSS overrides inline-on-block.
            var _blkSet = { p:1, h1:1, h2:1, h3:1, h4:1, h5:1, h6:1, li:1 };
            var _sb = range.startContainer;
            if ( _sb.nodeType !== Node.ELEMENT_NODE ) { _sb = _sb.parentNode; }
            while ( _sb && _sb !== body && !_blkSet[ _sb.tagName.toLowerCase() ] ) { _sb = _sb.parentNode; }
            var _eb = range.endContainer;
            if ( _eb.nodeType !== Node.ELEMENT_NODE ) { _eb = _eb.parentNode; }
            while ( _eb && _eb !== body && !_blkSet[ _eb.tagName.toLowerCase() ] ) { _eb = _eb.parentNode; }
            if ( _sb && _sb !== body && _eb && _sb !== _eb ) {
                range.setEnd( _sb, _sb.childNodes.length );
            }

            var frag = range.extractContents();
            wrapper.appendChild( frag );
            range.insertNode( wrapper );
            // Re-select wrapper contents so toolbar reflects active state and an
            // immediate second click can detect the wrapper for toggle-off.
            if ( !attrs ) {
                try {
                    var selRange = document.createRange();
                    selRange.selectNodeContents( wrapper );
                    sel.removeAllRanges();
                    sel.addRange( selRange );
                } catch ( e ) { /* non-critical */ }
            }
        }

        // Sync DOM → shHtmlDraft so Code mode and preview stay consistent.
        shHtmlDraft = serializeSimple( body );
        $( '#sh-email-body-hidden' ).val( shHtmlDraft );
        updateToolbarState();
    }

    /**
     * Wrap selected content in a <ul>/<ol> > <li>.
     * Syncs contenteditable DOM → shHtmlDraft afterward.
     *
     * @param {string} listTag 'ul' | 'ol'.
     */
    function applyListFormat( listTag ) {
        var sel = window.getSelection();
        if ( !sel || !sel.rangeCount ) { return; }
        var range = sel.getRangeAt( 0 );
        var body  = document.getElementById( 'sh-email-body' );

        var existingList = findAncestorWithTag( range, listTag, body );

        if ( existingList ) {
            // Toggle OFF: unpack each <li> into a <p> and remove the list.
            var parent = existingList.parentNode;
            Array.prototype.forEach.call( existingList.childNodes, function( li ) {
                if ( li.nodeType !== Node.ELEMENT_NODE ) { return; }
                var p = document.createElement( 'p' );
                while ( li.firstChild ) { p.appendChild( li.firstChild ); }
                parent.insertBefore( p, existingList );
            } );
            parent.removeChild( existingList );
        } else {
            // Toggle ON: wrap selection in list > li.
            var list = document.createElement( listTag );
            var li   = document.createElement( 'li' );
            try {
                li.appendChild( range.extractContents() );
            } catch ( e ) {
                li.appendChild( document.createTextNode( sel.toString() ) );
            }
            list.appendChild( li );
            range.insertNode( list );
            // Re-select li contents so toolbar reflects the active state and
            // an immediate second click can detect the list ancestor for toggle-off.
            try {
                var selRange = document.createRange();
                selRange.selectNodeContents( li );
                sel.removeAllRanges();
                sel.addRange( selRange );
            } catch ( e ) { /* non-critical */ }
        }

        shHtmlDraft = serializeSimple( body );
        $( '#sh-email-body-hidden' ).val( shHtmlDraft );
        updateToolbarState();
    }

    /**
     * Reflect the current cursor/selection formatting in the toolbar buttons.
     * Adds/removes .sh-editor-btn-active based on ancestor elements.
     * Read-only: no DOM mutations.
     */
    function updateToolbarState() {
        var sel = window.getSelection();
        if ( !sel || !sel.rangeCount ) { return; }
        var range     = sel.getRangeAt( 0 );
        var body      = document.getElementById( 'sh-email-body' );
        var formatMap = { bold: 'strong', italic: 'em', underline: 'u' };

        // Resolve effective boundary nodes — descend into childNodes[offset] when the
        // container is an element (Firefox/Safari element-level boundaries on triple-click).
        var tbStart = range.startContainer;
        if ( tbStart.nodeType === Node.ELEMENT_NODE ) {
            // Advance past leading <br> elements and whitespace-only text nodes that
            // browsers inject before real content after blur/refocus cycles.
            var si = range.startOffset;
            while ( si < tbStart.childNodes.length ) {
                var sc = tbStart.childNodes[ si ];
                if ( ( sc.nodeType === Node.ELEMENT_NODE && sc.tagName.toLowerCase() === 'br' ) ||
                     ( sc.nodeType === Node.TEXT_NODE && sc.textContent.trim() === '' ) ) {
                    si++;
                } else {
                    break;
                }
            }
            tbStart = tbStart.childNodes[ si ] || tbStart;
        } else if ( tbStart.nodeType === Node.TEXT_NODE && tbStart.textContent.trim() === '' ) {
            // startContainer is itself a phantom empty text node (left by insertNode after
            // extractContents). Walk forward through siblings to the first meaningful node
            // so closestWrapper() can locate the formatting ancestor correctly.
            var ns = tbStart.nextSibling;
            while ( ns &&
                    ( ( ns.nodeType === Node.ELEMENT_NODE && ns.tagName.toLowerCase() === 'br' ) ||
                      ( ns.nodeType === Node.TEXT_NODE && ns.textContent.trim() === '' ) ) ) {
                ns = ns.nextSibling;
            }
            if ( ns ) { tbStart = ns; }
        }
        if ( tbStart.nodeType !== Node.ELEMENT_NODE ) { tbStart = tbStart.parentNode; }

        var tbEnd = range.endContainer;
        if ( tbEnd.nodeType === Node.ELEMENT_NODE && range.endOffset > 0 ) {
            // Walk backwards from endOffset - 1 skipping trailing <br> elements and
            // whitespace-only text nodes that browsers inject for cursor positioning.
            // These are siblings of the real content, so they must not become tbEnd
            // or closestWrapper() will fail to find the formatting ancestor.
            var ei = range.endOffset - 1;
            while ( ei > 0 ) {
                var ec = tbEnd.childNodes[ ei ];
                if ( !ec ) { break; }
                if ( ( ec.nodeType === Node.ELEMENT_NODE  && ec.tagName.toLowerCase() === 'br' ) ||
                     ( ec.nodeType === Node.TEXT_NODE && ec.textContent.trim() === '' ) ) {
                    ei--;
                } else {
                    break;
                }
            }
            tbEnd = tbEnd.childNodes[ ei ] || tbEnd;
        } else if ( tbEnd.nodeType === Node.TEXT_NODE && tbEnd.textContent.trim() === '' ) {
            // endContainer is itself a phantom empty text node; walk backward to real content.
            var ps = tbEnd.previousSibling;
            while ( ps &&
                    ( ( ps.nodeType === Node.ELEMENT_NODE && ps.tagName.toLowerCase() === 'br' ) ||
                      ( ps.nodeType === Node.TEXT_NODE && ps.textContent.trim() === '' ) ) ) {
                ps = ps.previousSibling;
            }
            if ( ps ) { tbEnd = ps; }
        }
        if ( tbEnd.nodeType !== Node.ELEMENT_NODE ) { tbEnd = tbEnd.parentNode; }

        Object.keys( formatMap ).forEach( function( action ) {
            var tagUC     = formatMap[ action ].toUpperCase();
            var startWrap = closestWrapper( tbStart, tagUC, body );
            var endWrap   = closestWrapper( tbEnd,   tagUC, body );
            // Active only when BOTH boundary nodes share the same formatting ancestor.
            // Using identity (===) rather than contains() correctly handles cases
            // where tbEnd is a sibling of the wrapper (e.g. a trailing <br>).
            var active = !!( startWrap && endWrap && startWrap === endWrap );
            $( '[data-action="' + action + '"]' ).toggleClass( 'sh-editor-btn-active', active );
        } );

        // Lists
        [ 'insertUnorderedList', 'insertOrderedList' ].forEach( function( action ) {
            var tag    = action === 'insertUnorderedList' ? 'ul' : 'ol';
            var active = !!findAncestorWithTag( range, tag, body );
            $( '[data-action="' + action + '"]' ).toggleClass( 'sh-editor-btn-active', active );
        } );

        // Block-format segmented control — highlight button matching current block ancestor.
        var allBlockTags = { p:1, h1:1, h2:1, h3:1, h4:1, h5:1, h6:1 };
        var blockNode = range.startContainer;
        if ( blockNode.nodeType !== Node.ELEMENT_NODE ) { blockNode = blockNode.parentNode; }
        var currentBlock = '';
        while ( blockNode && blockNode !== body ) {
            if ( blockNode.nodeType === Node.ELEMENT_NODE
                 && allBlockTags[ blockNode.tagName.toLowerCase() ] ) {
                currentBlock = blockNode.tagName.toLowerCase();
                break;
            }
            blockNode = blockNode.parentNode;
        }
        $( '.sh-block-btn' ).removeClass( 'sh-editor-btn-active' );
        if ( currentBlock ) {
            $( '.sh-block-btn[data-block="' + currentBlock + '"]' )
                .addClass( 'sh-editor-btn-active' );
        }
    }

    /**
     * Change the block-level tag of the element containing the cursor.
     * Supported targets: p, h2, h3 (all already in serializer + PHP allowlist).
     * No-op when already in the target tag.
     *
     * @param {string} tag  'p' | 'h2' | 'h3'
     */
    function applyBlockFormat( tag ) {
        var body  = document.getElementById( 'sh-email-body' );
        var sel   = window.getSelection();
        if ( !sel || !sel.rangeCount ) { return; }
        var range = sel.getRangeAt( 0 );

        var blockTags = { p:1, h1:1, h2:1, h3:1, h4:1, h5:1, h6:1 };

        // Collect every block-tagged direct child of body that intersects the selection.
        // intersectsNode() returns true when the node is an ancestor of a boundary point
        // or lies between both boundary points — so a collapsed cursor inside a block
        // also matches that block, and Ctrl+A matches every block.
        var blocks = [];
        var ch = body.childNodes;
        for ( var i = 0; i < ch.length; i++ ) {
            var c = ch[ i ];
            if ( c.nodeType === Node.ELEMENT_NODE
                    && blockTags[ c.tagName.toLowerCase() ]
                    && range.intersectsNode( c ) ) {
                blocks.push( c );
            }
        }

        // Fallback: walk up from startContainer for content nested deeper than a
        // direct-child block (edge-case HTML loaded from HTML mode).
        if ( blocks.length === 0 ) {
            var node = range.startContainer;
            if ( node.nodeType !== Node.ELEMENT_NODE ) { node = node.parentNode; }
            while ( node && node !== body ) {
                if ( node.nodeType === Node.ELEMENT_NODE
                        && blockTags[ node.tagName.toLowerCase() ] ) {
                    blocks.push( node );
                    break;
                }
                node = node.parentNode;
            }
        }

        if ( blocks.length === 0 ) { return; }

        // Convert each block, skipping those already at the target tag.
        var newEls = [];
        blocks.forEach( function ( blockEl ) {
            if ( blockEl.tagName.toLowerCase() === tag ) { return; }
            var newEl = document.createElement( tag );
            while ( blockEl.firstChild ) { newEl.appendChild( blockEl.firstChild ); }
            blockEl.parentNode.replaceChild( newEl, blockEl );
            newEls.push( newEl );
        } );

        if ( newEls.length === 0 ) { return; } // all selected blocks already had target tag

        // Restore selection inside the converted element(s).
        // selectNodeContents / setStart+setEnd keep the range inside the block so a
        // subsequent inline format (italic, bold) does not extract the entire block
        // element and produce invalid em-wrapping-h2 HTML.
        try {
            var selRange = document.createRange();
            if ( newEls.length === 1 ) {
                selRange.selectNodeContents( newEls[ 0 ] );
            } else {
                var lastNewEl = newEls[ newEls.length - 1 ];
                selRange.setStart( newEls[ 0 ], 0 );
                selRange.setEnd( lastNewEl, lastNewEl.childNodes.length );
            }
            sel.removeAllRanges();
            sel.addRange( selRange );
        } catch ( e ) { /* non-critical */ }

        shHtmlDraft = serializeSimple( body );
        $( '#sh-email-body-hidden' ).val( shHtmlDraft );
        updateToolbarState();
    }

    // ── All event binding deferred until DOM is ready ─────────────────────
    $(document).ready(function() {

        // ============================================
        // TASK 3 — CODEMIRROR INITIALIZATION
        // ============================================
        var $codeTextarea = $('#sh-email-body-code');
        if ( $codeTextarea.length
             && typeof wp !== 'undefined'
             && wp.codeEditor
             && typeof secureholdCMSettings !== 'undefined' ) {
            var result = wp.codeEditor.initialize( $codeTextarea[0], secureholdCMSettings );
            if ( result && result.codemirror ) {
                cmEditor = result.codemirror;
                // Ensure CM fills its container and enables line-wrap for emails.
                cmEditor.setOption( 'lineWrapping', true );
            }
        }

        // Seed the HTML draft buffer from the server-rendered textarea value.
        // If CM was initialized above it holds the same string; this ensures
        // Simple → HTML mode-switch works correctly even if CM is unavailable.
        shHtmlDraft = $codeTextarea.val() || '';

        // ============================================
        // TASK 3 — EDITOR MODE TOGGLE (Simple ↔ HTML)
        // ============================================
        $(document).on('click', '.sh-mode-btn', function() {
            var mode = $(this).data('mode');
            if ( mode === editorMode ) { return; }

            $('.sh-mode-btn').removeClass('active');
            $(this).addClass('active');

            if ( mode === 'code' ) {
                // Simple → HTML: restore the preserved HTML draft.
                // NEVER rebuild HTML from the simple editor's plain text — that
                // would lose structure and could re-introduce injection vectors.
                // shHtmlDraft always holds the last trusted HTML string.
                if ( cmEditor ) {
                    cmEditor.setValue( prettyHtml( shHtmlDraft ) );
                    setTimeout(function() { cmEditor.refresh(); }, 30);
                } else {
                    $codeTextarea.val( prettyHtml( shHtmlDraft ) );
                }
                $('#sh-simple-toolbar').hide();
                $('#sh-email-body').hide();
                $('.sh-code-editor-wrap').show();
                if ( cmEditor ) {
                    setTimeout(function() {
                        cmEditor.refresh();
                        cmEditor.focus();
                    }, 50);
                }
            } else {
                // HTML → Simple:
                // 1. Capture the current HTML draft BEFORE overwriting anything.
                shHtmlDraft = cmEditor ? cmEditor.getValue() : $codeTextarea.val();

                // 2. Sanitize on capture so the draft is always clean.
                shHtmlDraft = clientSanitize( shHtmlDraft );

                // 3. Render as structured blocks using safe DOM APIs only.
                //    SECURITY: never innerHTML / .html() on admin DOM elements.
                renderSimpleFromDraft( shHtmlDraft, document.getElementById('sh-email-body') );
                $('#sh-email-body-hidden').val( shHtmlDraft );

                $('.sh-code-editor-wrap').hide();
                $('#sh-simple-toolbar').show();
                $('#sh-email-body').show();
            }

            editorMode = mode;
        });

        // ============================================
        // BODY-EDITOR SYNC (contenteditable → shHtmlDraft + hidden textarea)
        // On every keystroke in Simple mode, keep shHtmlDraft in sync via the
        // safe serializer so Code mode and preview always see current content.
        // ============================================
        $(document).on('input', '#sh-email-body', function() {
            var serialized = serializeSimple( this );
            shHtmlDraft = serialized;
            $('#sh-email-body-hidden').val( serialized );
        });

        // ============================================
        // TOOLBAR ACTIONS (Simple mode)
        // Uses Selection/Range API — no execCommand.
        // ============================================
        $(document).on('click', '.sh-editor-btn[data-action]', function(e) {
            e.preventDefault();
            var action = $(this).data('action');

            switch ( action ) {
                case 'bold':
                    applyInlineFormat( 'strong' );
                    break;
                case 'italic':
                    applyInlineFormat( 'em' );
                    break;
                case 'underline':
                    applyInlineFormat( 'u' );
                    break;
                case 'insertUnorderedList':
                    applyListFormat( 'ul' );
                    break;
                case 'insertOrderedList':
                    applyListFormat( 'ol' );
                    break;
                case 'createLink':
                    var url = prompt( 'Enter URL:' );
                    if ( url && !/^\s*javascript:/i.test( url ) ) {
                        applyInlineFormat( 'a', {
                            href:   url,
                            target: '_blank',
                            rel:    'noopener noreferrer'
                        } );
                    }
                    break;
            }
            $('#sh-email-body').focus();
        });

        // Preserve contenteditable selection when clicking block-format buttons.
        $( document ).on( 'mousedown', '.sh-block-btn', function( e ) {
            e.preventDefault();
        } );

        $( document ).on( 'click', '.sh-block-btn[data-block]', function( e ) {
            e.preventDefault();
            // focus() first: ensures the editor is active before applying the block format.
            // Calling it AFTER would reset the selection set by applyBlockFormat in
            // Firefox/Safari, causing subsequent inline formats (italic, bold) to receive
            // a body-level range and produce invalid em-wrapping-h2 HTML.
            $( '#sh-email-body' ).focus();
            applyBlockFormat( $( this ).data( 'block' ) );
        } );

        // Update toolbar active state on every selection change inside the Simple editor.
        document.addEventListener( 'selectionchange', function() {
            if ( document.activeElement !== document.getElementById( 'sh-email-body' ) ) { return; }
            updateToolbarState();
        } );

        // ============================================
        // VARIABLES SIDEBAR
        // ============================================
        $(document).on('click', '#sh-show-variables, #sh-show-variables-code', function(e) {
            e.preventDefault();
            $('#sh-variables-sidebar').fadeIn(200);
        });

        $(document).on('click', '.sh-sidebar-overlay', function(e) {
            if ( e.target === this ) { $(this).fadeOut(200); }
        });

        $(document).on('click', '.sh-sidebar-close', function() {
            $(this).closest('.sh-sidebar-overlay').fadeOut(200);
        });

        // Insert variable at cursor — handles both Simple and Code modes.
        $(document).on('click', '.sh-variable-item', function() {
            var variable = $(this).data('variable');

            if ( editorMode === 'code' && cmEditor ) {
                cmEditor.replaceSelection( variable );
                cmEditor.focus();
            } else {
                // In Simple mode, insert at cursor via Selection API.
                var sel = window.getSelection();
                if ( sel && sel.rangeCount ) {
                    var range = sel.getRangeAt(0);
                    range.deleteContents();
                    range.insertNode( document.createTextNode( variable ) );
                    range.collapse( false );
                } else {
                    // Fallback: append a text node.
                    var body = document.getElementById('sh-email-body');
                    if ( body ) { body.appendChild( document.createTextNode( variable ) ); }
                }
                // Sync after inserting variable.
                var bodyEl = document.getElementById('sh-email-body');
                if ( bodyEl ) {
                    shHtmlDraft = serializeSimple( bodyEl );
                    $('#sh-email-body-hidden').val( shHtmlDraft );
                }
            }
            $('#sh-variables-sidebar').fadeOut(200);
        });

        // ============================================
        // EMAIL PREVIEW (WC-wrapped, sandboxed iframe)
        // ============================================
        function firePreview() {
            var $simpleBtn = $('#sh-preview-email');
            var $codeBtn   = $('#sh-preview-email-code');
            var origSimple = $simpleBtn.html();
            var origCode   = $codeBtn.html();
            var spinner    = '<span class="dashicons dashicons-update sh-spin"></span>';

            // Collect body from whichever editor is currently active.
            // In Simple mode, use the safe serializer (not innerHTML) so the
            // draft is accurate and any unsaved typing is captured.
            var bodyContent;
            if ( editorMode === 'code' ) {
                bodyContent = cmEditor ? cmEditor.getValue() : $codeTextarea.val();
            } else {
                bodyContent = serializeSimple( document.getElementById('sh-email-body') );
            }

            // Task 5: client-side sanitization pass before sending to server.
            // Server-side sanitize_email_content() is still authoritative.
            bodyContent = clientSanitize( bodyContent );

            // Disable both preview buttons and show a spinner.
            $simpleBtn.prop('disabled', true).html(spinner + ' Loading&hellip;');
            $codeBtn.prop('disabled', true).html(spinner + ' Loading&hellip;');

            function restoreButtons() {
                $simpleBtn.prop('disabled', false).html(origSimple);
                $codeBtn.prop('disabled', false).html(origCode);
            }

            $.ajax({
                url:      AJAXURL,
                type:     'POST',
                dataType: 'json',   // always parse as JSON; non-JSON → error callback
                data: {
                    action:     'securehold_preview_email',
                    nonce:      NONCE,
                    email_type: $('#sh-email-type').val(),
                    mode:       editorMode,
                    body:       bodyContent,
                    footer:     $('#sh-email-footer').val()
                },
                success: function(response) {
                    restoreButtons();

                    // Accept any string html, including empty (typeof check, not truthiness).
                    if ( response && response.success && response.data
                         && typeof response.data.html === 'string' ) {
                        var frame = document.getElementById('sh-preview-frame');
                        var modal = document.getElementById('sh-preview-modal');

                        if ( !frame || !modal ) {
                            showNotification('error', 'Preview UI elements not found. Please reload the page.');
                            return;
                        }

                        // srcdoc isolates WC email CSS from admin styles.
                        // sandbox="allow-same-origin" is on the iframe — allow-scripts
                        // is intentionally ABSENT so no JS executes inside the frame.
                        frame.srcdoc = response.data.html;

                        // Clear any inline display so the CSS base rule controls it,
                        // then add .is-open which switches the overlay to display:flex,
                        // opacity:1 and pointer-events:auto via the ID-specific rule.
                        modal.style.display = '';
                        modal.classList.add('is-open');
                    } else {
                        var msg = (response && response.data && response.data.message)
                            ? response.data.message : I18N.serverError;
                        showNotification('error', msg);
                    }
                },
                error: function(xhr) {
                    restoreButtons();
                    // Show a trimmed excerpt of the raw server response so the
                    // developer can see any PHP notice/warning that corrupted the JSON.
                    var raw = '';
                    if ( xhr.responseText ) {
                        raw = xhr.responseText
                            .replace(/<[^>]+>/g, ' ')   // strip HTML tags
                            .replace(/\s+/g, ' ')        // collapse whitespace
                            .trim()
                            .substring(0, 200);
                    }
                    showNotification('error', raw || I18N.serverError);
                }
            });
        }

        $(document).on('click', '#sh-preview-email, #sh-preview-email-code', function(e) {
            e.preventDefault();
            firePreview();
        });

        // Close any modal when clicking its backdrop or a .sh-modal-close button.
        // - classList.remove('is-open') handles #sh-preview-modal (CSS-driven hide).
        // - style.display = 'none' is the fallback for other modals (e.g. test-email)
        //   that rely on the inline style rather than the .is-open class.
        // Both ops are always applied; they're no-ops on whichever path doesn't apply.
        function closeModal(overlay) {
            if ( !overlay ) { return; }
            overlay.classList.remove('is-open');
            overlay.style.display = 'none';
        }

        $(document).on('click', '.sh-modal-overlay', function(e) {
            if ( e.target === this ) { closeModal(this); }
        });

        $(document).on('click', '.sh-modal-close', function() {
            closeModal( $(this).closest('.sh-modal-overlay')[0] );
        });

        // ============================================
        // SEND TEST EMAIL  (direct — no modal)
        // ============================================
        $(document).on('click', '#sh-send-test-email', function(e) {
            e.preventDefault();

            var $btn     = $(this);
            var origHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update sh-spin"></span> Sending...');

            $.ajax({
                url:      AJAXURL,
                type:     'POST',
                dataType: 'json',   // always parse as JSON; non-JSON → error callback
                data: {
                    action:     'securehold_send_test_email',
                    nonce:      NONCE,
                    email_type: $('#sh-email-type').val(),
                    email_id:   $('#sh-email-id').val()
                },
                success: function(response) {
                    $btn.prop('disabled', false).html(origHtml);
                    if ( response && response.success ) {
                        var recipient = (response.data && response.data.recipient)
                            ? response.data.recipient : '';
                        showNotification('success',
                            recipient ? 'Test email sent to ' + recipient + '.' : 'Test email sent.');
                    } else {
                        var errMsg = (response && response.data && response.data.message)
                            ? response.data.message : 'Failed to send test email.';
                        showNotification('error', errMsg);
                    }
                },
                error: function(xhr) {
                    $btn.prop('disabled', false).html(origHtml);
                    var raw = '';
                    if ( xhr.responseText ) {
                        raw = xhr.responseText
                            .replace(/<[^>]+>/g, ' ')
                            .replace(/\s+/g, ' ')
                            .trim()
                            .substring(0, 200);
                    }
                    showNotification('error', raw || I18N.serverError);
                }
            });
        });

        // ============================================
        // TASK 1 — PER-EMAIL ENABLED TOGGLE (instant-save)
        // Uses enabled_effective from server response so the sidebar reflects
        // the real effective state (WC enabled AND master gates).
        // ============================================
        $(document).on('change', '#sh-email-enabled', function() {
            var $input    = $(this);
            var emailType = $('#sh-email-type').val();
            var value     = $input.is(':checked') ? 'yes' : 'no';

            $input.prop('disabled', true);

            $.post(AJAXURL, {
                action:     'securehold_save_email_enabled',
                nonce:      NONCE,
                email_type: emailType,
                value:      value
            })
            .done(function(response) {
                $input.prop('disabled', false);

                if ( !response || !response.success ) {
                    $input.prop('checked', !$input.is(':checked'));
                    var msg = (response && response.data && response.data.message)
                              ? response.data.message : I18N.saveFailed;
                    showNotification('error', msg);
                    return;
                }

                showNotification('success', I18N.toggleSaved);

                // Use server-computed effective state (accounts for master gates).
                var effective = (response.data && response.data.enabled_effective !== undefined)
                    ? response.data.enabled_effective
                    : $input.is(':checked');
                updateSidebarStatus(emailType, effective);
            })
            .fail(function() {
                $input.prop('disabled', false);
                $input.prop('checked', !$input.is(':checked'));
                showNotification('error', I18N.serverError);
            });
        });

        // ============================================
        // RESET TO DEFAULT
        // ============================================
        $(document).on('click', '#sh-reset-template', function(e) {
            e.preventDefault();

            if ( !confirm(I18N.resetConfirm) ) { return; }

            var $btn     = $(this);
            var origHtml = $btn.html();

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update sh-spin"></span> ' + I18N.resetting);

            $.ajax({
                url:  AJAXURL,
                type: 'POST',
                data: {
                    action:     'securehold_reset_email_to_default',
                    nonce:      NONCE,
                    email_type: $('#sh-email-type').val()
                },
                success: function(response) {
                    $btn.prop('disabled', false).html(origHtml);

                    if ( response && response.success ) {
                        var s = (response.data && response.data.settings)
                            ? response.data.settings : {};

                        if ( s.subject !== undefined ) { $('#sh-email-subject').val(s.subject); }
                        if ( s.heading !== undefined ) { $('#sh-email-heading').val(s.heading); }
                        if ( s.body !== undefined ) {
                            // Sanitize and update the draft buffer.
                            shHtmlDraft = clientSanitize( s.body );

                            // Render structured blocks in Simple view.
                            renderSimpleFromDraft(
                                shHtmlDraft,
                                document.getElementById('sh-email-body')
                            );
                            $('#sh-email-body-hidden').val( shHtmlDraft );

                            // CodeMirror (HTML mode) receives the full markup.
                            if ( cmEditor ) { cmEditor.setValue( shHtmlDraft ); }
                            $codeTextarea.val( shHtmlDraft );
                        }
                        if ( s.footer !== undefined ) { $('#sh-email-footer').val(s.footer); }
                        if ( s.enabled !== undefined ) {
                            var isEnabled = (s.enabled === true || s.enabled === 'true' || s.enabled === 'yes');
                            $('#sh-email-enabled').prop('checked', isEnabled);
                            updateSidebarStatus($('#sh-email-type').val(), isEnabled);
                        }

                        showNotification('success', I18N.resetDone);
                    } else {
                        var errMsg = (response && response.data && response.data.message)
                            ? response.data.message : I18N.resetFailed;
                        showNotification('error', errMsg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    showNotification('error', I18N.serverError);
                }
            });
        });

        // ============================================
        // SAVE CHANGES
        // WHY NOT form submit: the page wraps all tabs in a single outer <form>.
        // HTML disallows nested forms; the browser drops the inner one silently.
        // Solution: type="button" + click handler.
        // ============================================
        $(document).on('click', '#sh-save-email-settings', function(e) {
            e.preventDefault();

            var $btn     = $(this);
            var origHtml = $btn.html();

            var emailType = $('#sh-email-type').val();
            var emailId   = $('#sh-email-id').val();

            // Collect body from whichever editor is active, then sanitize.
            var bodyContent;
            if ( editorMode === 'code' ) {
                bodyContent = cmEditor ? cmEditor.getValue() : $codeTextarea.val();
            } else {
                bodyContent = serializeSimple( document.getElementById('sh-email-body') );
            }
            bodyContent = clientSanitize( bodyContent );

            var data = {
                action:     'securehold_save_email_settings',
                nonce:      NONCE,
                email_type: emailType,
                email_id:   emailId,
                enabled:    String($('#sh-email-enabled').is(':checked')),
                subject:    $('#sh-email-subject').val(),
                heading:    $('#sh-email-heading').val(),
                body:       bodyContent,
                footer:     $('#sh-email-footer').val()
            };

            $btn.prop('disabled', true)
                .html('<span class="dashicons dashicons-update sh-spin"></span> ' + I18N.saving);

            $.ajax({
                url:  AJAXURL,
                type: 'POST',
                data: data,
                success: function(response) {
                    $btn.prop('disabled', false).html(origHtml);

                    if ( response && response.success ) {
                        showNotification('success', I18N.saved);
                        updateSidebarStatus(emailType, data.enabled === 'true');

                        if ( response.data && response.data.settings ) {
                            var s = response.data.settings;
                            if ( s.subject !== undefined ) { $('#sh-email-subject').val(s.subject); }
                            if ( s.heading !== undefined ) { $('#sh-email-heading').val(s.heading); }
                        }
                    } else {
                        var msg = (response && response.data && response.data.message)
                            ? response.data.message : I18N.saveFailed;
                        showNotification('error', msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).html(origHtml);
                    showNotification('error', I18N.serverError);
                }
            });
        });

        // ============================================
        // TASK 1 — MASTER NOTIFICATION TOGGLES
        // After save, update ALL sidebar items from the effective_map returned
        // by the server — one AJAX call refreshes the entire sidebar instantly.
        // ============================================
        $(document).on('change', '.sh-master-toggle-input', function() {
            var $input  = $(this);
            var setting = $input.data('setting');
            var value   = $input.is(':checked') ? 'yes' : 'no';

            $input.prop('disabled', true);

            $.post(AJAXURL, {
                action:  'securehold_save_notification_master',
                nonce:   NONCE,
                setting: setting,
                value:   value
            })
            .done(function(response) {
                $input.prop('disabled', false);

                if ( !response || !response.success ) {
                    $input.prop('checked', !$input.is(':checked'));
                    var msg = (response && response.data && response.data.message)
                              ? response.data.message : I18N.saveFailed;
                    showNotification('error', msg);
                    return;
                }

                showNotification('success', I18N.toggleSaved);

                // Update every sidebar indicator from the server-computed map.
                if ( response.data && response.data.effective_map ) {
                    $.each(response.data.effective_map, function(emailType, isEnabled) {
                        updateSidebarStatus(emailType, isEnabled);
                    });
                }
            })
            .fail(function() {
                $input.prop('disabled', false);
                $input.prop('checked', !$input.is(':checked'));
                showNotification('error', I18N.serverError);
            });
        });

        // ============================================
        // HELPER FUNCTIONS
        // ============================================

        /**
         * Show a brief slide-in notification toast.
         * Text is set via .text() to prevent XSS.
         *
         * @param {string} type    'success' | 'error'
         * @param {string} message Plain-text message.
         */
        function showNotification(type, message) {
            var icon = type === 'success' ? 'yes-alt' : 'dismiss';
            var $n   = $('<div class="sh-notification sh-notification-' + type + '">' +
                         '<span class="dashicons dashicons-' + icon + '"></span>' +
                         '<span class="sh-notification-text"></span>' +
                         '</div>');
            $n.find('.sh-notification-text').text(message);
            $('body').append($n);
            setTimeout(function() { $n.addClass('show'); }, 10);
            setTimeout(function() {
                $n.removeClass('show');
                setTimeout(function() { $n.remove(); }, 300);
            }, 3000);
        }

        // Expose globally so other scripts (e.g. admin-branding.js) can reuse
        // the same toast without duplicating its implementation.
        window.shShowNotification = showNotification;

        /**
         * Task 1: Update the sidebar enabled/disabled icon for one email type.
         * Targets by data-email-type attribute (reliable; doesn't rely on href parsing).
         *
         * @param {string}  emailType  Legacy key e.g. 'customer_hold_created'.
         * @param {boolean} enabled    Effective enabled state.
         */
        function updateSidebarStatus(emailType, enabled) {
            var $item   = $('.sh-email-item[data-email-type="' + emailType + '"]');
            var $status = $item.find('.sh-email-status');

            if ( !$status.length || $status.hasClass('coming-soon') ) { return; }

            if ( enabled ) {
                $status.removeClass('disabled').addClass('enabled');
                $status.find('.dashicons')
                    .removeClass('dashicons-dismiss')
                    .addClass('dashicons-yes-alt');
            } else {
                $status.removeClass('enabled').addClass('disabled');
                $status.find('.dashicons')
                    .removeClass('dashicons-yes-alt')
                    .addClass('dashicons-dismiss');
            }
        }

    }); // end $(document).ready

})(jQuery);
