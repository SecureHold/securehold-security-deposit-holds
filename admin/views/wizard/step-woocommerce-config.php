<?php
/**
 * Step: WooCommerce Configuration
 * Confirms SecureHold WP compatibility with the current WooCommerce setup.
 * Guest checkout is fully supported — no account creation is required or enforced.
 *
 * @since 4.0.0 - Guest-first approach
 * @since 4.1.0 - Removed account registration recommendations (not SecureHold's scope)
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="wizard-step-content">
    <h2><?php esc_html_e( 'WooCommerce Configuration', 'securehold-security-deposit-holds' ); ?></h2>

    <p style="font-size: 15px; line-height: 1.6;">
        <?php esc_html_e( 'SecureHold WP works with both guest checkout and registered customers. No specific WooCommerce account settings are required.', 'securehold-security-deposit-holds' ); ?>
    </p>

    <!-- Guest checkout support callout -->
    <div style="background: #ECFDF5; border-left: 4px solid #10B981; padding: 1rem 1.25rem; margin: 1.5rem 0; border-radius: 4px;">
        <h4 style="margin: 0 0 0.5rem 0; color: #065F46; display: flex; align-items: center; gap: 0.5rem;">
            <span class="dashicons dashicons-yes-alt" style="color: #10B981;"></span>
            <?php esc_html_e( 'Guest Checkout Supported', 'securehold-security-deposit-holds' ); ?>
        </h4>
        <p style="margin: 0; color: #047857; font-size: 14px; line-height: 1.6;">
            <?php esc_html_e( 'SecureHold WP automatically creates a Stripe customer from billing details when a guest checks out. No account creation is required for security deposits to work.', 'securehold-security-deposit-holds' ); ?>
        </p>
    </div>

    <!-- How reusability works -->
    <div style="background: #EFF6FF; padding: 1.5rem; border-radius: 8px; margin: 1.5rem 0; border: 1px solid #BFDBFE;">
        <h4 style="margin: 0 0 1rem 0; display: flex; align-items: center; gap: 0.5rem;">
            <span class="dashicons dashicons-info" style="color: #2563EB;"></span>
            <?php esc_html_e( 'How Payment Reusability Works', 'securehold-security-deposit-holds' ); ?>
        </h4>

        <p style="margin: 0 0 0.75rem 0; font-size: 14px; line-height: 1.6; color: #1E3A5F;">
            <?php esc_html_e( 'SecureHold WP ensures payment method reusability for off-session holds through its checkout engine. It automatically sets setup_future_usage=off_session on the checkout PaymentIntent via WooCommerce Stripe Gateway filters — no customer action or checkbox is required.', 'securehold-security-deposit-holds' ); ?>
        </p>

        <ul style="margin: 0; padding-left: 1.5rem; line-height: 1.8; font-size: 14px; color: #1E3A5F;">
            <li><?php esc_html_e( 'Compatible with standard credit and debit cards', 'securehold-security-deposit-holds' ); ?></li>
            <li><?php esc_html_e( 'Works for guest checkout and registered customers', 'securehold-security-deposit-holds' ); ?></li>
            <li><?php esc_html_e( 'Some payment methods (wallets, bank redirects) may have reusability constraints — SecureHold logs a diagnostic when this occurs', 'securehold-security-deposit-holds' ); ?></li>
        </ul>
    </div>

    <div class="wizard-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-setup-wizard&step=2' ) ); ?>" class="sh-btn sh-btn-secondary">
            &larr; <?php esc_html_e( 'Back', 'securehold-security-deposit-holds' ); ?>
        </a>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-setup-wizard&step=4' ) ); ?>" class="sh-btn sh-btn-primary">
            <?php esc_html_e( 'Continue', 'securehold-security-deposit-holds' ); ?> &rarr;
        </a>
    </div>
</div>
