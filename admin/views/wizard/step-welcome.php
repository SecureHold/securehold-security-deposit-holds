<?php if ( ! defined( 'ABSPATH' ) ) { exit; } ?>
<div class="wizard-step-content">
    <h2><?php esc_html_e('Welcome to SecureHold WP!', 'securehold-security-deposit-holds'); ?></h2>

    <p style="font-size: 16px; line-height: 1.6;">
        <?php esc_html_e('This setup wizard will help you configure SecureHold WP in just a few minutes.', 'securehold-security-deposit-holds'); ?>
    </p>

    <div class="wizard-help-box">
        <h3><?php esc_html_e('What is SecureHold WP?', 'securehold-security-deposit-holds'); ?></h3>
        <p><?php esc_html_e('SecureHold WP automatically creates Stripe pre-authorizations (security deposits) for your WooCommerce orders without charging your customers. Perfect for vacation rentals, equipment rentals, and service bookings.', 'securehold-security-deposit-holds'); ?></p>
    </div>
    
    <h3><?php esc_html_e('What we\'ll do together:', 'securehold-security-deposit-holds'); ?></h3>
    <ul style="font-size: 15px; line-height: 2;">
        <li>✅ Install Stripe SDK automatically</li>
        <li>✅ Connect your Stripe account</li>
        <li>✅ Configure webhooks automatically</li>
        <li>✅ Test everything works</li>
    </ul>
    
    <p style="font-size: 14px; color: #666;">
        <?php esc_html_e('Estimated time: 3-5 minutes', 'securehold-security-deposit-holds'); ?>
    </p>
    
    <div class="wizard-actions">
        <div></div>
        <a href="<?php echo esc_url( admin_url('admin.php?page=securehold-setup-wizard&step=2') ); ?>" class="sh-btn sh-btn-primary">
            <?php esc_html_e('Let\'s Get Started', 'securehold-security-deposit-holds'); ?> →
        </a>

    </div>
</div>
