<?php
/**
 * Admin Notices for Configuration Issues
 *
 * The hook is registered by Securehold_Core through Securehold_Loader, the same
 * way every other admin hook is. This class used to register it from its own
 * constructor, which was a second wiring mechanism nobody ever triggered: the
 * file was required but the class was never instantiated, so the notice could
 * not appear at all. Keeping the registration in one place is what stops that
 * happening again.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Admin_Notices {

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
        // Orders carrying a scheduled-strategy error.
        //
        // The previous query joined wp_posts and wp_postmeta directly. Under HPOS
        // orders live in wc_orders and their meta in wc_orders_meta, so on a
        // modern store this returned nothing and the warning never appeared —
        // while the plugin declared HPOS compatibility.
        //
        // Only the meta lookup is branched, because those two table names are the
        // only ones we can rely on. Everything read from the order itself goes
        // through the WooCommerce CRUD, which serves both storage backends.
        $order_ids = self::get_orders_with_scheduled_error();

        $orders = array();
        $cutoff = time() - ( 30 * DAY_IN_SECONDS );

        foreach ( $order_ids as $order_id ) {
            $wc_order = wc_get_order( $order_id );

            if ( ! $wc_order || ! in_array( $wc_order->get_status(), array( 'processing', 'on-hold', 'pending', 'completed' ), true ) ) {
                continue;
            }

            $created = $wc_order->get_date_created();

            if ( ! $created || $created->getTimestamp() < $cutoff ) {
                continue;
            }

            $orders[] = $wc_order;

            if ( count( $orders ) >= 20 ) {
                break;
            }
        }
        
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
                                <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"
                                   style="display: inline-block; padding: 4px 10px; background: white; border: 1px solid #ddd; border-radius: 3px; text-decoration: none; color: #2271b1;">
                                    Order #<?php echo absint($order->get_id()); ?>
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

    /**
     * Order IDs carrying a scheduled-strategy error, newest first.
     *
     * Branches only on the meta table name: wc_orders_meta under HPOS, postmeta
     * otherwise. No order field is read here — the caller uses the WooCommerce
     * CRUD for those, which serves both backends and spares us from hard-coding
     * the HPOS column layout.
     *
     * @since 3.4.4
     * @return int[]
     */
    /**
     * Warn that a deposit was charged for but never placed.
     *
     * Registered separately from show_configuration_warnings(), which only
     * speaks under the scheduled capture strategy. This failure happens under
     * every strategy — staging hit it on 'immediate' — so gating it the same
     * way would hide exactly the case that prompted it.
     *
     * @return void
     */
    public function show_failed_hold_warning() {
        if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

        if ( empty( $current_page ) || strpos( $current_page, 'securehold' ) !== 0 ) {
            return;
        }

        $order_ids = self::get_orders_with_failed_hold();

        $orders = array();
        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $orders[] = $order;
            }
            if ( count( $orders ) >= 20 ) {
                break;
            }
        }

        if ( empty( $orders ) ) {
            return;
        }

        $count = count( $orders );
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'SecureHold: deposits that were never placed', 'securehold-security-deposit-holds' ); ?></strong>
            </p>
            <p>
                <?php
                printf(
                    esc_html(
                        /* translators: %d = number of orders */
                        _n(
                            '%d order was paid with a security deposit due, but the hold could not be created.',
                            '%d orders were paid with a security deposit due, but the hold could not be created.',
                            $count,
                            'securehold-security-deposit-holds'
                        )
                    ),
                    (int) $count
                );
                ?>
            </p>
            <ul>
                <?php foreach ( $orders as $order ) :
                    $failure = $order->get_meta( '_securehold_hold_failed' );
                    $reason  = is_array( $failure ) && isset( $failure['code'] ) ? $failure['code'] : '';
                    ?>
                    <li>
                        <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                            <?php printf( esc_html__( 'Order #%d', 'securehold-security-deposit-holds' ), (int) $order->get_id() ); ?>
                        </a>
                        <?php if ( $reason ) : ?>
                            &mdash; <code><?php echo esc_html( $reason ); ?></code>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Orders where a deposit was due and never got created.
     *
     * Distinct from the scheduled-strategy warning above: that one is about a
     * missing or invalid date, this one is about a hold that failed outright.
     * Staging produced it on the Blocks checkout, where the order was charged
     * in full and nothing on screen said the deposit was absent.
     *
     * @return int[]
     */
    private static function get_orders_with_failed_hold() {
        global $wpdb;

        $hpos = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
            && method_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' )
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos ) {
            $rows = $wpdb->get_col(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta
                 WHERE meta_key = '_securehold_hold_failed'
                 ORDER BY order_id DESC
                 LIMIT 100"
            );
        } else {
            $rows = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_securehold_hold_failed'
                 ORDER BY post_id DESC
                 LIMIT 100"
            );
        }

        return array_map( 'absint', (array) $rows );
    }

    private static function get_orders_with_scheduled_error() {
        global $wpdb;

        $hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
            && method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
            && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos ) {
            $rows = $wpdb->get_col(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}wc_orders_meta
                 WHERE meta_key = '_securehold_scheduled_error'
                 AND meta_value IN ('missing_date', 'invalid_date')
                 ORDER BY order_id DESC
                 LIMIT 100"
            );
        } else {
            $rows = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_securehold_scheduled_error'
                 AND meta_value IN ('missing_date', 'invalid_date')
                 ORDER BY post_id DESC
                 LIMIT 100"
            );
        }

        return array_map( 'absint', (array) $rows );
    }

}