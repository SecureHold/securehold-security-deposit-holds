/**
 * SecureHold Admin - Health Check page script
 * Loaded only on the Health Check screen (securehold-health).
 * Handle: securehold-admin-health-check
 *
 * PHP data injected via wp_localize_script into secureholdHealthCheck:
 *   .nonce   - securehold_diagnose_order_stripe nonce
 *   .i18n    - translated strings
 */
(function($) {
    'use strict';

    var params = (typeof secureholdHealthCheck !== 'undefined') ? secureholdHealthCheck : {};
    var i18n   = params.i18n || {};

    // ── Helper: table row ────────────────────────────────────────
    function _tr(label, value) {
        return '<tr>'
            + '<td style="padding:6px 12px 6px 0;color:#6b7280;white-space:nowrap;vertical-align:top;border-bottom:1px solid #f3f4f6;font-weight:500;">' + label + '</td>'
            + '<td style="padding:6px 0;border-bottom:1px solid #f3f4f6;">' + value + '</td>'
            + '</tr>';
    }

    // ── Run diagnosis button ─────────────────────────────────────
    $('#securehold-diag-run').on('click', function() {
        var orderId = $('#securehold-diag-order-id').val();
        if (!orderId || orderId <= 0) {
            alert(i18n.invalidOrderId || 'Please enter a valid Order ID.');
            return;
        }

        var $btn     = $(this);
        var $spinner = $('#securehold-diag-spinner');
        var $result  = $('#securehold-diag-result');

        $btn.prop('disabled', true);
        $spinner.show();
        $result.hide().empty();

        $.ajax({
            url:  ajaxurl,
            type: 'POST',
            data: {
                action:   'securehold_diagnose_order_stripe',
                order_id: orderId,
                nonce:    params.nonce || ''
            },
            success: function(response) {
                $btn.prop('disabled', false);
                $spinner.hide();

                if (!response.success) {
                    var msg = (response.data && response.data.message) ? response.data.message : 'Unknown error';
                    $result.html(
                        '<div style="padding:12px;background:#fee2e2;border-left:4px solid #ef4444;border-radius:6px;color:#991b1b;font-size:13px;">'
                        + '<strong>Error:</strong> ' + msg
                        + '</div>'
                    ).show();
                    return;
                }

                var d    = response.data;
                var html = '';

                // Diagnosis badge
                var diagColors = {
                    'reusable':             { bg: '#dcfce7', border: '#22c55e', text: '#166534', label: i18n.diagReusable            || 'Reusable',             icon: '✅' },
                    'no_setup_future_usage':{ bg: '#fef3cd', border: '#ffc107', text: '#856404', label: i18n.diagNoSfu               || 'No setup_future_usage', icon: '⚠️' },
                    'pm_not_attached':      { bg: '#fef3cd', border: '#ffc107', text: '#856404', label: i18n.diagPmNotAttached        || 'PM Not Attached',       icon: '⚠️' },
                    'no_intent_id':         { bg: '#fee2e2', border: '#ef4444', text: '#991b1b', label: i18n.diagNoIntentId           || 'No Intent ID',          icon: '❌' },
                    'api_error':            { bg: '#fee2e2', border: '#ef4444', text: '#991b1b', label: i18n.diagApiError             || 'API Error',             icon: '❌' }
                };
                var dc = diagColors[d.diagnosis] || { bg: '#f3f4f6', border: '#9ca3af', text: '#374151', label: d.diagnosis, icon: '❓' };

                html += '<div style="padding:12px 16px;background:' + dc.bg + ';border-left:4px solid ' + dc.border + ';border-radius:6px;color:' + dc.text + ';font-size:14px;font-weight:600;margin-bottom:12px;">';
                html += dc.icon + ' ' + (i18n.diagnosisLabel || 'Diagnosis:') + ' ' + dc.label;
                if (d.can_create_hold) {
                    html += ' — <span style="font-weight:normal;">' + (i18n.holdShouldSucceed || 'Hold creation should succeed') + '</span>';
                }
                html += '</div>';

                // Details table
                html += '<table style="width:100%;border-collapse:collapse;font-size:13px;">';

                if (d.intent) {
                    html += _tr(i18n.paymentIntent || 'PaymentIntent', '<code>' + d.intent.id + '</code> (' + d.intent.status + ')');
                    html += _tr('setup_future_usage', d.intent.setup_future_usage
                        ? '<strong style="color:#166534;">' + d.intent.setup_future_usage + '</strong>'
                        : '<strong style="color:#dc2626;">' + (i18n.notSet || 'not set') + '</strong>');
                    html += _tr(i18n.piCustomer || 'PI Customer', d.intent.customer || '<em style="color:#9ca3af;">' + (i18n.none || 'none') + '</em>');
                    html += _tr(i18n.piAmount   || 'PI Amount', (d.intent.amount / 100).toFixed(2) + ' ' + d.intent.currency.toUpperCase());
                }

                if (d.payment_method && !d.payment_method.error) {
                    html += _tr(i18n.paymentMethod || 'Payment Method', '<code>' + d.payment_method.id + '</code>');
                    html += _tr(i18n.card || 'Card', (d.payment_method.card_brand || '') + ' ****' + (d.payment_method.card_last4 || '') + (d.payment_method.card_exp ? ' exp ' + d.payment_method.card_exp : ''));
                    html += _tr(i18n.pmAttachedTo || 'PM attached to', d.payment_method.customer
                        ? '<strong style="color:#166534;">' + d.payment_method.customer + '</strong>'
                        : '<strong style="color:#dc2626;">' + (i18n.notAttached || 'not attached') + '</strong>');
                } else if (d.payment_method && d.payment_method.error) {
                    html += _tr(i18n.paymentMethod || 'Payment Method', '<span style="color:#dc2626;">' + (i18n.errorLabel || 'Error:') + ' ' + d.payment_method.error + '</span>');
                }

                if (d.customer && !d.customer.error) {
                    html += _tr(i18n.customer || 'Customer', '<code>' + d.customer.id + '</code> — ' + (d.customer.email || '') + (d.customer.name ? ' (' + d.customer.name + ')' : ''));
                }

                if (d.sources) {
                    var sourceKeys = Object.keys(d.sources);
                    if (sourceKeys.length > 0) {
                        html += _tr(i18n.orderMeta || 'Order Meta', sourceKeys.map(function(k) {
                            return '<small>' + k + ': ' + d.sources[k] + '</small>';
                        }).join('<br>'));
                    }
                }

                html += '</table>';

                if (d.recommendations && d.recommendations.length > 0) {
                    html += '<div style="margin-top:12px;padding:10px 14px;background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;font-size:12px;line-height:1.6;color:#92400e;">';
                    html += '<strong>💡 ' + (i18n.recommendations || 'Recommendations:') + '</strong>';
                    html += '<ul style="margin:6px 0 0 16px;padding:0;">';
                    d.recommendations.forEach(function(r) {
                        html += '<li>' + r + '</li>';
                    });
                    html += '</ul></div>';
                }

                $result.html(html).show();
            },
            error: function() {
                $btn.prop('disabled', false);
                $spinner.hide();
                $result.html(
                    '<div style="padding:12px;background:#fee2e2;border-left:4px solid #ef4444;border-radius:6px;color:#991b1b;font-size:13px;">'
                    + (i18n.networkError || 'Network error. Please try again.')
                    + '</div>'
                ).show();
            }
        });
    });

    // ── Enter key on order ID input ──────────────────────────────
    $('#securehold-diag-order-id').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#securehold-diag-run').trigger('click');
        }
    });

})(jQuery);
