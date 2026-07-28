<?php
/**
 * Plugin Installer - Automatically install required WordPress plugins
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Plugin_Installer {
    
    /**
     * Required plugins configuration
     */
    private static $required_plugins = array(
        'woocommerce' => array(
            'name' => 'WooCommerce',
            'slug' => 'woocommerce',
            'file' => 'woocommerce/woocommerce.php',
            'download_url' => 'https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip',
            'required' => true
        ),
        'stripe' => array(
            'name' => 'WooCommerce Stripe Payment Gateway',
            'slug' => 'woocommerce-gateway-stripe',
            'file' => 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php',
            'download_url' => 'https://downloads.wordpress.org/plugin/woocommerce-gateway-stripe.latest-stable.zip',
            'required' => true
        )
    );
    
    /**
     * Check if a plugin is installed
     */
    public static function is_plugin_installed($plugin_slug) {
        $plugin_config = self::$required_plugins[$plugin_slug];
        $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_config['file'];
        
        return file_exists($plugin_path);
    }
    
    /**
     * Check if a plugin is active
     */
    public static function is_plugin_active($plugin_slug) {
        $plugin_config = self::$required_plugins[$plugin_slug];
        
        return is_plugin_active($plugin_config['file']);
    }
    
    /**
     * Get plugin status
     */
    public static function get_plugin_status($plugin_slug) {
        $installed = self::is_plugin_installed($plugin_slug);
        $active = $installed ? self::is_plugin_active($plugin_slug) : false;
        
        return array(
            'installed' => $installed,
            'active' => $active,
            'status' => $active ? 'active' : ($installed ? 'inactive' : 'not_installed')
        );
    }
    
    /**
     * Install a plugin from WordPress.org
     */
    public static function install_plugin($plugin_slug) {
        if (!current_user_can('install_plugins')) {
            return new WP_Error('permission_denied', __('You do not have permission to install plugins.', 'securehold-security-deposit-holds'));
        }
        
        $plugin_config = self::$required_plugins[$plugin_slug];
        
        // Check if already installed
        if (self::is_plugin_installed($plugin_slug)) {
            return array('success' => true, 'message' => __('Plugin already installed.', 'securehold-security-deposit-holds'));
        }
        
        // Load WordPress plugin install functions
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        
        // Get plugin info from WordPress.org
        $api = plugins_api('plugin_information', array(
            'slug' => $plugin_config['slug'],
            'fields' => array('short_description' => false, 'sections' => false, 'requires' => false, 'rating' => false, 'ratings' => false, 'downloaded' => false, 'last_updated' => false, 'added' => false, 'tags' => false, 'compatibility' => false, 'homepage' => false, 'donate_link' => false)
        ));
        
        if (is_wp_error($api)) {
            /* translators: %s is the plugin name */
            return new WP_Error('plugin_not_found', sprintf(__('Could not find plugin %s on WordPress.org.', 'securehold-security-deposit-holds'), $plugin_config['name']));
        }
        
        // Install plugin
        $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
        $install_result = $upgrader->install($api->download_link);
        
        if (is_wp_error($install_result)) {
            return $install_result;
        }
        
        if (!$install_result) {
            /* translators: %s is the plugin name */
            return new WP_Error('install_failed', sprintf(__('Failed to install %s.', 'securehold-security-deposit-holds'), $plugin_config['name']));
        }

        /* translators: %s is the plugin name */
        return array('success' => true, 'message' => sprintf(__('%s installed successfully!', 'securehold-security-deposit-holds'), $plugin_config['name']));
    }
    
    /**
     * Activate a plugin
     */
    public static function activate_plugin($plugin_slug) {
        if (!current_user_can('activate_plugins')) {
            return new WP_Error('permission_denied', __('You do not have permission to activate plugins.', 'securehold-security-deposit-holds'));
        }
        
        $plugin_config = self::$required_plugins[$plugin_slug];
        
        // Check if installed
        if (!self::is_plugin_installed($plugin_slug)) {
            /* translators: %s is the plugin name */
            return new WP_Error('not_installed', sprintf(__('%s is not installed.', 'securehold-security-deposit-holds'), $plugin_config['name']));
        }
        
        // Check if already active
        if (self::is_plugin_active($plugin_slug)) {
            return array('success' => true, 'message' => __('Plugin already active.', 'securehold-security-deposit-holds'));
        }
        
        // Activate plugin
        $result = activate_plugin($plugin_config['file']);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        /* translators: %s is the plugin name */
        return array('success' => true, 'message' => sprintf(__('%s activated successfully!', 'securehold-security-deposit-holds'), $plugin_config['name']));
    }
    
    /**
     * Install and activate a plugin
     */
    public static function install_and_activate($plugin_slug) {
        // Install if needed
        if (!self::is_plugin_installed($plugin_slug)) {
            $install_result = self::install_plugin($plugin_slug);
            
            if (is_wp_error($install_result)) {
                return $install_result;
            }
        }
        
        // Activate if needed
        if (!self::is_plugin_active($plugin_slug)) {
            $activate_result = self::activate_plugin($plugin_slug);
            
            if (is_wp_error($activate_result)) {
                return $activate_result;
            }
        }
        
        return array('success' => true, 'message' => __('Plugin installed and activated successfully!', 'securehold-security-deposit-holds'));
    }
    
    /**
     * Check all required plugins status
     */
    public static function check_all_requirements() {
        $status = array();
        
        foreach (self::$required_plugins as $slug => $config) {
            $status[$slug] = self::get_plugin_status($slug);
            $status[$slug]['name'] = $config['name'];
        }
        
        return $status;
    }
    
    /**
     * Are all required plugins active?
     */
    public static function all_requirements_met() {
        foreach (self::$required_plugins as $slug => $config) {
            if (!self::is_plugin_active($slug)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get required plugins list
     */
    public static function get_required_plugins() {
        return self::$required_plugins;
    }
}
