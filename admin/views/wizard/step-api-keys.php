<?php
/**
 * Wizard Step 5: API Keys
 * Version: 3.2.1 - Original Style with Fix
 */
if (!defined('ABSPATH')) exit;

$current_mode = get_option('securehold_stripe_mode', 'test');
$test_pub = get_option('securehold_stripe_test_publishable_key', '');
$test_secret = get_option('securehold_stripe_test_secret_key', '');
$live_pub = get_option('securehold_stripe_live_publishable_key', '');
$live_secret = get_option('securehold_stripe_live_secret_key', '');
?>

<div class="wizard-step-content">
    <h2><?php esc_html_e('Connect Your Stripe Account', 'securehold-security-deposit-holds'); ?></h2>
    
    <p><?php esc_html_e('Enter your Stripe API keys. You can find these in your Stripe Dashboard.', 'securehold-security-deposit-holds'); ?></p>
    
    <div class="wizard-help-box">
        <h4><?php esc_html_e('Where to find your API keys:', 'securehold-security-deposit-holds'); ?></h4>
        <ol style="font-size: 13px;">
            <li><?php esc_html_e('Go to', 'securehold-security-deposit-holds'); ?> <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard → Developers → API Keys</a></li>
            <li><?php esc_html_e('Copy your Publishable key (starts with pk_)', 'securehold-security-deposit-holds'); ?></li>
            <li><?php esc_html_e('Copy your Secret key (starts with sk_)', 'securehold-security-deposit-holds'); ?></li>
        </ol>
    </div>
    
    <form method="post" action="">
        <?php wp_nonce_field('securehold_wizard_nonce'); ?>
        <input type="hidden" name="securehold_wizard_action" value="save_stripe_keys">
        
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label><?php esc_html_e('Mode', 'securehold-security-deposit-holds'); ?></label>
                </th>
                <td>
                    <select name="stripe_mode" class="regular-text" id="stripe-mode-select">
                        <option value="test" <?php selected($current_mode, 'test'); ?>><?php esc_html_e('Test Mode (Recommended to start)', 'securehold-security-deposit-holds'); ?></option>
                        <option value="live" <?php selected($current_mode, 'live'); ?>><?php esc_html_e('Live Mode', 'securehold-security-deposit-holds'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Start with Test mode to verify everything works before going Live.', 'securehold-security-deposit-holds'); ?></p>
                </td>
            </tr>
        </table>
        
        <div id="test-keys" style="<?php echo $current_mode === 'live' ? 'display:none;' : ''; ?>">
            <h3><?php esc_html_e('Test API Keys', 'securehold-security-deposit-holds'); ?></h3>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Test Publishable Key', 'securehold-security-deposit-holds'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="test_publishable_key" value="<?php echo esc_attr($test_pub); ?>" class="regular-text code" placeholder="pk_test_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Test Secret Key', 'securehold-security-deposit-holds'); ?></label>
                    </th>
                    <td>
                        <div class="sh-input-wrapper" style="position: relative; display: inline-block; width: 100%; max-width: 25em;">
                            <input type="password" name="test_secret_key" value="<?php echo esc_attr($test_secret); ?>" class="regular-text code" placeholder="sk_test_..." style="width: 100%; padding-right: 40px;">
                            
                            <button type="button" class="sh-toggle-password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #666;">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <div id="live-keys" style="<?php echo $current_mode === 'test' ? 'display:none;' : ''; ?>">
            <h3><?php esc_html_e('Live API Keys', 'securehold-security-deposit-holds'); ?></h3>
            <div class="wizard-warning-box">
                <p><strong><?php esc_html_e('Warning:', 'securehold-security-deposit-holds'); ?></strong> <?php esc_html_e('Live mode will process real payments. Make sure to test thoroughly in Test mode first.', 'securehold-security-deposit-holds'); ?></p>
            </div>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Live Publishable Key', 'securehold-security-deposit-holds'); ?></label>
                    </th>
                    <td>
                        <input type="text" name="live_publishable_key" value="<?php echo esc_attr($live_pub); ?>" class="regular-text code" placeholder="pk_live_...">
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label><?php esc_html_e('Live Secret Key', 'securehold-security-deposit-holds'); ?></label>
                    </th>
                    <td>
                        <div class="sh-input-wrapper" style="position: relative; display: inline-block; width: 100%; max-width: 25em;">
                            <input type="password" name="live_secret_key" value="<?php echo esc_attr($live_secret); ?>" class="regular-text code" placeholder="sk_live_..." style="width: 100%; padding-right: 40px;">
                            
                            <button type="button" class="sh-toggle-password" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #666;">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                        </div>
                    </td>                
                </tr>
            </table>
        </div>
        
        <div class="wizard-help-box" style="background: #EFF6FF; border-left: 4px solid #3B82F6; padding: 1.5rem; border-radius: 8px; margin: 2rem 0;">
            <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem; color: #1E40AF;">
                <span class="dashicons dashicons-info" style="font-size: 20px;"></span>
                <?php esc_html_e('What happens when you save:', 'securehold-security-deposit-holds'); ?>
            </h4>
            
            <ul style="margin: 0; padding-left: 1.5rem; line-height: 1.8; font-size: 14px; color: #1E3A8A;">
                <li>✅ <?php esc_html_e('API keys will be saved securely for SecureHold WP', 'securehold-security-deposit-holds'); ?></li>
                <li>✅ <?php esc_html_e('WooCommerce Stripe Gateway will be configured automatically', 'securehold-security-deposit-holds'); ?></li>
                <li>✅ <?php esc_html_e('Stripe payment method will be enabled in WooCommerce', 'securehold-security-deposit-holds'); ?></li>
                <li>✅ <?php esc_html_e('Card saving will be enabled (required for security deposits)', 'securehold-security-deposit-holds'); ?></li>
                <li>❌ <?php esc_html_e('Apple Pay / Google Pay will be disabled (not compatible with deposits)', 'securehold-security-deposit-holds'); ?></li>
            </ul>
            
            <p style="margin: 1rem 0 0 0; font-size: 13px; color: #64748B;">
                <strong><?php esc_html_e('Note:', 'securehold-security-deposit-holds'); ?></strong>
                <?php esc_html_e('You won\'t need to configure Stripe manually in WooCommerce - everything is done automatically!', 'securehold-security-deposit-holds'); ?>
            </p>
         </div>

        
        <div class="wizard-actions">
            <a href="<?php echo esc_url( admin_url('admin.php?page=securehold-setup-wizard&step=4') ); ?>" class="sh-btn sh-btn-secondary">
                ← <?php esc_html_e('Back', 'securehold-security-deposit-holds'); ?>
            </a>
            
            <button type="submit" class="sh-btn sh-btn-primary">
                <?php esc_html_e('Save & Continue', 'securehold-security-deposit-holds'); ?> →
            </button>

        </div>
    </form>
</div>