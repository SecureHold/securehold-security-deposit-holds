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
            if ( ! $order || self::is_failure_dismissed( $order ) ) {
                continue;
            }
            $orders[] = $order;
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
                        <?php
                        $dismiss_url = wp_nonce_url(
                            admin_url( 'admin-post.php?action=securehold_dismiss_hold_failure&order_id=' . $order->get_id() ),
                            'securehold_dismiss_hold_failure_' . $order->get_id()
                        );
                        ?>
                        &mdash;
                        <a href="<?php echo esc_url( $dismiss_url ); ?>"
                           style="font-size:12px;"
                           onclick="return confirm('<?php echo esc_js( __( 'Dismiss this notice for this order? The error stays visible on the order and in the logs — only this banner entry is hidden.', 'securehold-security-deposit-holds' ) ); ?>');">
                            <?php esc_html_e( 'Dismiss', 'securehold-security-deposit-holds' ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Identity of a '_securehold_hold_failed' occurrence, used to tell "the
     * failure just dismissed" apart from "a new one that happens to look
     * similar".
     *
     * Since 3.5.0, record_hold_failure() stamps a 'failure_id' (wp_generate_uuid4())
     * fresh on every call — that is the identity, full stop: two failures
     * can never share one, however close in time or however identical their
     * code/detail (Multi-Hold and the fast-path retry both make that a real
     * scenario, not a theoretical one).
     *
     * Legacy fallback only: an order that failed before this field existed
     * has no 'failure_id' and is never migrated (per instructions). For that
     * case alone, an md5(at|code|detail) fingerprint stands in — still not
     * perfectly collision-proof at second precision, but no worse than
     * dismissing did before 'failure_id' existed, and it only ever applies
     * to a failure recorded before this release.
     *
     * @since 3.5.0
     * @param array $failure The '_securehold_hold_failed' value.
     * @return string Empty string when there is nothing to identify.
     */
    private static function failure_identity( $failure ) {
        if ( ! is_array( $failure ) || empty( $failure['at'] ) ) {
            return '';
        }

        if ( ! empty( $failure['failure_id'] ) ) {
            return 'id:' . $failure['failure_id'];
        }

        // Legacy failure recorded before 'failure_id' existed.
        return 'fp:' . md5(
            $failure['at'] . '|' .
            ( isset( $failure['code'] ) ? $failure['code'] : '' ) . '|' .
            ( isset( $failure['detail'] ) ? $failure['detail'] : '' )
        );
    }

    /**
     * AJAX-free acknowledgment for one order's current hold-failure entry on
     * the banner above. Never deletes anything: it stamps the identity of
     * which exact failure was acknowledged (see failure_identity()), so the
     * banner can compare "current failure" against "last dismissed failure"
     * and only hide a match. Any later call to record_hold_failure() mints a
     * brand new 'failure_id' and therefore resurfaces automatically — see
     * is_failure_dismissed().
     *
     * @since 3.5.0
     * @return void
     */
    public function handle_dismiss_hold_failure() {
        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

        check_admin_referer( 'securehold_dismiss_hold_failure_' . $order_id );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'securehold-security-deposit-holds' ), 403 );
        }

        $order = $order_id ? wc_get_order( $order_id ) : false;

        if ( $order ) {
            $identity = self::failure_identity( $order->get_meta( '_securehold_hold_failed' ) );

            // Nothing to acknowledge (already resolved / never failed) — a
            // no-op, not an error, so a stale link never wp_die()s.
            if ( '' !== $identity ) {
                $order->update_meta_data( '_securehold_hold_failed_dismissed_id', $identity );
                $order->save();
            }
        }

        // wp_get_referer() only ever returns this site's own admin referer
        // (WordPress validates it internally); wp_safe_redirect() checks the
        // host again regardless. Neither reads an arbitrary user-supplied
        // redirect_to-style URL.
        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=securehold' ) );
        exit;
    }

    /**
     * Whether this order's CURRENT '_securehold_hold_failed' entry is the
     * exact one an admin already dismissed.
     *
     * Compared by failure_identity() — the 'failure_id' UUID minted fresh by
     * every record_hold_failure() call (legacy fingerprint fallback only for
     * a failure recorded before 3.5.0). record_hold_failure() overwrites
     * '_securehold_hold_failed' wholesale on every failure, so any later
     * failure carries a new identity and the banner is never blindly
     * suppressed for a new problem.
     *
     * @param WC_Order $order
     * @return bool
     */
    private static function is_failure_dismissed( $order ) {
        $identity = self::failure_identity( $order->get_meta( '_securehold_hold_failed' ) );

        if ( '' === $identity ) {
            return false; // Nothing to identify — never hide blindly.
        }

        return $order->get_meta( '_securehold_hold_failed_dismissed_id' ) === $identity;
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