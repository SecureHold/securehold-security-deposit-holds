/**
 * SecureHold Admin - Settings page script
 * Loaded only on the Settings screen (securehold-settings).
 * Handle: securehold-admin-settings-js
 *
 * PHP data injected via wp_localize_script into secureholdSettingsParams:
 *   .nonce   - securehold_inspect_order_meta nonce
 *   .i18n    - translated strings
 */
jQuery(function($) {

    var params = (typeof secureholdSettingsParams !== 'undefined') ? secureholdSettingsParams : {};
    var i18n   = params.i18n || {};

    // ── Credential key masking: Update / Cancel toggle ────────────────────────
    // "Update" button: hide the masked display, show the empty edit input and focus it.
    $(document).on('click', '.sh-key-update-btn', function() {
        var displayId = $(this).data('display');
        var editId    = $(this).data('edit');
        $('#' + displayId).hide();
        $('#' + editId).show().find('input').first().focus();
    });

    // "Cancel" button: hide the edit input, restore the masked display, clear any typed value.
    $(document).on('click', '.sh-key-cancel-btn', function() {
        var displayId = $(this).data('display');
        var editId    = $(this).data('edit');
        $('#' + editId).hide().find('input').val('');
        $('#' + displayId).show();
    });

    // ── Meta key helper dropdown ──────────────────────────────────────────────
    $('#sh-meta-key-helper').on('change', function() {
        var val = $(this).val();
        if (val) {
            $('#securehold_date_field_key').val(val).trigger('focus');
        }
        $(this).val(''); // Reset to placeholder
    });

    // ── Resolution Policy help toggle ─────────────────────────────────────────
    $('#sh-resolution-policy').on('change', function() {
        var val = $(this).val();
        $('.sh-policy-help').hide();
        if (val === 'highest_deposit_wins') {
            $('#sh-policy-help-highest-deposit-wins').slideDown(200);
        } else {
            $('#sh-policy-help-priority-chain').slideDown(200);
        }
    });

    // ── Engine Version help toggle ────────────────────────────────────────────
    $('#sh-engine-version').on('change', function() {
        var val = $(this).val();
        $('#sh-engine-help-v2, #sh-engine-help-legacy').hide();
        if (val === 'legacy') {
            $('#sh-engine-help-legacy').slideDown(200);
        } else {
            $('#sh-engine-help-v2').slideDown(200);
        }
    });

    // ── Aggregation Mode help toggle ──────────────────────────────────────────
    $('#sh-aggregation-mode').on('change', function() {
        var val = $(this).val();
        $('#sh-agg-help-per-order, #sh-agg-help-per-item').hide();
        if (val === 'per_item_aggregated') {
            $('#sh-agg-help-per-item').slideDown(200);
        } else {
            $('#sh-agg-help-per-order').slideDown(200);
        }
    });

    // ── Timing card selection ─────────────────────────────────────────────────
    $('.timing-card input[name="securehold_capture_timing"]').on('change', function() {
        var strategy = $(this).val();
        $('.timing-card').removeClass('selected');
        $(this).closest('.timing-card').addClass('selected');
        $('.timing-settings').hide();
        $('#setting-' + strategy).slideDown(200);
    });

    // ── Inspect Order Meta Keys Helper ────────────────────────────────────────
    $('#sh-inspect-order-btn').on('click', function() {
        var orderId = $('#sh-inspect-order-id').val();
        var $result = $('#sh-inspect-result');
        var $btn    = $(this);

        if (!orderId || parseInt(orderId) <= 0) {
            $result.html(
                '<p class="sh-inspect-message error">'
                + (i18n.inspectInvalidId || 'Please enter a valid Order ID.')
                + '</p>'
            ).show();
            return;
        }

        $btn.prop('disabled', true);
        $result.html(
            '<p class="sh-inspect-message loading">'
            + '<span class="dashicons dashicons-update spin" style="animation:rotation 1s linear infinite; font-size:14px; width:14px; height:14px; vertical-align:middle; margin-right:4px;"></span>'
            + (i18n.inspecting || 'Inspecting order...')
            + '</p>'
        ).show();

        $.post(ajaxurl, {
            action:   'securehold_inspect_order_meta',
            nonce:    params.nonce || '',
            order_id: orderId
        }, function(response) {
            $btn.prop('disabled', false);

            if (!response.success) {
                $result.html(
                    '<p class="sh-inspect-message error">'
                    + '<span class="dashicons dashicons-warning" style="font-size:14px; width:14px; height:14px; vertical-align:middle; margin-right:4px;"></span>'
                    + response.data.message
                    + '</p>'
                ).show();
                return;
            }

            var data = response.data;
            var html = '<div style="margin-top:0.75rem;">';
            html += '<p class="sh-inspect-summary">'
                + (i18n.found || 'Found') + ' <strong>' + data.count + '</strong> '
                + (i18n.metaKeysOn || 'meta keys on order') + ' #' + data.order_id
                + '</p>';

            html += '<div class="sh-inspect-table-wrap">';
            html += '<table class="sh-inspect-table">';
            html += '<thead><tr>';
            html += '<th>' + (i18n.colKey    || 'Key')           + '</th>';
            html += '<th>' + (i18n.colValue  || 'Value (sample)') + '</th>';
            html += '<th class="col-source">' + (i18n.colSource || 'Source') + '</th>';
            html += '<th class="col-use">'    + (i18n.colUse    || 'Use')    + '</th>';
            html += '</tr></thead><tbody>';

            $.each(data.meta, function(i, m) {
                var originLabel = m.origin === 'order_meta'
                    ? '<span class="sh-inspect-origin-order">' + (i18n.originOrder || 'Order') + '</span>'
                    : '<span class="sh-inspect-origin-item">'  + (i18n.originItem  || 'Item')  + '</span>';

                var itemInfo = m.item
                    ? ' <span class="sh-inspect-item-info" title="' + $('<span>').text(m.item).html() + '">(' + $('<span>').text(m.item).html() + ')</span>'
                    : '';

                html += '<tr>';
                html += '<td class="col-key">'   + $('<span>').text(m.key).html()   + '</td>';
                html += '<td class="col-value" title="' + $('<span>').text(m.value).html() + '">' + $('<span>').text(m.value).html() + '</td>';
                html += '<td class="col-source">' + originLabel + itemInfo + '</td>';
                html += '<td class="col-use">';
                html += '<button type="button" class="sh-use-key-btn" data-key="' + $('<span>').text(m.key).html() + '" title="' + (i18n.copyToField || 'Copy to Date Meta Key field') + '">';
                html += '<span class="dashicons dashicons-clipboard"></span>';
                html += '</button></td>';
                html += '</tr>';
            });

            html += '</tbody></table></div></div>';
            $result.html(html).show();

            // "Use this key" button handler
            $result.find('.sh-use-key-btn').on('click', function(e) {
                e.preventDefault();
                var key = $(this).data('key');
                $('#securehold_date_field_key').val(key).trigger('focus');
                $(this).html('<span class="dashicons dashicons-yes"></span>').addClass('confirmed');
                var that = this;
                setTimeout(function() {
                    $(that).html('<span class="dashicons dashicons-clipboard"></span>').removeClass('confirmed');
                }, 1500);
            });

        }).fail(function() {
            $btn.prop('disabled', false);
            $result.html(
                '<p class="sh-inspect-message error">'
                + (i18n.requestFailed || 'Request failed. Please try again.')
                + '</p>'
            ).show();
        });
    });

    // Allow Enter key in order ID field
    $('#sh-inspect-order-id').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#sh-inspect-order-btn').trigger('click');
        }
    });

    // ── Deposit Clause: modal open / close ────────────────────────────────────
    var $clauseModal = $('#sh-clause-modal');

    function openClauseModal() {
        $clauseModal.removeAttr('hidden');
        $clauseModal.find('.sh-clause-modal-close').first().trigger('focus');
        $('body').addClass('sh-modal-open');
    }

    function closeClauseModal() {
        $clauseModal.attr('hidden', '');
        $('body').removeClass('sh-modal-open');
        $('.sh-clause-modal-trigger').trigger('focus');
    }

    $(document).on('click', '.sh-clause-modal-trigger', function() {
        openClauseModal();
    });

    $(document).on('click', '.sh-clause-modal-close', function() {
        closeClauseModal();
    });

    $(document).on('click', '.sh-clause-modal-backdrop', function() {
        closeClauseModal();
    });

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$clauseModal.attr('hidden')) {
            closeClauseModal();
        }
    });

    // Trap focus inside modal (Tab / Shift+Tab cycling).
    $clauseModal.on('keydown', function(e) {
        if (e.key !== 'Tab') return;
        var focusable = $clauseModal.find(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        ).filter(':visible');
        if (!focusable.length) return;
        var first = focusable.first()[0];
        var last  = focusable.last()[0];
        if (e.shiftKey) {
            if (document.activeElement === first) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if (document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
    });

    // ── CGV Clause: Copy to clipboard ─────────────────────────────────────────
    $('#sh-copy-clause-btn').on('click', function() {
        var $btn = $(this);
        var text = $('#sh-cgv-clause-text').text().replace(/\s{2,}/g, ' ').trim();

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                $btn.addClass('sh-copy-success');
                $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
                setTimeout(function() {
                    $btn.removeClass('sh-copy-success');
                    $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
                }, 2000);
            }).catch(function() {
                secureholdFallbackCopy(text, $btn);
            });
        } else {
            secureholdFallbackCopy(text, $btn);
        }
    });

    function secureholdFallbackCopy(text, $btn) {
        var $ta = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 }).appendTo('body');
        $ta[0].select();
        try {
            document.execCommand('copy');
            $btn.addClass('sh-copy-success');
            $btn.find('.dashicons').removeClass('dashicons-clipboard').addClass('dashicons-yes');
            setTimeout(function() {
                $btn.removeClass('sh-copy-success');
                $btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-clipboard');
            }, 2000);
        } catch (e) { /* silent */ }
        $ta.remove();
    }

});
