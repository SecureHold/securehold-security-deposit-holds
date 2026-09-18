<?php
/**
 * Fired when the plugin is uninstalled
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete all options
$options_to_delete = array(
    // Core / versioning
    'securehold_version',
    'securehold_db_version',
    'securehold_db_migration_failed',

    // Stripe connection
    'securehold_stripe_mode',
    'securehold_stripe_test_publishable_key',
    'securehold_stripe_test_secret_key',
    'securehold_stripe_live_publishable_key',
    'securehold_stripe_live_secret_key',
    'securehold_webhook_secret',
    'securehold_stripe_webhook_secret',  // Legacy: stale option written by settings page before v3.5.0 fix
    'securehold_stripe_currency',

    // Migration flags and divergence notice
    'securehold_migration_webhook_secret_v1',
    'securehold_migration_wc_stripe_notice_v1',
    'securehold_wc_stripe_divergence_notice',

    // Global deposit settings
    'securehold_default_hold_amount',
    'securehold_hold_duration_days',
    'securehold_auto_release',
    'securehold_auto_release_days',
    'securehold_require_3ds',

    // Capture timing & strategy
    'securehold_capture_timing',
    'securehold_delay_days',
    'securehold_trigger_status',
    'securehold_date_field_key',
    'securehold_scheduled_days_number',
    'securehold_scheduled_direction',
    'securehold_days_before_date',

    // Category & product rules
    'securehold_category_rules',

    // Checkout message
    'securehold_enable_checkout_message',
    'securehold_checkout_message',
    'securehold_checkout_message_style',
    'securehold_checkout_message_position',

    // My Account tab
    'securehold_enable_my_account_tab',
    'securehold_my_account_menu_label',
    'securehold_my_account_page_title',
    'securehold_my_account_page_description',
    'securehold_my_account_empty_message',

    // Deposit rules: thresholds, exclusions, failure handling
    'securehold_min_cart_amount',
    'securehold_excluded_products',
    'securehold_excluded_categories',
    'securehold_require_deposit_auth',

    // Logging
    'securehold_enable_logging',

    // Setup / onboarding
    'securehold_setup_completed',         // wizard completion flag — must be cleared so wizard re-appears on reinstall
    'securehold_setup_wizard_completed',  // redundant alias written alongside securehold_setup_completed
    'securehold_setup_modal_dismissed',
    'securehold_config_notice_dismissed',
    'securehold_wizard_completed',
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Per-order hold-creation locks (securehold_hold_lock_<order_id>).
// Named dynamically, so they cannot be listed above. The scheduler releases each
// one on every exit path; this only sweeps a lock orphaned by a fatal error.
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        $wpdb->esc_like('securehold_hold_lock_') . '%'
    )
);

// Drop custom tables
$table_holds = $wpdb->prefix . 'securehold_holds';
$table_logs = $wpdb->prefix . 'securehold_logs';

$wpdb->query("DROP TABLE IF EXISTS $table_holds");
$wpdb->query("DROP TABLE IF EXISTS $table_logs");

// Clear scheduled cron hooks
wp_clear_scheduled_hook('securehold_create_holds_cron');
wp_clear_scheduled_hook('securehold_auto_release_cron');
wp_clear_scheduled_hook('securehold_maintenance_cron');
wp_clear_scheduled_hook('securehold_trigger_scheduled_hold');

// Delete SecureHold meta from every store that can hold it.
//
// Order meta moved to wc_orders_meta under HPOS, so cleaning wp_postmeta alone
// left rows behind on any modern store. Both tables are swept whenever they
// exist, rather than branching on whether HPOS is active right now: a store that
// ran HPOS and then switched back still has data in wc_orders_meta, and that
// data is just as orphaned.
//
// Product meta always lives in wp_postmeta, so that sweep is never conditional.
//
// Only SecureHold's own keys are removed. WooCommerce and WooCommerce Stripe
// data — _stripe_intent_id, _stripe_payment_method and the rest — belongs to
// those plugins and is left untouched.
$securehold_meta_prefix = $wpdb->esc_like( '_securehold_' ) . '%';

$securehold_legacy_keys = array(
    'stripe_caution_intent_id',  // pre-3.x SecureHold key, no _stripe_ prefix
    'empreinte_effectuee',       // pre-3.x SecureHold key
);

$securehold_meta_tables = array( $wpdb->postmeta );

$wc_orders_meta = $wpdb->prefix . 'wc_orders_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wc_orders_meta ) ) === $wc_orders_meta ) {
    $securehold_meta_tables[] = $wc_orders_meta;
}

foreach ( $securehold_meta_tables as $table ) {

    // One indexed DELETE per pattern, rather than loading rows first: this runs
    // once, and must stay affordable on a store with a large order history.
    $wpdb->query(
        $wpdb->prepare( "DELETE FROM `{$table}` WHERE meta_key LIKE %s", $securehold_meta_prefix )
    );

    foreach ( $securehold_legacy_keys as $legacy_key ) {
        $wpdb->query(
            $wpdb->prepare( "DELETE FROM `{$table}` WHERE meta_key = %s", $legacy_key )
        );
    }
}

// Clear any transients
delete_transient('securehold_stripe_connected');
