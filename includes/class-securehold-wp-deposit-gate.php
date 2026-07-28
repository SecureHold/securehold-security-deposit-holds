<?php
/**
 * Deposit Gate
 *
 * Two responsibilities:
 *   1. Centralized pre-check: should_require_deposit( $order ) — called by the
 *      Scheduler BEFORE any Stripe attempt to skip holds when the order doesn't
 *      qualify (min cart threshold, all items excluded, zero amount).
 *   2. Post-payment failure reaction: when "Require successful deposit
 *      authorization" is enabled, places the order on-hold if the hold failed.
 *
 * IMPORTANT: Gate must be evaluated before ANY hold creation attempt.
 * Every code path that leads to SecureHold_Stripe::create_payment_intent()
 * or SecureHold_DB::insert_deposit() MUST call should_require_deposit() first.
 *
 * Covered entry points (as of 5.6.0):
 *   - Scheduler non-force path  (create_hold_for_order, $force=false)
 *   - Scheduler force path      (create_hold_for_order, $force=true)
 *   - All WC hooks that invoke the Scheduler:
 *       woocommerce_payment_complete, woocommerce_order_status_processing,
 *       woocommerce_order_status_completed, woocommerce_order_status_changed
 *   - WooCommerce integration   (class-securehold-wp-woo.php)
 *   - Order class triggers      (class-securehold-wp-order.php)
 *   - Diagnostics retry tool    (class-securehold-wp-diagnostics-service.php)
 *
 * @since 5.5.0
 * @since 5.6.0 Added should_require_deposit() centralized gate.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Securehold_Deposit_Gate {

    public function __construct() {
        add_action( 'woocommerce_payment_complete', array( $this, 'check_deposit_after_payment' ), 20 );
    }

    /**
     * Centralized check: should a deposit hold be created for this order?
     *
     * Gate must be evaluated before ANY hold creation attempt.
     *
     * Must be called AFTER config resolution (Computation Service) so that
     * deposit_amount_resolved is available. Returns false to skip hold entirely.
     *
     * Checks performed:
     *   1. Minimum cart amount threshold (wc_format_decimal for locale safety).
     *   2. Product / category exclusions (all line items excluded → no hold).
     *   3. Resolved deposit amount > 0.
     *
     * @since 5.6.0
     * @param WC_Order $order
     * @return bool True = proceed with hold creation. False = skip entirely.
     */
    public static function should_require_deposit( $order ) {
        if ( ! $order ) {
            return false;
        }

        $order_id = $order->get_id();

        // ── 1. Minimum Cart Amount threshold ──
        // Use wc_format_decimal() to handle locale-specific decimal separators
        // (e.g. "100,50" stored from European admin → 100.50).
        $raw_threshold  = get_option( 'securehold_min_cart_amount', '' );
        $threshold      = ( '' !== $raw_threshold && false !== $raw_threshold )
            ? (float) wc_format_decimal( $raw_threshold )
            : 0.0;
        $order_total    = (float) $order->get_total();

        if ( $threshold > 0 && $order_total < $threshold ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Deposit Gate: BLOCKED — order total below minimum cart threshold', array(
                    'order_id'      => $order_id,
                    'order_total'   => $order_total,
                    'threshold'     => $threshold,
                    'raw_threshold' => $raw_threshold,
                ), 'info' );
            }

            // Debug order note (only when WP_DEBUG is active).
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $order->add_order_note( sprintf(
                    /* translators: 1: order total, 2: threshold */
                    'SecureHold Gate DEBUG: blocked by min cart threshold (order_total=%s < threshold=%s)',
                    $order_total,
                    $threshold
                ) );
            }

            return false;
        }

        // ── 2. Product & category exclusions ──
        // If EVERY line item is excluded, no hold should be created.
        if ( ! class_exists( 'Securehold_Deposit_Computation_Service' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/services/class-securehold-wp-computation-service.php';
        }

        if ( class_exists( 'Securehold_Deposit_Computation_Service' ) ) {
            $items = $order->get_items();
            $has_qualifying_item = false;

            foreach ( $items as $item ) {
                $product_id = $item->get_product_id();
                if ( $product_id && ! Securehold_Deposit_Computation_Service::is_product_excluded( $product_id ) ) {
                    $has_qualifying_item = true;
                    break;
                }
            }

            if ( ! $has_qualifying_item && ! empty( $items ) ) {
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Deposit Gate: BLOCKED — all order items are excluded', array(
                        'order_id'    => $order_id,
                        'items_count' => count( $items ),
                    ), 'info' );
                }

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    $order->add_order_note( sprintf(
                        'SecureHold Gate DEBUG: blocked by exclusions (all %d items excluded)',
                        count( $items )
                    ) );
                }

                return false;
            }
        }

        // ── 3. Deposit amount must be > 0 ──
        // Read the resolved amount from order meta (persisted by Scheduler after config snapshot).
        $resolved_meta = $order->get_meta( '_securehold_deposit_amount', true );
        if ( '' !== $resolved_meta && (float) wc_format_decimal( $resolved_meta ) <= 0 ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Deposit Gate: BLOCKED — deposit amount is zero or negative', array(
                    'order_id'       => $order_id,
                    'deposit_amount' => $resolved_meta,
                ), 'info' );
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $order->add_order_note( sprintf(
                    'SecureHold Gate DEBUG: blocked — deposit amount=%s (resolved to 0 or less)',
                    $resolved_meta
                ) );
            }

            return false;
        }

        /**
         * Allow PRO to add advanced gate conditions on top of the FREE gate.
         *
         * PRO uses this to enforce minimum cart thresholds, product/category
         * exclusion lists, and other configurable deposit conditions.
         * Return false to block hold creation.
         *
         * @since 4.5.0
         * @param bool     $allowed  Whether hold creation is allowed (default true).
         * @param WC_Order $order    The WooCommerce order.
         */
        $allowed = apply_filters( 'securehold_deposit_gate', true, $order );

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log(
                $allowed
                    ? 'Deposit Gate: PASSED — hold creation allowed'
                    : 'Deposit Gate: BLOCKED by securehold_deposit_gate filter',
                array(
                    'order_id'    => $order_id,
                    'order_total' => $order_total,
                    'threshold'   => $threshold,
                ),
                $allowed ? 'debug' : 'info'
            );
        }

        return $allowed;
    }

    /**
     * After payment completes, verify that the deposit authorization succeeded.
     *
     * The Scheduler runs at priority 10 and stores failure metadata on the order.
     * This method checks that metadata at priority 20.
     *
     * @param int $order_id
     */
    public function check_deposit_after_payment( $order_id ) {
        // Gate disabled — do nothing.
        if ( get_option( 'securehold_require_deposit_auth', '1' ) !== '1' ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Check if the Scheduler recorded a failure on this order.
        $failure_reason = $order->get_meta( '_securehold_hold_failure_reason', true );
        if ( empty( $failure_reason ) ) {
            return; // No failure (success, or deposit not applicable).
        }

        // Also verify a failed deposit exists in the DB to avoid false positives
        // from stale meta left by previous attempts.
        if ( class_exists( 'SecureHold_DB' ) || $this->load_db_class() ) {
            $deposit = SecureHold_DB::get_deposit( $order_id );
            if ( ! $deposit || $deposit->status !== 'failed' ) {
                return; // Deposit succeeded or is in a non-failed state.
            }
        }

        // ── Deposit failed — place order on hold ──
        $customer_message = __( 'Security deposit authorization failed. Please try another payment method.', 'securehold-security-deposit-holds' );

        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %s = failure reason code */
                __( 'Order placed on hold: security deposit authorization failed (%s). Customer notified.', 'securehold-security-deposit-holds' ),
                $failure_reason
            )
        );

        // Notify the customer via WooCommerce notice (visible on checkout redirect).
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $customer_message, 'error' );
        }

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Deposit Gate: order placed on hold due to deposit failure', array(
                'order_id' => $order_id,
                'failure_reason' => $failure_reason,
            ), 'warning' );
        }
    }

    /**
     * Load the DB class if not already available.
     *
     * @return bool
     */
    private function load_db_class() {
        $db_path = defined( 'SECUREHOLD_PLUGIN_DIR' )
            ? SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php'
            : plugin_dir_path( dirname( __FILE__ ) ) . 'includes/database/class-securehold-wp-db.php';
        if ( file_exists( $db_path ) ) {
            require_once $db_path;
            return class_exists( 'SecureHold_DB' );
        }
        return false;
    }
}
