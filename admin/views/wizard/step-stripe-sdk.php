<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-stripe-installer.php';
$is_installed = Securehold_Stripe_Installer::is_installed();
$status       = Securehold_Stripe_Installer::get_status_message();
?>

<div class="wizard-step-content">
    <h2><?php esc_html_e( 'Stripe SDK', 'securehold-security-deposit-holds' ); ?></h2>

    <p><?php esc_html_e( 'SecureHold WP communicates with Stripe using its official PHP SDK, which is bundled inside the plugin.', 'securehold-security-deposit-holds' ); ?></p>

    <?php if ( $is_installed ) : ?>
        <div class="wizard-success-box">
            <span class="dashicons dashicons-yes-alt"></span>
            <h3><?php esc_html_e( 'Stripe SDK is bundled and ready.', 'securehold-security-deposit-holds' ); ?></h3>
            <p><?php esc_html_e( 'No additional installation is required. You\'re ready to move to the next step.', 'securehold-security-deposit-holds' ); ?></p>
        </div>
    <?php else : ?>
        <div class="wizard-warning-box">
            <p><strong><?php esc_html_e( 'Status:', 'securehold-security-deposit-holds' ); ?></strong> <?php echo esc_html( $status['message'] ); ?></p>
        </div>
        <div class="wizard-help-box">
            <h4><?php esc_html_e( 'How to fix this', 'securehold-security-deposit-holds' ); ?></h4>
            <p><?php esc_html_e( 'The Stripe SDK files appear to be missing from the plugin directory. This usually means the plugin was installed from an incomplete or corrupted ZIP file.', 'securehold-security-deposit-holds' ); ?></p>
            <ol style="font-size: 13px;">
                <li><?php esc_html_e( 'Log in to your SecureHold WP account dashboard.', 'securehold-security-deposit-holds' ); ?></li>
                <li><?php esc_html_e( 'Download the latest plugin ZIP.', 'securehold-security-deposit-holds' ); ?></li>
                <li><?php esc_html_e( 'Reinstall via Plugins → Add New → Upload Plugin.', 'securehold-security-deposit-holds' ); ?></li>
            </ol>
            <p><a href="<?php echo esc_url( SECUREHOLD_WP_URL_DOCS_GETTING_STARTED ); ?>" target="_blank"><?php esc_html_e( 'View installation guide', 'securehold-security-deposit-holds' ); ?> →</a></p>
        </div>
    <?php endif; ?>

    <div class="wizard-actions">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-setup-wizard&step=3' ) ); ?>" class="sh-btn sh-btn-secondary">
            ← <?php esc_html_e( 'Back', 'securehold-security-deposit-holds' ); ?>
        </a>

        <?php if ( $is_installed ) : ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-setup-wizard&step=5' ) ); ?>" class="sh-btn sh-btn-primary">
                <?php esc_html_e( 'Continue', 'securehold-security-deposit-holds' ); ?> →
            </a>
        <?php endif; ?>
    </div>
</div>
