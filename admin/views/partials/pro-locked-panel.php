<?php
/**
 * Reusable PRO-locked feature panel (FREE-only fallback UI).
 *
 * Renders SecureHold's existing `.sh-card` / `.sh-locked-feature` empty-state
 * pattern (already shipped in securehold-wp-design-system.css, previously
 * unused) with a "PRO" badge and a CTA to the pricing page.
 *
 * This partial is presentation only: no PRO code, no settings read or
 * written, no interactive control. It is included only when the
 * corresponding PRO feature is not active (callers gate on
 * securehold_feature_enabled() / securehold_rule_engine_enabled() before
 * including this file), so it never appears alongside the real PRO UI.
 *
 * Expects the following variables set by the including file:
 *   string $sh_locked_title        Feature name (already translated).
 *   string $sh_locked_description  One-line benefit description (already translated).
 *   string $sh_locked_icon         Optional dashicon class. Defaults to 'dashicons-lock'.
 *   bool   $sh_locked_no_wrap      Optional. True to skip the outer .sh-card wrapper
 *                                  (use when the caller already provides one).
 *
 * @package SecureHold
 * @since   3.4.9
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$sh_locked_icon    = isset( $sh_locked_icon ) ? $sh_locked_icon : 'dashicons-lock';
$sh_locked_no_wrap = ! empty( $sh_locked_no_wrap );
?>
<?php if ( ! $sh_locked_no_wrap ) : ?>
<div class="sh-card">
<?php endif; ?>
    <div class="sh-locked-feature">
        <span class="dashicons <?php echo esc_attr( $sh_locked_icon ); ?> sh-locked-feature__icon" aria-hidden="true"></span>
        <h3>
            <?php echo esc_html( $sh_locked_title ); ?>
            <span class="sh-pro-badge"><?php esc_html_e( 'PRO', 'securehold-security-deposit-holds' ); ?></span>
        </h3>
        <p><?php echo esc_html( $sh_locked_description ); ?></p>
        <a href="<?php echo esc_url( SECUREHOLD_WP_URL_PRICING ); ?>" target="_blank" rel="noopener noreferrer" class="sh-btn sh-btn-primary">
            <?php esc_html_e( 'Learn More', 'securehold-security-deposit-holds' ); ?>
        </a>
    </div>
<?php if ( ! $sh_locked_no_wrap ) : ?>
</div>
<?php endif; ?>
