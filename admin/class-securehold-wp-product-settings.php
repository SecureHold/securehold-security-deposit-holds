<?php
/**
 * Handles product-specific settings logic
 * Version: 3.9.2 - Fix Products List
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Product_Settings {

    /**
     * Get settings for a specific product
     */
    public static function get_settings($product_id) {
        $enabled = get_post_meta($product_id, '_securehold_enabled', true) === 'yes';

        return array(
            'enabled' => $enabled,
            'deposit_amount' => get_post_meta($product_id, '_securehold_deposit_amount', true),
            'capture_timing' => get_post_meta($product_id, '_securehold_capture_timing', true),
            'delay_days' => get_post_meta($product_id, '_securehold_delay_days', true),
            'date_field_key' => get_post_meta($product_id, '_securehold_date_field_key', true),
            'days_before_date' => get_post_meta($product_id, '_securehold_days_before_date', true),
            'scheduled_days' => get_post_meta($product_id, '_securehold_scheduled_days', true),
            'scheduled_direction' => get_post_meta($product_id, '_securehold_scheduled_direction', true),
            'trigger_status' => get_post_meta($product_id, '_securehold_trigger_status', true),
        );
    }

    /**
     * Save settings for a specific product
     */
    public static function save_settings($product_id, $data) {
        // Force enabled to yes if we are saving specific settings
        $enabled = 'yes';
        update_post_meta($product_id, '_securehold_enabled', $enabled);

        update_post_meta($product_id, '_securehold_deposit_amount', sanitize_text_field($data['deposit_amount']));
        update_post_meta($product_id, '_securehold_capture_timing', sanitize_text_field($data['capture_timing']));

        // Optional fields
        if (isset($data['delay_days'])) update_post_meta($product_id, '_securehold_delay_days', sanitize_text_field($data['delay_days']));
        if (isset($data['date_field_key'])) update_post_meta($product_id, '_securehold_date_field_key', sanitize_text_field($data['date_field_key']));
        if (isset($data['days_before_date'])) update_post_meta($product_id, '_securehold_days_before_date', sanitize_text_field($data['days_before_date']));
        if (isset($data['trigger_status'])) update_post_meta($product_id, '_securehold_trigger_status', sanitize_text_field($data['trigger_status']));

        // Scheduled timing fields (new direction system)
        $direction = '';
        if (isset($data['scheduled_direction'])) {
            $direction = sanitize_text_field($data['scheduled_direction']);
            if ($direction !== '' && !in_array($direction, array('before', 'after', 'same_day'), true)) {
                $direction = '';
            }
            update_post_meta($product_id, '_securehold_scheduled_direction', $direction);
        }
        if (isset($data['scheduled_days'])) {
            $days = $data['scheduled_days'];
            if ($days !== '') {
                $days = absint($days);
                if ($days < 1 && $direction !== 'same_day') {
                    $days = 1;
                }
                if ($days > 365) $days = 365;
            }
            update_post_meta($product_id, '_securehold_scheduled_days', sanitize_text_field($days));
        }
    }

    /**
     * Delete settings for a product (Reset to global)
     */
    public static function delete_settings($product_id) {
        delete_post_meta($product_id, '_securehold_enabled');
        delete_post_meta($product_id, '_securehold_deposit_amount');
        delete_post_meta($product_id, '_securehold_capture_timing');
        delete_post_meta($product_id, '_securehold_delay_days');
        delete_post_meta($product_id, '_securehold_date_field_key');
        delete_post_meta($product_id, '_securehold_days_before_date');
        delete_post_meta($product_id, '_securehold_scheduled_days');
        delete_post_meta($product_id, '_securehold_scheduled_direction');
        delete_post_meta($product_id, '_securehold_trigger_status');
    }

    /**
     * Get all products that have custom settings
     * Looks for _securehold_enabled OR _securehold_deposit_amount
     */
    public static function get_products_with_custom_settings() {
        $args = array(
            'post_type'      => array('product', 'product_variation'),
            'posts_per_page' => -1,
            'meta_query'     => array(
                'relation' => 'OR',
                array(
                    'key'     => '_securehold_enabled',
                    'value'   => 'yes',
                    'compare' => '='
                ),
                array(
                    'key'     => '_securehold_deposit_amount',
                    'value'   => '',
                    'compare' => '!='
                )
            ),
            'fields' => 'ids' // Return only IDs for performance
        );

        $query = new WP_Query($args);
        $products = array();

        if ($query->have_posts()) {
            foreach ($query->posts as $product_id) {
                $wc_product = wc_get_product($product_id);
                if ($wc_product) {
                    $settings = self::get_settings($product_id);
                    // Double check to avoid false positives
                    if ($settings['enabled'] || !empty($settings['deposit_amount'])) {
                        $products[] = array(
                            'id' => $product_id,
                            'title' => $wc_product->get_formatted_name(),
                            'settings' => $settings
                        );
                    }
                }
            }
        }

        return $products;
    }
    
    // Fallback for select2 population if needed
    public static function get_all_products() {
        return []; 
    }
}