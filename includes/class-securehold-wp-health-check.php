<?php
/**
 * Health Check System
 * Verifies all plugin components are working correctly
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Health_Check {
    
    /**
     * Run all health checks
     */
    public static function run_all_checks() {
        return array(
            'stripe_sdk'          => self::check_stripe_sdk(),
            'stripe_keys'         => self::check_stripe_keys(),
            'webhook'             => self::check_webhook(),
            'woocommerce'         => self::check_woocommerce(),
            'stripe_gateway'      => self::check_stripe_gateway(),
            'database'            => self::check_database(),
            'cron'                => self::check_cron(),
            'permissions'         => self::check_permissions(),
            'transparency_notice' => self::check_transparency_notice(),
        );
    }
    
    /**
     * Check if Stripe SDK is installed
     */
    public static function check_stripe_sdk() {
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-stripe-installer.php';
    
        if (!\Securehold_Stripe_Installer::is_installed()) {
            return array(
                'status'  => 'error',
                'message' => __('Stripe SDK is not installed', 'securehold-security-deposit-holds'),
                'action'  => array(
                    'label' => __('Install Now', 'securehold-security-deposit-holds'),
                    'url'   => admin_url('admin.php?page=securehold-setup-wizard&step=2')
                )
            );
        }
    
        // SDK is loaded at boot. Determine the active class name: scoped build uses
        // SecureHoldWP\Vendor\Stripe\Stripe; dev environment uses \Stripe\Stripe.
        $stripe_class = \Securehold_Stripe_Installer::get_stripe_class();

        if ( ! $stripe_class ) {
            return array(
                'status'  => 'error',
                'message' => __('Stripe SDK seems installed but Stripe classes cannot be loaded', 'securehold-security-deposit-holds'),
                'action'  => array(
                    'label' => __('Reinstall SDK', 'securehold-security-deposit-holds'),
                    'url'   => admin_url('admin.php?page=securehold-setup-wizard&step=2')
                )
            );
        }

        $version = defined( $stripe_class . '::VERSION' )
            ? constant( $stripe_class . '::VERSION' )
            : __( 'unknown', 'securehold-security-deposit-holds' );
    
        return array(
            'status'  => 'success',
            /* translators: %s is the Stripe SDK version number */
            'message' => sprintf(__('Stripe SDK loaded (version: %s)', 'securehold-security-deposit-holds'), $version),
            'action'  => array(
                'label' => __('Test Stripe SDK', 'securehold-security-deposit-holds'),
                'url'   => wp_nonce_url(
                    admin_url('admin.php?page=securehold-health&action=securehold_test_stripe_sdk'),
                    'securehold_test_stripe_sdk'
                )
            )
        );
    }
    
    /**
     * Check if Stripe API keys are configured
     */
    public static function check_stripe_keys() {
        $keys = securehold_get_stripe_keys();
    
        // If missing keys
        if (empty($keys['secret']) || empty($keys['publishable'])) {
            return array(
                'status'  => 'error',
                'message' => __('Stripe API keys not configured', 'securehold-security-deposit-holds'),
                'action'  => array(
                    'label' => __('Configure Now', 'securehold-security-deposit-holds'),
                    'url'   => admin_url('admin.php?page=securehold-setup-wizard&step=3')
                )
            );
        }
    
        // Keys exist, do automatic validation (current behavior)
        try {
            Securehold_Stripe::init_stripe();
            \Stripe\Balance::retrieve();
    
            return array(
                'status'  => 'success',
                'message' => __('Stripe API keys are valid', 'securehold-security-deposit-holds'),
    
                // Add manual test button (nonce protected)
                'action'  => array(
                    'label' => __('Test API Keys', 'securehold-security-deposit-holds'),
                    'url'   => wp_nonce_url(
                        admin_url('admin.php?page=securehold-health&action=securehold_test_api_keys'),
                        'securehold_test_api_keys'
                    )
                )
            );
    
        } catch (\Exception $e) {
            return array(
                'status'  => 'error',
                'message' => __('Stripe API keys are invalid: ', 'securehold-security-deposit-holds') . $e->getMessage(),
    
                // Keep Update Keys as the primary fix action
                'action'  => array(
                    'label' => __('Update Keys', 'securehold-security-deposit-holds'),
                    'url'   => admin_url('admin.php?page=securehold-settings')
                )
            );
        }
    }
    
    /**
     * Check webhook configuration
     */
    public static function check_webhook() {
        $webhook_secret = get_option('securehold_webhook_secret');
        
        if (!empty($webhook_secret)) {
            return array(
                'status'  => 'success',
                'message' => __('Webhook is configured', 'securehold-security-deposit-holds'),
                'action'  => array(
                    'label' => __('Test Webhook', 'securehold-security-deposit-holds'),
                    'url'   => wp_nonce_url(
                        admin_url('admin.php?page=securehold-health&action=securehold_test_webhook'),
                        'securehold_test_webhook'
                    ),
                ),
            );
        }
        
        return array(
            'status' => 'warning',
            'message' => __('Webhook is not configured', 'securehold-security-deposit-holds'),
            'action' => array(
                'label' => __('Auto-Configure', 'securehold-security-deposit-holds'),
                'url' => admin_url('admin.php?page=securehold-setup-wizard&step=4')
            )
        );
    }
    
    /**
     * Check WooCommerce
     */
    public static function check_woocommerce() {
    
        if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_VERSION' ) ) {
            return array(
                'status'  => 'error',
                'message' => __('WooCommerce is not active', 'securehold-security-deposit-holds'),
                'action'  => array(
                    'label' => __('Install WooCommerce', 'securehold-security-deposit-holds'),
                    'url'   => admin_url('plugin-install.php?s=woocommerce&tab=search')
                )
            );
        }
    
        // Customer account settings are optional: SecureHold WP fully supports guest checkout.
        return array(
            'status'  => 'success',
            /* translators: %s is the WooCommerce version number */
            'message' => sprintf( __( 'WooCommerce %s is active', 'securehold-security-deposit-holds' ), WC()->version ),
            'action'  => array(
                'label' => __('Test WooCommerce', 'securehold-security-deposit-holds'),
                'url'   => wp_nonce_url(
                    admin_url('admin.php?page=securehold-health&action=securehold_test_woocommerce'),
                    'securehold_test_woocommerce'
                )
            )
        );
    }
    
    /**
     * Check Stripe Gateway
     */
    public static function check_stripe_gateway() {
    
        $action = array(
            'label' => __('Test Stripe Gateway', 'securehold-security-deposit-holds'),
            'url'   => wp_nonce_url(
                admin_url('admin.php?page=securehold-health&action=securehold_test_stripe_gateway'),
                'securehold_test_stripe_gateway'
            )
        );
    
        // Force WooCommerce to load gateway class files before checking class existence.
        // WooCommerce lazily initializes payment gateways; class_exists( 'WC_Gateway_Stripe' )
        // returns false until payment_gateways() is called. Guards prevent fatal error if WooCommerce is absent.
        if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'payment_gateways' ) ) {
            WC()->payment_gateways()->payment_gateways();
        }

        // Dual detection: by PHP class name OR by gateway ID in the registered list.
        // class_exists( 'WC_Gateway_Stripe' ) can return false on some environments
        // even after payment_gateways() is called (class file not auto-loaded by name).
        // Searching by gateway ID is the reliable fallback — mirrors admin.php diagnostics.
        $found_by_class = class_exists( 'WC_Gateway_Stripe' );
        $found_by_id    = false;
        $enabled        = null;

        if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'payment_gateways' ) ) {
            $pg = WC()->payment_gateways();
            if ( $pg && method_exists( $pg, 'payment_gateways' ) ) {
                foreach ( $pg->payment_gateways() as $gw ) {
                    if ( ! is_object( $gw ) || empty( $gw->id ) ) {
                        continue;
                    }
                    if ( (string) $gw->id === 'stripe' || (string) $gw->id === 'woocommerce_stripe'
                        || stripos( (string) $gw->id, 'stripe' ) !== false ) {
                        $found_by_id = true;
                        $enabled     = ( isset( $gw->enabled ) && $gw->enabled === 'yes' );
                        break;
                    }
                }
            }
        }

        if ( ! $found_by_class && ! $found_by_id ) {
            return array(
                'status'  => 'error',
                'message' => __( 'WooCommerce Stripe Gateway is not active', 'securehold-security-deposit-holds' ),
                'action'  => array(
                    'label' => __( 'Install Stripe Gateway', 'securehold-security-deposit-holds' ),
                    'url'   => admin_url( 'plugin-install.php?s=woocommerce+stripe&tab=search' )
                )
            );
        }

        if ( $enabled === false ) {
            return array(
                'status'  => 'warning',
                'message' => __( 'Stripe Gateway is installed but not enabled in WooCommerce', 'securehold-security-deposit-holds' ),
                'action'  => $action
            );
        }

        return array(
            'status'  => 'success',
            'message' => __( 'WooCommerce Stripe Gateway is available', 'securehold-security-deposit-holds' ),
            'action'  => $action
        );
    }
    
    /**
     * Check database tables
     */
    public static function check_database() {
        global $wpdb;
        
        $table_holds = $wpdb->prefix . 'securehold_holds';
        $table_logs = $wpdb->prefix . 'securehold_logs';
        
        $holds_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_holds ) ) ) === $table_holds;
        $logs_exists  = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_logs ) ) ) === $table_logs;

        if ($holds_exists && $logs_exists) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
            $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table_holds}" );
            
            return array(
                'status' => 'success',
                /* translators: %d is the number of security deposit holds in the database */
                'message' => sprintf(__('Database tables exist (%d holds)', 'securehold-security-deposit-holds'), $count),
                'action' => null
            );
        }
        
        return array(
            'status' => 'error',
            'message' => __('Database tables are missing', 'securehold-security-deposit-holds'),
            'action' => array(
                'label' => __('Recreate Tables', 'securehold-security-deposit-holds'),
                'url' => admin_url('admin.php?page=securehold-settings&recreate_tables=1')
            )
        );
    }
    
    /**
     * Check cron jobs
     */
    public static function check_cron() {
    
        $action = array(
            'label' => __('Run Diagnostics', 'securehold-security-deposit-holds'),
            'url'   => wp_nonce_url(
                admin_url('admin.php?page=securehold-health&action=securehold_test_cron'),
                'securehold_test_cron'
            )
        );
    
        $wp_cron_disabled = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
        $has_handler      = (has_action('securehold_trigger_scheduled_hold') !== false);
    
        if (!$has_handler) {
            return array(
                'status'  => 'error',
                'message' => __('SecureHold cron handler is missing. Scheduled holds cannot run.', 'securehold-security-deposit-holds'),
                'action'  => $action
            );
        }
    
        if ($wp_cron_disabled) {
            return array(
                'status'  => 'warning',
                'message' => __('WP Cron is disabled. Scheduled tasks may rely on server cron.', 'securehold-security-deposit-holds'),
                'action'  => $action
            );
        }
    
        return array(
            'status'  => 'success',
            'message' => __('Scheduled tasks system is available.', 'securehold-security-deposit-holds'),
            'action'  => $action
        );
    }
    
    /**
     * Check file permissions
     */
    public static function check_permissions() {
        $vendor_dir = SECUREHOLD_PLUGIN_DIR . 'vendor';
        
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP < 6.3 fallback
        if (function_exists( 'wp_is_writable' ) ? wp_is_writable( SECUREHOLD_PLUGIN_DIR ) : is_writable(SECUREHOLD_PLUGIN_DIR)) {
            return array(
                'status' => 'success',
                'message' => __('Plugin directory is writable', 'securehold-security-deposit-holds'),
                'action' => null
            );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        
        return array(
            'status' => 'warning',
            'message' => __('Plugin directory is not writable', 'securehold-security-deposit-holds'),
            'action' => array(
                'label' => __('Fix Permissions', 'securehold-security-deposit-holds'),
                'url' => SECUREHOLD_WP_URL_DOCS_TROUBLESHOOTING
            )
        );
    }
    
    /**
     * Check that the deposit transparency notice is active at checkout.
     *
     * The notice is a neutral, non-blocking disclosure shown when a security
     * deposit applies to an order. Disabling it means customers are not informed
     * of the deposit hold at checkout.
     *
     * @since 4.1.0
     * @return array
     */
    public static function check_transparency_notice() {
        // The option defaults to true (enabled) if never explicitly saved.
        $is_active = (bool) get_option( 'securehold_enable_transparency_notice', true );

        if ( $is_active ) {
            return array(
                'status'  => 'success',
                'message' => __( 'Deposit transparency notice is active at checkout.', 'securehold-security-deposit-holds' ),
                'action'  => null,
            );
        }

        return array(
            'status'  => 'warning',
            'message' => __( 'Deposit transparency notice is disabled — customers may not be informed of the security deposit hold at checkout.', 'securehold-security-deposit-holds' ),
            'action'  => array(
                'label' => __( 'Review Settings', 'securehold-security-deposit-holds' ),
                'url'   => admin_url( 'admin.php?page=securehold-settings' ),
            ),
        );
    }

    /**
     * Get overall health status
     */
    public static function get_overall_status($checks) {
        $has_error = false;
        $has_warning = false;
        
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                $has_error = true;
            } elseif ($check['status'] === 'warning') {
                $has_warning = true;
            }
        }
        
        if ($has_error) {
            return 'error';
        } elseif ($has_warning) {
            return 'warning';
        }
        
        return 'success';
    }
}
