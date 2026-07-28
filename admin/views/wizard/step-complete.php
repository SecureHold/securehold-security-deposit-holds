<?php
/**
 * Wizard Step 7: Complete
 */
if (!defined('ABSPATH')) exit;

// Define destination URLs for the "What's Next?" cards
$settings_url   = admin_url('admin.php?page=securehold-settings');
$test_order_url = admin_url('post-new.php?post_type=shop_order');
$health_url     = admin_url('admin.php?page=securehold-health');
$docs_url       = SECUREHOLD_WP_URL_DOCS;
?>

<div class="wizard-step-content">
    <div class="wizard-success-box">
        <span class="dashicons dashicons-yes-alt"></span>
        <h2><?php esc_html_e('Setup Complete! 🎉', 'securehold-security-deposit-holds'); ?></h2>
        <p style="font-size: 16px;"><?php esc_html_e('SecureHold WP is now ready to protect your rentals with security deposits.', 'securehold-security-deposit-holds'); ?></p>
    </div>

    <h3><?php esc_html_e('What\'s Next?', 'securehold-security-deposit-holds'); ?></h3>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:30px 0;">
        <div style="border:1px solid #ddd;padding:20px;border-radius:8px;">
            <h4><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Configure Settings', 'securehold-security-deposit-holds'); ?></h4>
            <p><?php esc_html_e('Set your default security deposit amount and other preferences.', 'securehold-security-deposit-holds'); ?></p>
            <a href="<?php echo esc_url($settings_url); ?>" class="sh-btn sh-btn-secondary">
                <?php esc_html_e('Go to Settings', 'securehold-security-deposit-holds'); ?>
            </a>
        </div>

        <div style="border:1px solid #ddd;padding:20px;border-radius:8px;">
            <h4><span class="dashicons dashicons-cart"></span> <?php esc_html_e('Test with an Order', 'securehold-security-deposit-holds'); ?></h4>
            <p><?php esc_html_e('Create a test order to see how security deposits work.', 'securehold-security-deposit-holds'); ?></p>
            <a href="<?php echo esc_url($test_order_url); ?>" class="sh-btn sh-btn-secondary">
                <?php esc_html_e('Create Test Order', 'securehold-security-deposit-holds'); ?>
            </a>
        </div>

        <div style="border:1px solid #ddd;padding:20px;border-radius:8px;">
            <h4><span class="dashicons dashicons-heart"></span> <?php esc_html_e('View Health Check', 'securehold-security-deposit-holds'); ?></h4>
            <p><?php esc_html_e('Verify everything is configured correctly.', 'securehold-security-deposit-holds'); ?></p>
            <a href="<?php echo esc_url($health_url); ?>" class="sh-btn sh-btn-secondary">
                <?php esc_html_e('View Health Status', 'securehold-security-deposit-holds'); ?>
            </a>
        </div>

        <div style="border:1px solid #ddd;padding:20px;border-radius:8px;">
            <h4><span class="dashicons dashicons-book"></span> <?php esc_html_e('Read Documentation', 'securehold-security-deposit-holds'); ?></h4>
            <p><?php esc_html_e('Learn how to customize SecureHold WP for your business.', 'securehold-security-deposit-holds'); ?></p>
            <a href="<?php echo esc_url($docs_url); ?>" class="sh-btn sh-btn-secondary" target="_blank" rel="noopener">
                <?php esc_html_e('View Docs', 'securehold-security-deposit-holds'); ?>
            </a>
        </div>
    </div>

    <div class="wizard-help-box securehold-test-section" style="background: linear-gradient(135deg, var(--sh-primary, #2563eb) 0%, #1e40af 100%); color: white; position: relative; overflow: hidden; border: none; padding: 30px;">

        <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; z-index: 1; pointer-events: none;"></div>
        <div style="position: absolute; bottom: -30px; left: -30px; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%; z-index: 1; pointer-events: none;"></div>

        <div style="position: relative; z-index: 2;">
            <h4 style="color: white; margin-top: 0; font-size: 1.3em;">
                <span class="dashicons dashicons-admin-tools" style="background: rgba(255,255,255,0.2); padding: 8px; border-radius: 50%; margin-right: 10px; vertical-align: middle;"></span>
                <?php esc_html_e('Test Your Configuration', 'securehold-security-deposit-holds'); ?>
            </h4>

            <p style="color: rgba(255,255,255,0.9); font-size: 1.1em;">
                <?php esc_html_e('Run a complete end-to-end test to verify everything works correctly. This test will:', 'securehold-security-deposit-holds'); ?>
            </p>

            <ul style="color: rgba(255,255,255,0.9);">
                <li><span class="dashicons dashicons-yes" style="color: #4ade80;"></span> <?php esc_html_e('Create a test Stripe customer and payment method', 'securehold-security-deposit-holds'); ?></li>
                <li><span class="dashicons dashicons-yes" style="color: #4ade80;"></span> <?php esc_html_e('Process a test WooCommerce order with payment', 'securehold-security-deposit-holds'); ?></li>
                <li><span class="dashicons dashicons-yes" style="color: #4ade80;"></span> <?php esc_html_e('Verify all Stripe metadata is correctly saved', 'securehold-security-deposit-holds'); ?></li>
                <li><span class="dashicons dashicons-yes" style="color: #4ade80;"></span> <?php esc_html_e('Check webhook configuration', 'securehold-security-deposit-holds'); ?></li>
                <li><span class="dashicons dashicons-yes" style="color: #4ade80;"></span> <?php esc_html_e('Clean up automatically (no charges made)', 'securehold-security-deposit-holds'); ?></li>
            </ul>

            <br>

            <button type="button" id="securehold-test-config" class="sh-btn sh-btn-secondary">
                <?php esc_html_e('Run Configuration Test', 'securehold-security-deposit-holds'); ?>
            </button>

            <div id="securehold-test-results" style="display: none; margin-top: 20px; background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; backdrop-filter: blur(5px);">
                <div id="securehold-test-progress">
                    <div class="securehold-test-loading" style="color: white;">
                        <div class="securehold-spinner" style="border-left-color: white;"></div>
                        <strong><?php esc_html_e('Testing configuration...', 'securehold-security-deposit-holds'); ?></strong>
                    </div>
                    <div id="securehold-test-steps" style="color: white;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="wizard-help-box">
        <h4><?php esc_html_e('Quick Tips:', 'securehold-security-deposit-holds'); ?></h4>
        <ul style="font-size: 14px; line-height: 1.8;">
            <li><?php esc_html_e('Start in Test mode and thoroughly test before switching to Live mode', 'securehold-security-deposit-holds'); ?></li>
            <li><?php esc_html_e('Security deposits are created automatically for orders with a checkout date', 'securehold-security-deposit-holds'); ?></li>
            <li><?php esc_html_e('You can also create holds manually from the order edit page', 'securehold-security-deposit-holds'); ?></li>
            <li><?php esc_html_e('Holds expire after 30 days and are automatically released', 'securehold-security-deposit-holds'); ?></li>
            <li><?php esc_html_e('You can capture full amount, partial amount, or release the hold anytime', 'securehold-security-deposit-holds'); ?></li>
        </ul>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field('securehold_wizard_nonce'); ?>
        <input type="hidden" name="securehold_wizard_action" value="complete_setup">

        <div class="wizard-actions">
            <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-setup-wizard&step=6')); ?>" class="sh-btn sh-btn-secondary">
                ← <?php esc_html_e('Back', 'securehold-security-deposit-holds'); ?>
            </a>

            <!-- Make this secondary too, as requested -->
            <button type="submit" class="sh-btn sh-btn-secondary">
                <?php esc_html_e('Complete Setup & Start Using SecureHold WP', 'securehold-security-deposit-holds'); ?>
            </button>
        </div>
    </form>
</div>
