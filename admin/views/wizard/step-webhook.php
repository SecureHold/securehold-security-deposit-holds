<?php
/**
 * Wizard Step 6: Webhook Configuration
 */
if (!defined('ABSPATH')) exit;

$webhook_secret = get_option('securehold_webhook_secret', '');
$webhook_configured = !empty($webhook_secret);
$webhook_url = get_rest_url(null, 'securehold/v1/webhook');
$stripe_mode = get_option('securehold_stripe_mode', 'test');
$stripe_webhooks_url = ($stripe_mode === 'live')
    ? 'https://dashboard.stripe.com/webhooks'
    : 'https://dashboard.stripe.com/test/webhooks';
$existing_endpoints_count = null;

$keys = function_exists('securehold_get_stripe_keys') ? securehold_get_stripe_keys() : array();
if (!empty($keys['secret'])) {
    require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-webhook-configurator.php';
    $configurator = new Securehold_Webhook_Configurator();
    $count = $configurator->count_endpoints_for_url(rest_url('securehold/v1/webhook'));

    if (!is_wp_error($count)) {
        $existing_endpoints_count = (int) $count;
    }
}

// Optional, if you store it (recommended)
$webhook_endpoint_id = get_option('securehold_webhook_endpoint_id', '');
?>

<div class="wizard-step-content">

    <div style="text-align:center;margin-bottom:2rem;">
        <h2 style="font-size:1.5rem;font-weight:700;color:#1e293b;margin-bottom:0.5rem;">
            <?php esc_html_e('Configure Stripe Webhook', 'securehold-security-deposit-holds'); ?>
        </h2>
        <p style="color:#64748b;font-size:1rem;max-width:680px;margin:0 auto;">
            <?php esc_html_e('Webhooks allow Stripe to notify SecureHold WP when holds are authorized, captured, or released in real-time.', 'securehold-security-deposit-holds'); ?>
        </p>
    </div>

    <?php if ($webhook_configured) : ?>
        <div class="wizard-success-box" style="margin-bottom:1.5rem;">
            <span class="dashicons dashicons-yes-alt"></span>
            <h3><?php esc_html_e('Webhook is configured!', 'securehold-security-deposit-holds'); ?></h3>
            <p><?php esc_html_e('You can review or update the webhook details below.', 'securehold-security-deposit-holds'); ?></p>
        </div>
    <?php endif; ?>

    <?php if (!is_null($existing_endpoints_count) && $existing_endpoints_count > 1) : ?>
    <div class="notice notice-warning" style="margin: 0 0 16px 0;">
            <p style="margin: 10px 12px;">
                <?php esc_html_e('Multiple Stripe webhook endpoints were detected for this URL. This can cause signature verification errors. Keep only one active endpoint in Stripe for this URL.', 'securehold-security-deposit-holds'); ?>
            </p>
        </div>
    <?php endif; ?>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:1.5rem;border-radius:8px;margin-bottom:1.5rem;">

        <h4 style="margin-top:0;font-size:1.1rem;color:#334155;">
            <span class="dashicons dashicons-admin-links" style="margin-right:6px;"></span>
            <?php esc_html_e('Endpoint URL', 'securehold-security-deposit-holds'); ?>
        </h4>

        <div style="display:flex;gap:10px;align-items:center;margin:10px 0 0;">
            <code style="background:#fff;border:1px solid #cbd5e1;padding:8px 12px;border-radius:4px;flex:1;color:#0f172a;">
                <?php echo esc_url($webhook_url); ?>
            </code>
            <button type="button" id="copy-webhook-btn" class="button" title="<?php esc_html_e('Copy URL', 'securehold-security-deposit-holds'); ?>">
                <span class="dashicons dashicons-clipboard" style="margin-top:3px;"></span>
            </button>
        </div>

        <?php if (!empty($webhook_endpoint_id)) : ?>
            <p style="margin-top:10px;color:#64748b;font-size:13px;">
                <?php esc_html_e('Stripe Webhook Endpoint ID:', 'securehold-security-deposit-holds'); ?>
                <code><?php echo esc_html($webhook_endpoint_id); ?></code>
            </p>
        <?php endif; ?>
    </div>

    <div style="background:#ffffff;border:1px solid #e2e8f0;padding:1.5rem;border-radius:8px;margin-bottom:1.5rem;">

        <h4 style="margin-top:0;font-size:1.1rem;color:#334155;">
            <span class="dashicons dashicons-list-view" style="margin-right:6px;"></span>
            <?php esc_html_e('Required events', 'securehold-security-deposit-holds'); ?>
        </h4>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;">
            <code style="font-size:0.85em;background:#e2e8f0;padding:4px 8px;border-radius:6px;">payment_intent.amount_capturable_updated</code>
            <code style="font-size:0.85em;background:#e2e8f0;padding:4px 8px;border-radius:6px;">payment_intent.succeeded</code>
            <code style="font-size:0.85em;background:#e2e8f0;padding:4px 8px;border-radius:6px;">payment_intent.canceled</code>
            <code style="font-size:0.85em;background:#e2e8f0;padding:4px 8px;border-radius:6px;">payment_intent.payment_failed</code>
        </div>

        <p style="margin-top:12px;color:#64748b;font-size:13px;">
            <?php esc_html_e('If you use automatic configuration, SecureHold WP will create or update the endpoint in your Stripe account.', 'securehold-security-deposit-holds'); ?>
        </p>
    </div>

    <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:1.5rem;border-radius:8px;margin-bottom:1.5rem;">
        <h4 style="margin-top:0;font-size:1.1rem;color:#334155;">
            <span class="dashicons dashicons-shield" style="margin-right:6px;"></span>
            <?php esc_html_e('Webhook Signing Secret', 'securehold-security-deposit-holds'); ?>
        </h4>
        
        <div class="wizard-help-box" style="margin-top:0; margin-bottom:16px; background:#eff6ff; border-left:4px solid #2563eb; padding:1.25rem; border-radius:4px;">
            <h4 style="margin-top:0; color:#1e40af; font-size:1.05rem;">
                <span class="dashicons dashicons-info-outline" style="margin-right:6px;"></span>
                <?php esc_html_e('How to copy the signing secret from Stripe', 'securehold-security-deposit-holds'); ?>
            </h4>
        
            <ol style="margin:0 0 12px 18px; color:#1e3a8a; line-height:1.9;">
                <li><?php esc_html_e('Open Stripe Webhooks using the button above.', 'securehold-security-deposit-holds'); ?></li>
                <li><?php esc_html_e('Find the endpoint that matches this URL:', 'securehold-security-deposit-holds'); ?></li>
            </ol>
        
            <code style="display:block; background:#fff; border:1px solid #cbd5e1; padding:8px 12px; border-radius:6px; color:#0f172a;">
                <?php echo esc_url($webhook_url); ?>
            </code>
        
            <ol start="3" style="margin:12px 0 0 18px; color:#1e3a8a; line-height:1.9;">
                <li><?php esc_html_e('Click the endpoint, then click Reveal next to Signing secret.', 'securehold-security-deposit-holds'); ?></li>
                <li><?php esc_html_e('Copy the value starting with whsec_ and paste it in the field below.', 'securehold-security-deposit-holds'); ?></li>
            </ol>
        
            <div style="margin-top:12px;">
                <a href="<?php echo esc_url($stripe_webhooks_url); ?>" target="_blank" rel="noopener"
                   class="sh-btn sh-btn-secondary" style="display:inline-flex; align-items:center; gap:8px;">
                    <span class="dashicons dashicons-external"></span>
                    <?php esc_html_e('Open Stripe Webhooks', 'securehold-security-deposit-holds'); ?>
                </a>
            </div>
        </div>

        <form method="post" action="" style="margin-top:1rem;">
            <?php wp_nonce_field('securehold_wizard_nonce'); ?>
            <input type="hidden" name="securehold_wizard_action" value="save_webhook">

            <div class="sh-input-field" style="margin-bottom:1rem;">
                <label for="webhook_secret" class="sh-label-modern"><?php esc_html_e('Signing secret (whsec_)', 'securehold-security-deposit-holds'); ?></label>

                <div class="sh-input-wrapper">
                    <input
                        type="password"
                        id="webhook_secret"
                        name="webhook_secret"
                        value="<?php echo esc_attr($webhook_secret); ?>"
                        class="sh-input-modern"
                        placeholder="whsec_..."
                        style="padding-right:40px;"
                        autocomplete="off"
                    >
                    <button type="button" class="sh-toggle-password">
                        <span class="dashicons dashicons-visibility"></span>
                    </button>
                </div>

                <p style="margin-top:8px;color:#64748b;font-size:13px;">
                    <?php esc_html_e('You can paste the signing secret from Stripe, or update it here if you changed the endpoint.', 'securehold-security-deposit-holds'); ?>
                </p>
            </div>

            <button type="submit" class="sh-btn sh-btn-secondary">
                <?php esc_html_e('Save Webhook Secret', 'securehold-security-deposit-holds'); ?>
            </button>
        </form>
    </div>

    <div class="wizard-help-box" style="background:#eff6ff;border-left:4px solid #2563eb;padding:1.5rem;border-radius:4px;margin-bottom:1.5rem;">
        <h4 style="margin-top:0;color:#1e40af;font-size:1.1rem;">
            <span class="dashicons dashicons-admin-generic" style="margin-right:5px;"></span>
            <?php echo $webhook_configured
                ? esc_html__('Automatic Configuration (Reconfigure)', 'securehold-security-deposit-holds')
                : esc_html__('Automatic Configuration (Recommended)', 'securehold-security-deposit-holds'); ?>
        </h4>

        <p style="color:#1e3a8a;margin-bottom:1rem;">
            <?php esc_html_e('This will create or configure the Stripe webhook endpoint via the API.', 'securehold-security-deposit-holds'); ?>
        </p>

        <a href="<?php echo esc_url($stripe_webhooks_url); ?>" target="_blank" rel="noopener"
           class="sh-btn sh-btn-secondary" style="margin-bottom:12px; display:inline-flex; align-items:center; gap:8px;">
            <span class="dashicons dashicons-external"></span>
            <?php esc_html_e('Open Stripe Webhooks', 'securehold-security-deposit-holds'); ?>
        </a>

        <form method="post" action="">
            <?php wp_nonce_field('securehold_wizard_nonce'); ?>
            <input type="hidden" name="securehold_wizard_action" value="auto_configure_webhook">
            <button type="submit" class="sh-btn sh-btn-secondary">
                <?php echo $webhook_configured
                    ? esc_html__('Reconfigure Webhook Automatically', 'securehold-security-deposit-holds')
                    : esc_html__('Auto-Configure Webhook', 'securehold-security-deposit-holds'); ?>
            </button>
        </form>

        <p style="margin-top:12px;color:#1e3a8a;font-size:13px;">
            <?php esc_html_e('Tip: In Stripe, keep only one active endpoint for this URL to avoid signature errors.', 'securehold-security-deposit-holds'); ?>
        </p>
    </div>

    <div class="wizard-actions">
        <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-setup-wizard&step=5')); ?>" class="sh-btn sh-btn-secondary">
            ← <?php esc_html_e('Back', 'securehold-security-deposit-holds'); ?>
        </a>

        <?php if ($webhook_configured) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=securehold-setup-wizard&step=7')); ?>" class="sh-btn sh-btn-primary">
                <?php esc_html_e('Continue', 'securehold-security-deposit-holds'); ?> →
            </a>
        <?php else : ?>
            <button type="button" class="sh-btn sh-btn-secondary" disabled style="opacity:0.6;cursor:not-allowed;">
                <?php esc_html_e('Save the webhook secret to continue', 'securehold-security-deposit-holds'); ?>
            </button>
        <?php endif; ?>
    </div>
</div>
