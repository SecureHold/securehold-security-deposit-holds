<?php
/**
 * Setup Modal - Auto-opens on first plugin activation or first order
 * Version 3.0.2 - FIXED
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Setup_Modal {
    
    public function __construct() {
        // Modal functionality disabled in v3.0.4 - using dashboard overlay instead
        // add_action('admin_enqueue_scripts', array($this, 'enqueue_modal_assets'));
        // add_action('admin_footer', array($this, 'render_modal'));
        add_action('wp_ajax_securehold_dismiss_setup', array($this, 'dismiss_setup'));
    }
    
    /**
     * Enqueue modal assets - DISABLED
     */
    public function enqueue_modal_assets($hook) {
        // Disabled in v3.0.4 - using overlay on dashboard instead
        return;
    }
    
    /**
     * Render modal HTML - DISABLED
     */
    public function render_modal() {
        // Disabled in v3.0.4 - using overlay on dashboard instead
        return;
    }

    /**
     * Handle dismiss setup
     */
    public function dismiss_setup() {
        check_ajax_referer('securehold_modal_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(array('message' => 'Permission denied'));

        update_option('securehold_setup_dismissed', true);
        
        wp_send_json_success(array(
            'message' => __('Setup wizard dismissed. You can start it anytime from the SecureHold WP menu.', 'securehold-security-deposit-holds')
        ));
    }
}
