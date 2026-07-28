<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-plugin-installer.php';

$plugins_status = Securehold_Plugin_Installer::check_all_requirements();
$all_met = Securehold_Plugin_Installer::all_requirements_met();
?>

<div class="wizard-step-content">
    <h2><?php esc_html_e('Install Required Plugins', 'securehold-security-deposit-holds'); ?></h2>
    
    <p><?php esc_html_e('SecureHold WP needs WooCommerce and the Stripe Payment Gateway to function. Let\'s check if they\'re installed and install them automatically if needed.', 'securehold-security-deposit-holds'); ?></p>
    
    <?php if ($all_met) : ?>
        <div class="wizard-success-box">
            <span class="dashicons dashicons-yes-alt"></span>
            <h3><?php esc_html_e('All requirements are met! ✅', 'securehold-security-deposit-holds'); ?></h3>
            <p><?php esc_html_e('WooCommerce and Stripe Payment Gateway are installed and active.', 'securehold-security-deposit-holds'); ?></p>
        </div>
    <?php else : ?>
        <div class="securehold-plugins-check">
            <?php foreach ($plugins_status as $slug => $status) : ?>
                <div class="plugin-check-item" style="background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;margin:15px 0;">
                    <div style="display:flex;align-items:center;gap:15px;">
                        <?php if ($status['active']) : ?>
                            <span class="dashicons dashicons-yes-alt" style="font-size:32px;width:32px;height:32px;color:#4CAF50;"></span>
                        <?php elseif ($status['installed']) : ?>
                            <span class="dashicons dashicons-warning" style="font-size:32px;width:32px;height:32px;color:#ff9800;"></span>
                        <?php else : ?>
                            <span class="dashicons dashicons-dismiss" style="font-size:32px;width:32px;height:32px;color:#f44336;"></span>
                        <?php endif; ?>
                        
                        <div style="flex:1;">
                            <h3 style="margin:0;font-size:16px;"><?php echo esc_html($status['name']); ?></h3>
                            <p style="margin:5px 0 0 0;color:#666;">
                                <?php if ($status['active']) : ?>
                                    <strong style="color:#4CAF50;"><?php esc_html_e('Installed and Active', 'securehold-security-deposit-holds'); ?></strong>
                                <?php elseif ($status['installed']) : ?>
                                    <strong style="color:#ff9800;"><?php esc_html_e('Installed but not active', 'securehold-security-deposit-holds'); ?></strong>
                                <?php else : ?>
                                    <strong style="color:#f44336;"><?php esc_html_e('Not installed', 'securehold-security-deposit-holds'); ?></strong>
                                <?php endif; ?>
                            </p>
                        </div>
                        
                        <div>
                            <?php if (!$status['active']) : ?>
                                <form method="post" action="" style="display:inline;">
                                    <?php wp_nonce_field('securehold_wizard_nonce'); ?>
                                    <input type="hidden" name="securehold_wizard_action" value="install_plugin">
                                    <input type="hidden" name="plugin_slug" value="<?php echo esc_attr($slug); ?>">
                                    
                                    <?php if (!$status['installed']) : ?>
                                        <button type="submit" class="button button-primary">
                                            <span class="dashicons dashicons-download" style="margin-top:4px;"></span>
                                            <?php esc_html_e('Install & Activate', 'securehold-security-deposit-holds'); ?>
                                        </button>
                                    <?php else : ?>
                                        <button type="submit" class="button button-primary">
                                            <span class="dashicons dashicons-yes" style="margin-top:4px;"></span>
                                            <?php esc_html_e('Activate', 'securehold-security-deposit-holds'); ?>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="wizard-help-box">
            <h4><?php esc_html_e('Why are these plugins required?', 'securehold-security-deposit-holds'); ?></h4>
            <ul style="font-size: 13px; line-height: 1.8;">
                <li><strong>WooCommerce:</strong> <?php esc_html_e('SecureHold WP integrates with WooCommerce orders to create security deposits for your bookings.', 'securehold-security-deposit-holds'); ?></li>
                <li><strong>Stripe Payment Gateway:</strong> <?php esc_html_e('Required to process pre-authorization holds on customer credit cards via Stripe.', 'securehold-security-deposit-holds'); ?></li>
            </ul>
        </div>
        
        <div class="wizard-help-box">
            <h4><?php esc_html_e('What happens when I click "Install & Activate"?', 'securehold-security-deposit-holds'); ?></h4>
            <ol style="font-size: 13px; line-height: 1.8;">
                <li><?php esc_html_e('SecureHold WP downloads the plugin from WordPress.org', 'securehold-security-deposit-holds'); ?></li>
                <li><?php esc_html_e('Automatically installs it on your site', 'securehold-security-deposit-holds'); ?></li>
                <li><?php esc_html_e('Activates the plugin', 'securehold-security-deposit-holds'); ?></li>
                <li><?php esc_html_e('You continue with the wizard - done in seconds!', 'securehold-security-deposit-holds'); ?></li>
            </ol>
            <p style="font-size:13px;color:#666;">
                <?php esc_html_e('This is completely safe and is the same as installing plugins manually from the WordPress admin.', 'securehold-security-deposit-holds'); ?>
            </p>
        </div>
    <?php endif; ?>
    
    <div class="wizard-actions">
        <a href="<?php echo esc_url( admin_url('admin.php?page=securehold-setup-wizard&step=1') ); ?>" class="sh-btn sh-btn-secondary">
            ← <?php esc_html_e('Back', 'securehold-security-deposit-holds'); ?>
        </a>
        
        <?php if ($all_met) : ?>
            <a href="<?php echo esc_url( admin_url('admin.php?page=securehold-setup-wizard&step=3') ); ?>" class="sh-btn sh-btn-primary">
                <?php esc_html_e('Continue', 'securehold-security-deposit-holds'); ?> →
            </a>
        <?php else : ?>
            <button type="button" class="sh-btn sh-btn-secondary" disabled style="opacity:0.6;cursor:not-allowed;">
                <?php esc_html_e('Install all plugins first', 'securehold-security-deposit-holds'); ?>
            </button>
        <?php endif; ?>
    </div>
</div>
