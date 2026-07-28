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

// Drop custom tables
$table_holds = $wpdb->prefix . 'securehold_holds';
$table_logs = $wpdb->prefix . 'securehold_logs';

$wpdb->query("DROP TABLE IF EXISTS $table_holds");
$wpdb->query("DROP TABLE IF EXISTS $table_logs");

// Clear scheduled cron hooks
wp_clear_scheduled_hook('securehold_create_holds_cron');
wp_clear_scheduled_hook('securehold_auto_release_cron');
wp_clear_scheduled_hook('securehold_trigger_scheduled_hold');

// Delete all post meta related to SecureHold
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_securehold_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'stripe_caution_intent_id'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'empreinte_effectuee'");

// Clear any transients
delete_transient('securehold_stripe_connected');
