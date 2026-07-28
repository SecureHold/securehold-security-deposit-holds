<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function securehold_get_stripe_keys() {
    $mode = get_option('securehold_stripe_mode', 'test');
    
    if ($mode === 'live') {
        return array(
            'publishable' => get_option('securehold_stripe_live_publishable_key'),
            'secret' => get_option('securehold_stripe_live_secret_key'),
        );
    }
    
    return array(
        'publishable' => get_option('securehold_stripe_test_publishable_key'),
        'secret' => get_option('securehold_stripe_test_secret_key'),
    );
}

function securehold_format_currency($amount, $currency = 'usd') {
    return number_format($amount, 2) . ' ' . strtoupper($currency);
}

/**
 * SecureHold leveled logging system.
 *
 * Severity levels (ordered by importance):
 *   'error'   → Always logged. Failures, exceptions, broken state.
 *   'warning' → Always logged. Non-fatal issues, degraded paths.
 *   'info'    → Logged with reduced detail when debug OFF. Key lifecycle events.
 *   'debug'   → Only logged when "Enable Debug Logging" is ON.
 *
 * @param string $message  Human-readable log message (max 255 chars stored in DB).
 * @param array  $data     Structured context (key-value). Avoid raw Stripe args.
 * @param string $severity One of: 'error', 'warning', 'info', 'debug'.
 */
function securehold_log($message, $data = array(), $severity = 'info') {

    // ── Determine debug mode (cached per request) ──
    static $debug_enabled = null;
    if ($debug_enabled === null) {
        $debug_enabled = (get_option('securehold_enable_logging', 'no') === 'yes');
    }

    // ── Level gate: skip DEBUG entries when debug is OFF ──
    if ($severity === 'debug' && !$debug_enabled) {
        return;
    }

    // ── Scrub verbose data from INFO logs when debug is OFF ──
    $log_data = $data;
    if (!$debug_enabled && $severity === 'info' && is_array($log_data)) {
        // Keep only essential keys — strip verbose diagnostic context
        $essential_keys = array(
            'order_id', 'intent_id', 'customer_id', 'payment_method_id',
            'sfu', 'setup_future_usage', 'sfu_before', 'sfu_after',
            'diagnosis', 'injection_layer', 'pm_reusable',
            'error', 'reason', 'status', 'hold_id',
            // Rule Engine winner summary keys
            'source', 'timing', 'amount_resolved', 'fallbacks_count',
        );
        $log_data = array_intersect_key($log_data, array_flip($essential_keys));
    }

    // Write to the WooCommerce file logger.
    if (function_exists('wc_get_logger')) {
        $logger = wc_get_logger();
        $context = array('source' => 'securehold-stripe-deposits');
        $log_entry = $message;
        if (!empty($log_data)) {
            $log_entry .= ' ' . wp_json_encode($log_data);
        }
        $logger->log($severity, $log_entry, $context);
    }

    // Write to the plugin's database log table (used by the admin log viewer).
    global $wpdb;
    $table_name = $wpdb->prefix . 'securehold_logs';

    // Guard: table may be absent on a partially installed or freshly activated site.
    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
        // Auto-extract order_id from data array when available (populates indexed column)
        $log_order_id = null;
        if (is_array($log_data) && !empty($log_data['order_id'])) {
            $log_order_id = intval($log_data['order_id']);
        }

        $wpdb->insert(
            $table_name,
            array(
                'order_id'   => $log_order_id,
                'event_type' => 'system',
                'message'    => substr($message, 0, 255),
                'data'       => !empty($log_data) ? wp_json_encode($log_data) : null,
                'severity'   => $severity,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );
    }
}

function securehold_get_woocommerce_stripe_keys() {
    $stripe_settings = get_option('woocommerce_stripe_settings');
    $testmode = (!empty($stripe_settings['testmode']) && $stripe_settings['testmode'] === 'yes');
    
    if ($testmode) {
        $secret_key = !empty($stripe_settings['test_secret_key']) ? $stripe_settings['test_secret_key'] : '';
        $publishable_key = !empty($stripe_settings['test_publishable_key']) ? $stripe_settings['test_publishable_key'] : '';
    } else {
        $secret_key = !empty($stripe_settings['secret_key']) ? $stripe_settings['secret_key'] : '';
        $publishable_key = !empty($stripe_settings['publishable_key']) ? $stripe_settings['publishable_key'] : '';
    }
    
    return array(
        'secret' => $secret_key,
        'publishable' => $publishable_key,
        'testmode' => $testmode
    );
}

/**
 * Compare SecureHold mode vs WooCommerce Stripe Gateway mode.
 * Reads only wp_options — no API call, no side effects.
 * 'gateway_active' is false when the WooCommerce Stripe plugin has never been configured.
 *
 * @return array {
 *   securehold:     'test'|'live',
 *   woocommerce:    'test'|'live'|null,
 *   gateway_active: bool,
 *   aligned:        bool,
 * }
 */
function securehold_get_stripe_mode_status() {
    $sh_mode = get_option( 'securehold_stripe_mode', 'test' );

    // Force WooCommerce to load gateway class files before checking class existence.
    // WooCommerce lazily initializes payment gateways; class_exists( 'WC_Gateway_Stripe' )
    // returns false until payment_gateways() is called. Guards prevent fatal error if WooCommerce is absent.
    if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'payment_gateways' ) ) {
        WC()->payment_gateways()->payment_gateways();
    }

    $gateway_active = class_exists( 'WC_Gateway_Stripe' );

    if ( $gateway_active ) {
        $wc_settings = get_option( 'woocommerce_stripe_settings', array() );
        $wc_mode     = ( ! empty( $wc_settings['testmode'] ) && $wc_settings['testmode'] === 'yes' )
            ? 'test'
            : 'live';
    } else {
        $wc_mode = null;
    }

    return array(
        'securehold'     => $sh_mode,
        'woocommerce'    => $wc_mode,
        'gateway_active' => $gateway_active,
        'aligned'        => $gateway_active && ( $sh_mode === $wc_mode ),
    );
}

if ( ! function_exists( 'securehold_rule_engine_enabled' ) ) {
    /**
     * Returns true when the premium Rule Engine is available and enabled.
     *
     * PRO registers: add_filter( 'securehold_rule_engine_enabled', '__return_true' );
     * Future: replace __return_true with securehold_feature_enabled('rule_engine') check.
     *
     * @since 4.6.0
     * @return bool
     */
    function securehold_rule_engine_enabled() {
        if ( function_exists( 'securehold_feature_enabled' ) ) {
            return securehold_feature_enabled( 'rule_engine' );
        }
        return (bool) apply_filters( 'securehold_rule_engine_enabled', false );
    }
}

if ( ! function_exists( 'securehold_pro_automations_enabled' ) ) {
    /**
     * Returns true when the PRO automation engine is available and enabled.
     * Controls: delayed, scheduled, status-based strategies, configurable auto-release.
     *
     * Resolution order:
     *   1. If the centralized feature flag system is available, delegate to it.
     *   2. Otherwise fall back to the legacy filter (PRO registers __return_true).
     *
     * @since 4.7.0
     * @return bool
     */
    function securehold_pro_automations_enabled() {
        if ( function_exists( 'securehold_feature_enabled' ) ) {
            return securehold_feature_enabled( 'deposit_automation' );
        }
        return (bool) apply_filters( 'securehold_pro_automations_enabled', false );
    }
}

if ( ! function_exists( 'securehold_frontend_pro_enabled' ) ) {
    /**
     * Returns true when PRO frontend features are available and enabled.
     *
     * Controls:
     *   - My Account tab (endpoint, menu item, content rendering)
     *   - Advanced checkout message variables (all except {amount}), style, position
     *   - [securehold_my_deposits] shortcode rendering
     *   - [securehold_checkout_message] shortcode rendering
     *
     * Resolution order:
     *   1. If the centralized feature flag system is available, delegate to it.
     *   2. Otherwise fall back to the legacy filter (PRO registers __return_true).
     *
     * @since 5.5.0
     * @return bool
     */
    function securehold_frontend_pro_enabled() {
        if ( function_exists( 'securehold_feature_enabled' ) ) {
            return securehold_feature_enabled( 'frontend' );
        }
        return (bool) apply_filters( 'securehold_frontend_pro_enabled', false );
    }
}

if ( ! function_exists( 'securehold_feature_enabled' ) ) {
    /**
     * Centralized feature flag resolver.
     *
     * Returns true when a named feature is enabled for the current runtime.
     * PRO plugin enables PRO features via the 'securehold_feature_enabled' filter.
     * A future license system can restrict features by adding a higher-priority filter.
     *
     * @since 5.6.0
     * @param  string $feature  Feature slug: 'rule_engine', 'deposit_automation',
     *                          'frontend', 'dashboard', 'logs', 'tools', 'extensions'.
     *                          Unknown slugs return false by default.
     * @return bool
     */
    function securehold_feature_enabled( $feature ) {
        static $defaults = array(
            'rule_engine'        => false,
            'deposit_automation' => false,
            'frontend'           => false,
            'dashboard'          => false,
            'logs'               => false,
            'tools'              => false,
            'extensions'         => false,
        );

        $default = isset( $defaults[ $feature ] ) ? $defaults[ $feature ] : false;

        return (bool) apply_filters( 'securehold_feature_enabled', $default, $feature );
    }
}

if ( ! function_exists( 'securehold_license_status' ) ) {
    /**
     * Returns the current stored license status for SecureHold PRO.
     *
     * Read-only helper. Makes no API call and has no side effects.
     * Returns 'inactive' when no license data is stored.
     *
     * Possible values: 'valid' | 'invalid' | 'expired' | 'inactive' | 'unknown'
     *                  'not_found' | 'conflict'
     *
     * @since 5.7.0
     * @return string
     */
    function securehold_license_status() {
        $data = get_option( 'securehold_pro_license', array() );
        return ! empty( $data['status'] ) ? (string) $data['status'] : 'inactive';
    }
}

if ( ! function_exists( 'securehold_license_is_valid' ) ) {
    /**
     * Returns true when the stored SecureHold PRO license allows feature access.
     *
     * 'valid'   — license confirmed active.
     * 'unknown' — grace period: server unreachable after 7+ consecutive check
     *             failures; features remain enabled to avoid disruption.
     *
     * All other statuses (inactive, invalid, expired, not_found, conflict) → false.
     * Makes no API call and has no side effects.
     *
     * @since 5.8.0
     * @return bool
     */
    function securehold_license_is_valid() : bool {
        return in_array( securehold_license_status(), array( 'valid', 'unknown' ), true );
    }
}

