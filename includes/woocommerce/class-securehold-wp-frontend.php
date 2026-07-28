<?php
/**
 * Frontend Integration - My Account Area
 *
 * Displays security deposits in the WooCommerce My Account area.
 * Uses order-based lookup (not WP user ID) for compatibility with guest-created deposits.
 *
 * @since 4.0.0 - Guest-first: lookup by order_id via customer's orders
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Frontend {

    public function __construct() {
        // Add the endpoint (URL)
        add_action('init', array($this, 'add_security_deposits_endpoint'));

        // Add the tab to the menu
        add_filter('woocommerce_account_menu_items', array($this, 'add_security_deposits_link_my_account'));

        // Display content
        add_action('woocommerce_account_security-deposits_endpoint', array($this, 'security_deposits_content'));
    }

    /**
     * Register new endpoint
     */
    public function add_security_deposits_endpoint() {
        add_rewrite_endpoint('security-deposits', EP_ROOT | EP_PAGES);
    }

    /**
     * Add link to My Account menu
     */
    public function add_security_deposits_link_my_account($items) {
        $new_items = array();
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            // Insert after the "Orders" tab
            if ($key === 'orders') {
                $new_items['security-deposits'] = __('Security Deposits', 'securehold-security-deposit-holds');
            }
        }
        return $new_items;
    }

    /**
     * Render content for the endpoint.
     * Uses order-based lookup for full guest order compatibility.
     */
    public function security_deposits_content() {
        $user_id = get_current_user_id();

        if (!$user_id) {
            echo '<div class="woocommerce-message woocommerce-message--info woocommerce-info">';
            esc_html_e('Please log in to view your security deposits.', 'securehold-security-deposit-holds');
            echo '</div>';
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'securehold_holds';

        // Get deposits via the customer's order IDs (not by WP user_id stored in customer_id column)
        // This ensures we find deposits even if customer_id stores a Stripe customer ID (cus_xxx)
        $order_ids = wc_get_orders(array(
            'customer_id' => $user_id,
            'limit'       => -1,
            'return'      => 'ids',
            'status'      => array('wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending'),
        ));

        $deposits = array();
        if (!empty($order_ids)) {
            $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
            $deposits = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE order_id IN ($placeholders) ORDER BY created_at DESC",
                $order_ids
            ));
        }

        if (empty($deposits)) {
            echo '<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">';
            echo '<a class="woocommerce-Button button" href="' . esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))) . '">' . esc_html__('Browse products', 'securehold-security-deposit-holds') . '</a>';
            esc_html_e('No security deposits found.', 'securehold-security-deposit-holds');
            echo '</div>';
            return;
        }
        ?>
        <h3><?php esc_html_e('My Security Deposits', 'securehold-security-deposit-holds'); ?></h3>
        <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
            <thead>
                <tr>
                    <th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Order', 'securehold-security-deposit-holds'); ?></span></th>
                    <th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Date', 'securehold-security-deposit-holds'); ?></span></th>
                    <th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Status', 'securehold-security-deposit-holds'); ?></span></th>
                    <th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e('Amount', 'securehold-security-deposit-holds'); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deposits as $deposit) :
                    $order = wc_get_order($deposit->order_id);
                    $status_label = ucfirst($deposit->status);
                    if ($deposit->status === 'authorized') $status_label = __('Active (Held)', 'securehold-security-deposit-holds');
                    if ($deposit->status === 'released') $status_label = __('Released', 'securehold-security-deposit-holds');
                    if ($deposit->status === 'captured') $status_label = __('Charged', 'securehold-security-deposit-holds');

                    $status_class = 'processing';
                    if ($deposit->status === 'released') $status_class = 'completed';
                    if ($deposit->status === 'captured') $status_class = 'on-hold';
                    if ($deposit->status === 'failed') $status_class = 'failed';
                ?>
                <tr class="woocommerce-orders-table__row">
                    <td class="woocommerce-orders-table__cell">
                        <a href="<?php echo esc_url($order ? $order->get_view_order_url() : '#'); ?>">
                            #<?php echo esc_html($deposit->order_id); ?>
                        </a>
                    </td>
                    <td class="woocommerce-orders-table__cell">
                        <time datetime="<?php echo esc_attr(gmdate('Y-m-d', strtotime($deposit->created_at))); ?>">
                            <?php echo esc_html(date_i18n(get_option('date_format'), strtotime($deposit->created_at))); ?>
                        </time>
                    </td>
                    <td class="woocommerce-orders-table__cell">
                        <span class="order-status status-<?php echo esc_attr($status_class); ?>" style="padding: 5px 10px; background: #f0f0f0; border-radius: 4px; font-size: 0.85em; font-weight: 600;">
                            <?php echo esc_html($status_label); ?>
                        </span>
                    </td>
                    <td class="woocommerce-orders-table__cell">
                        <?php echo esc_html(securehold_format_currency($deposit->amount, $deposit->currency)); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
