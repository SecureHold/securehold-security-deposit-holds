<?php
/**
 * Hold scheduling and auto-release engine.
 *
 * Orchestrates hold creation across all timing strategies (immediate, delayed,
 * scheduled, status-based) and manages the WP-Cron job for auto-releasing
 * expired holds before Stripe's 7-day PaymentIntent expiration.
 *
 * @package SecureHold_WP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Scheduler {

    /**
     * Register WP-Cron action hooks.
     *
     * @since 1.0.0
     */
    public function __construct() {
        add_action('securehold_trigger_scheduled_hold', array($this, 'execute_force_hold'));
        add_action('securehold_auto_release_cron', array($this, 'process_auto_release'));
    }

    /**
     * Schedule or unschedule the recurring auto-release cron based on the feature toggle.
     *
     * Called on plugin activation and on each admin page load as a safety net.
     * The cron hook is 'securehold_auto_release_cron', running twice daily.
     *
     * @since 2.0.0
     *
     * @return void
     */
    public static function schedule_auto_release_cron() {
        $auto_release = get_option('securehold_auto_release', 'no');

        if ($auto_release === 'yes') {
            if (!wp_next_scheduled('securehold_auto_release_cron')) {
                wp_schedule_event(time(), 'twicedaily', 'securehold_auto_release_cron');
                if (function_exists('securehold_log')) {
                    securehold_log('Auto-release cron scheduled (twicedaily)', array(), 'debug');
                }
            }
        } else {
            // If disabled, clear the cron
            $timestamp = wp_next_scheduled('securehold_auto_release_cron');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'securehold_auto_release_cron');
                if (function_exists('securehold_log')) {
                    securehold_log('Auto-release cron unscheduled (feature disabled)', array(), 'debug');
                }
            }
        }
    }

    /**
     * Cron callback: release all authorized holds that have passed their expiration date.
     *
     * Queries the database for expired holds, cancels each one on Stripe,
     * updates the DB row to 'released', and fires the release email notification.
     *
     * @since 2.0.0
     *
     * @return void
     */
    public function process_auto_release() {
        $auto_release = get_option('securehold_auto_release', 'no');
        if ($auto_release !== 'yes') {
            return;
        }

        if (!class_exists('SecureHold_DB') || !class_exists('SecureHold_Stripe')) {
            if (function_exists('securehold_log')) {
                securehold_log('Auto-release: Required classes not available', array(), 'error');
            }
            return;
        }

        $expiring_holds = SecureHold_DB::get_expiring_holds();

        if (empty($expiring_holds)) {
            if (function_exists('securehold_log')) {
                securehold_log('Auto-release cron: No expired holds found', array(), 'debug');
            }
            return;
        }

        if (function_exists('securehold_log')) {
            securehold_log('Auto-release cron: Processing expired holds', array('count' => count($expiring_holds)), 'info');
        }

        foreach ($expiring_holds as $hold) {
            $this->release_single_hold($hold);
        }
    }

    /**
     * Release a single hold: cancel on Stripe, update DB, notify.
     *
     * @since 2.0.0
     *
     * @param object $hold  Database row from securehold_holds.
     * @return bool          True on success, false on failure.
     */
    public function release_single_hold($hold) {
        $intent_id = $hold->intent_id;
        $order_id = $hold->order_id;

        // Skip fake/pending intents (manual mode placeholders)
        if (strpos($intent_id, 'pending_manual_') === 0 || strpos($intent_id, 'failed_') === 0) {
            if (function_exists('securehold_log')) {
                securehold_log('Auto-release: Skipping non-Stripe intent', array('intent_id' => $intent_id, 'order_id' => $order_id), 'debug');
            }
            return false;
        }

        // Cancel on Stripe
        $result = SecureHold_Stripe::cancel_payment_intent($intent_id);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            // If already canceled on Stripe, just update our DB
            if ($error_code === 'already_canceled') {
                SecureHold_DB::update_hold($hold->id, array(
                    'status' => 'released',
                    'released_at' => current_time('mysql'),
                    'notes' => __('Auto-released (already canceled on Stripe).', 'securehold-security-deposit-holds')
                ));
                return true;
            }

            // If already captured, mark accordingly
            if ($error_code === 'already_captured') {
                SecureHold_DB::update_hold($hold->id, array(
                    'status' => 'captured',
                    'notes' => __('Was already captured on Stripe when auto-release attempted.', 'securehold-security-deposit-holds')
                ));
                return false;
            }

            if (function_exists('securehold_log')) {
                securehold_log('Auto-release failed', array(
                    'order_id' => $order_id,
                    'intent_id' => $intent_id,
                    'error' => $result->get_error_message()
                ), 'error');
            }

            // Add order note about failure
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(sprintf(
                    'SecureHold WP: Auto-release failed - %s',
                    $result->get_error_message()
                ));
            }
            return false;
        }

        // Success: update DB
        SecureHold_DB::update_hold($hold->id, array(
            'status' => 'released',
            'released_at' => current_time('mysql'),
            'notes' => __('Automatically released before Stripe expiration.', 'securehold-security-deposit-holds')
        ));

        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $formatted_amount = wc_price($hold->amount, array('currency' => $hold->currency));
            $order->add_order_note(sprintf(
                'SecureHold WP: Security deposit of %s automatically released (Stripe authorization expired).',
                wp_strip_all_tags($formatted_amount)
            ));
        }

        // Notify the customer that their security deposit has been released.
        self::fire_email( 'securehold_deposit_released', $order_id, $hold );

        // Notify the admin that the hold was auto-released.
        self::fire_email( 'securehold_admin_hold_released', $order_id, $hold );

        if (function_exists('securehold_log')) {
            securehold_log('Auto-released hold', array(
                'order_id' => $order_id,
                'intent_id' => $intent_id,
                'amount' => $hold->amount
            ), 'info');
        }

        return true;
    }

    /**
     * Cron callback: execute a previously deferred hold for an order.
     *
     * Fired by the 'securehold_trigger_scheduled_hold' single cron event.
     *
     * @since 2.0.0
     *
     * @param int $order_id  WooCommerce order ID.
     * @return void
     */
    public function execute_force_hold($order_id) {
        // Downgrade safety: a deferred hold scheduled by PRO must not run in FREE if PRO
        // automations are no longer active. Skip cleanly without touching the DB row or
        // calling Stripe, so it can be re-processed if PRO is re-enabled.
        $sh_saved_strategy = '';
        $sh_order          = wc_get_order( $order_id );
        if ( $sh_order ) {
            $sh_saved_strategy = $sh_order->get_meta( '_securehold_timing_strategy', true );
        }
        if ( ! $sh_saved_strategy ) {
            $sh_saved_strategy = get_post_meta( $order_id, '_securehold_timing_strategy', true );
        }
        if ( in_array( $sh_saved_strategy, array( 'delayed', 'scheduled', 'status' ), true )
            && ! securehold_pro_automations_enabled() ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log(
                    'execute_force_hold: PRO automations not active — skipping deferred hold.',
                    array( 'order_id' => $order_id, 'strategy' => $sh_saved_strategy ),
                    'warning'
                );
            }
            return;
        }

        if (function_exists('securehold_log')) {
            securehold_log('Cron: Executing scheduled hold', array('order_id' => $order_id), 'info');
        }

        // Update the scheduled placeholder to "executing" state
        if (class_exists('SecureHold_DB')) {
            $existing = SecureHold_DB::get_deposit($order_id);
            if ($existing && $existing->status === 'scheduled') {
                SecureHold_DB::update_hold($existing->id, array(
                    'notes' => __('Cron triggered — creating hold now...', 'securehold-security-deposit-holds'),
                ));
            }
        }

        // Update order meta
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_securehold_deposit_status', 'executing');
            $order->save();
        }

        $this->create_hold_for_order($order_id, true);
    }

    /**
     * Main entry point for hold creation across all timing strategies.
     *
     * Evaluates the configured strategy (immediate, delayed, scheduled, status-based),
     * checks the deposit gate, and routes to the correct creation path.
     * Includes a 60-second deduplication guard to prevent double execution on the same order.
     *
     * @since 1.0.0
     *
     * @param int  $order_id        WooCommerce order ID.
     * @param bool $force_execution Skip the deduplication guard and re-execute immediately.
     *                              Used by execute_force_hold() when a cron-deferred hold fires.
     * @return bool|void  True if the hold was skipped (already in a final state), void otherwise.
     */
    public function create_hold_for_order($order_id, $force_execution = false) {

        $order = wc_get_order($order_id);
        if (!$order) return false;

        // ── Entry point log ──
        if (function_exists('securehold_log')) {
            securehold_log('Scheduler: create_hold_for_order CALLED', array(
                'order_id'        => $order_id,
                'force_execution' => $force_execution,
                'order_status'    => $order->get_status(),
                'payment_method'  => $order->get_payment_method(),
            ), 'debug');
        }

        if (!$force_execution) {
            $attempt_timestamp = $order->get_meta('_securehold_attempt_made', true);

            if (!empty($attempt_timestamp) && (time() - (int)$attempt_timestamp) < 60) {
                if (function_exists('securehold_log')) {
                    securehold_log('Scheduler: Skipped (dedup < 60s)', array('order_id' => $order_id), 'debug');
                }
                return true;
            }

            $order->update_meta_data('_securehold_attempt_made', time());
            $order->save();
        }

        if (class_exists('SecureHold_DB')) {
            $existing = SecureHold_DB::get_deposit($order_id);
            if ($existing && in_array($existing->status, array('authorized', 'captured', 'released'))) {
                if (function_exists('securehold_log')) {
                    securehold_log('Scheduler: Skipped (deposit already final)', array(
                        'order_id' => $order_id,
                        'status'   => $existing->status,
                    ), 'debug');
                }
                return true;
            }
            // 'scheduled', 'pending_manual', 'pending', 'failed' entries are non-final — continue
        }

        $payment_method = $order->get_payment_method();
        if (strpos($payment_method, 'stripe') === false) {
            if (function_exists('securehold_log')) {
                securehold_log('Scheduler: Skipped (not Stripe)', array(
                    'order_id'       => $order_id,
                    'payment_method' => $payment_method,
                ), 'debug');
            }
            return false;
        }

        if (!$force_execution) {
            // ── Resolve configuration via Computation Service (aggregation-aware) ──
            // Delegates to Config Resolver in per_order mode; aggregates per-item in per_item_aggregated mode.
            if (!class_exists('Securehold_Deposit_Computation_Service') && defined('SECUREHOLD_PLUGIN_DIR')) {
                require_once SECUREHOLD_PLUGIN_DIR . 'includes/services/class-securehold-wp-computation-service.php';
            }
            if (!class_exists('Securehold_Config_Resolver') && defined('SECUREHOLD_PLUGIN_DIR')) {
                require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-config-resolver.php';
            }

            $config = Securehold_Deposit_Computation_Service::compute($order);
            $strategy = $config['timing'];

            // ── Persist FULL configuration snapshot for frozen history display ──
            // Every value displayed in "Applied Configuration" is stored at creation
            // time so the block is 100 % deterministic and never reads live settings.
            $resolved_aggregation = isset( $config['aggregation_mode'] ) ? $config['aggregation_mode'] : 'per_order';
            $resolved_policy      = isset( $config['policy'] ) ? $config['policy'] : 'priority_chain';

            // Core triad
            $order->update_meta_data( '_securehold_aggregation_mode', $resolved_aggregation );
            $order->update_meta_data( '_securehold_rule_policy', $resolved_policy );
            $order->update_meta_data( '_securehold_timing_strategy', $strategy );

            // Source provenance
            $order->update_meta_data( '_securehold_source', $config['source'] );
            $order->update_meta_data( '_securehold_source_id', (string) $config['source_id'] );
            $order->update_meta_data( '_securehold_source_label', $config['source_label'] );

            // Deposit amount (raw config string, e.g. "300" or "10%")
            $order->update_meta_data( '_securehold_deposit_amount', $config['deposit_amount'] );

            // Strategy-specific parameters
            $order->update_meta_data( '_securehold_delay_days', $config['delay_days'] );
            $order->update_meta_data( '_securehold_date_field_key', $config['date_field_key'] );
            $order->update_meta_data( '_securehold_scheduled_days', $config['scheduled_days'] );
            $order->update_meta_data( '_securehold_scheduled_direction', $config['scheduled_direction'] );
            $order->update_meta_data( '_securehold_trigger_status', $config['trigger_status'] );

            // Operational settings snapshotted at creation time
            $order->update_meta_data( '_securehold_auto_release_days', get_option( 'securehold_auto_release_days', '7' ) );
            $order->update_meta_data( '_securehold_stripe_mode', get_option( 'securehold_stripe_mode', 'test' ) );

            // Per-item specific: persist breakdown and winner.
            if ( 'per_item_aggregated' === $resolved_aggregation && ! empty( $config['item_breakdown'] ) ) {
                $order->update_meta_data( '_securehold_item_breakdown', $config['item_breakdown'] );
                if ( ! empty( $config['winner_item'] ) ) {
                    $order->update_meta_data( '_securehold_winner_item', $config['winner_item'] );
                }
            }

            $order->save();

            // Debug logging for full snapshot persistence
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Aggregation snapshot persisted', array(
                    'order_id'         => $order_id,
                    'aggregation_mode' => $resolved_aggregation,
                    'rule_policy'      => $resolved_policy,
                    'final_strategy'   => $strategy,
                    'source'           => $config['source'],
                    'deposit_amount'   => $config['deposit_amount'],
                ), 'debug' );
            }

            if (function_exists('securehold_log')) {
                securehold_log('Scheduler: Config resolved (single source)', array(
                    'order_id'        => $order_id,
                    'source'          => $config['source'],
                    'source_id'       => $config['source_id'],
                    'source_label'    => $config['source_label'],
                    'strategy'        => $strategy,
                    'amount_raw'      => $config['deposit_amount'],
                    'amount_resolved' => $config['deposit_amount_resolved'],
                    'aggregation'     => isset( $config['aggregation_mode'] ) ? $config['aggregation_mode'] : 'per_order',
                    'fallbacks'       => isset($config['fallbacks']) ? $config['fallbacks'] : array(),
                ), 'debug');
            }

            // ── Centralized Deposit Gate (non-force path) ──
            // Gate must be evaluated before ANY hold creation attempt.
            // Checks: min cart threshold (wc_format_decimal), product/category exclusions, amount > 0.
            if ( ! class_exists( 'Securehold_Deposit_Gate' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
                require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-deposit-gate.php';
            }
            if ( class_exists( 'Securehold_Deposit_Gate' ) && ! Securehold_Deposit_Gate::should_require_deposit( $order ) ) {
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Scheduler: GATE BLOCKED — deposit skipped (non-force path)', array(
                        'order_id' => $order_id,
                    ), 'info' );
                }
                $order->add_order_note(
                    __( 'SecureHold WP: Security deposit skipped — order does not meet deposit requirements (threshold, exclusions, or zero amount).', 'securehold-security-deposit-holds' )
                );
                return true;
            }

            /**
             * Extension point for additional hold-creation strategies.
             *
             * Add-ons may hook here to handle strategies beyond the built-in
             * Immediate and Manual modes. Returning true signals that the strategy
             * has been fully handled and the scheduler should stop processing it.
             *
             * @since 4.5.0
             * @param bool   $handled    Whether the strategy has been handled (default false).
             * @param string $strategy   Strategy key (e.g. 'immediate', 'manual').
             * @param int    $order_id   WooCommerce order ID.
             * @param array  $config     Resolved configuration payload from Computation Service.
             */
            $strategy_handled = apply_filters( 'securehold_create_hold_strategy', false, $strategy, $order_id, $config );
            if ( true === $strategy_handled ) {
                return true;
            }

            // Built-in strategies are Immediate and Manual. Any other strategy that
            // was not handled by an extension is a no-op here — the scheduler stops
            // without creating a hold.
            if ( ! in_array( $strategy, array( 'immediate', 'manual' ), true ) ) {
                return true;
            }

            // MANUAL MODE: Create pending entry and stop
            if ($strategy === 'manual') {
                // Check if pending entry already exists
                if (class_exists('SecureHold_DB')) {
                    $existing = SecureHold_DB::get_deposit($order_id);
                    if ($existing) {
                        return true; // Already has an entry
                    }

                    // Create pending_manual entry
                    $pending_data = array(
                        'order_id' => $order_id,
                        'customer_id' => $order->get_meta('_stripe_customer_id', true) ?: 'pending',
                        'intent_id' => 'pending_manual_' . $order_id . '_' . time(),
                        'payment_method_id' => $order->get_meta('_stripe_source_id', true) ?: 'pending',
                        'amount' => 0,
                        'captured_amount' => 0,
                        'currency' => $order->get_currency(),
                        'status' => 'pending_manual',
                        'notes' => __('Awaiting manual hold creation by admin.', 'securehold-security-deposit-holds'),
                        'created_at' => current_time('mysql')
                    );
                    SecureHold_DB::insert_deposit($pending_data);

                    $order->update_meta_data('_securehold_deposit_status', 'pending_manual');
                    $order->update_meta_data('_securehold_timing_strategy', 'manual');
                    $order->save();

                    if ( function_exists( 'securehold_log' ) ) {
                        securehold_log( 'Strategy meta set', array( 'order_id' => $order_id, 'timing' => 'manual' ), 'debug' );
                    }

                    $order->add_order_note(__('SecureHold WP: Manual mode - Awaiting admin action to create security deposit.', 'securehold-security-deposit-holds'));

                    if (function_exists('securehold_log')) {
                        securehold_log('Scheduler: Manual mode — pending_manual row created', array('order_id' => $order_id), 'info');
                    }
                }
                return true; // Stop here, don't create hold automatically
            }

            // At this point the strategy is Immediate — proceed with immediate hold creation.

            // Ensure _securehold_timing_strategy is always set for filter/display consistency.
            $existing_strategy_meta = $order->get_meta( '_securehold_timing_strategy', true );
            if ( empty( $existing_strategy_meta ) ) {
                $order->update_meta_data( '_securehold_timing_strategy', $strategy );
                $order->save();

                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Strategy meta set', array(
                        'order_id' => $order_id,
                        'timing'   => $strategy,
                    ), 'debug' );
                }
            }

            if (function_exists('securehold_log')) {
                securehold_log('Scheduler: Proceeding to hold creation', array(
                    'order_id' => $order_id,
                    'strategy' => $strategy,
                    'source'   => $config['source'],
                ), 'info');
            }
        }

        // ── Resolve Stripe data (guest-first approach) ──
        // Uses the full resolution chain: order meta → PaymentIntent → create guest customer
        $customer_id = '';
        $payment_method_id = '';

        if (class_exists('SecureHold_Stripe') && method_exists('SecureHold_Stripe', 'resolve_stripe_data_for_order')) {
            $stripe_data = SecureHold_Stripe::resolve_stripe_data_for_order($order);
            $customer_id = $stripe_data['customer_id'];
            $payment_method_id = $stripe_data['payment_method_id'];

            if (function_exists('securehold_log')) {
                securehold_log('Scheduler: Stripe data resolution', array(
                    'order_id' => $order_id,
                    'source' => $stripe_data['source'],
                    'has_customer' => !empty($customer_id),
                    'has_pm' => !empty($payment_method_id),
                    'is_guest' => $order->get_customer_id() == 0,
                ), 'debug');
            }
        } else {
            // Fallback: direct meta read (legacy path)
            $customer_id = $order->get_meta('_stripe_customer_id', true);
            $payment_method_id = $order->get_meta('_stripe_payment_method', true);
            if (empty($payment_method_id)) {
                $payment_method_id = $order->get_meta('_stripe_source_id', true);
            }
        }

        if (empty($customer_id) || empty($payment_method_id)) {
            if (function_exists('securehold_log')) {
                securehold_log('Missing Stripe data after full resolution chain', array(
                    'order_id' => $order_id,
                    'has_customer_id' => !empty($customer_id),
                    'has_payment_method' => !empty($payment_method_id),
                    'is_guest' => $order->get_customer_id() == 0,
                    'order_payment_method' => $order->get_payment_method(),
                ), 'error');
            }
            $order->add_order_note(sprintf(
                'SecureHold WP: Could not create security deposit — missing Stripe data (customer: %s, payment method: %s). This may happen if the payment gateway did not save the required data.',
                !empty($customer_id) ? 'found' : 'missing',
                !empty($payment_method_id) ? 'found' : 'missing'
            ));
            return new WP_Error('missing_stripe_data', 'Missing Stripe data after full resolution');
        }

        // ── Resolve amount via Computation Service (aggregation-aware) ──
        // For force_execution (cron callback), resolver was not called above,
        // so we must resolve here to get the correct amount.
        if (!class_exists('Securehold_Deposit_Computation_Service') && defined('SECUREHOLD_PLUGIN_DIR')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/services/class-securehold-wp-computation-service.php';
        }
        if (!class_exists('Securehold_Config_Resolver') && defined('SECUREHOLD_PLUGIN_DIR')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-config-resolver.php';
        }

        // Use existing $config if available (non-force path), otherwise resolve fresh.
        if (!isset($config)) {
            $config = Securehold_Deposit_Computation_Service::compute($order);

            // Force-execution path: persist aggregation metadata if per_item_aggregated.
            if ( ! empty( $config['aggregation_mode'] ) && $config['aggregation_mode'] === 'per_item_aggregated' && ! empty( $config['item_breakdown'] ) ) {
                $order->update_meta_data( '_securehold_aggregation_mode', 'per_item_aggregated' );
                $order->update_meta_data( '_securehold_item_breakdown', $config['item_breakdown'] );
                if ( ! empty( $config['winner_item'] ) ) {
                    $order->update_meta_data( '_securehold_winner_item', $config['winner_item'] );
                }
                $order->save();

                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Force-exec: Aggregation metadata persisted', array(
                        'order_id'        => $order_id,
                        'aggregation'     => 'per_item_aggregated',
                        'items_count'     => count( $config['item_breakdown'] ),
                        'total'           => $config['deposit_amount_resolved'],
                        'winner_strategy' => $config['timing'],
                    ), 'debug' );
                }
            }
        }

        // Safety net: ensure _securehold_timing_strategy is always persisted.
        // Normally set during placeholder creation, but force_execution path may
        // reach here without the earlier block (e.g. cron callback).
        $current_strategy_meta = $order->get_meta( '_securehold_timing_strategy', true );
        if ( empty( $current_strategy_meta ) ) {
            $order->update_meta_data( '_securehold_timing_strategy', $config['timing'] );
            $order->save();

            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Strategy meta set', array(
                    'order_id' => $order_id,
                    'timing'   => $config['timing'],
                    'context'  => 'safety_net',
                ), 'debug' );
            }
        }

        // ── Centralized Deposit Gate (force-execution path) ──
        // Gate must be evaluated before ANY hold creation attempt.
        // Covers: cron callbacks, diagnostics retry tool, manual re-trigger.
        if ( ! class_exists( 'Securehold_Deposit_Gate' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-deposit-gate.php';
        }
        if ( class_exists( 'Securehold_Deposit_Gate' ) && ! Securehold_Deposit_Gate::should_require_deposit( $order ) ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: GATE BLOCKED — deposit skipped (force path)', array(
                    'order_id' => $order_id,
                ), 'info' );
            }
            $order->add_order_note(
                __( 'SecureHold WP: Security deposit skipped — order does not meet deposit requirements (threshold, exclusions, or zero amount).', 'securehold-security-deposit-holds' )
            );
            return true;
        }

        $amount = $config['deposit_amount_resolved'];
        $currency = $order->get_currency();

        // ── DEBUG LOG: Final config before hold creation ──
        if (function_exists('securehold_log')) {
            securehold_log('Scheduler: FINAL config before hold creation', array(
                'order_id'          => $order_id,
                'source'            => $config['source'],
                'source_id'         => $config['source_id'],
                'source_label'      => $config['source_label'],
                'timing'            => $config['timing'],
                'deposit_amount'    => $config['deposit_amount'],
                'amount_resolved'   => $amount,
                'delay_days'        => $config['delay_days'],
                'trigger_status'    => $config['trigger_status'],
                'fallbacks'         => isset($config['fallbacks']) ? $config['fallbacks'] : array(),
                'currency'          => $currency,
            ), 'debug');
        }

        if (!class_exists('SecureHold_Stripe')) {
            if (defined('SECUREHOLD_PLUGIN_DIR')) {
                require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe.php';
            }
        }

        $intent = SecureHold_Stripe::create_payment_intent($amount, $currency, $customer_id, $payment_method_id, $order_id);

        if (is_wp_error($intent)) {
            $error_code = $intent->get_error_code();
            $error_message = $intent->get_error_message();

            if (function_exists('securehold_log')) {
                securehold_log('Hold creation failed', array(
                    'error' => $error_message,
                    'error_code' => $error_code,
                    'order_id' => $order_id,
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                ), 'error');
            }

            // ── Mark deposit as FAILED with actionable note ──
            // Do NOT delete the deposit row — keep it visible in the admin UI.
            if (class_exists('SecureHold_DB')) {
                $existing_deposit = SecureHold_DB::get_deposit($order_id);

                if ($error_code === 'pm_single_use') {
                    $failed_note = 'Hold creation failed: Payment method is single-use and cannot be reused for an off-session hold. ' .
                        'SecureHold WP automatically configures reusability via checkout engine filters, but this payment method type may have additional constraints. ' .
                        'Run the SecureHold checkout diagnostic in Tools > Diagnostics for details.';
                } elseif ( $error_code === 'account_mismatch' ) {
                    $failed_note = __( 'Hold creation failed: The payment appears to belong to a different Stripe account or environment than the one currently configured in SecureHold WP. Please verify that WooCommerce Stripe and SecureHold WP use the same Stripe account and the same mode (test/live).', 'securehold-security-deposit-holds' );
                } else {
                    $failed_note = sprintf(
                        /* translators: %s = error message */
                        __('Hold creation failed: %s', 'securehold-security-deposit-holds'),
                        $error_message
                    );
                }

                if ($existing_deposit) {
                    // Update existing scheduled/pending_manual/other entry to failed
                    SecureHold_DB::update_hold($existing_deposit->id, array(
                        'status' => 'failed',
                        'amount' => $amount,
                        'notes'  => $failed_note,
                    ));
                } else {
                    // Insert a new failed entry so it's visible in Deposits list
                    SecureHold_DB::insert_deposit(array(
                        'order_id'          => $order_id,
                        'customer_id'       => $customer_id ?: 'N/A',
                        'intent_id'         => 'failed_' . $error_code . '_' . $order_id . '_' . time(),
                        'payment_method_id' => $payment_method_id ?: 'N/A',
                        'amount'            => $amount,
                        'captured_amount'   => 0,
                        'currency'          => $currency,
                        'status'            => 'failed',
                        'notes'             => $failed_note,
                        'created_at'        => current_time('mysql'),
                    ));
                }

                // Update order meta
                $order->update_meta_data('_securehold_deposit_status', 'failed');
                $order->delete_meta_data('_securehold_deposit_next_run');
                $order->save();

                $order->add_order_note(
                    'SecureHold WP: ' . $failed_note . "\n\n" .
                    __('Technical detail: ', 'securehold-security-deposit-holds') . $error_message
                );
            } else {
                $order->add_order_note('SecureHold WP Error: ' . $error_message);
            }

            // Fire deposit-failed email notification.
            self::fire_email( 'securehold_deposit_failed', $order_id,
                (object) array( 'amount' => $amount, 'currency' => $currency ) );

            // Notify admin of the failure.
            self::fire_email( 'securehold_admin_hold_failed', $order_id,
                (object) array( 'amount' => $amount, 'currency' => $currency ) );

            return $intent;
        }

        if (function_exists('securehold_log')) {
            securehold_log('Hold authorized', array('intent_id' => $intent->id, 'order_id' => $order_id), 'info');
        }

        // Resolve the actual PM used (may differ from $payment_method_id if cloned)
        $actual_pm = $payment_method_id;
        if (!empty($intent->payment_method)) {
            $intent_pm = is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method;
            if ($intent_pm !== $payment_method_id) {
                $actual_pm = $intent_pm;
                if (function_exists('securehold_log')) {
                    securehold_log('Hold used different PM than originally stored', array(
                        'original_pm' => $payment_method_id,
                        'actual_pm' => $actual_pm,
                        'order_id' => $order_id,
                    ), 'warning');
                }
                // Update order meta with the actual PM used
                $order->update_meta_data('_stripe_payment_method', $actual_pm);
                $order->save();
            }
        }

        $hold_data = array(
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'intent_id' => $intent->id,
            'payment_method_id' => $actual_pm,
            'amount' => $amount,
            'captured_amount' => 0,
            'currency' => $currency,
            'status' => 'authorized',
            'created_at' => current_time('mysql')
        );

        if (class_exists('SecureHold_DB')) {
            // Check if a scheduled/pending placeholder exists — update it instead of duplicating
            $existing_deposit = SecureHold_DB::get_deposit($order_id);
            if ($existing_deposit && in_array($existing_deposit->status, array('scheduled', 'pending', 'pending_manual', 'failed'))) {
                SecureHold_DB::update_hold($existing_deposit->id, array(
                    'customer_id'       => $customer_id,
                    'intent_id'         => $intent->id,
                    'payment_method_id' => $actual_pm,
                    'amount'            => $amount,
                    'captured_amount'   => 0,
                    'currency'          => $currency,
                    'status'            => 'authorized',
                    'authorized_at'     => current_time('mysql'),
                    'notes'             => '',
                ));
            } else {
                SecureHold_DB::insert_deposit($hold_data);
            }

            // Update order meta
            $order->update_meta_data('_securehold_deposit_status', 'authorized');
            $order->delete_meta_data('_securehold_deposit_next_run');
            $order->save();
        }

        $formatted_amount = wc_price($amount, array('currency' => $currency));
        $order->add_order_note(sprintf(
            'SecureHold WP: Security deposit of %s authorized. Payment Intent: %s',
            wp_strip_all_tags($formatted_amount),
            $intent->id
        ));

        // Store hold amount so Securehold_Emails::replace_variables() can resolve {hold_amount}.
        $order->update_meta_data( '_securehold_hold_amount', $amount );
        $order->save();

        // Fire deposit-authorized email notification.
        self::fire_email( 'securehold_deposit_authorized', $order_id,
            (object) array( 'amount' => $amount, 'currency' => $currency ) );

        // Notify admin of the new hold.
        self::fire_email( 'securehold_admin_hold_created', $order_id,
            (object) array( 'amount' => $amount, 'currency' => $currency ) );

        return true;
    }

    public static function fire_email( $email_id, $order_id, $hold = null ) {
        if ( class_exists( 'Securehold_Email_Manager' ) ) {
            Securehold_Email_Manager::fire_email( $email_id, $order_id, $hold );
        }
    }
}