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
     * Fallback retry window after a 'missing_stripe_data' failure.
     *
     * The only thing that has ever retried a hold after this specific failure
     * is an incidental LATER re-fire of one of the four WooCommerce hooks this
     * class listens on (woocommerce_payment_complete and the three order
     * status hooks) — which happens reliably when several of them fire in
     * sequence within the same checkout request, or when a Stripe webhook
     * later calls $order->payment_complete() again. Neither is guaranteed:
     * order #71 (sienna-hornet-244266.hostingersite.com, real-world Multi-Hold
     * retest) reused a saved payment method, completed in one hook pass, and
     * received no later webhook — so its two Hold Groups reached
     * 'missing_stripe_data' and were never retried at all, silently. Order #72
     * (fresh card entry) happened to get a webhook-driven second pass and
     * succeeded. This is not Booking- or Multi-Hold-specific: a legacy
     * single-hold order taking the same checkout path would lose its one hold
     * exactly the same way — confirmed by reading the code path, which treats
     * every group_key (including null) identically.
     *
     * This constant is the bounded, group-aware safety net: reuses the
     * existing scheduled-hold WP-Cron primitive (schedule_hold_event() /
     * execute_force_hold(), already built for the 'scheduled' timing
     * strategy) rather than a new queue or job system.
     *
     * @since 3.4.6
     */
    const MISSING_STRIPE_DATA_RETRY_DELAY = 30;

    /**
     * Maximum number of fallback retries per (order_id, group_key) after
     * 'missing_stripe_data'. Bounded so a merchant whose order genuinely never
     * gets a valid payment method (e.g. a truly incompatible Stripe setup)
     * does not get retried forever — after this many attempts the existing
     * '_securehold_hold_failed' marker and support-bundle surface remain the
     * path to a human decision, exactly as before this fix for any other
     * terminal failure.
     *
     * @since 3.4.6
     */
    const MISSING_STRIPE_DATA_MAX_RETRIES = 3;

    /**
     * Register WP-Cron action hooks.
     *
     * @since 1.0.0
     */
    public function __construct() {
        // accepted_args = 2: a legacy event scheduled with array($order_id) still
        // calls execute_force_hold($order_id) — WordPress only passes as many
        // args as the event actually carries. A Multi-Hold event scheduled with
        // array($order_id, $group_key) now also reaches its second parameter.
        add_action('securehold_trigger_scheduled_hold', array($this, 'execute_force_hold'), 10, 2);
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
     * Cron callback: execute a previously deferred hold for an order, or for
     * one specific Hold Group within it.
     *
     * Fired by the 'securehold_trigger_scheduled_hold' single cron event.
     *
     * @since 2.0.0
     * @since 1.4.0 (Multi-Hold engine, scheduling increment) Added $group_key.
     *              An event scheduled before this change carries only
     *              $order_id — accepted_args = 2 on the add_action() in the
     *              constructor means WordPress simply does not pass a second
     *              argument for those, and $group_key keeps its default.
     *
     * @param int         $order_id  WooCommerce order ID.
     * @param string|null $group_key Opaque Hold Group identifier, or null for the default group.
     * @return void
     */
    public function execute_force_hold($order_id, $group_key = null) {
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
                    array( 'order_id' => $order_id, 'group_key' => $group_key, 'strategy' => $sh_saved_strategy ),
                    'warning'
                );
            }
            return;
        }

        if (function_exists('securehold_log')) {
            securehold_log('Cron: Executing scheduled hold', array('order_id' => $order_id, 'group_key' => $group_key), 'info');
        }

        // Update the scheduled placeholder to "executing" state — THIS group's
        // row, never an arbitrary one of the order.
        if (class_exists('SecureHold_DB')) {
            $existing = SecureHold_DB::get_hold_for_group($order_id, $group_key);
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

        $this->create_hold_for_order($order_id, true, $group_key);
    }

    /**
     * Args WordPress stores for a scheduled hold event, and the same args a
     * lookup or cancellation must pass back for wp_next_scheduled() /
     * wp_unschedule_event() to match it.
     *
     * WP-Cron identifies a single event by hook name AND its exact args
     * array — group_key = null yields array($order_id), byte-identical to
     * every event this scheduler has ever created, so a legacy lookup/cancel
     * still finds it. A non-null key extends the array rather than replacing
     * it, so two Hold Groups of the same order produce two distinct args
     * arrays and are never mistaken for one another.
     *
     * @since 1.4.0 (Multi-Hold engine, scheduling increment)
     *
     * @param int         $order_id
     * @param string|null $group_key
     * @return array
     */
    public static function scheduled_hold_args( $order_id, $group_key = null ) {
        $order_id = (int) $order_id;
        return ( $group_key === null ) ? array( $order_id ) : array( $order_id, $group_key );
    }

    /**
     * Timestamp of a specific scheduled hold event, or false if none exists.
     *
     * @since 1.4.0 (Multi-Hold engine, scheduling increment)
     *
     * @param int         $order_id
     * @param string|null $group_key
     * @return int|false
     */
    public static function find_scheduled_hold( $order_id, $group_key = null ) {
        return wp_next_scheduled( 'securehold_trigger_scheduled_hold', self::scheduled_hold_args( $order_id, $group_key ) );
    }

    /**
     * Schedule one hold event, guarded the same way every existing caller
     * already guards its own wp_schedule_single_event() call: only if no
     * event with these exact args is pending yet.
     *
     * @since 1.4.0 (Multi-Hold engine, scheduling increment)
     *
     * @param int         $timestamp
     * @param int         $order_id
     * @param string|null $group_key
     * @return bool  True when scheduled, false when one already existed.
     */
    public static function schedule_hold_event( $timestamp, $order_id, $group_key = null ) {
        if ( self::find_scheduled_hold( $order_id, $group_key ) ) {
            return false;
        }

        wp_schedule_single_event( $timestamp, 'securehold_trigger_scheduled_hold', self::scheduled_hold_args( $order_id, $group_key ) );

        return true;
    }

    /**
     * Cancel one specific scheduled hold event without touching any other
     * group's event on the same order.
     *
     * No Booking or grouping logic lives here — this only knows how to find
     * and remove the one WP-Cron event matching (order_id, group_key). A
     * caller (a future Booking Rescheduler, an admin action) decides when
     * cancelling is the right response to a reschedule or a cancellation.
     *
     * @since 1.4.0 (Multi-Hold engine, scheduling increment)
     *
     * @param int         $order_id
     * @param string|null $group_key Null targets the default group's own event.
     * @return bool  True when an event was found and removed, false if none existed.
     */
    public static function cancel_scheduled_hold( $order_id, $group_key = null ) {
        $args      = self::scheduled_hold_args( $order_id, $group_key );
        $timestamp = wp_next_scheduled( 'securehold_trigger_scheduled_hold', $args );

        if ( ! $timestamp ) {
            return false;
        }

        wp_unschedule_event( $timestamp, 'securehold_trigger_scheduled_hold', $args );

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Scheduler: cancelled scheduled hold', array(
                'order_id'  => $order_id,
                'group_key' => $group_key,
            ), 'info' );
        }

        return true;
    }

    /**
     * Move one specific scheduled hold event to a new time, leaving every
     * other group's event on the same order untouched.
     *
     * Implemented as cancel-then-reschedule rather than an in-place WP-Cron
     * update, because WordPress has no API to change a pending event's own
     * timestamp — only to remove one and add another.
     *
     * @since 1.4.0 (Multi-Hold engine, scheduling increment)
     *
     * @param int         $order_id
     * @param int         $new_timestamp
     * @param string|null $group_key
     * @return bool  True (a new event is always scheduled; the prior one, if any, is removed first).
     */
    public static function reschedule_hold( $order_id, $new_timestamp, $group_key = null ) {
        self::cancel_scheduled_hold( $order_id, $group_key );

        wp_schedule_single_event( $new_timestamp, 'securehold_trigger_scheduled_hold', self::scheduled_hold_args( $order_id, $group_key ) );

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Scheduler: rescheduled hold', array(
                'order_id'   => $order_id,
                'group_key'  => $group_key,
                'new_run_at' => $new_timestamp,
            ), 'info' );
        }

        return true;
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
     * @param int         $order_id        WooCommerce order ID.
     * @param bool        $force_execution Skip the deduplication guard and re-execute immediately.
     *                                     Used by execute_force_hold() when a cron-deferred hold fires.
     * @param string|null $group_key       Opaque Hold Group identifier. Null (the default) is the
     *                                     implicit default group — every existing caller passes
     *                                     nothing, so this is dormant until a caller opts in.
     * @return bool|WP_Error|void  True when skipped or already handled, WP_Error on
     *                             Stripe failure, void otherwise.
     */
    public function create_hold_for_order($order_id, $force_execution = false, $group_key = null) {

        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return false;
        }

        // ── Booking Integrations extension point ──
        // Scoped to group_key === null (the DEFAULT group) only: it never
        // affects a call that already targets a specific group (a booking's
        // own Hold Group, force_execution retries, etc.). An external
        // grouping policy that already decided this order's real Hold
        // Groups — and already called create_hold_for_order() once per
        // group with its own group_key — uses this filter to say so, so the
        // default single-hold creation the WooCommerce hooks would
        // otherwise still trigger for this order does not ALSO run and add
        // an unwanted extra hold on top of the booking-specific ones.
        // Default false: no callback registered means zero behaviour change
        // for every commande and every existing installation.
        if ( $group_key === null && apply_filters( 'securehold_skip_default_hold_group', false, $order_id ) ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: Skipped (default group handled by an external grouping policy)', array(
                    'order_id' => $order_id,
                ), 'debug' );
            }
            return true;
        }

        // ── Level 1: per-request guard ──
        // Keyed by (order_id, group_key) rather than order_id alone, so a
        // legitimate second call for a DIFFERENT group of the same order is
        // never mistaken for re-entrancy on the first. group_key = null keeps
        // the exact legacy key ("{order_id}|"), so a Single-Hold-per-Order
        // commande behaves identically to before this change.
        static $in_flight = array();
        $in_flight_key = $order_id . '|' . ( $group_key !== null ? (string) $group_key : '' );

        if ( array_key_exists( $in_flight_key, $in_flight ) ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: Skipped (already handled in this request)', array(
                    'order_id'  => $order_id,
                    'group_key' => $group_key,
                    'force'     => $force_execution,
                ), 'debug' );
            }
            return true;
        }

        // ── Level 2: cross-request lock ──
        $lock_token = self::acquire_hold_lock( $order_id, $group_key );

        if ( $lock_token === false ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: Skipped (hold creation already in progress)', array(
                    'order_id'  => $order_id,
                    'group_key' => $group_key,
                    'force'     => $force_execution,
                ), 'warning' );
            }
            return true;
        }

        $in_flight[ $in_flight_key ] = true;

        try {
            $result = $this->run_hold_creation( $order_id, $force_execution, $group_key );

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
                unset( $in_flight[ $in_flight_key ] );
            }

            return $result;
        } finally {
            // Released on success, on WP_Error and on any uncaught throwable, so
            // a legitimate retry is never blocked by a leftover lock. Conditional
            // on our own token: a run whose lock expired and was reclaimed must
            // not delete the new holder's row on its way out.
            self::release_hold_lock( $order_id, $lock_token, $group_key );
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
     * Option name backing the hold-creation lock for an order, or for one
     * specific Hold Group within it.
     *
     * $group_key = null (every caller before the Multi-Hold engine, and every
     * Single-Hold-per-Order commande after it) returns the exact legacy key,
     * so nothing already relying on that string changes. A non-null key is
     * hashed rather than concatenated raw: group_key is an opaque value a
     * future grouping policy controls, not text this class should trust to be
     * short or option-name-safe, and a fixed-length suffix rules out two
     * different keys ever colliding by producing the same option name.
     *
     * @since 3.4.4
     * @since 1.4.0 (Multi-Hold engine, locking increment) Added $group_key.
     *
     * @param int         $order_id  WooCommerce order ID.
     * @param string|null $group_key Opaque Hold Group identifier, or null for the default group.
     * @return string
     */
    private static function hold_lock_key( $order_id, $group_key = null ) {
        $order_id = (int) $order_id;

        if ( $group_key === null || $group_key === '' ) {
            return 'securehold_hold_lock_' . $order_id;
        }

        return 'securehold_hold_lock_' . $order_id . '_' . substr( md5( (string) $group_key ), 0, 12 );
    }

    /**
     * Key for the lock covering the terminal operations (capture, release) on
     * one hold.
     *
     * Deliberately distinct from the creation lock: a deposit still being
     * created must not block a capture, and the two belong to different phases
     * of the deposit's life.
     *
     * $hold_id = null keeps the exact legacy, order-wide key — every caller
     * before the Multi-Hold engine, and every Single-Hold-per-Order commande
     * after it. A non-null id scopes the lock to that one hold: capturing
     * Hold A and releasing Hold B are independent operations on independent
     * Stripe objects and must not contend for the same lock. Capture and
     * release of the SAME hold still share one key, so those two continue to
     * contend for it instead of both reaching Stripe — the id is a more
     * stable identity than group_key here because capture/release already
     * address a specific row by its own id, never by group_key.
     *
     * @since 3.4.4
     * @since 1.4.0 (Multi-Hold engine, locking increment) Added $hold_id.
     *
     * @param int      $order_id
     * @param int|null $hold_id  Primary key of the targeted hold row, or null for the legacy key.
     * @return string
     */
    private static function terminal_lock_key( $order_id, $hold_id = null ) {
        $order_id = (int) $order_id;

        if ( empty( $hold_id ) ) {
            return 'securehold_terminal_lock_' . $order_id;
        }

        return 'securehold_terminal_lock_' . $order_id . '_' . (int) $hold_id;
    }

    /**
     * Acquire the creation lock. Thin wrapper over the shared primitive.
     *
     * @param int         $order_id
     * @param string|null $group_key Opaque Hold Group identifier, or null for the default group.
     * @return string|false Owner token, or false when someone else holds it.
     */
    public static function acquire_hold_lock( $order_id, $group_key = null ) {
        return self::acquire_lock( self::hold_lock_key( $order_id, $group_key ) );
    }

    /**
     * @param int         $order_id
     * @param string|null $token     Token returned by acquire_hold_lock().
     * @param string|null $group_key Must match the value passed to acquire_hold_lock().
     * @return void
     */
    public static function release_hold_lock( $order_id, $token = null, $group_key = null ) {
        self::release_lock( self::hold_lock_key( $order_id, $group_key ), $token );
    }

    /**
     * Acquire the lock guarding capture and release.
     *
     * Staging showed two simultaneous captures both reaching Stripe, with only
     * Stripe's own refusal preventing a double charge. The protection worked
     * but was borrowed, not designed. This is the same atomic mechanism the
     * creation path has used since the lock was made atomic — one
     * implementation, several keys.
     *
     * @param int      $order_id
     * @param int|null $hold_id  Primary key of the targeted hold row, or null for the legacy key.
     * @return string|false
     */
    public static function acquire_terminal_lock( $order_id, $hold_id = null ) {
        return self::acquire_lock( self::terminal_lock_key( $order_id, $hold_id ) );
    }

    /**
     * @param int         $order_id
     * @param string|null $token   Token returned by acquire_terminal_lock().
     * @param int|null    $hold_id Must match the value passed to acquire_terminal_lock().
     * @return void
     */
    public static function release_terminal_lock( $order_id, $token = null, $hold_id = null ) {
        self::release_lock( self::terminal_lock_key( $order_id, $hold_id ), $token );
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
            // Unique per call, regardless of how many failures land in the
            // same second with the same code/detail (Multi-Hold, or a fast
            // retry re-failing moments later) — this is what the admin
            // notice's Dismiss identifies a specific occurrence by, rather
            // than 'at'/'code'/'detail', none of which are guaranteed
            // distinct between two genuinely separate failures.
            'failure_id' => wp_generate_uuid4(),
            'code'       => $code,
            'detail'     => $detail,
            'at'         => current_time( 'mysql' ),
        ) );
        $order->save();
    }

    /**
     * Bounded, group-aware fallback retry after 'missing_stripe_data'.
     *
     * The only reason a hold retries at all today is an incidental later
     * re-fire of one of the four WooCommerce hooks this class listens on —
     * fine when it happens, but nothing guarantees it does (see the constant
     * docblock for the real order this was found on). This closes that gap
     * with the smallest possible primitive: the exact WP-Cron event and
     * callback already built for the 'scheduled' timing strategy
     * (schedule_hold_event() / execute_force_hold(), reached through the
     * unchanged 'securehold_trigger_scheduled_hold' hook), never a new queue.
     *
     * Keyed by (order_id, group_key), never by order_id alone, so a
     * commande's other Hold Groups are neither delayed nor duplicated by one
     * group's retry — each group gets its own bounded attempt count and its
     * own cron event, exactly like every other Multi-Hold primitive.
     *
     * Deliberately narrow: only 'missing_stripe_data' schedules a retry. A
     * genuine Stripe refusal (record_hold_failure( ..., 'stripe_error', ... ))
     * is an answer, not a transient gap, and must keep going through the
     * existing failed-deposit surfacing instead.
     *
     * @since 3.4.6
     *
     * @param WC_Order    $order
     * @param int         $order_id
     * @param string|null $group_key
     * @return void
     */
    private static function maybe_schedule_missing_stripe_data_retry( $order, $order_id, $group_key ) {
        if ( ! class_exists( 'SecureHold_DB' ) ) {
            return;
        }

        // A hold already reaching a terminal state must never be retried into
        // existence again — the same guard run_hold_creation() itself opens
        // with, checked here too because this path can be reached from a
        // retry that raced a manual admin action.
        $existing = SecureHold_DB::get_hold_for_group( $order_id, $group_key );
        if ( $existing && in_array( $existing->status, array( 'authorized', 'captured', 'released', 'cancelled' ), true ) ) {
            return;
        }

        $dedup_key = $group_key !== null ? (string) $group_key : '';

        $retries = $order->get_meta( '_securehold_missing_data_retries', true );
        if ( ! is_array( $retries ) ) {
            $retries = array();
        }
        $attempt = isset( $retries[ $dedup_key ] ) ? (int) $retries[ $dedup_key ] : 0;

        if ( $attempt >= self::MISSING_STRIPE_DATA_MAX_RETRIES ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: missing_stripe_data — max fallback retries reached, no further automatic attempt', array(
                    'order_id'  => $order_id,
                    'group_key' => $group_key,
                    'attempts'  => $attempt,
                ), 'warning' );
            }
            return;
        }

        $retries[ $dedup_key ] = $attempt + 1;
        $order->update_meta_data( '_securehold_missing_data_retries', $retries );
        $order->save();

        $scheduled = self::schedule_hold_event( time() + self::MISSING_STRIPE_DATA_RETRY_DELAY, $order_id, $group_key );

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Scheduler: missing_stripe_data — fallback retry scheduled', array(
                'order_id'   => $order_id,
                'group_key'  => $group_key,
                'attempt'    => $attempt + 1,
                'max'        => self::MISSING_STRIPE_DATA_MAX_RETRIES,
                'delay'      => self::MISSING_STRIPE_DATA_RETRY_DELAY,
                // false only means an event for this exact (order_id, group_key)
                // was already pending — never an error. A natural hook re-fire
                // or a prior retry may have gotten there first.
                'newly_scheduled' => $scheduled,
            ), 'info' );
        }
    }

    /**
     * Fast-path retry: re-attempt a 'missing_stripe_data' hold the moment
     * WooCommerce Stripe finishes persisting the data that was missing,
     * instead of waiting for the next WP-Cron tick of the scheduled
     * fallback above.
     *
     * Fired on 'wc_gateway_stripe_process_response', which the WooCommerce
     * Stripe gateway calls (since v3.1.9, 2017) at the end of
     * process_response() — after it has stored the payment method / charge
     * data on the order — from every path that resolves a charge or intent:
     * classic checkout, the redirect/3DS confirm controller, every webhook
     * branch, and the Blocks/UPE gateway (saved cards, subscriptions,
     * pre-orders included). A SetupIntent-only flow with no charge never
     * reaches process_response(), but that path is already covered by the
     * existing 'woocommerce_payment_complete' hook.
     *
     * Purely additive: this never replaces the WP-Cron fallback scheduled by
     * maybe_schedule_missing_stripe_data_retry(), never touches its retry
     * counter (that quota belongs to the scheduled fallback only — a fast
     * retry that itself fails still goes through run_hold_creation() and
     * increments it exactly as before), and never re-derives which Hold
     * Groups need retrying: it simply reads the same
     * '_securehold_missing_data_retries' map the scheduled retry already
     * maintains (cleared per-group on success by run_hold_creation()), so a
     * group with no pending missing_stripe_data entry costs nothing here.
     *
     * Duplicate-PaymentIntent safety comes entirely from primitives that
     * already exist: create_hold_for_order()'s cross-request lock and
     * per-request guard, plus run_hold_creation()'s own terminal-status
     * check for this exact group_key — both run unchanged, so this fast
     * path racing the WP-Cron retry (or firing twice itself) can never
     * result in two calls reaching Stripe for the same group.
     *
     * @since 3.5.0
     *
     * @param object   $response Stripe charge/intent response object (unused —
     *                            only the order's own persisted state matters here).
     * @param WC_Order $order
     * @return void
     */
    public function maybe_fast_retry_missing_stripe_data( $response, $order ) {
        if ( ! $order || ! is_object( $order ) || ! method_exists( $order, 'get_payment_method' ) ) {
            return;
        }

        if ( strpos( $order->get_payment_method(), 'stripe' ) === false ) {
            return;
        }

        $retries = $order->get_meta( '_securehold_missing_data_retries', true );
        if ( ! is_array( $retries ) || empty( $retries ) ) {
            return;
        }

        $order_id = $order->get_id();

        foreach ( array_keys( $retries ) as $dedup_key ) {
            $group_key = ( '' === $dedup_key ) ? null : $dedup_key;

            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Scheduler: missing_stripe_data, fast-path retry triggered by wc_gateway_stripe_process_response', array(
                    'order_id'  => $order_id,
                    'group_key' => $group_key,
                ), 'info' );
            }

            $this->create_hold_for_order( $order_id, true, $group_key );
        }
    }

    /**
     * Actual hold creation. Reached only through create_hold_for_order(),
     * which owns the idempotence guards.
     *
     * @since 3.4.4 Extracted unchanged from create_hold_for_order().
     *
     * @param int         $order_id        WooCommerce order ID.
     * @param bool        $force_execution Skip the 60-second deduplication guard.
     * @param string|null $group_key       Opaque Hold Group identifier, or null for the
     *                                     default group. Every existing call site passes
     *                                     nothing, so this is dormant until a caller (a
     *                                     future grouping policy) opts a commande in.
     * @return bool|WP_Error|void
     */
    private function run_hold_creation($order_id, $force_execution = false, $group_key = null) {

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
            // Keyed by group_key (empty string for the default/legacy group),
            // never by order_id alone: a commande with more than one Hold
            // Group must let each group occupy its OWN 60-second window. A
            // single order-wide timestamp meant the first group to reach the
            // Stripe call anywhere in the same request stamped the order for
            // every other group too, so the second group's legitimate retry
            // was turned away by a window that was never its own — the exact
            // cause of order #68 losing Booking A's hold while Booking B's
            // succeeded. Reading a legacy bare timestamp (pre-fix) as the
            // default group's own entry keeps existing installs unaffected.
            $attempt_stamps = $order->get_meta('_securehold_attempt_made', true);
            if (!is_array($attempt_stamps)) {
                $attempt_stamps = empty($attempt_stamps) ? array() : array('' => $attempt_stamps);
            }
            $dedup_key = $group_key !== null ? (string) $group_key : '';
            $attempt_timestamp = isset($attempt_stamps[$dedup_key]) ? $attempt_stamps[$dedup_key] : null;

            if (!empty($attempt_timestamp) && (time() - (int)$attempt_timestamp) < 60) {
                if (function_exists('securehold_log')) {
                    securehold_log('Scheduler: Skipped (dedup < 60s)', array('order_id' => $order_id, 'group_key' => $group_key), 'debug');
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
            // Targets THIS group's own row, never "a" row of the order: on a
            // commande with more than one Hold Group, Group B being final
            // must not be read as Group A already being done, or vice versa.
            $existing = SecureHold_DB::get_hold_for_group($order_id, $group_key);
            // 'cancelled' is a deliberate admin action, not a transient failure —
            // unlike 'failed' (which a later WC hook re-fire is meant to retry),
            // an explicit cancellation must not be silently overridden by an
            // incidental status-change hook creating a hold anyway.
            if ($existing && in_array($existing->status, array('authorized', 'captured', 'released', 'cancelled'))) {
                if (function_exists('securehold_log')) {
                    securehold_log('Scheduler: Skipped (deposit already final)', array(
                        'order_id'  => $order_id,
                        'group_key' => $group_key,
                        'status'    => $existing->status,
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

            // Booking Integrations extension point: compute() resolves an
            // ORDER-WIDE config (per_order or per_item_aggregated, per the
            // merchant's own aggregation setting) — correct for the default
            // group, but not aware of any other group_key. A grouping policy
            // that already resolved a specific booking's own amount/timing
            // (reusing Securehold_Deposit_Computation_Service::resolve_item_config()
            // on that booking's order item — never a second Rule Engine)
            // overrides the config for THAT group only through this filter.
            // Default: returns $config unchanged, so every existing
            // installation (group_key always null) is unaffected.
            $config = apply_filters( 'securehold_resolve_hold_config', $config, $order, $group_key );
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
             * @param bool        $handled    Whether the strategy has been handled (default false).
             * @param string      $strategy   Strategy key (e.g. 'immediate', 'manual').
             * @param int         $order_id   WooCommerce order ID.
             * @param array       $config     Resolved configuration payload from Computation Service.
             * @param string|null $group_key  Opaque Hold Group identifier, or null for the
             *                                default group. Added so a grouping policy's
             *                                delayed/status bookings can be scheduled
             *                                independently instead of forcing a whole-order
             *                                fallback (@since 3.4.7). A handler registered
             *                                with the pre-3.4.7 4-argument signature keeps
             *                                working unchanged — WordPress simply never
             *                                passes it the 5th argument.
             */
            $strategy_handled = apply_filters( 'securehold_create_hold_strategy', false, $strategy, $order_id, $config, $group_key );
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
                // Check if a pending entry already exists — for THIS group, not
                // an arbitrary one of the order.
                if (class_exists('SecureHold_DB')) {
                    $existing = SecureHold_DB::get_hold_for_group($order_id, $group_key);
                    if ($existing) {
                        return true; // Already has an entry
                    }

                    // Create pending_manual entry
                    $pending_data = array(
                        'order_id' => $order_id,
                        'group_key' => $group_key,
                        'customer_id' => $order->get_meta('_stripe_customer_id', true) ?: 'pending',
                        'intent_id' => 'pending_manual_' . $order_id . '_' . time(),
                        'payment_method_id' => $order->get_meta('_stripe_source_id', true) ?: 'pending',
                        'amount' => 0,
                        'captured_amount' => 0,
                        'currency' => $order->get_currency(),
                        'status' => 'pending_manual',
                        'notes' => __('Awaiting manual hold creation by admin.', 'securehold-security-deposit-holds'),
                        // Diagnostic-only (Booking Integrations, e.g. provider/booking_id) —
                        // set only when a caller's config override provides it.
                        'metadata' => isset( $config['metadata'] ) ? $config['metadata'] : null,
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
            self::maybe_schedule_missing_stripe_data_retry( $order, $order_id, $group_key );

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
            // Same Booking Integrations override point as the non-force path
            // above — this is the branch a booking's own scheduled cron
            // actually runs through (force_execution = true), so a group's
            // resolved amount/timing must be re-applied here too.
            $config = apply_filters( 'securehold_resolve_hold_config', $config, $order, $group_key );

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
            // Same per-group map the check above reads. Only THIS group's
            // entry is stamped — sibling groups in the same commande keep
            // their own independent window, open or already occupied.
            $attempt_stamps = $order->get_meta('_securehold_attempt_made', true);
            if (!is_array($attempt_stamps)) {
                $attempt_stamps = empty($attempt_stamps) ? array() : array('' => $attempt_stamps);
            }
            $dedup_key = $group_key !== null ? (string) $group_key : '';
            $attempt_stamps[$dedup_key] = time();
            $order->update_meta_data('_securehold_attempt_made', $attempt_stamps);
            $order->save();
        }

        // A placeholder for THIS group (scheduled/pending_manual/failed) may
        // already exist — its id is the stable identity build_idempotency_key()
        // prefers, and the one Stripe metadata should report. Immediate's very
        // first attempt has none yet; the fallback in build_idempotency_key()
        // covers that case (order_id, group_key).
        $existing_hold_for_group = class_exists('SecureHold_DB') ? SecureHold_DB::get_hold_for_group($order_id, $group_key) : null;
        $hold_id_for_stripe      = $existing_hold_for_group ? $existing_hold_for_group->id : null;

        $intent = SecureHold_Stripe::create_payment_intent($amount, $currency, $customer_id, $payment_method_id, $order_id, $hold_id_for_stripe, $group_key);

        if (is_wp_error($intent)) {
            $error_code = $intent->get_error_code();
            $error_message = $intent->get_error_message();

            if (function_exists('securehold_log')) {
                securehold_log('Hold creation failed', array(
                    'error' => $error_message,
                    'error_code' => $error_code,
                    'order_id' => $order_id,
                    'group_key' => $group_key,
                    'hold_id' => $hold_id_for_stripe,
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                ), 'error');
            }

            // ── Mark deposit as FAILED with actionable note ──
            // Do NOT delete the deposit row — keep it visible in the admin UI.
            // THIS group's row, never an arbitrary one of the order: Hold B
            // failing must never touch Hold A's row, whatever A's own status.
            if (class_exists('SecureHold_DB')) {
                $existing_deposit = SecureHold_DB::get_hold_for_group($order_id, $group_key);

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
                        'group_key'         => $group_key,
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

            // Fire deposit-failed email notification. Hold-level id included
            // for consistency with the other events even though this email
            // has no per-hold anti-duplicate guard today — Hold B failing
            // must be traceable back to Hold B specifically, not "the order".
            self::fire_email( 'securehold_deposit_failed', $order_id,
                (object) array( 'id' => $hold_id_for_stripe, 'amount' => $amount, 'currency' => $currency ) );

            // Notify admin of the failure.
            self::fire_email( 'securehold_admin_hold_failed', $order_id,
                (object) array( 'id' => $hold_id_for_stripe, 'amount' => $amount, 'currency' => $currency ) );

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
            'group_key' => $group_key,
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
            // Diagnostic-only (Booking Integrations, e.g. provider/booking_id) —
            // only present when a config override (booking policy) provided it.
            // The update-existing-placeholder branch below never touches this
            // column, so a placeholder's own metadata (set at its creation) survives.
            'metadata' => isset( $config['metadata'] ) ? $config['metadata'] : null,
            'created_at' => $authorized_at
        );

        // Row id of the hold this run just authorized, so the authorized-email
        // context can be tied to this specific hold rather than the order in
        // general (Securehold_Email_Manager::hold_key() / already_sent()).
        $authorized_hold_id = null;

        if (class_exists('SecureHold_DB')) {
            // Check if a scheduled/pending placeholder exists for THIS group —
            // update it instead of duplicating. Never a bare get_deposit($order_id):
            // on a commande with more than one group, that could pick up a
            // different hold's placeholder and corrupt it.
            $existing_deposit = SecureHold_DB::get_hold_for_group($order_id, $group_key);
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
                $authorized_hold_id = $existing_deposit->id;
            } else {
                SecureHold_DB::insert_deposit($hold_data);
                // insert_deposit() reports success/failure, not the new row's id
                // (other callers rely on that boolean contract) — a fresh read by
                // intent_id gets the id this specific attempt just created,
                // without guessing which row a bare get_deposit($order_id) would
                // return if another one already existed.
                $inserted = SecureHold_DB::get_deposit_by_intent($intent->id);
                $authorized_hold_id = $inserted ? $inserted->id : null;
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

        // Clear only THIS group's retry count — a sibling group still
        // mid-retry on the same order must keep its own counter untouched.
        $retries = $order->get_meta( '_securehold_missing_data_retries', true );
        if ( is_array( $retries ) ) {
            $dedup_key = $group_key !== null ? (string) $group_key : '';
            if ( isset( $retries[ $dedup_key ] ) ) {
                unset( $retries[ $dedup_key ] );
                $order->update_meta_data( '_securehold_missing_data_retries', $retries );
            }
        }
        $order->save();

        // Fire deposit-authorized email notification.
        self::fire_email( 'securehold_deposit_authorized', $order_id,
            (object) array( 'id' => $authorized_hold_id, 'amount' => $amount, 'currency' => $currency, 'intent_id' => $intent->id ) );

        // Notify admin of the new hold.
        self::fire_email( 'securehold_admin_hold_created', $order_id,
            (object) array( 'id' => $authorized_hold_id, 'amount' => $amount, 'currency' => $currency, 'intent_id' => $intent->id ) );

        return true;
    }

    public static function fire_email( $email_id, $order_id, $hold = null ) {
        if ( class_exists( 'Securehold_Email_Manager' ) ) {
            Securehold_Email_Manager::fire_email( $email_id, $order_id, $hold );
        }
    }
}