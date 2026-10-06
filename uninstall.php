<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * SAFETY MODEL (read this before touching anything below):
 *
 * Deleting a WordPress plugin from the Plugins screen is a single click with
 * no confirmation of *what* gets wiped. An incident on 2026-09-25 showed
 * exactly how that can go wrong: this same file, reached from an orphaned
 * duplicate plugin folder, dropped the live installation's holds/logs
 * tables and wiped ~40 options and all `_securehold_*` postmeta/order-meta
 * site-wide — despite the duplicate never having done anything else.
 *
 * Two independent conditions must BOTH hold before this file deletes a
 * single row of business data:
 *
 *   1. Explicit opt-in — `securehold_delete_data_on_uninstall` is truthy.
 *      OFF by default. Settings > Connection > Danger Zone.
 *   2. Canonical install — WP_UNINSTALL_PLUGIN (set by WordPress core to the
 *      exact "<folder>/<main-file>.php" of the plugin actually being
 *      uninstalled) must point at this plugin's canonical folder name, not
 *      a duplicate/renamed/backup copy.
 *
 * If either condition is false, this file does nothing but return —
 * WordPress still removes the plugin's files; all SecureHold data stays.
 *
 * @since 3.5.0 Rewritten for the opt-in + canonical-install guard.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// ── Guard 1: explicit opt-in ────────────────────────────────────────────────
// Convention matches the plugin's other boolean options: '1' = checked.
// Anything else (unset, '', '0', false) means "preserve data" — the safe
// default for every existing install that never saw this setting before.
$securehold_delete_data_opt_in = get_option( 'securehold_delete_data_on_uninstall', '' );

if ( '1' !== $securehold_delete_data_opt_in ) {
    return;
}

// ── Guard 2: canonical install only ─────────────────────────────────────────
// WP_UNINSTALL_PLUGIN is set by WordPress core (wp-admin/includes/plugin.php,
// uninstall_plugin()) to the plugin file path relative to WP_PLUGIN_DIR, e.g.
// "securehold-security-deposit-holds/securehold-wp-stripe-deposits.php". Its
// directory component is the ACTUAL folder that was just deleted/uninstalled
// — it cannot be spoofed by the plugin's own code, since WordPress derives it
// from the real filesystem path before this file is ever included. A
// duplicate folder (an auto-generated "-xxxx" suffix from a failed deploy, a
// "-backup"/"-old"/"-fix" clone, anything not exactly the canonical slug)
// fails this check and the destructive branch below never runs.
$securehold_canonical_slug       = 'securehold-security-deposit-holds';
$securehold_uninstalling_folder  = dirname( WP_UNINSTALL_PLUGIN );
$securehold_is_canonical_install = ( $securehold_canonical_slug === $securehold_uninstalling_folder );

if ( ! $securehold_is_canonical_install ) {
    return;
}

// ── Both guards passed: proceed with destructive cleanup ────────────────────

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

    // Multi-Hold engine (WooCommerce Native Multi-Hold, 3.5.0+)
    'securehold_hold_structure',
    'securehold_multi_hold_grouping_source',

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

    // This opt-in flag itself
    'securehold_delete_data_on_uninstall',
);

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// Per-order hold-creation locks (securehold_hold_lock_<order_id>).
// Named dynamically, so they cannot be listed above. The scheduler releases each
// one on every exit path; this only sweeps a lock orphaned by a fatal error.
// Scoped to this plugin's own option-name prefix — never a risk to other data.
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
// This is a PRECISE, ENUMERATED allow-list of the exact meta_key strings this
// plugin (FREE + PRO) writes — never a `LIKE '_securehold_%'` sweep. That
// broad pattern was the 2026-09-25 incident's second bug: it also matched
// `_securehold_site_*` keys, which belong to the separate "SecureHold Site"
// plugin, not this one. An IN() list cannot accidentally reach into another
// component's namespace just because it shares the `_securehold_` prefix.
// WooCommerce's own `_stripe_*` gateway meta is likewise never touched.
$securehold_owned_meta_keys = array(
    '_securehold_aggregation_mode',
    '_securehold_attempt_made',
    '_securehold_auto_release_days',
    '_securehold_capture_timing',
    '_securehold_captured_amount',
    '_securehold_date_field_key',
    '_securehold_date_resolved',
    '_securehold_date_source',
    '_securehold_days_before_date',
    '_securehold_delay_days',
    '_securehold_deposit_amount',
    '_securehold_deposit_next_run',
    '_securehold_deposit_status',
    '_securehold_email_authorized_sent',   // Securehold_Email_Deposit_Authorized::SENT_META
    '_securehold_email_captured_sent',     // Securehold_Email_Deposit_Captured::SENT_META
    '_securehold_email_released_sent',     // Securehold_Email_Deposit_Released::SENT_META
    '_securehold_enabled',
    '_securehold_hold_amount',
    '_securehold_hold_failed',
    '_securehold_hold_failure_message',
    '_securehold_hold_failure_reason',
    '_securehold_item_breakdown',
    '_securehold_missing_data_retries',
    '_securehold_pi_customer',
    '_securehold_pi_diagnosed',
    '_securehold_pi_diagnosis_result',
    '_securehold_pi_payment_method',
    '_securehold_pi_setup_future_usage',
    '_securehold_pm_customer',
    '_securehold_pm_reusable',
    '_securehold_pm_type',
    '_securehold_rule_policy',
    '_securehold_scheduled_days',
    '_securehold_scheduled_direction',
    '_securehold_sfu_injection_layer',
    '_securehold_sfu_update_attempted',
    '_securehold_sfu_update_result',
    '_securehold_source',
    '_securehold_source_id',
    '_securehold_source_label',
    '_securehold_stripe_mode',
    '_securehold_stripe_resolved',
    '_securehold_test_product',
    '_securehold_timing_strategy',
    '_securehold_trigger_status',
    '_securehold_winner_item',
);

// Legacy pre-3.x keys that predate the `_securehold_` prefix entirely.
$securehold_legacy_keys = array(
    'stripe_caution_intent_id',  // pre-3.x SecureHold key, no _stripe_ prefix
    'empreinte_effectuee',       // pre-3.x SecureHold key
);

$securehold_meta_tables = array( $wpdb->postmeta );

$wc_orders_meta = $wpdb->prefix . 'wc_orders_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wc_orders_meta ) ) === $wc_orders_meta ) {
    $securehold_meta_tables[] = $wc_orders_meta;
}

$securehold_owned_placeholders = implode( ',', array_fill( 0, count( $securehold_owned_meta_keys ), '%s' ) );

foreach ( $securehold_meta_tables as $table ) {

    // One indexed DELETE against the enumerated key list, rather than a
    // prefix LIKE sweep or loading rows first: still a single query, but it
    // can only ever remove rows whose meta_key exactly matches a key this
    // plugin is known to write.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM `{$table}` WHERE meta_key IN ({$securehold_owned_placeholders})",
            $securehold_owned_meta_keys
        )
    );

    foreach ( $securehold_legacy_keys as $legacy_key ) {
        $wpdb->query(
            $wpdb->prepare( "DELETE FROM `{$table}` WHERE meta_key = %s", $legacy_key )
        );
    }
}

// Clear any transients
delete_transient('securehold_stripe_connected');
