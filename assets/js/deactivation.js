/**
 * Deactivation Modal Handler
 *
 * Scroll-freeze strategy:
 *  1. MutationObserver on document.body (subtree:true) — detects every DOM
 *     change that could affect the overlay visibility, regardless of which
 *     code path triggers show/hide.
 *  2. Visibility via getClientRects().length > 0 — the only reliable method
 *     for position:fixed elements (offsetParent is ALWAYS null for fixed).
 *  3. Belt-and-suspenders: freezeScroll() is also called eagerly in the click
 *     handler so there is zero gap before the first MutationObserver callback.
 *  4. position:fixed + scrollY save/restore on body — prevents desktop scroll.
 *  5. overflow:hidden on html + body — catches WP admin root scroll.
 *  6. wheel + touchmove blocked via document (capture:true, passive:false).
 *     Scroll is allowed ONLY inside .securehold-deactivation-dialog (white box).
 *     The dark overlay background is NOT an allowed scroll target.
 *  7. keydown blocked for scroll keys (Space, arrows, Page*, Home, End) when
 *     focus is outside the dialog.
 */
(function($) {
    'use strict';

    var deactivationLink = '';
    var currentPlugin    = 'free'; // 'free' | 'pro' — updated on each deactivation click
    var modalIsOpen      = false;
    var scrollObserver   = null;

    // Keys that trigger page scroll — blocked when modal is open and focus is
    // outside the dialog.
    var SCROLL_KEYS = {
        ' ':        true,
        'PageUp':   true,
        'PageDown': true,
        'End':      true,
        'Home':     true,
        'ArrowUp':  true,
        'ArrowDown':true
    };

    /**
     * Returns the white dialog element (only zone where scroll is allowed).
     * @return {Element|null}
     */
    function getDialog() {
        return document.querySelector('.securehold-deactivation-dialog');
    }

    /**
     * wheel / touchmove handler — blocks background scroll.
     *
     * The condition is intentionally strict: only events whose target is
     * INSIDE the dialog (white box) are let through.  Events on the dark
     * overlay background are blocked — overlay.contains(e.target) would have
     * returned true for those too, which is why the previous guard was wrong.
     */
    function blockScroll(e) {
        if ( ! modalIsOpen ) { return; }
        var dialog = getDialog();
        if ( dialog && dialog.contains(e.target) ) { return; }
        e.preventDefault();
        e.stopPropagation();
    }

    /**
     * keydown handler — blocks keyboard-triggered scroll when focus is outside
     * the dialog.  Allows normal keyboard interaction inside the dialog
     * (textarea, inputs, radio buttons).
     */
    function blockKeyScroll(e) {
        if ( ! modalIsOpen ) { return; }
        if ( ! SCROLL_KEYS[ e.key ] ) { return; }
        var dialog = getDialog();
        if ( dialog && dialog.contains(document.activeElement) ) { return; }
        e.preventDefault();
        e.stopPropagation();
    }

    /**
     * Lock body scroll. Guard against double-call via modalIsOpen flag.
     */
    function freezeScroll() {
        if ( modalIsOpen ) { return; }
        var scrollY = window.scrollY || window.pageYOffset;
        document.body.dataset.shScrollY = String(scrollY);
        document.documentElement.classList.add('securehold-modal-open');
        document.body.classList.add('securehold-modal-open');
        document.body.style.position = 'fixed';
        document.body.style.top      = '-' + scrollY + 'px';
        document.body.style.width    = '100%';
        modalIsOpen = true;
        // capture:true → we intercept before native scroll handling fires.
        // passive:false → we are allowed to call preventDefault().
        document.addEventListener('wheel',     blockScroll,    { capture: true, passive: false });
        document.addEventListener('touchmove', blockScroll,    { capture: true, passive: false });
        document.addEventListener('keydown',   blockKeyScroll, { capture: true });
    }

    /**
     * Unlock body scroll and restore scroll position. Guard against double-call.
     */
    function unfreezeScroll() {
        if ( ! modalIsOpen ) { return; }
        modalIsOpen = false;
        // capture:true must match the add() call for removeEventListener to work.
        document.removeEventListener('wheel',     blockScroll,    { capture: true });
        document.removeEventListener('touchmove', blockScroll,    { capture: true });
        document.removeEventListener('keydown',   blockKeyScroll, { capture: true });
        document.documentElement.classList.remove('securehold-modal-open');
        document.body.classList.remove('securehold-modal-open');
        document.body.style.position = '';
        document.body.style.top      = '';
        document.body.style.width    = '';
        var scrollY = parseInt(document.body.dataset.shScrollY || '0', 10);
        window.scrollTo(0, scrollY);
        delete document.body.dataset.shScrollY;
    }

    /**
     * Reliable visibility check for position:fixed elements.
     *
     * offsetParent is always null for position:fixed regardless of visibility,
     * so it cannot be used.  getClientRects() returns a non-empty DOMRectList
     * only when the element has a layout box — i.e. display !== none.
     *
     * @param  {Element|null} overlay
     * @return {boolean}
     */
    function isOverlayVisible( overlay ) {
        if ( ! overlay ) { return false; }
        if ( overlay.getClientRects().length === 0 ) { return false; }
        return window.getComputedStyle( overlay ).display !== 'none';
    }

    /**
     * MutationObserver — watches document.body with subtree:true.
     *
     * Watching document.body catches every mutation that could affect the
     * overlay's visibility regardless of which code path triggers show/hide.
     * attributeFilter limits callbacks to style/class changes only.
     */
    function initScrollObserver() {
        if ( scrollObserver ) { return; }

        scrollObserver = new MutationObserver(function() {
            var overlay = document.getElementById('securehold-deactivation-modal');
            var visible = isOverlayVisible( overlay );

            if ( visible && ! modalIsOpen ) {
                freezeScroll();
            } else if ( ! visible && modalIsOpen ) {
                unfreezeScroll();
            }
        });

        scrollObserver.observe( document.body, {
            subtree:         true,
            childList:       true,
            attributes:      true,
            attributeFilter: ['style', 'class']
        });
    }

    $(document).ready(function() {

        initScrollObserver();

        // Intercept deactivation link clicks for both FREE and PRO plugin rows.
        //
        // NOTE: data-plugin is used instead of data-slug. WordPress derives data-slug
        // from sanitize_title(Plugin Name), NOT from the folder name. The FREE plugin's
        // long name ("SecureHold WP: Stripe Security Deposits for WooCommerce") produces
        // a slug that does not match "securehold-wp", so the click was silently missed.
        // data-plugin is always set to the plugin file path (folder/file.php) and is
        // stable across WordPress versions and plugin name changes.
        $(document).on(
            'click',
            '[data-plugin="securehold-wp/securehold-wp-stripe-deposits.php"] a[href*="action=deactivate"], ' +
            '[data-plugin="securehold-pro/securehold-pro.php"] a[href*="action=deactivate"]',
            function(e) {
                e.preventDefault();
                e.stopPropagation();

                deactivationLink = $(this).attr('href');

                // Detect which plugin row was clicked.
                var row = $(this).closest('tr[data-plugin]');
                currentPlugin = ( row.data('plugin') === 'securehold-pro/securehold-pro.php' ) ? 'pro' : 'free';

                // Sync hidden field and update subtitle before the modal opens.
                $( '#securehold-plugin-type' ).val( currentPlugin );
                var pluginLabel = ( currentPlugin === 'pro' ) ? 'SecureHold PRO' : 'SecureHold WP';
                $( '#securehold-modal-plugin-name' ).text( pluginLabel );

                // Belt-and-suspenders: freeze immediately in the same task so there
                // is no gap before the first MutationObserver callback fires.
                freezeScroll();
                $('#securehold-deactivation-modal').fadeIn(200);

                return false;
            }
        );

        // Reason radio — show/hide textarea.
        $('input[name="reason"]').on('change', function() {
            var showTextarea = $(this).data('show-textarea');
            var placeholder  = $(this).data('placeholder');

            if ( showTextarea ) {
                $('#securehold-reason-textarea').attr('placeholder', placeholder);
                $('#securehold-reason-details').slideDown(200);
            } else {
                $('#securehold-reason-details').slideUp(200);
            }
        });

        // Cancel — unfreezeScroll is triggered by MutationObserver when fadeOut
        // sets display:none, but unfreezeScroll() also guards against double-call.
        $('#securehold-cancel-deactivation').on('click', function() {
            $('#securehold-deactivation-modal').fadeOut(200);
            $('input[name="reason"]').prop('checked', false);
            $('#securehold-reason-details').hide();
            $('#securehold-reason-textarea').val('');
        });

        // Skip & Deactivate.
        $('#securehold-skip-deactivation').on('click', function() {
            window.location.href = deactivationLink;
        });

        // Submit & Deactivate.
        $('#securehold-deactivation-form').on('submit', function(e) {
            e.preventDefault();

            var reason = $('input[name="reason"]:checked').val();
            if ( ! reason ) {
                alert('Please select a reason');
                return;
            }

            var details    = $('#securehold-reason-textarea').val();
            var $submitBtn = $('#securehold-submit-deactivation');
            $submitBtn.prop('disabled', true).text('Sending...');

            $.ajax({
                url:  ajaxurl,
                type: 'POST',
                data: {
                    action:  'securehold_deactivation_feedback',
                    nonce:   SecureHoldAdmin.deactivationNonce,
                    plugin:  currentPlugin,
                    reason:  reason,
                    details: details
                },
                success: function() { window.location.href = deactivationLink; },
                error:   function() { window.location.href = deactivationLink; }
            });
        });

        // Close on overlay background click.
        $('.securehold-deactivation-overlay').on('click', function(e) {
            if ( e.target === this ) {
                $('#securehold-cancel-deactivation').trigger('click');
            }
        });
    });

})(jQuery);
