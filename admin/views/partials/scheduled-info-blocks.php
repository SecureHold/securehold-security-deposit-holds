<?php
/**
 * Shared partial: Date Resolution Chain + Developer note.
 *
 * Included inside the Scheduled strategy panel of every configuration tab
 * (Global, Product Rules, Category Rules). Keep this file as the single
 * source of truth — never duplicate these blocks inline.
 *
 * @since 4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<!-- Date Resolution Chain Info Box -->
<div class="sh-resolution-chain-box">
    <div class="sh-resolution-chain-header">
        <span class="dashicons dashicons-search" style="color: #6366f1;"></span>
        <strong><?php esc_html_e( 'Date Resolution Chain', 'securehold-security-deposit-holds' ); ?></strong>
    </div>
    <p class="sh-resolution-chain-desc">
        <?php esc_html_e( 'The system attempts to resolve the date using the following priority:', 'securehold-security-deposit-holds' ); ?>
    </p>
    <ol class="sh-resolution-chain-steps">
        <li><strong><?php esc_html_e( 'Order meta', 'securehold-security-deposit-holds' ); ?></strong> &mdash; <?php esc_html_e( 'top-level order metadata (most common)', 'securehold-security-deposit-holds' ); ?></li>
        <li><strong><?php esc_html_e( 'Order item meta', 'securehold-security-deposit-holds' ); ?></strong> &mdash; <?php esc_html_e( 'line item metadata (e.g. per-product dates)', 'securehold-security-deposit-holds' ); ?></li>
        <li><strong><?php esc_html_e( 'Product meta', 'securehold-security-deposit-holds' ); ?></strong> &mdash; <?php esc_html_e( 'product post meta (static date on product)', 'securehold-security-deposit-holds' ); ?></li>
        <li><strong><?php esc_html_e( 'Booking CPT', 'securehold-security-deposit-holds' ); ?></strong> &mdash; <?php esc_html_e( 'WooCommerce Bookings integration', 'securehold-security-deposit-holds' ); ?></li>
        <li><strong><?php esc_html_e( 'Custom table', 'securehold-security-deposit-holds' ); ?></strong> &mdash; <?php esc_html_e( 'third-party plugin tables', 'securehold-security-deposit-holds' ); ?></li>
        <li><strong><?php esc_html_e( 'Developer filter', 'securehold-security-deposit-holds' ); ?></strong> &mdash; <code>securehold_resolve_scheduled_date</code></li>
    </ol>
    <p class="sh-resolution-chain-hint">
        <?php esc_html_e( 'Use the "Inspect Order Meta Keys" tool in Settings to discover available keys for a real order.', 'securehold-security-deposit-holds' ); ?>
    </p>
</div>

<!-- Developer Filter Note -->
<div class="sh-developer-note">
    <span class="dashicons dashicons-editor-code"></span>
    <div>
        <strong><?php esc_html_e( 'Developer', 'securehold-security-deposit-holds' ); ?></strong>
        <p>
            <?php
            printf(
                /* translators: %s = filter hook name */
                esc_html__( 'If none of the built-in sources contain your date, use the %s filter to return a custom timestamp.', 'securehold-security-deposit-holds' ),
                '<code>securehold_resolve_scheduled_date</code>'
            );
            ?>
        </p>
    </div>
</div>
