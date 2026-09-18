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
     * Lifetime of the cross-request hold-creation lock, in seconds.
     *
     * Sized to outlast one full creation run rather than to throttle retries.
     * A run issues a bounded series of Stripe calls (retrieve / detach / attach /
     * customer update / intent create, plus the clone fallback), each of which
     * normally answers in well under a second. 90s leaves ample room for a slow
     * Stripe round-trip while keeping a lock orphaned by a fatal error or a
     * killed worker short enough that no merchant is left blocked.
     *
     * This is NOT a retry cooldown: the lock is released on every exit path,
     * including failure, so a deliberate retry after a real Stripe error is
     * available immediately. The TTL only ever applies to a crashed process.
     *
     * @since 3.4.4
     */
    const HOLD_LOCK_TTL = 90;

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
     * Idempotence wrapper. Every caller reaches hold creation through here:
     * WooCommerce status hooks, the order metabox, the manual AJAX button,
     * execute_force_hold() (cron), and the PRO retry tool. Two guards ensure a
     * single logical creation per order, whatever the entry point:
     *
     *   Level 1 — per-request static guard. Neutralises repeat calls inside one
     *   PHP request. This covers legacy (non-HPOS) order saves, where the
     *   metabox handler runs on both save_post_shop_order and
     *   woocommerce_process_shop_order_meta, and re-entrancy through
     *   woocommerce_order_status_changed fired by $order->save() below.
     *
     *   Level 2 — cross-request lock. Covers genuine concurrency that a static
     *   variable cannot see: parallel AJAX requests, cron overlapping with an
     *   admin action, or several PHP workers on the same order.
     *
     * Both guards return true — the convention already used by the "skipped"
     * paths in run_hold_creation() — meaning "nothing to do, handled elsewhere".
     * They never mask a genuine failure: the lock is released on every exit
     * path, so a deliberate retry after a real Stripe error still works
     * immediately.
     *
     * @since 1.0.0
     * @since 3.4.4 Added the two idempotence guards.
     *
     * @param int  $order_id        WooCommerce order ID.
     * @param bool $force_execution Skip the deduplication guard and re-execute immediately.
     *                              Used by execute_force_hold() when a cron-deferred hold fires.
     * @return bool|WP_Error|void  True when skipped or already handled, WP_Error on
     *                             Stripe failure, void otherwise.
     */
    public function create_hold_for_order($order_id, $force_execution = false) {

        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return false;
        }

        // ── Level 1: per-request guard ──
        // Set before running so a nested call (re-entrancy via $order->save())
        // sees the order as already in flight instead of starting a second run.
        static $in_flight = array();

        if ( array_key_exists( $order_id, $in_flight ) ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: Skipped (already handled in this request)', array(
                    'order_id' => $order_id,
                    'force'    => $force_execution,
                ), 'debug' );
            }
            return true;
        }

        // ── Level 2: cross-request lock ──
        $lock_token = self::acquire_hold_lock( $order_id );

        if ( $lock_token === false ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: Skipped (hold creation already in progress)', array(
                    'order_id' => $order_id,
                    'force'    => $force_execution,
                ), 'warning' );
            }
            return true;
        }

        $in_flight[ $order_id ] = true;

        try {
            $result = $this->run_hold_creation( $order_id, $force_execution );

            // Blocks moves a draft order to pending before it calls Stripe, so
            // the first attempt runs with no PaymentIntent and no
            // PaymentMethod and fails for want of data that arrives moments
            // later. Measured on staging: the premature call ran 4.7 seconds
            // before three further hooks fired in the same request, all with
            // both values present, and all three were turned away by this
            // guard. Holding it while the run is in flight is right; holding
            // it after that failure is what left the deposit uncreated.
            //
            // Only this one error code is transient. Anything else — a Stripe
            // refusal, a gate decision — is an answer, and keeps the latch.
            if ( is_wp_error( $result ) && $result->get_error_code() === 'missing_stripe_data' ) {
                unset( $in_flight[ $order_id ] );
            }

            return $result;
        } finally {
            // Released on success, on WP_Error and on any uncaught throwable, so
            // a legitimate retry is never blocked by a leftover lock. Conditional
            // on our own token: a run whose lock expired and was reclaimed must
            // not delete the new holder's row on its way out.
            self::release_hold_lock( $order_id, $lock_token );
        }
    }

    /**
     * Acquire the cross-request hold-creation lock for an order.
     *
     * Mutual exclusion rests on a single INSERT IGNORE: MySQL either creates the
     * row or it does not, and the affected-row count says which happened. No
     * read precedes it, so no two callers can both conclude the row was absent.
     *
     * This replaces an add_option() based acquisition. That worked while
     * WordPress compiled add_option() to an INSERT ... ON DUPLICATE KEY UPDATE
     * whose UPDATE was a no-op, making a collision report zero affected rows.
     * WordPress 7.1 writes option_value and autoload in that UPDATE, so a
     * collision now reports rows changed and add_option() returns true — leaving
     * only its cache-backed get_option() pre-check, which is not atomic and which
     * two concurrent callers can both pass.
     *
     * The stored value is an ownership token and an expiry, nothing else: no
     * order data, no credential.
     *
     * @since 3.4.4
     *
     * @param int $order_id WooCommerce order ID.
     * @return string|false Owner token when acquired, false when another process holds it.
     */
    private static function acquire_lock( $lock_key ) {
        global $wpdb;

        $token    = self::new_lock_token();
        $value    = $token . '|' . ( time() + self::HOLD_LOCK_TTL );

        // INSERT IGNORE: one row created, or none. Never an overwrite.
        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
                $lock_key,
                $value
            )
        );

        // Direct SQL bypasses the options cache, so a later get_option() for this
        // name would otherwise answer from a stale 'notoptions' entry.
        self::forget_lock_cache( $lock_key );

        if ( $inserted ) {
            return $token;
        }

        // A row exists. Take it over only if it has expired, and only by swapping
        // the exact value we just read: if another process got there first, its
        // write changed the value and this UPDATE matches nothing.
        $current = $wpdb->get_var(
            $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s LIMIT 1", $lock_key )
        );

        if ( $current === null ) {
            // Released between the INSERT and this read. Leave it to the next
            // attempt rather than looping.
            return false;
        }

        $expires_at = self::lock_expiry( $current );

        if ( $expires_at > time() ) {
            return false;
        }

        $swapped = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
                $value,
                $lock_key,
                $current
            )
        );

        self::forget_lock_cache( $lock_key );

        if ( ! $swapped ) {
            // Someone else reclaimed it first.
            return false;
        }

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Scheduler: Reclaimed stale hold lock', array(
                'order_id'   => $order_id,
                'expired_at' => $expires_at,
                'ttl'        => self::HOLD_LOCK_TTL,
            ), 'warning' );
        }

        return $token;
    }

    /**
     * Release the lock, but only if we still own it.
     *
     * The delete is conditional on the exact token written at acquisition. A
     * process whose lock expired and was reclaimed by someone else must not be
     * able to delete the new owner's row on its way out — which an unconditional
     * delete would do, handing a third process a lock the second still believes
     * it holds.
     *
     * @since 3.4.4
     *
     * @param int         $order_id WooCommerce order ID.
     * @param string|null $token    Token returned by acquire_hold_lock().
     * @return void
     */
    private static function release_lock( $lock_key, $token = null ) {
        global $wpdb;

        if ( empty( $token ) ) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` LIKE %s",
                $lock_key,
                $wpdb->esc_like( $token . '|' ) . '%'
            )
        );

        self::forget_lock_cache( $lock_key );
    }

    /**
     * Random ownership token. Not a secret — it identifies a holder, and never
     * carries order or credential data.
     *
     * @since 3.4.4
     * @return string
     */
    private static function new_lock_token() {
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }

        return uniqid( 'sh', true );
    }

    /**
     * Expiry timestamp encoded in a stored lock value.
     *
     * Values written before this format existed carry a bare timestamp; those are
     * read as an acquisition time so they still expire rather than blocking an
     * order forever.
     *
     * @since 3.4.4
     *
     * @param string $value Stored option value.
     * @return int Unix timestamp.
     */
    private static function lock_expiry( $value ) {
        $value = (string) $value;

        if ( strpos( $value, '|' ) !== false ) {
            $parts = explode( '|', $value, 2 );
            return (int) $parts[1];
        }

        return (int) $value + self::HOLD_LOCK_TTL;
    }

    /**
     * Drop the options-cache entries for a lock written through direct SQL.
     *
     * @since 3.4.4
     *
     * @param string $lock_key Option name.
     * @return void
     */
    private static function forget_lock_cache( $lock_key ) {
        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( $lock_key, 'options' );
            wp_cache_delete( 'notoptions', 'options' );
        }
    }

    /**
     * Option name backing the hold-creation lock for an order.
     *
     * @since 3.4.4
     *
     * @param int $order_id WooCommerce order ID.
     * @return string
     */
    private static function hold_lock_key( $order_id ) {
        return 'securehold_hold_lock_' . (int) $order_id;
    }

    /**
     * Key for the lock covering the terminal operations.
     *
     * Deliberately distinct from the creation lock: a deposit still being
     * created must not block a capture, and the two belong to different phases
     * of the deposit's life. Capture and release share this one key, so a
     * capture and a release fired at the same moment contend for it instead of
     * both reaching Stripe.
     *
     * @param int $order_id
     * @return string
     */
    private static function terminal_lock_key( $order_id ) {
        return 'securehold_terminal_lock_' . (int) $order_id;
    }

    /**
     * Acquire the creation lock. Thin wrapper over the shared primitive.
     *
     * @param int $order_id
     * @return string|false Owner token, or false when someone else holds it.
     */
    public static function acquire_hold_lock( $order_id ) {
        return self::acquire_lock( self::hold_lock_key( $order_id ) );
    }

    /**
     * @param int         $order_id
     * @param string|null $token Token returned by acquire_hold_lock().
     * @return void
     */
    public static function release_hold_lock( $order_id, $token = null ) {
        self::release_lock( self::hold_lock_key( $order_id ), $token );
    }

    /**
     * Acquire the lock guarding capture and release.
     *
     * Staging showed two simultaneous captures both reaching Stripe, with only
     * Stripe's own refusal preventing a double charge. The protection worked
     * but was borrowed, not designed. This is the same atomic mechanism the
     * creation path has used since the lock was made atomic — one
     * implementation, two keys.
     *
     * @param int $order_id
     * @return string|false
     */
    public static function acquire_terminal_lock( $order_id ) {
        return self::acquire_lock( self::terminal_lock_key( $order_id ) );
    }

    /**
     * @param int         $order_id
     * @param string|null $token Token returned by acquire_terminal_lock().
     * @return void
     */
    public static function release_terminal_lock( $order_id, $token = null ) {
        self::release_lock( self::terminal_lock_key( $order_id ), $token );
    }

    /**
     * Record that a deposit was due and could not be created.
     *
     * Staging showed an order charged in full with no deposit and nothing on
     * the order to say so — the only trace was one row in the log table, which
     * nobody reads until a customer complains. This marker is what the support
     * bundle and the admin notice read.
     *
     * Deliberately written even for the transient failure Blocks produces on
     * every order: the retry a moment later deletes it again, and an order that
     * never reaches that retry is exactly the one worth surfacing.
     *
     * @param WC_Order $order  Order the deposit was due on.
     * @param string   $code   Machine-readable reason.
     * @param string   $detail Human-readable detail. Never a Stripe payload.
     * @return void
     */
    private static function record_hold_failure( $order, $code, $detail ) {
        if ( ! $order || ! method_exists( $order, 'update_meta_data' ) ) {
            return;
        }

        $order->update_meta_data( '_securehold_hold_failed', array(
            'code'   => $code,
            'detail' => $detail,
            'at'     => current_time( 'mysql' ),
        ) );
        $order->save();
    }

    /**
     * Actual hold creation. Reached only through create_hold_for_order(),
     * which owns the idempotence guards.
     *
     * @since 3.4.4 Extracted unchanged from create_hold_for_order().
     *
     * @param int  $order_id        WooCommerce order ID.
     * @param bool $force_execution Skip the 60-second deduplication guard.
     * @return bool|WP_Error|void
     */
    private function run_hold_creation($order_id, $force_execution = false) {

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

            // The stamp itself is written much further down, just before the
            // hold PaymentIntent is created. It used to be written here, before
            // anything had been resolved, which meant a run that never reached
            // Stripe still burned the window: on Blocks the premature attempt
            // stamped the order and the retry four seconds later was turned
            // away by this very check. This guard exists to stop us hammering
            // Stripe, and an attempt that never reached Stripe has nothing to
            // throttle.
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
            // Fallback when the Stripe wrapper is unavailable. Reads through the
            // canonical resolver rather than repeating a fallback order of its
            // own, so a legacy reference is refused here too.
            $customer_id       = $order->get_meta('_stripe_customer_id', true);
            $payment_method_id = ( class_exists('SecureHold_Stripe') && method_exists('SecureHold_Stripe', 'get_payment_method_token_from_order') )
                ? SecureHold_Stripe::get_payment_method_token_from_order($order)
                : '';
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
            self::record_hold_failure( $order, 'missing_stripe_data', 'Stripe customer or payment method not available yet' );

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

        // Last safe point before the only call that creates anything at
        // Stripe. Everything needed is resolved, the gate has passed, and the
        // amount is known — so this is a real attempt and it should occupy the
        // deduplication window checked at the top of this method.
        //
        // Still confined to the non-force path: the admin metabox, the cron
        // callback and the PRO retry tool deliberately bypass the window, and
        // stamping the order here would start throttling them.
        if (!$force_execution) {
            $order->update_meta_data('_securehold_attempt_made', time());
            $order->save();
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
                } elseif ( $error_code === 'stripe_context_mismatch_confirmed' || $error_code === 'account_mismatch' ) {
                    // Confirmed by the Stripe context diagnosis, so the strong
                    // wording is earned. 'account_mismatch' is the pre-3.4.4 code,
                    // still handled for holds that failed before the upgrade.
                    $failed_note = __( 'Hold creation failed: SecureHold WP and the WooCommerce Stripe Gateway are using incompatible Stripe contexts. The payment does not exist in the Stripe account or environment SecureHold WP is configured with. Copy the API keys from the Stripe environment where this payment appears.', 'securehold-security-deposit-holds' );
                } elseif ( $error_code === 'stripe_context_mismatch_suspected' || $error_code === 'stripe_object_not_accessible' ) {
                    // Not established. The object is unreachable, which has more
                    // than one ordinary cause, so the wording stays careful.
                    $failed_note = __( 'Hold creation failed: this Stripe object could not be accessed with the current SecureHold WP credentials. The object may belong to another Stripe environment, or the stored payment reference may no longer be valid. Run the Stripe context check in SecureHold WP > Health Check for a verdict.', 'securehold-security-deposit-holds' );
                } elseif ( $error_code === 'legacy_payment_method' || $error_code === 'invalid_payment_reference' ) {
                    $failed_note = __( 'Hold creation failed: the payment reference stored on this order is not a reusable Stripe payment method. Orders taken through older versions of the WooCommerce Stripe Gateway can carry a legacy source or card reference, which cannot be used for an off-session security deposit. This does not indicate a problem with your Stripe configuration.', 'securehold-security-deposit-holds' );
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
                self::record_hold_failure( $order, 'stripe_error', 'Stripe refused the hold PaymentIntent' );
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

        // One reading of the clock for the whole operation. Two calls a few
        // milliseconds apart would have the insert and the update branches
        // disagree about the same authorization.
        $authorized_at = current_time('mysql');

        $hold_data = array(
            'order_id' => $order_id,
            'customer_id' => $customer_id,
            'intent_id' => $intent->id,
            'payment_method_id' => $actual_pm,
            'amount' => $amount,
            'captured_amount' => 0,
            'currency' => $currency,
            'status' => 'authorized',
            // The database layer will not date an authorization on its own; the
            // caller that watched Stripe approve it is the one that knows.
            'authorized_at' => $authorized_at,
            'created_at' => $authorized_at
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
                    'authorized_at'     => $authorized_at,
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

        // The deposit exists now, so any earlier failure on this order is
        // history. Under Blocks the first attempt always fails and the retry
        // succeeds moments later, so leaving the marker would report a problem
        // on every single order.
        $order->delete_meta_data( '_securehold_hold_failed' );
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