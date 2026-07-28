<?php
/**
 * WooCommerce integration class
 * Version: 3.3.0 - Clean version (Admin logic moved to Securehold_Admin)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SecureHold_Woo {
    
    public function __construct() {
        $this->define_public_hooks();
    }
    
    public function define_public_hooks() {
        // NOTE: Hold creation hooks (woocommerce_order_status_processing, _completed,
        // _changed, woocommerce_payment_complete) are registered in class-securehold-wp-core.php
        // directly on the Scheduler. Do NOT duplicate them here.
        //
        // Removed: legacy per-product "Security Deposit" WooCommerce tab (predates the
        // Rule Engine). PRO owns per-product configuration via its own centralized
        // Product Rules screen — it never hooks woocommerce_product_data_tabs/_panels.
        // Existing _securehold_deposit_amount postmeta is untouched and still read by
        // Securehold_Product_Settings::get_settings() (PRO UI + resolver).
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout'));
        add_filter('woocommerce_email_classes', array($this, 'add_email_classes'));
        
        // Note: L'affichage admin est maintenant géré par SecureHold_Admin
    }

    public function handle_order_status_change($order_id) {
        // Always call the scheduler — it uses the Config Resolver internally
        // to determine the correct strategy (Product Rule > Category Rule > Global).
        // The scheduler handles ALL strategies: immediate, delayed, scheduled, status, manual.
        if (function_exists('securehold_log')) {
            securehold_log('Order status changed — triggering scheduler', array('order_id' => $order_id), 'debug');
        }

        if (defined('SECUREHOLD_PLUGIN_DIR')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/cron/class-securehold-wp-scheduler.php';
            $scheduler = new Securehold_Scheduler();
            $scheduler->create_hold_for_order($order_id);
        }
    }
    
    public static function get_hold_amount($order) {
        $items = $order->get_items();
        foreach ($items as $item) {
            $product_id = $item->get_product_id();
            $override = get_post_meta($product_id, '_securehold_deposit_amount', true);
            if (!empty($override)) {
                if (strpos($override, '%') !== false) {
                    $percent = floatval(trim(str_replace('%', '', $override)));
                    return ($order->get_total() * $percent) / 100;
                }
                return floatval($override);
            }
        }
        $global_amount = get_option('securehold_default_deposit_amount', 300);
        if (strpos($global_amount, '%') !== false) {
            $percent = floatval(trim(str_replace('%', '', $global_amount)));
            return ($order->get_total() * $percent) / 100;
        }
        return floatval($global_amount);
    }
    
    public function validate_checkout() { }
    
    public function add_email_classes( $email_classes ) {
        if ( ! defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            return $email_classes;
        }

        // ── Debug: confirm filter callback fired (WP_DEBUG only) ─────────
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'securehold_log' ) ) {
            securehold_log( 'add_email_classes: woocommerce_email_classes filter fired', array(
                'wc_email_class_exists' => class_exists( 'WC_Email' ),
                'keys_before'           => array_keys( $email_classes ),
            ), 'debug' );
        }

        // Ensure the Securehold_Emails template/manager class is available.
        $manager_path = SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-emails.php';
        if ( file_exists( $manager_path ) ) {
            require_once $manager_path;
        }

        // Load centralized email manager (gate checks, fire_email entry point).
        $email_manager_path = SECUREHOLD_PLUGIN_DIR . 'includes/emails/class-securehold-email-manager.php';
        if ( file_exists( $email_manager_path ) ) {
            require_once $email_manager_path;
        }

        // Register all WC_Email-based notification classes.
        $email_dir   = SECUREHOLD_PLUGIN_DIR . 'includes/emails/';
        $email_files = array(
            // Customer emails
            'class-securehold-email-deposit-authorized.php'  => 'Securehold_Email_Deposit_Authorized',
            'class-securehold-email-deposit-failed.php'      => 'Securehold_Email_Deposit_Failed',
            'class-securehold-email-deposit-released.php'    => 'Securehold_Email_Deposit_Released',
            'class-securehold-email-deposit-captured.php'    => 'Securehold_Email_Deposit_Captured',
            // Admin notification emails
            'class-securehold-email-admin-hold-created.php'  => 'Securehold_Email_Admin_Hold_Created',
            'class-securehold-email-admin-hold-failed.php'   => 'Securehold_Email_Admin_Hold_Failed',
            'class-securehold-email-admin-hold-captured.php' => 'Securehold_Email_Admin_Hold_Captured',
            'class-securehold-email-admin-hold-released.php' => 'Securehold_Email_Admin_Hold_Released',
        );

        foreach ( $email_files as $file => $class ) {
            $path = $email_dir . $file;
            if ( file_exists( $path ) ) {
                require_once $path;
                if ( class_exists( $class ) ) {
                    $email_classes[ $class ] = new $class();
                } else {
                    // Always log this — it means the file was included but the class
                    // wasn't defined (e.g. WC_Email guard returned early).
                    if ( function_exists( 'securehold_log' ) ) {
                        securehold_log( 'add_email_classes: class not found after require', array(
                            'class'                 => $class,
                            'file'                  => $file,
                            'wc_email_class_exists' => class_exists( 'WC_Email' ),
                        ), 'error' );
                    }
                }
            } else {
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'add_email_classes: email class file missing', array(
                        'path' => $path,
                    ), 'error' );
                }
            }
        }

        // ── Debug: confirm our emails were injected (WP_DEBUG only) ──────
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'securehold_log' ) ) {
            $injected_ids = array();
            foreach ( $email_classes as $key => $obj ) {
                if ( $obj instanceof WC_Email ) {
                    $injected_ids[ $key ] = $obj->id;
                }
            }
            securehold_log( 'add_email_classes: injection complete', array(
                'keys_after'  => array_keys( $email_classes ),
                'id_map'      => $injected_ids,
            ), 'debug' );
        }

        return $email_classes;
    }

    /**
     * Legacy shim — kept for backward compatibility with third-party callers.
     *
     * @deprecated 5.0.0 Use Securehold_Email_Manager::fire_email() instead.
     */
    public static function send_release_email( $order_id, $hold ) {
        if ( class_exists( 'Securehold_Email_Manager' ) ) {
            $context = is_numeric( $hold )
                ? (object) array( 'amount' => floatval( $hold ) )
                : $hold;
            Securehold_Email_Manager::fire_email( 'securehold_deposit_released', $order_id, $context );
            return;
        }

        // Fallback: direct trigger if manager not loaded.
        if ( ! function_exists( 'WC' ) || ! is_callable( array( WC(), 'mailer' ) ) ) {
            return;
        }
        foreach ( WC()->mailer()->get_emails() as $email_obj ) {
            if ( $email_obj instanceof WC_Email && $email_obj->id === 'securehold_deposit_released' ) {
                $email_obj->trigger( $order_id, $hold );
                break;
            }
        }
    }
}