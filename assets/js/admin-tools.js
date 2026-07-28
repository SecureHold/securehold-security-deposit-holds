/**
 * SecureHold WP — Tools page button handlers (FREE).
 *
 * Wires the diagnostic buttons on Tools & Maintenance to the existing
 * wp_ajax_securehold_test_config endpoint shipped by the FREE plugin.
 *
 * Buttons handled here:
 *   - #btn-stripe-inspector   -> renders results into #sh-inspector-results
 *   - #btn-run-tool-test      -> opens the existing #sh-test-modal and renders results
 *
 * The Restart Setup Wizard button is a real <form> submit and needs no JS.
 *
 * Required globals (provided by enqueue_admin_assets()):
 *   ajaxurl                       (WP core, admin pages)
 *   secureholdAdminParams.nonces.testConfig (existing nonce for securehold_test_config)
 *
 * The script handle is "securehold-admin-tools" — matches the wp_script_is()
 * guard in admin/views/tools-page.php so the inline tab-routing block stays
 * compatible when PRO ships its own admin-tools script.
 */
( function( $ ) {
    'use strict';

    function getNonce() {
        if ( typeof window.secureholdAdminParams !== 'undefined'
            && secureholdAdminParams.nonces
            && secureholdAdminParams.nonces.testConfig ) {
            return secureholdAdminParams.nonces.testConfig;
        }
        return '';
    }

    function getAjaxUrl() {
        if ( typeof window.ajaxurl === 'string' && ajaxurl ) {
            return ajaxurl;
        }
        if ( typeof window.secureholdAdminParams !== 'undefined' && secureholdAdminParams.ajaxurl ) {
            return secureholdAdminParams.ajaxurl;
        }
        return '';
    }

    function escapeHtml( str ) {
        return ( '' + ( str == null ? '' : str ) )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }

    function summariseSteps( steps ) {
        var s = { total: 0, success: 0, warning: 0, error: 0 };
        if ( ! steps || ! steps.length ) { return s; }
        steps.forEach( function( step ) {
            s.total++;
            var status = step.status || 'success';
            if ( status === 'error' )        { s.error++; }
            else if ( status === 'warning' ) { s.warning++; }
            else                             { s.success++; }
        } );
        return s;
    }

    /**
     * Compact "verdict + checklist" rendering.
     * Used for the Diagnostics > Stripe Status & Compliance card — short, scannable.
     */
    function buildSummaryHtml( response ) {
        var steps = response && response.steps;
        if ( ! steps || ! steps.length ) {
            return '<div class="sh-verdict sh-verdict--warning">' +
                '<span class="dashicons dashicons-warning"></span>' +
                '<span>No diagnostic data returned.</span>' +
                '</div>';
        }
        var stats = summariseSteps( steps );
        var verdictClass = 'sh-verdict--success';
        var verdictIcon  = 'yes-alt';
        var verdictText  = 'All ' + stats.total + ' checks passed.';
        if ( stats.error > 0 ) {
            verdictClass = 'sh-verdict--error';
            verdictIcon  = 'dismiss';
            verdictText  = stats.error + ' issue(s) detected — see details below.';
        } else if ( stats.warning > 0 ) {
            verdictClass = 'sh-verdict--warning';
            verdictIcon  = 'warning';
            verdictText  = stats.warning + ' warning(s) — review the checks below.';
        }

        // Compact verdict: smaller padding + font via .sh-verdict--sm
        var html = '<div class="sh-verdict ' + verdictClass + ' sh-verdict--sm">' +
            '<span class="dashicons dashicons-' + verdictIcon + '"></span>' +
            '<span>' + escapeHtml( verdictText ) + '</span>' +
            '</div>';

        // Responsive 2-column grid via .sh-check-list--grid; rows compact via .sh-check-row--sm
        html += '<div class="sh-check-list sh-check-list--grid">';
        steps.forEach( function( step ) {
            var status = step.status || 'success';
            var rowClass = 'sh-check-row--success';
            var rowIcon  = 'yes-alt';
            if ( status === 'error' )        { rowClass = 'sh-check-row--error';   rowIcon = 'dismiss'; }
            else if ( status === 'warning' ) { rowClass = 'sh-check-row--warning'; rowIcon = 'warning'; }
            html += '<div class="sh-check-row sh-check-row--sm ' + rowClass + '">' +
                '<span class="dashicons dashicons-' + rowIcon + '"></span>' +
                '<div class="sh-check-row-content">' +
                    '<strong>' + escapeHtml( step.name || '' ) + '</strong>' +
                    '<span>' + escapeHtml( step.message || '' ) + '</span>' +
                '</div>' +
                '</div>';
        } );
        html += '</div>';
        return html;
    }

    /**
     * Detailed end-to-end report rendering.
     * Used for System > Configuration Self-Test — full step-by-step in the modal.
     * Uses .sh-test-step cards (rich gradients defined in the page's inline <style>).
     */
    function buildReportHtml( response ) {
        var steps = response && response.steps;
        if ( ! steps || ! steps.length ) {
            return '<div class="sh-test-results"><div class="sh-test-step sh-test-step-warning">' +
                    '<span class="dashicons dashicons-warning"></span>' +
                    '<div><strong>No data</strong><span>The diagnostic returned no steps.</span></div>' +
                '</div></div>';
        }
        var stats = summariseSteps( steps );
        var verdictClass = 'sh-verdict--success';
        var verdictIcon  = 'yes-alt';
        var verdictText  = 'End-to-end flow validated — ' + stats.total + ' steps completed.';
        if ( stats.error > 0 ) {
            verdictClass = 'sh-verdict--error';
            verdictIcon  = 'dismiss';
            verdictText  = stats.error + ' step(s) failed out of ' + stats.total + '.';
        } else if ( stats.warning > 0 ) {
            verdictClass = 'sh-verdict--warning';
            verdictIcon  = 'warning';
            verdictText  = stats.warning + ' warning(s) raised across ' + stats.total + ' steps.';
        }

        var html = '<div class="sh-verdict ' + verdictClass + '">' +
            '<span class="dashicons dashicons-' + verdictIcon + '"></span>' +
            '<span>' + escapeHtml( verdictText ) + '</span>' +
            '</div>';

        html += '<div class="sh-test-results">';
        steps.forEach( function( step ) {
            var status = step.status || 'success';
            var icon   = 'yes-alt';
            if ( status === 'error' )        { icon = 'dismiss'; }
            else if ( status === 'warning' ) { icon = 'warning'; }
            html += '<div class="sh-test-step sh-test-step-' + status + '">' +
                    '<span class="dashicons dashicons-' + icon + '"></span>' +
                    '<div>' +
                        '<strong>' + escapeHtml( step.name || '' ) + '</strong>' +
                        '<span>' + escapeHtml( step.message || '' ) + '</span>' +
                    '</div>' +
                '</div>';
        } );
        html += '</div>';
        return html;
    }

    /**
     * Fire the AJAX test and render through the caller-supplied renderer.
     *
     * options.renderer: required. function(response, $target).
     */
    function runDiagnostic( options ) {
        var $button   = options.$button;
        var $target   = options.$target;
        var $loading  = options.$loading;          // optional
        var renderer  = options.renderer || buildReportHtml;
        var originalHtml = $button.html();

        $button.prop( 'disabled', true );
        $button.html( '<span class="dashicons dashicons-update"></span> …' );

        if ( $loading && $loading.length ) { $loading.show(); }
        if ( $target && $target.length )   { $target.show().html( '' ); }

        $.ajax( {
            url: getAjaxUrl(),
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'securehold_test_config',
                nonce:  getNonce()
            }
        } ).done( function( response ) {
            if ( $loading && $loading.length ) { $loading.hide(); }
            if ( $target && $target.length )   { $target.show().html( renderer( response ) ); }
        } ).fail( function( jqXHR ) {
            if ( $loading && $loading.length ) { $loading.hide(); }
            var rj = jqXHR && jqXHR.responseJSON;
            if ( rj && rj.steps && rj.steps.length ) {
                if ( $target && $target.length ) { $target.show().html( renderer( rj ) ); }
                return;
            }
            if ( $target && $target.length ) {
                var msg = 'Diagnostic request failed (HTTP ' +
                    ( jqXHR && jqXHR.status ? jqXHR.status : '?' ) + ').';
                $target.show().html(
                    '<div class="sh-verdict sh-verdict--error">' +
                        '<span class="dashicons dashicons-dismiss"></span>' +
                        '<span>' + escapeHtml( msg ) + '</span>' +
                    '</div>'
                );
            }
        } ).always( function() {
            $button.prop( 'disabled', false ).html( originalHtml );
        } );
    }

    // ── Tab routing ─────────────────────────────────────────────────────
    // Activates the matching .sh-tab-panel for the clicked .sh-tab-link[data-tab].
    // Persists the active tab in localStorage so a reload lands on the same panel.
    function activateTab( tab ) {
        var $links  = $( '.sh-tabs-wrapper .sh-tab-link[data-tab]' );
        var $panels = $( '.sh-tab-panel' );
        if ( ! tab ) {
            return;
        }
        $links.removeClass( 'active' ).attr( 'aria-selected', 'false' );
        $links.filter( '[data-tab="' + tab + '"]' ).addClass( 'active' ).attr( 'aria-selected', 'true' );
        $panels.hide().removeClass( 'sh-tab-panel--active' );
        $( '#sh-tab-' + tab ).show().addClass( 'sh-tab-panel--active' );
        try { localStorage.setItem( 'securehold_tools_active_tab', tab ); } catch ( e ) {}
    }

    $( function() {
        // Tab clicks — delegated so it survives any DOM swap.
        $( document ).on( 'click', '.sh-tabs-wrapper .sh-tab-link[data-tab]', function( e ) {
            e.preventDefault();
            activateTab( $( this ).data( 'tab' ) );
        } );

        // Restore the last active tab from localStorage on initial load.
        // If the stored tab no longer exists (e.g. Advanced Tools hidden in FREE),
        // fall back to "diagnostics" and rewrite localStorage so the user doesn't
        // see the saved value re-evaluated on every reload.
        var saved = '';
        try { saved = localStorage.getItem( 'securehold_tools_active_tab' ); } catch ( e ) {}
        if ( saved && ! $( '#sh-tab-' + saved ).length ) {
            saved = 'diagnostics';
            try { localStorage.setItem( 'securehold_tools_active_tab', saved ); } catch ( e ) {}
        }
        if ( saved && $( '#sh-tab-' + saved ).length ) {
            activateTab( saved );
        }

        // ── Diagnostics > Stripe Status & Compliance ────────────────────
        // Renders a compact "verdict + checklist" inline in the card.
        $( document ).on( 'click', '#btn-stripe-inspector', function( e ) {
            e.preventDefault();
            runDiagnostic( {
                $button:  $( this ),
                $target:  $( '#sh-inspector-results' ),
                renderer: buildSummaryHtml
            } );
        } );

        // ── System > Configuration Self-Test ────────────────────────────
        // Opens the design-system modal and renders the full end-to-end report
        // with rich .sh-test-step cards plus a verdict banner.
        // The modal uses .sh-modal-overlay (opacity:0 by default) and only
        // becomes visible when the .active class is added.
        $( document ).on( 'click', '#btn-run-tool-test', function( e ) {
            e.preventDefault();
            var $modal   = $( '#sh-test-modal' );
            var $results = $modal.find( '.sh-modal-results' );
            var $loading = $modal.find( '.sh-modal-loading' );

            $modal.addClass( 'active' );
            $results.hide().html( '' );
            $loading.show();

            runDiagnostic( {
                $button:  $( this ),
                $target:  $results,
                $loading: $loading,
                renderer: buildReportHtml
            } );
        } );

        // ── Close diagnostic modal ──────────────────────────────────────
        // Close on button click and on backdrop click (clicking the overlay
        // itself, not its inner .sh-modal child).
        $( document ).on( 'click', '.sh-modal-close-btn', function( e ) {
            e.preventDefault();
            $( '#sh-test-modal' ).removeClass( 'active' );
        } );
        $( document ).on( 'click', '#sh-test-modal', function( e ) {
            if ( e.target === this ) {
                $( this ).removeClass( 'active' );
            }
        } );
    } );

} )( jQuery );
