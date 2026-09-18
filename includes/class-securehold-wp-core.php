<?php
/**
 * SecureHold Core — Main plugin orchestrator
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Core {

    protected $loader;
    protected $plugin_name;
    protected $version;

    public function __construct() {
        $this->version = SECUREHOLD_VERSION;
        $this->plugin_name = 'securehold-stripe-deposits';
        
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
    }
    
    private function load_dependencies() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-securehold-wp-loader.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/helpers.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/database/class-securehold-wp-db.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-securehold-wp-hold-state.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/stripe/class-securehold-wp-stripe.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/woocommerce/class-securehold-wp-woo.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/cron/class-securehold-wp-scheduler.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-securehold-wp-admin.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'admin/class-securehold-wp-admin-notices.php';
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-securehold-wp-telemetry.php';

        $this->loader = new Securehold_Loader();
    }
    
    private function define_admin_hooks() {
        $plugin_admin = new Securehold_Admin();
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_plugin_admin_menu');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_admin_assets');
        $this->loader->add_action('wp_ajax_securehold_test_config', $plugin_admin, 'ajax_test_config');
        $this->loader->add_action('admin_post_securehold_reset_wizard', $plugin_admin, 'handle_reset_wizard');
        $this->loader->add_action('admin_post_securehold_export_support_bundle', $plugin_admin, 'handle_export_support_bundle');

        // Configuration warnings. The class was loaded but never instantiated,
        // so this notice had never been able to display on any install.
        $plugin_notices = new Securehold_Admin_Notices();
        $this->loader->add_action('admin_notices', $plugin_notices, 'show_configuration_warnings');
        $this->loader->add_action('admin_notices', $plugin_notices, 'show_failed_hold_warning');

        // Opt-in usage telemetry — self-registers its own hooks (notice,
        // AJAX, cron), same pattern as PRO's Securehold_License_Manager.
        new Securehold_Wp_Telemetry();
    }
    
    private function define_public_hooks() {
        // Instantiate SecureHold_Woo so its constructor calls define_public_hooks(),
        // which registers add_filter('woocommerce_email_classes', ...) and other WC hooks.
        // Without this, the WC email classes never appear in WC > Settings > Emails.
        new SecureHold_Woo();

        $scheduler = new Securehold_Scheduler();

        // Ensure auto-release cron is correctly scheduled/unscheduled based on settings
        Securehold_Scheduler::schedule_auto_release_cron();
        
        // 1. Déclencheurs de paiement standard
        $this->loader->add_action('woocommerce_payment_complete', $scheduler, 'create_hold_for_order');
        
        // 2. Déclencheurs de statut (Processing/Completed)
        $this->loader->add_action('woocommerce_order_status_processing', $scheduler, 'create_hold_for_order');
        $this->loader->add_action('woocommerce_order_status_completed', $scheduler, 'create_hold_for_order');
        
        // 3. Déclencheur générique de changement de statut (Pour la stratégie "By Status")
        // Note: create_hold_for_order accepte $order_id en 1er argument, ce qui correspond au hook WC
        $this->loader->add_action('woocommerce_order_status_changed', $scheduler, 'create_hold_for_order', 10, 1);
        
        // Deposit Failure Gate — reacts to hold failures after the Scheduler runs.
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-securehold-wp-deposit-gate.php';
        new Securehold_Deposit_Gate();

        // Webhooks Stripe
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/stripe/class-securehold-wp-webhook.php';
        $webhook = new Securehold_Webhook();
        $this->loader->add_action('rest_api_init', $webhook, 'register_routes');
    }
    
    public function run() {
        $this->loader->run();
    }
    
    public function get_loader() {
        return $this->loader;
    }
}