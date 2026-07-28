<?php
/**
 * Partial: Stats Overview Grid
 * Shared between Dashboard and Deposits page for UI consistency.
 */

if (!defined('ABSPATH')) exit;

global $wpdb;
$table_name = $wpdb->prefix . 'securehold_holds';

// Initialize defaults
$total_holds = 0;
$total_authorized_amount = 0;
$total_captured_amount = 0;
$active_holds_count = 0;
$active_holds_amount = 0;
$scheduled_holds_count = 0;
$released_holds_count = 0;
$released_holds_amount = 0;

// Currency: use WooCommerce's configured currency for aggregate stats.
// Legacy: was get_option('securehold_stripe_currency', 'USD') — wrong when WC is EUR/GBP.

// Perform calculations if table exists
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
    // 1. Total Count
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $total_holds = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

    // 2. Total Authorized (Volume historique)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $total_auth_raw = $wpdb->get_var( "SELECT SUM(amount) FROM {$table_name}" );
    $total_authorized_amount = $total_auth_raw ? $total_auth_raw : 0;

    // 3. Total Captured (Revenus/Encaissements)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $total_cap_raw = $wpdb->get_var( "SELECT SUM(captured_amount) FROM {$table_name} WHERE status = 'captured'" );
    $total_captured_amount = $total_cap_raw ? $total_cap_raw : 0;

    // 4. Active Holds (En cours)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $active_holds_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE status = 'authorized'" );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $active_amount_raw = $wpdb->get_var( "SELECT SUM(amount) FROM {$table_name} WHERE status = 'authorized'" );
    $active_holds_amount = $active_amount_raw ? $active_amount_raw : 0;

    // 5. Scheduled Holds (Awaiting execution)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $scheduled_holds_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE status = 'scheduled'" );

    // 6. Released Holds (returned to customers)
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $released_holds_count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name} WHERE status = 'released'" );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $released_amount_raw = $wpdb->get_var( "SELECT SUM(amount) FROM {$table_name} WHERE status = 'released'" );
    $released_holds_amount = $released_amount_raw ? $released_amount_raw : 0;
}
?>

<div class="sh-stat-grid" style="margin-bottom: 2rem;">
    
    <div class="sh-stat-card primary">
        <div class="sh-stat-label"><?php esc_html_e('Total Holds Created', 'securehold-security-deposit-holds'); ?></div>
        <div class="sh-stat-value sh-stat-value--spaced"><?php echo number_format($total_holds); ?></div>
        <div class="sh-stat-change positive">
            <span class="dashicons dashicons-shield"></span>
            <?php esc_html_e('All time volume', 'securehold-security-deposit-holds'); ?>
        </div>
    </div>

    <div class="sh-stat-card warning">
        <div class="sh-stat-label"><?php esc_html_e('Active Holds Amount', 'securehold-security-deposit-holds'); ?></div>
        <div class="sh-stat-value sh-stat-value--spaced"><?php echo wp_kses_post( wc_price( $active_holds_amount ) ); ?></div>
        <div class="sh-stat-change">
            <?php
            /* translators: %s is the number of active security deposit holds */
            echo esc_html( sprintf( _n('%s active deposit', '%s active deposits', $active_holds_count, 'securehold-security-deposit-holds'), number_format($active_holds_count) ) ); ?>
            <?php if ($scheduled_holds_count > 0) : ?>
                <span style="margin-left:6px; color:#7C3AED; font-size:0.8em;">
                    <?php
                    /* translators: %s is the number of scheduled security deposit holds */
                    echo esc_html( sprintf( _n('+ %s scheduled', '+ %s scheduled', $scheduled_holds_count, 'securehold-security-deposit-holds'), number_format($scheduled_holds_count) ) ); ?>
                </span>
            <?php endif; ?>
        </div>
        <span class="dashicons dashicons-clock sh-stat-icon"></span>
    </div>

    <div class="sh-stat-card success">
        <div class="sh-stat-label"><?php esc_html_e('Total Captured', 'securehold-security-deposit-holds'); ?></div>
        <div class="sh-stat-value sh-stat-value--spaced"><?php echo wp_kses_post( wc_price( $total_captured_amount ) ); ?></div>
        <div class="sh-stat-change">
            <span class="dashicons dashicons-money-alt"></span>
            <?php esc_html_e('Funds collected', 'securehold-security-deposit-holds'); ?>
        </div>
        <span class="dashicons dashicons-yes-alt sh-stat-icon" style="color:var(--sh-success);"></span>
    </div>

    <div class="sh-stat-card info">
        <div class="sh-stat-label"><?php esc_html_e('Released Deposits', 'securehold-security-deposit-holds'); ?></div>
        <div class="sh-stat-value sh-stat-value--spaced"><?php echo wp_kses_post( wc_price( $released_holds_amount ) ); ?></div>
        <div class="sh-stat-change">
            <?php
            /* translators: %s is the number of released security deposit holds */
            echo esc_html( sprintf( _n('%s released deposit', '%s released deposits', $released_holds_count, 'securehold-security-deposit-holds'), number_format($released_holds_count) ) ); ?>
        </div>
        <span class="dashicons dashicons-undo sh-stat-icon"></span>
    </div>
</div>