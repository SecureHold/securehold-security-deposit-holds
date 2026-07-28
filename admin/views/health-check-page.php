<?php
/**
 * Health Check Page
 * Version: 3.1.2 - Premium Header Style
 */
if (!defined('ABSPATH')) exit;

require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-health-check.php';

$checks = Securehold_Health_Check::run_all_checks();
$overall_status = Securehold_Health_Check::get_overall_status($checks);

$status_icons = array(
    'success' => 'dashicons-yes-alt',
    'warning' => 'dashicons-warning',
    'error'   => 'dashicons-dismiss'
);

$status_colors = array(
    'success' => '#4CAF50',
    'warning' => '#ff9800',
    'error'   => '#f44336'
);

// Notices (tests results)
$webhook_test_notice = get_transient('securehold_webhook_test_notice_' . get_current_user_id());
if (is_array($webhook_test_notice)) {
    delete_transient('securehold_webhook_test_notice_' . get_current_user_id());
}

$api_keys_test_notice = get_transient('securehold_api_keys_test_notice_' . get_current_user_id());
if (is_array($api_keys_test_notice)) {
    delete_transient('securehold_api_keys_test_notice_' . get_current_user_id());
}

$stripe_sdk_test_notice = get_transient('securehold_stripe_sdk_test_notice_' . get_current_user_id());
if (is_array($stripe_sdk_test_notice)) {
    delete_transient('securehold_stripe_sdk_test_notice_' . get_current_user_id());
}

$woocommerce_test_notice = get_transient('securehold_woocommerce_test_notice_' . get_current_user_id());
if (is_array($woocommerce_test_notice)) {
    delete_transient('securehold_woocommerce_test_notice_' . get_current_user_id());
}

$stripe_gateway_test_notice = get_transient('securehold_stripe_gateway_test_notice_' . get_current_user_id());
if (is_array($stripe_gateway_test_notice)) {
    delete_transient('securehold_stripe_gateway_test_notice_' . get_current_user_id());
}
$cron_test_notice = get_transient('securehold_cron_test_notice_' . get_current_user_id());
if (is_array($cron_test_notice)) {
    delete_transient('securehold_cron_test_notice_' . get_current_user_id());
}

?>

<div class="wrap securehold-wrapper" style="position: relative;">
    <?php
    // Subtitle based on overall status
    $subtitle = '';
    if ($overall_status === 'success') {
        $subtitle = __('All systems operational. Your secure deposits are running smoothly.', 'securehold-security-deposit-holds');
    } elseif ($overall_status === 'warning') {
        $subtitle = __('System operational, but some optional configurations need attention.', 'securehold-security-deposit-holds');
    } else {
        $subtitle = __('Attention required: Critical issues might affect deposit capture.', 'securehold-security-deposit-holds');
    }

    // Header action (Refresh + date)
    ob_start();
    ?>
    <div style="text-align: right;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-health')); ?>"
           class="sh-btn"
           style="background: rgba(255,255,255,0.15); color: white; border: 1px solid rgba(255,255,255,0.3); font-weight: 500; font-size: 0.85rem; display: inline-flex; align-items: center; text-decoration: none;">
            <span class="dashicons dashicons-update" style="font-size: 16px; width: 16px; height: 16px; margin-right: 6px;"></span>
            <?php esc_html_e('Refresh Status', 'securehold-security-deposit-holds'); ?>
        </a>
        <div style="margin-top: 0.5rem; font-size: 0.8rem; opacity: 0.7; color: white;">
            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'))); ?>
        </div>
    </div>
    <?php
    $header_action = ob_get_clean();

    Securehold_Admin::render_page_header(
        __('System Health', 'securehold-security-deposit-holds'),
        $subtitle,
        'dashicons-heart',
        $header_action
    );
    ?>

    <div class="securehold-health-checks">
        <?php foreach ($checks as $check_name => $check) : ?>
            <div class="health-check-item" style="background: white; padding: 1.5rem; border: 1px solid #e5e7eb; border-radius: 0.5rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,0.05); transition: transform 0.2s ease;">
                <div style="display: flex; align-items: flex-start; gap: 1rem;">

                    <div style="flex-shrink: 0; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: <?php echo esc_attr($check['status'] === 'success' ? '#dcfce7' : ($check['status'] === 'warning' ? '#fef9c3' : '#fee2e2')); ?>;">
                        <span class="dashicons <?php echo esc_attr($status_icons[$check['status']]); ?>"
                              style="font-size: 20px; width: 20px; height: 20px; color: <?php echo esc_attr($status_colors[$check['status']]); ?>;"></span>
                    </div>

                    <div style="flex: 1;">
                        <h3 style="margin: 0 0 0.25rem 0; font-size: 1rem; font-weight: 600; color: #1f2937;">
                            <?php
                            $titles = array(
                                'stripe_sdk'          => __('Stripe SDK', 'securehold-security-deposit-holds'),
                                'stripe_keys'         => __('Stripe API Keys', 'securehold-security-deposit-holds'),
                                'webhook'             => __('Webhook Configuration', 'securehold-security-deposit-holds'),
                                'woocommerce'         => __('WooCommerce', 'securehold-security-deposit-holds'),
                                'stripe_gateway'      => __('Stripe Payment Gateway', 'securehold-security-deposit-holds'),
                                'database'            => __('Database Tables', 'securehold-security-deposit-holds'),
                                'cron'                => __('Scheduled Tasks', 'securehold-security-deposit-holds'),
                                'permissions'         => __('File Permissions', 'securehold-security-deposit-holds'),
                                'transparency_notice' => __('Deposit Transparency Notice', 'securehold-security-deposit-holds'),
                            );
                            echo esc_html(isset($titles[$check_name]) ? $titles[$check_name] : ucfirst($check_name));
                            ?>
                        </h3>

                        <p style="margin: 0; color: #6b7280; font-size: 0.875rem; line-height: 1.5;">
                            <?php echo esc_html($check['message']); ?>
                        </p>

                        <?php if ($check_name === 'stripe_keys' && is_array($api_keys_test_notice)) : ?>
                            <div style="margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid <?php echo esc_attr($api_keys_test_notice['success'] ? '#22c55e' : '#ef4444'); ?>; color: #111827;">
                                <?php echo esc_html($api_keys_test_notice['message']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($check_name === 'webhook' && is_array($webhook_test_notice)) : ?>
                            <div style="margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid <?php echo esc_attr($webhook_test_notice['success'] ? '#22c55e' : '#ef4444'); ?>; color: #111827;">
                                <?php echo esc_html($webhook_test_notice['message']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($check_name === 'stripe_sdk' && is_array($stripe_sdk_test_notice)) : ?>
                            <div style="margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid <?php echo esc_attr($stripe_sdk_test_notice['success'] ? '#22c55e' : '#ef4444'); ?>; color: #111827;">
                                <?php echo esc_html($stripe_sdk_test_notice['message']); ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($check_name === 'woocommerce' && is_array($woocommerce_test_notice)) : ?>
                            <div style="margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid <?php echo esc_attr($woocommerce_test_notice['success'] ? '#22c55e' : '#ef4444'); ?>; color: #111827;">
                                <?php echo esc_html($woocommerce_test_notice['message']); ?>
                            </div>

                            <?php if (!empty($woocommerce_test_notice['details']) && is_array($woocommerce_test_notice['details'])) : ?>
                                <div style="margin-top: 12px;">
                                    <ul style="margin: 0; padding-left: 18px;">
                                        <?php foreach ($woocommerce_test_notice['details'] as $row) : ?>
                                            <li style="margin: 6px 0; display: flex; gap: 8px; align-items: flex-start;">
                                                <span class="dashicons <?php echo esc_attr(!empty($row['success']) ? 'dashicons-yes-alt' : 'dashicons-dismiss'); ?>"
                                                      style="color: <?php echo esc_attr(!empty($row['success']) ? '#22c55e' : '#ef4444'); ?>; margin-top: 2px;"></span>
                                                <div>
                                                    <div style="font-weight: 600; color: #111827;">
                                                        <?php echo esc_html($row['label']); ?>
                                                    </div>
                                                    <div style="color: #4b5563;">
                                                        <?php echo esc_html($row['message']); ?>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($check_name === 'stripe_gateway' && is_array($stripe_gateway_test_notice)) : ?>
                            <div style="margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid <?php echo esc_attr($stripe_gateway_test_notice['success'] ? '#22c55e' : '#ef4444'); ?>; color: #111827;">
                                <?php echo esc_html($stripe_gateway_test_notice['message']); ?>
                            </div>

                            <?php if (!empty($stripe_gateway_test_notice['details']) && is_array($stripe_gateway_test_notice['details'])) : ?>
                                <div style="margin-top: 12px;">
                                    <ul style="margin: 0; padding-left: 18px;">
                                        <?php foreach ($stripe_gateway_test_notice['details'] as $row) : ?>
                                            <li style="margin: 6px 0; display: flex; gap: 8px; align-items: flex-start;">
                                                <span class="dashicons <?php echo esc_attr(!empty($row['success']) ? 'dashicons-yes-alt' : 'dashicons-dismiss'); ?>"
                                                      style="color: <?php echo esc_attr(!empty($row['success']) ? '#22c55e' : '#ef4444'); ?>; margin-top: 2px;"></span>
                                                <div>
                                                    <div style="font-weight: 600; color: #111827;">
                                                        <?php echo esc_html($row['label']); ?>
                                                    </div>
                                                    <div style="color: #4b5563;">
                                                        <?php echo esc_html($row['message']); ?>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($check_name === 'cron' && is_array($cron_test_notice)) : ?>
                        <div style="margin-top: 10px; padding: 10px 12px; border-radius: 8px; font-size: 13px; line-height: 1.5; background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid <?php echo esc_attr($cron_test_notice['success'] ? '#22c55e' : '#ef4444'); ?>; color: #111827;">
                            <?php echo esc_html($cron_test_notice['message']); ?>
                        </div>
                    
                        <?php if (!empty($cron_test_notice['details']) && is_array($cron_test_notice['details'])) : ?>
                            <div style="margin-top: 12px;">
                                <ul style="margin: 0; padding-left: 18px;">
                                    <?php foreach ($cron_test_notice['details'] as $row) : ?>
                                        <li style="margin: 6px 0; display: flex; gap: 8px; align-items: flex-start;">
                                            <span class="dashicons <?php echo esc_attr(!empty($row['success']) ? 'dashicons-yes-alt' : 'dashicons-dismiss'); ?>"
                                                  style="color: <?php echo esc_attr(!empty($row['success']) ? '#22c55e' : '#ef4444'); ?>; margin-top: 2px;"></span>
                                            <div>
                                                <div style="font-weight: 600; color: #111827;">
                                                    <?php echo esc_html($row['label']); ?>
                                                </div>
                                                <div style="color: #4b5563;">
                                                    <?php echo esc_html($row['message']); ?>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    </div>

                    <?php if (isset($check['action']) && $check['action']) : ?>
                        <div>
                            <a href="<?php echo esc_url($check['action']['url']); ?>"
                               class="sh-btn sh-btn-primary"
                               style="padding: 0.5rem 1rem; font-size: 0.75rem;">
                                <?php echo esc_html($check['action']['label']); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Order Stripe Diagnostic Tool ── -->
    <div class="sh-card" style="margin-top: 2rem; padding: 1.5rem;">
        <h3 style="margin: 0 0 0.5rem 0; font-size: 1.1rem; font-weight: 600; color: #1f2937;">
            <span class="dashicons dashicons-search" style="font-size: 20px; width: 20px; height: 20px; vertical-align: text-bottom; color: #6366f1;"></span>
            <?php esc_html_e('Order Stripe Diagnostic', 'securehold-security-deposit-holds'); ?>
        </h3>
        <p style="color: #6b7280; font-size: 0.875rem; margin: 0 0 1rem 0;">
            <?php esc_html_e('Enter an order ID to diagnose its Stripe payment state: setup_future_usage, payment method reusability, customer attachment, and hold compatibility.', 'securehold-security-deposit-holds'); ?>
        </p>

        <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
            <input type="number" id="securehold-diag-order-id" placeholder="<?php esc_attr_e('Order ID (e.g. 1234)', 'securehold-security-deposit-holds'); ?>"
                   style="padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px; font-size: 0.875rem; width: 200px;">
            <button type="button" id="securehold-diag-run" class="sh-btn sh-btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.875rem;">
                <span class="dashicons dashicons-search" style="font-size: 16px; width: 16px; height: 16px; margin-right: 4px;"></span>
                <?php esc_html_e('Diagnose', 'securehold-security-deposit-holds'); ?>
            </button>
            <span id="securehold-diag-spinner" style="display:none;" class="spinner is-active" style="float:none;"></span>
        </div>

        <div id="securehold-diag-result" style="display:none; margin-top: 1.25rem;"></div>
    </div>


    <div class="sh-card sh-text-center" style="margin-top: 2rem;">

        <h3 style="margin-top: 0; color: var(--sh-gray-900); font-size: 1.25rem; font-weight: 600;">
            <?php esc_html_e('Need Help?', 'securehold-security-deposit-holds'); ?>
        </h3>

        <p style="color: var(--sh-gray-600); margin-bottom: 1.5rem; font-size: 1rem;">
            <?php esc_html_e('If you\'re experiencing issues, here are some resources:', 'securehold-security-deposit-holds'); ?>
        </p>

        <div style="display: flex; gap: 1rem; justify-content: center; flex-wrap: wrap;">

            <a href="<?php echo esc_url( SECUREHOLD_WP_URL_DOCS ); ?>" target="_blank" class="sh-btn sh-btn-secondary" rel="noopener">
                <span class="dashicons dashicons-book"></span>
                <?php esc_html_e('Documentation', 'securehold-security-deposit-holds'); ?>
            </a>

            <a href="<?php echo esc_url( SECUREHOLD_WP_URL_DOCS_TROUBLESHOOTING ); ?>" target="_blank" class="sh-btn sh-btn-secondary" rel="noopener">
                <span class="dashicons dashicons-sos"></span>
                <?php esc_html_e('Troubleshooting Guide', 'securehold-security-deposit-holds'); ?>
            </a>

            <a href="<?php echo esc_url( SECUREHOLD_WP_URL_SUPPORT ); ?>" target="_blank" class="sh-btn sh-btn-secondary" rel="noopener">
                <span class="dashicons dashicons-email"></span>
                <?php esc_html_e('Contact Support', 'securehold-security-deposit-holds'); ?>
            </a>

            <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-setup-wizard')); ?>" class="sh-btn sh-btn-primary">
                <span class="dashicons dashicons-controls-repeat"></span>
                <?php esc_html_e('Re-run Setup Wizard', 'securehold-security-deposit-holds'); ?>
            </a>

        </div>
    </div>
</div>
