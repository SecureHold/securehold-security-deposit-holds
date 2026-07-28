<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Settings {
    
    public function register_settings() {
        register_setting( 'securehold_settings_group', 'securehold_stripe_mode', array( 'sanitize_callback' => 'sanitize_key' ) );
        register_setting( 'securehold_settings_group', 'securehold_stripe_test_publishable_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_stripe_test_secret_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_stripe_live_publishable_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_stripe_live_secret_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_webhook_secret', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_default_hold_amount', array( 'sanitize_callback' => 'floatval' ) );
        register_setting( 'securehold_settings_group', 'securehold_hold_duration_days', array( 'sanitize_callback' => 'absint' ) );
        register_setting( 'securehold_settings_group', 'securehold_auto_release', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'securehold_settings_group', 'securehold_require_3ds', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'securehold_settings_group', 'securehold_enable_logging', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'securehold_settings_group', 'securehold_stripe_currency', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        
        // --- TIMING STRATEGY ---
        // FREE supports two strategies: Immediate and Manual.
        register_setting( 'securehold_settings_group', 'securehold_capture_timing', array( 'sanitize_callback' => 'sanitize_key' ) ); // immediate, manual

        register_setting( 'securehold_settings_group', 'securehold_enable_checkout_message', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'securehold_settings_group', 'securehold_checkout_message', array( 'sanitize_callback' => 'wp_kses_post' ) );
        register_setting( 'securehold_settings_group', 'securehold_checkout_message_style', array( 'sanitize_callback' => 'sanitize_key' ) );
        register_setting( 'securehold_settings_group', 'securehold_checkout_message_position', array( 'sanitize_callback' => 'sanitize_key' ) );
        register_setting( 'securehold_settings_group', 'securehold_enable_my_account_tab', array( 'sanitize_callback' => 'rest_sanitize_boolean' ) );
        register_setting( 'securehold_settings_group', 'securehold_my_account_menu_label', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_my_account_page_title', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_my_account_page_description', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
        register_setting( 'securehold_settings_group', 'securehold_my_account_empty_message', array( 'sanitize_callback' => 'sanitize_text_field' ) );

        add_settings_section(
            'securehold_stripe_section',
            __('Stripe Configuration', 'securehold-security-deposit-holds'),
            array($this, 'stripe_section_callback'),
            'securehold-settings'
        );
        
        add_settings_field(
            'securehold_stripe_mode',
            __('Stripe Mode', 'securehold-security-deposit-holds'),
            array($this, 'stripe_mode_callback'),
            'securehold-settings',
            'securehold_stripe_section'
        );
        
        add_settings_field(
            'securehold_default_hold_amount',
            __('Default Hold Amount', 'securehold-security-deposit-holds'),
            array($this, 'hold_amount_callback'),
            'securehold-settings',
            'securehold_stripe_section'
        );
        
        add_settings_field(
            'securehold_require_3ds',
            __('Require 3D Secure', 'securehold-security-deposit-holds'),
            array($this, 'require_3ds_callback'),
            'securehold-settings',
            'securehold_stripe_section'
        );
        
        add_settings_field(
            'securehold_enable_logging',
            __('Enable Logging', 'securehold-security-deposit-holds'),
            array($this, 'enable_logging_callback'),
            'securehold-settings',
            'securehold_stripe_section'
        );
    }
    
    public function stripe_section_callback() {
        echo '<p>' . esc_html__( 'Configure your Stripe API keys and security deposit settings.', 'securehold-security-deposit-holds' ) . '</p>';
    }
    
    public function stripe_mode_callback() {
        $mode = get_option('securehold_stripe_mode', 'test');
        ?>
        <select name="securehold_stripe_mode">
            <option value="test" <?php selected($mode, 'test'); ?>><?php esc_html_e('Test Mode', 'securehold-security-deposit-holds'); ?></option>
            <option value="live" <?php selected($mode, 'live'); ?>><?php esc_html_e('Live Mode', 'securehold-security-deposit-holds'); ?></option>
        </select>
        <?php
    }
    
    public function hold_amount_callback() {
        $amount = get_option('securehold_default_hold_amount', 300);
        ?>
        <input type="text" name="securehold_default_hold_amount" value="<?php echo esc_attr($amount); ?>" placeholder="e.g. 300 or 20%">
        <p class="description"><?php esc_html_e('Enter a fixed amount (e.g. 300) OR a percentage (e.g. 20%).', 'securehold-security-deposit-holds'); ?></p>
    <?php
    }
    
    public function require_3ds_callback() {
        $require = get_option('securehold_require_3ds', 'yes');
        ?>
        <label>
            <input type="checkbox" name="securehold_require_3ds" value="yes" <?php checked($require, 'yes'); ?>>
            <?php esc_html_e('Require 3D Secure authentication for holds', 'securehold-security-deposit-holds'); ?>
        </label>
        <?php
    }
    
    public function enable_logging_callback() {
        $logging = get_option('securehold_enable_logging', 'no');
        ?>
        <label>
            <input type="checkbox" name="securehold_enable_logging" value="yes" <?php checked($logging, 'yes'); ?>>
            <?php esc_html_e('Enable debug logging', 'securehold-security-deposit-holds'); ?>
        </label>
        <?php
    }
}