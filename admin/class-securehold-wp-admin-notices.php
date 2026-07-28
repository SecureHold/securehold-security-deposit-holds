<?php
/**
 * Admin Notices for Configuration Issues
 * Version: 2.1.0 - Direct hook registration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Admin_Notices {
    
    public function __construct() {
        add_action('admin_notices', array($this, 'show_configuration_warnings'));
    }
    
    public function show_configuration_warnings() {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }
        
        $current_page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        
        if (empty($current_page) || strpos($current_page, 'securehold') !== 0) {
            return;
        }
        
        $strategy = get_option('securehold_capture_timing', 'immediate');
        if ($strategy !== 'scheduled') {
            return;
        }
        
        global $wpdb;
        $meta_key = get_option('securehold_date_field_key', '_checkout_date');
        
        // Look for orders with _securehold_scheduled_error meta (orders that failed scheduled strategy)
        $orders = $wpdb->get_results("
            SELECT DISTINCT p.ID, p.post_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('wc-processing', 'wc-on-hold', 'wc-pending', 'wc-completed')
            AND p.post_date > DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND pm.meta_key = '_securehold_scheduled_error'
            AND pm.meta_value IN ('missing_date', 'invalid_date')
            ORDER BY p.post_date DESC
            LIMIT 20
        ");
        
        if (empty($orders)) {
            return;
        }
        
        $count = count($orders);
        $settings_url = admin_url('admin.php?page=securehold-settings');
        ?>
        
        <div class="securehold-config-alert" style="background: #fff3cd; border-left: 4px solid #ff9800; padding: 15px 20px; margin: 20px 0; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <div style="display: flex; align-items: flex-start; gap: 15px;">
                <div style="flex-shrink: 0; font-size: 24px; color: #ff9800;">⚠️</div>
                <div style="flex: 1;">
                    <h3 style="margin: 0 0 10px 0; color: #856404; font-size: 16px;">
                        <strong>⚠️ Scheduled Strategy Error: Missing Date Field</strong>
                    </h3>
                    <p style="margin: 0 0 10px 0; color: #856404;">
                        <strong><?php echo absint($count); ?> order<?php echo $count > 1 ? 's' : ''; ?></strong>
                        could not create security deposits because the required date field 
                        "<strong><?php echo esc_html($meta_key); ?></strong>" is missing or invalid.
                    </p>
                    <p style="margin: 0 0 10px 0; color: #d63301;">
                        <strong>No deposits were created for these orders.</strong> 
                        Change your strategy or add the required date field to your checkout.
                    </p>
                    
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #856404; display: block; margin-bottom: 5px;">Affected Orders:</strong>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                            <?php foreach (array_slice($orders, 0, 10) as $order) : ?>
                                <a href="<?php echo esc_url( admin_url('post.php?post=' . absint($order->ID) . '&action=edit') ); ?>"
                                   style="display: inline-block; padding: 4px 10px; background: white; border: 1px solid #ddd; border-radius: 3px; text-decoration: none; color: #2271b1;">
                                    Order #<?php echo absint($order->ID); ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if ($count > 10) : ?>
                                <span style="padding: 4px 10px; color: #856404;">+<?php echo absint($count - 10); ?> more</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url($settings_url); ?>" class="button button-primary" style="background: #ff9800; border-color: #ff9800;">
                            Change Strategy
                        </a>
                        <a href="<?php echo esc_url( admin_url('edit.php?post_type=shop_order') ); ?>" class="button">
                            View Orders
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}