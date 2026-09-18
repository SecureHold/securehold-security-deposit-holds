<?php
/**
 * Support bundle builder.
 *
 * The previous bundle exported the last 50 log rows and nothing else. On a real
 * store that window covered 75 seconds, almost entirely debug chatter, while the
 * Stripe errors that explained the ticket sat a few hundred rows further back —
 * still in the database, simply never exported. Support had to ask the merchant
 * for a second round of information the bundle already could have carried.
 *
 * This builder is organised around the events worth diagnosing rather than around
 * a row count: failed holds and their own logs are collected by order, and errors
 * and warnings get their own sections, so no amount of debug volume can evict
 * them.
 *
 * Read-only, and every value passes through redact() before export.
 *
 * @package SecureHold_WP
 * @since   3.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Support_Bundle {

    /** Bumped when the bundle shape changes, so support can tell versions apart. */
    const BUNDLE_VERSION = '2.2';

    /** Failed holds carried in full, newest first. */
    const MAX_FAILED_HOLDS = 5;

    /** Log rows kept per failed hold. Enough for a whole creation attempt. */
    const MAX_LOGS_PER_HOLD = 40;

    const MAX_ERRORS   = 100;
    const MAX_WARNINGS = 60;

    /**
     * General recent rows, any severity.
     *
     * Deliberately small. This is the section the old bundle consisted of
     * entirely, and the one that hid everything else.
     */
    const MAX_RECENT_ACTIVITY = 25;

    /**
     * Order meta worth carrying, and how to treat each one.
     *
     * An allowlist, never a dump: order meta holds customer data that has no
     * place in a support file. 'id' values are Stripe identifiers, shortened on
     * the way out.
     */
    const ORDER_META_KEYS = array(
        '_securehold_hold_failure_reason'   => 'plain',
        '_securehold_hold_failure_message'  => 'plain',
        '_securehold_pi_diagnosis_result'   => 'plain',
        '_securehold_pi_setup_future_usage' => 'plain',
        '_securehold_pm_reusable'           => 'plain',
        '_securehold_pm_type'               => 'plain',
        '_securehold_sfu_injection_layer'   => 'plain',
        '_securehold_sfu_update_result'     => 'plain',
        '_securehold_timing_strategy'       => 'plain',
        '_securehold_deposit_status'        => 'plain',
        '_securehold_stripe_mode'           => 'plain',
        '_securehold_pi_customer'           => 'id',
        '_securehold_pi_payment_method'     => 'id',
        '_securehold_pm_customer'           => 'id',
    );

    /** @var array|null Memoised Stripe context verdict for this builder. */
    protected $context_cache = null;

    /**
     * Build the bundle.
     *
     * @since 3.4.4
     * @return array
     */
    public function build() {
        $failed_holds = $this->collect_failed_holds();

        $bundle = array(
            'bundle_version' => self::BUNDLE_VERSION,
            'generated_at'   => $this->now(),
            'timezone'       => $this->timezone(),
            'system'         => $this->collect_system(),
            'configuration'  => $this->collect_configuration(),
            'stripe_context' => $this->collect_stripe_context(),
            'license'        => $this->collect_license_diagnostics(),
            'database'       => $this->collect_database_stats(),
            'failed_holds'   => $failed_holds,
            'failed_hold_logs' => $this->collect_failed_hold_logs( $failed_holds ),
            'deposits_never_created' => $this->collect_deposits_never_created(),
            'recent_errors'  => $this->collect_logs_by_severity( 'error', self::MAX_ERRORS ),
            'recent_warnings' => $this->collect_logs_by_severity( 'warning', self::MAX_WARNINGS ),
            'recent_activity' => $this->collect_recent_activity(),
        );

        // Final pass. Anything that slipped a credential into a message, a log
        // payload or an order meta is caught here rather than at the reader's end.
        return self::redact( $bundle );
    }

    // =========================================================================
    // Sections
    // =========================================================================

    /**
     * Environment and plugin versions.
     *
     * A plugin that is not installed reports exactly that — never a guessed or
     * inherited version number.
     *
     * @since 3.4.4
     * @return array
     */
    protected function collect_system() {
        return array(
            'wordpress'              => $this->wp_version(),
            'php'                    => PHP_VERSION,
            'woocommerce'            => $this->plugin_version( 'WC_VERSION' ),
            'woocommerce_stripe'     => $this->woocommerce_stripe_version(),
            'securehold_free'        => $this->plugin_version( 'SECUREHOLD_VERSION' ),
            'securehold_pro'         => $this->plugin_version( 'SECUREHOLD_PRO_VERSION' ),
            'hpos_enabled'           => $this->hpos_enabled(),
            'multisite'              => $this->is_multisite(),
            'locale'                 => $this->locale(),
            'wp_debug'               => $this->wp_debug(),
            'cron_disabled'          => $this->cron_disabled(),
            'memory_limit'           => $this->memory_limit(),
            'max_execution_time'     => (int) ini_get( 'max_execution_time' ),
        );
    }

    /**
     * Configuration state, as distinct facts.
     *
     * setup_completed used to double as "Stripe is configured", which it never
     * was: it only records that someone pressed the last wizard button. The two
     * are reported separately so a support reader cannot conflate them.
     *
     * @since 3.4.4
     * @return array
     */
    protected function collect_configuration() {
        $keys    = $this->securehold_keys();
        $mode    = $this->mode_status();
        $context = $this->stripe_context();

        return array(
            'wizard_completed'              => (bool) $this->option( 'securehold_setup_completed', false ),
            'stripe_credentials_configured' => ( ! empty( $keys['secret'] ) && ! empty( $keys['publishable'] ) ),
            'stripe_credentials_valid'      => $this->credentials_valid( $context ),
            'securehold_stripe_mode'        => isset( $mode['securehold'] ) ? $mode['securehold'] : null,
            'woocommerce_stripe_mode'       => isset( $mode['woocommerce'] ) ? $mode['woocommerce'] : null,
            'woocommerce_gateway_active'    => ! empty( $mode['gateway_active'] ),
            'stripe_context_status'         => isset( $context['status'] ) ? $context['status'] : 'unknown',
            'webhook'                       => $this->collect_webhook_status(),
            'logging_enabled'               => ( $this->option( 'securehold_enable_logging', 'no' ) === 'yes' ),
            'capture_timing'                => $this->option( 'securehold_capture_timing', '(not set)' ),
            'default_hold_amount'           => $this->option( 'securehold_default_hold_amount', '(not set)' ),
            'resolution_policy'             => $this->option( 'securehold_resolution_policy', '(not set)' ),
            'aggregation_mode'              => $this->option( 'securehold_aggregation_mode', '(not set)' ),
            'auto_release'                  => $this->option( 'securehold_auto_release', '(not set)' ),
            'auto_release_days'             => $this->option( 'securehold_auto_release_days', '(not set)' ),
        );
    }

    /**
     * Stripe context verdict, taken from the diagnosis service.
     *
     * No Stripe call is made here. Securehold_Stripe_Context owns that logic;
     * duplicating it would give support two answers that could disagree.
     *
     * @since 3.4.4
     * @return array
     */
    protected function collect_stripe_context() {
        $context = $this->stripe_context();

        if ( empty( $context ) ) {
            return array( 'status' => 'unavailable' );
        }

        return array(
            'status'              => isset( $context['status'] ) ? $context['status'] : 'unknown',
            'securehold_account'  => isset( $context['accounts']['securehold'] ) ? $context['accounts']['securehold'] : null,
            'woocommerce_account' => isset( $context['accounts']['woocommerce'] ) ? $context['accounts']['woocommerce'] : null,
            'securehold_mode'     => isset( $context['mode']['securehold'] ) ? $context['mode']['securehold'] : null,
            'woocommerce_mode'    => isset( $context['mode']['woocommerce'] ) ? $context['mode']['woocommerce'] : null,
            'probe_order_id'      => isset( $context['probe']['order_id'] ) ? $context['probe']['order_id'] : null,
            'probe_intent'        => isset( $context['probe']['intent'] ) ? $context['probe']['intent'] : null,
            'probe_result'        => isset( $context['probe']['result'] ) ? $context['probe']['result'] : null,
            'reasons'             => isset( $context['notes'] ) ? array_values( (array) $context['notes'] ) : array(),
            'checked_at'          => isset( $context['checked_at'] ) ? $context['checked_at'] : null,
        );
    }

    /**
     * SecureHold PRO license diagnostics, so a future activation problem
     * (the ticket that motivated this section) can be read from one export
     * instead of a round of "what does the license page say" emails.
     *
     * PRO is never referenced directly — securehold_license_diagnostics()
     * resolves through the 'securehold_license_diagnostics' filter, which PRO
     * registers when it loads. Absent PRO, the default shape below is
     * returned, so this section always has a consistent, documented form.
     *
     * Never includes the license key: PRO's filter callback never puts it in
     * the array it returns, and redact() is a second barrier regardless.
     *
     * @since 3.4.7
     * @return array
     */
    protected function collect_license_diagnostics() {
        $default = array( 'pro_present' => false );
        $data    = $this->license_diagnostics();

        if ( ! is_array( $data ) || empty( $data ) ) {
            return $default;
        }

        return wp_parse_args( $data, $default );
    }

    /**
     * Recent failed holds, with the order meta that explains them.
     *
     * @since 3.4.4
     * @return array
     */
    /**
     * Orders where a deposit was due and no hold row was ever written.
     *
     * collect_failed_holds() reads the holds table, so it cannot see these:
     * there is no row to find. Staging produced exactly that on the Blocks
     * checkout — an order charged in full, no deposit, and nothing in the
     * bundle to show for it.
     *
     * @return array
     */
    protected function collect_deposits_never_created() {
        $out = array();

        foreach ( (array) $this->query_orders_with_failed_hold( self::MAX_FAILED_HOLDS ) as $order_id ) {
            $order = wc_get_order( (int) $order_id );
            if ( ! $order ) {
                continue;
            }

            $failure = $order->get_meta( '_securehold_hold_failed' );

            $out[] = array(
                'order_id' => $order->get_id(),
                'status'   => $order->get_status(),
                'total'    => $order->get_total(),
                'currency' => $order->get_currency(),
                'reason'   => is_array( $failure ) && isset( $failure['code'] ) ? $failure['code'] : 'unknown',
                'detail'   => is_array( $failure ) && isset( $failure['detail'] ) ? $failure['detail'] : '',
                'at'       => is_array( $failure ) && isset( $failure['at'] ) ? $failure['at'] : '',
                'edit_url' => $order->get_edit_order_url(),
            );
        }

        return $out;
    }

    /**
     * Order ids carrying the failure marker, from whichever store is active.
     *
     * Kept apart from collect_deposits_never_created() so the harness can feed
     * it rows, the way every other query in this class is arranged.
     *
     * @param int $limit Maximum rows.
     * @return int[]
     */
    protected function query_orders_with_failed_hold( $limit ) {
        global $wpdb;

        // The bundle can be built from contexts where the database handle is
        // not up yet; an empty section is better than a fatal in a diagnostic.
        if ( ! is_object( $wpdb ) ) {
            return array();
        }

        $hpos = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
            && method_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' )
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $table  = $hpos ? $wpdb->prefix . 'wc_orders_meta' : $wpdb->postmeta;
        $column = $hpos ? 'order_id' : 'post_id';

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT `{$column}` FROM `{$table}`
                 WHERE meta_key = '_securehold_hold_failed'
                 ORDER BY `{$column}` DESC LIMIT %d",
                $limit
            )
        );

        return array_map( 'absint', (array) $ids );
    }

    protected function collect_failed_holds() {
        $rows   = $this->query_failed_holds( self::MAX_FAILED_HOLDS );
        $holds  = array();

        foreach ( $rows as $row ) {
            $order_id = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;

            $holds[] = array(
                'hold_id'    => isset( $row['id'] ) ? (int) $row['id'] : null,
                'order_id'   => $order_id,
                'status'     => isset( $row['status'] ) ? $row['status'] : null,
                'amount'     => isset( $row['amount'] ) ? $row['amount'] : null,
                'currency'   => isset( $row['currency'] ) ? $row['currency'] : null,
                'intent_id'  => isset( $row['intent_id'] ) ? Securehold_Stripe_Context::mask_id( $row['intent_id'] ) : null,
                'created_at' => isset( $row['created_at'] ) ? $row['created_at'] : null,
                'updated_at' => isset( $row['updated_at'] ) ? $row['updated_at'] : null,
                'notes'      => isset( $row['notes'] ) ? $row['notes'] : null,
                'order_meta' => $this->collect_order_meta( $order_id ),
            );
        }

        return $holds;
    }

    /**
     * Allowlisted diagnostic meta for one order.
     *
     * @since 3.4.4
     *
     * @param int $order_id WooCommerce order ID.
     * @return array Only the keys that are actually set.
     */
    protected function collect_order_meta( $order_id ) {
        if ( $order_id <= 0 ) {
            return array();
        }

        $meta = array();

        foreach ( self::ORDER_META_KEYS as $key => $treatment ) {
            $value = $this->order_meta( $order_id, $key );

            if ( $value === '' || $value === null || $value === false ) {
                continue;
            }

            $meta[ $key ] = ( $treatment === 'id' )
                ? Securehold_Stripe_Context::mask_id( (string) $value )
                : $value;
        }

        return $meta;
    }

    /**
     * Logs belonging to each failed hold, however old.
     *
     * This is the section the old bundle could not produce. The rows are fetched
     * by order rather than by recency, so a few hundred newer debug entries no
     * longer bury the attempt that actually failed.
     *
     * @since 3.4.4
     *
     * @param array $failed_holds Output of collect_failed_holds().
     * @return array Keyed by "order_<id>".
     */
    protected function collect_failed_hold_logs( array $failed_holds ) {
        $logs = array();

        foreach ( $failed_holds as $hold ) {
            $order_id = (int) $hold['order_id'];

            if ( $order_id <= 0 || isset( $logs[ 'order_' . $order_id ] ) ) {
                continue;
            }

            $rows = $this->query_logs_for_order( $order_id, self::MAX_LOGS_PER_HOLD );

            $logs[ 'order_' . $order_id ] = array_map( array( $this, 'format_log_row' ), $rows );
        }

        return $logs;
    }

    /**
     * Recent rows of one severity.
     *
     * @since 3.4.4
     *
     * @param string $severity error|warning.
     * @param int    $limit    Maximum rows.
     * @return array
     */
    protected function collect_logs_by_severity( $severity, $limit ) {
        return array_map( array( $this, 'format_log_row' ), $this->query_logs_by_severity( $severity, $limit ) );
    }

    /**
     * A short tail of general activity, all severities.
     *
     * @since 3.4.4
     * @return array
     */
    protected function collect_recent_activity() {
        return array_map( array( $this, 'format_log_row' ), $this->query_recent_logs( self::MAX_RECENT_ACTIVITY ) );
    }

    /**
     * Table presence and row counts.
     *
     * @since 3.4.4
     * @return array
     */
    protected function collect_database_stats() {
        return $this->query_database_stats();
    }

    /**
     * Normalise one log row for export.
     *
     * @since 3.4.4
     *
     * @param array $row Raw row.
     * @return array
     */
    protected function format_log_row( $row ) {
        $entry = array(
            'created_at' => isset( $row['created_at'] ) ? $row['created_at'] : null,
            'severity'   => isset( $row['severity'] ) ? $row['severity'] : null,
            'message'    => isset( $row['message'] ) ? $row['message'] : null,
        );

        if ( ! empty( $row['order_id'] ) ) {
            $entry['order_id'] = (int) $row['order_id'];
        }

        if ( ! empty( $row['data'] ) ) {
            // Partial email anonymisation, as the previous bundle did. The full
            // redaction pass runs over the assembled bundle afterwards.
            $entry['data'] = preg_replace( '/([a-zA-Z0-9._%+-]{2})[a-zA-Z0-9._%+-]*@/', '$1***@', $row['data'] );
        }

        return $entry;
    }

    // =========================================================================
    // Redaction
    // =========================================================================

    /**
     * Strip anything credential-shaped from an assembled bundle.
     *
     * Deliberately applied to the finished structure rather than to the known
     * option names: a secret can arrive through a Stripe error message, a log
     * payload or an order meta that nobody thought to mask, and those are the
     * paths that leak.
     *
     * @since 3.4.4
     *
     * @param mixed $value Scalar, array, or anything else.
     * @return mixed Same shape, strings sanitised.
     */
    public static function redact( $value ) {
        if ( is_array( $value ) ) {
            $out = array();
            foreach ( $value as $k => $v ) {
                $out[ $k ] = self::redact( $v );
            }
            return $out;
        }

        if ( ! is_string( $value ) || $value === '' ) {
            return $value;
        }

        $patterns = array(
            // Stripe secret and restricted keys, live or test.
            '/\b(?:sk|rk)_(?:test|live)_[A-Za-z0-9]+/'          => '[redacted:stripe-secret-key]',
            // Webhook signing secrets.
            '/\bwhsec_[A-Za-z0-9_\-]+/'                          => '[redacted:webhook-secret]',
            // PaymentIntent / SetupIntent client secrets ("pi_123_secret_abc").
            '/\b(?:pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/'   => '[redacted:client-secret]',
            // Authorization headers, however they were captured.
            '/\bBearer\s+[A-Za-z0-9._\-]+/i'                     => 'Bearer [redacted]',
            '/\bAuthorization\s*:\s*\S+/i'                       => 'Authorization: [redacted]',
            // Cookie / Set-Cookie headers, however they were captured. The
            // whole value is stripped rather than parsed cookie-by-cookie —
            // same approach as the Authorization pattern above — so several
            // cookies in one header, or attributes like Path/Secure, are all
            // covered without trying to parse them individually. Checked
            // before the plain "Cookie:" pattern, and excluded from it via a
            // lookbehind, so "Set-Cookie:" is never redacted as "Set-" +
            // "Cookie: [redacted]".
            '/\bSet-Cookie\s*:\s*[^\r\n]+/i'                     => 'Set-Cookie: [redacted]',
            '/(?<!Set-)\bCookie\s*:\s*[^\r\n]+/i'                => 'Cookie: [redacted]',
            // WordPress session/auth cookies, even without a header prefix —
            // e.g. a raw cookie string picked up by a proxy or debug log.
            // The cookie name is kept for diagnosis, only the value is
            // stripped, so "Path=/; Secure" style attributes after it are
            // left alone rather than swept into the match.
            '/\b(wordpress_logged_in_[A-Za-z0-9]+)=[^;\s]+/i'    => '$1=[redacted]',
            '/\b(wordpress_sec_[A-Za-z0-9]+)=[^;\s]+/i'          => '$1=[redacted]',
            '/\b(wordpress_[A-Za-z0-9]+)=[^;\s]+/i'              => '$1=[redacted]',
            // WooCommerce's own session cookie — the one session identifier
            // this plugin's own architecture (a WooCommerce extension) makes
            // relevant beyond core WordPress auth cookies.
            '/\b(wp_woocommerce_session_[A-Za-z0-9]+)=[^;\s]+/i' => '$1=[redacted]',
            // JSON or query style assignments of a credential-ish field.
            '/"(client_secret|api_key|secret_key|secret)"\s*:\s*"[^"]*"/i' => '"$1":"[redacted]"',
            '/\b(client_secret|api_key|secret_key)\s*=\s*[^&\s"\']+/i'     => '$1=[redacted]',
            // SecureHold license keys (Securehold_LS_License_Key::CHARSET —
            // A-Z0-9 minus 0/1/I/O — 4 groups of 4). The bundle never places a
            // license key in a field on purpose; this is a second barrier in
            // case one ever reaches a log message or error string.
            '/\b[A-HJ-NP-Z2-9]{4}(?:-[A-HJ-NP-Z2-9]{4}){3}\b/'   => '[redacted:securehold-license-key]',
        );

        return preg_replace( array_keys( $patterns ), array_values( $patterns ), $value );
    }

    // =========================================================================
    // Seams — overridden by the test harness.
    // =========================================================================

    /** @return string */
    protected function now() { return current_time( 'mysql' ); }

    /** @return string */
    protected function timezone() { return function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : ''; }

    /** @return string */
    protected function wp_version() { return get_bloginfo( 'version' ); }

    /** @return bool */
    protected function is_multisite() { return is_multisite(); }

    /** @return string */
    protected function locale() { return get_locale(); }

    /** @return bool */
    protected function wp_debug() { return defined( 'WP_DEBUG' ) && WP_DEBUG; }

    /** @return bool */
    protected function cron_disabled() { return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON; }

    /** @return string */
    protected function memory_limit() { return defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ''; }

    /**
     * Value of a version constant, or an explicit "not installed".
     *
     * @since 3.4.4
     *
     * @param string $constant Constant name.
     * @return string
     */
    protected function plugin_version( $constant ) {
        return defined( $constant ) ? (string) constant( $constant ) : 'not installed';
    }

    /**
     * WooCommerce Stripe Gateway version.
     *
     * The gateway has published its version under more than one constant name,
     * so both are tried before reporting the plugin as present but unversioned.
     *
     * @since 3.4.4
     * @return string
     */
    protected function woocommerce_stripe_version() {
        foreach ( array( 'WC_STRIPE_VERSION', 'WC_STRIPE_PLUGIN_VERSION' ) as $constant ) {
            if ( defined( $constant ) ) {
                return (string) constant( $constant );
            }
        }

        if ( class_exists( 'WC_Gateway_Stripe' ) || class_exists( 'WC_Stripe' ) ) {
            return 'installed (version unknown)';
        }

        return 'not installed';
    }

    /** @return bool */
    protected function hpos_enabled() {
        return class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * @param string $name    Option name.
     * @param mixed  $default Fallback.
     * @return mixed
     */
    protected function option( $name, $default = false ) { return get_option( $name, $default ); }

    /** @return array */
    protected function securehold_keys() {
        return function_exists( 'securehold_get_stripe_keys' ) ? securehold_get_stripe_keys() : array();
    }

    /** @return array */
    protected function mode_status() {
        return function_exists( 'securehold_get_stripe_mode_status' ) ? securehold_get_stripe_mode_status() : array();
    }

    /** @return array */
    protected function license_diagnostics() {
        return function_exists( 'securehold_license_diagnostics' ) ? securehold_license_diagnostics() : array( 'pro_present' => false );
    }

    /**
     * The real REST route, never a remembered string.
     *
     * The previous bundle printed a hardcoded /wc-api/securehold_webhook/ that
     * has not been registered anywhere in years, and sent support looking at a
     * webhook problem that did not exist.
     *
     * @since 3.4.4
     * @return string
     */
    protected function webhook_url() {
        return function_exists( 'securehold_get_webhook_url' )
            ? securehold_get_webhook_url()
            : rest_url( 'securehold/v1/webhook' );
    }

    /**
     * Webhook state, taken from the configurator's read-only diagnosis.
     *
     * The bundle used to report webhook_configured as "a whsec_ is stored",
     * which stays true after the credentials move to another Stripe account —
     * exactly when the stored secret has stopped verifying anything.
     *
     * The signing secret is never exported, and the endpoint id is shortened.
     *
     * @since 3.4.4
     * @return array
     */
    protected function collect_webhook_status() {
        if ( ! class_exists( 'Securehold_Webhook_Configurator' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-webhook-configurator.php';
        }

        if ( ! class_exists( 'Securehold_Webhook_Configurator' ) ) {
            return array( 'status' => 'unavailable', 'url' => $this->webhook_url() );
        }

        $state = Securehold_Webhook_Configurator::get_status();

        return array(
            'status'              => $state['status'],
            'url'                 => $state['url'],
            'endpoint_id'         => $state['endpoint_id'],
            'endpoint_accessible' => $state['endpoint_accessible'],
            'url_matches'         => $state['url_matches'],
            'secret_configured'   => $state['secret_configured'],
            'repair_required'     => $state['repair_required'],
            'reason'              => $state['reason'],
        );
    }

    /**
     * Cached Stripe context verdict.
     *
     * @since 3.4.4
     * @return array
     */
    protected function stripe_context() {
        // Instance property, not a static: a static would be shared by every
        // builder in the request and would outlive the object it belongs to.
        if ( $this->context_cache !== null ) {
            return $this->context_cache;
        }

        if ( ! class_exists( 'Securehold_Stripe_Context' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe-context.php';
        }

        $this->context_cache = class_exists( 'Securehold_Stripe_Context' ) ? Securehold_Stripe_Context::get() : array();

        return $this->context_cache;
    }

    /**
     * Whether the credentials themselves are known to work.
     *
     * Any verdict other than "rejected by Stripe" means authentication passed;
     * a context problem is a different fact, reported separately.
     *
     * @since 3.4.4
     *
     * @param array $context Stripe context result.
     * @return bool|null Null when the verdict is unavailable.
     */
    protected function credentials_valid( $context ) {
        if ( empty( $context['status'] ) ) {
            return null;
        }

        return ( $context['status'] !== Securehold_Stripe_Context::STATUS_INVALID_CREDENTIALS );
    }

    /**
     * @param int $order_id Order ID.
     * @param string $key   Meta key.
     * @return mixed
     */
    protected function order_meta( $order_id, $key ) {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }

        $order = wc_get_order( $order_id );

        return $order ? $order->get_meta( $key, true ) : null;
    }

    // ── Database seams ──

    /** @return string */
    protected function holds_table() { global $wpdb; return $wpdb->prefix . 'securehold_holds'; }

    /** @return string */
    protected function logs_table() { global $wpdb; return $wpdb->prefix . 'securehold_logs'; }

    /**
     * @param int $limit Maximum rows.
     * @return array
     */
    protected function query_failed_holds( $limit ) {
        global $wpdb;
        $table = $this->holds_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE status = 'failed' ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param int $order_id Order ID.
     * @param int $limit    Maximum rows.
     * @return array Oldest first, so the attempt reads in order.
     */
    protected function query_logs_for_order( $order_id, $limit ) {
        global $wpdb;
        $table = $this->logs_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, severity, message, order_id, data FROM `{$table}`
                 WHERE order_id = %d ORDER BY id ASC LIMIT %d",
                $order_id,
                $limit
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param string $severity Severity value.
     * @param int    $limit    Maximum rows.
     * @return array
     */
    protected function query_logs_by_severity( $severity, $limit ) {
        global $wpdb;
        $table = $this->logs_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, severity, message, order_id, data FROM `{$table}`
                 WHERE severity = %s ORDER BY id DESC LIMIT %d",
                $severity,
                $limit
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @param int $limit Maximum rows.
     * @return array
     */
    protected function query_recent_logs( $limit ) {
        global $wpdb;
        $table = $this->logs_table();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, severity, message, order_id, data FROM `{$table}`
                 ORDER BY id DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );

        return is_array( $rows ) ? $rows : array();
    }

    /**
     * @return array
     */
    protected function query_database_stats() {
        global $wpdb;

        $holds_table = $this->holds_table();
        $logs_table  = $this->logs_table();

        $holds_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $holds_table ) ) === $holds_table;
        $logs_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $logs_table ) ) === $logs_table;

        $by_status = array();
        $total_logs = 0;
        $by_severity = array();

        if ( $holds_exists ) {
            foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM `{$holds_table}` GROUP BY status", ARRAY_A ) as $row ) {
                $by_status[ $row['status'] ] = (int) $row['cnt'];
            }
        }

        if ( $logs_exists ) {
            $total_logs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$logs_table}`" );

            foreach ( (array) $wpdb->get_results( "SELECT severity, COUNT(*) AS cnt FROM `{$logs_table}` GROUP BY severity", ARRAY_A ) as $row ) {
                $by_severity[ $row['severity'] ] = (int) $row['cnt'];
            }
        }

        return array(
            'holds_table_exists' => $holds_exists,
            'logs_table_exists'  => $logs_exists,
            'holds_by_status'    => $by_status,
            'total_holds'        => array_sum( $by_status ),
            'total_logs'         => $total_logs,
            'logs_by_severity'   => $by_severity,
        );
    }
}
