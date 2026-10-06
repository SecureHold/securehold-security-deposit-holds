<?php
/**
 * The first real Booking Grouping Policy: "1 booking = 1 Hold Group".
 *
 * This is a POLICY, not an engine rule — it lives entirely in the Booking
 * Integrations layer, registered through the Multi-Hold engine's existing
 * extension points (securehold_multi_hold_grouping_policies,
 * securehold_skip_default_hold_group, securehold_resolve_hold_config). It
 * never becomes a general rule of the engine itself, and every hook here is
 * a no-op unless Securehold_Multi_Hold::may_create_multiple_groups() is
 * true — which itself requires the feature flag, the admin's own "Multiple
 * Hold Groups" choice, AND this policy being registered, none of which any
 * existing installation has by default.
 *
 * Supports all five timing strategies group-independently: 'immediate',
 * 'manual' and 'scheduled' are handled directly here (see
 * resolve_groups_for_order() / maybe_create_hold_groups()); 'delayed' and
 * 'status' are PRO-only strategies dispatched through the
 * 'securehold_create_hold_strategy' filter into Securehold_Pro_Strategy_Engine
 * — as of PRO 1.2.x that filter and PRO's strategy methods are group-aware
 * (@since 3.4.7, paired with a PRO-side change), so a booking resolving to
 * 'delayed' or 'status' schedules/waits independently for its own group_key
 * exactly like every other strategy. A resolved strategy this policy still
 * cannot schedule per-group (none today) falls back to the historical
 * whole-order single-hold path rather than silently mis-scheduling one
 * booking's deposit.
 *
 * @package SecureHold
 * @since   1.4.0 (Multi-Hold engine, Booking Integrations increment)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Booking_Grouping_Policy {

    // Renamed from 'booking_per_booking' to match the grouping SOURCE
    // naming convention introduced with securehold_multi_hold_grouping_source
    // (WooCommerce Native Multi-Hold increment) — a source key, not a
    // description of the algorithm. Cosmetic only: this class is not
    // require_once'd nor ::register()'d in this release (see bootstrap),
    // so the rename has no runtime effect until it is reactivated.
    const POLICY_KEY = 'booking_activities';

    /** Per-request memo: order_id => array of resolved groups, or null if not applicable. */
    private static $resolved = array();

    /**
     * Wire every extension point this policy uses. Called once from plugin
     * bootstrap. Every hook registered here is inert unless
     * Securehold_Multi_Hold::may_create_multiple_groups() is true.
     *
     * @return void
     */
    public static function register() {
        add_filter( 'securehold_multi_hold_grouping_policies', array( __CLASS__, 'declare_policy' ) );
        add_filter( 'securehold_skip_default_hold_group', array( __CLASS__, 'maybe_skip_default' ), 10, 2 );
        add_filter( 'securehold_resolve_hold_config', array( __CLASS__, 'maybe_override_config' ), 10, 3 );

        // Same WooCommerce events Securehold_Core wires the engine's default
        // (group_key = null) hold creation to, at an earlier priority so a
        // decision for this order is already cached by the time that
        // default hook runs and asks securehold_skip_default_hold_group.
        add_action( 'woocommerce_payment_complete', array( __CLASS__, 'maybe_create_hold_groups' ), 5, 1 );
        add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_create_hold_groups' ), 5, 1 );
        add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_create_hold_groups' ), 5, 1 );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'maybe_create_hold_groups' ), 5, 1 );
    }

    /**
     * @param array $policies
     * @return array
     */
    public static function declare_policy( $policies ) {
        $policies[] = array(
            'key'   => self::POLICY_KEY,
            'label' => __( 'One booking = one security deposit', 'securehold-security-deposit-holds' ),
        );
        return $policies;
    }

    /**
     * @param bool $skip
     * @param int  $order_id
     * @return bool
     */
    public static function maybe_skip_default( $skip, $order_id ) {
        if ( ! self::is_active_source() ) {
            return $skip; // Declared, but not the chosen grouping source — stay inert.
        }
        self::maybe_create_hold_groups( $order_id );
        return ! empty( self::$resolved[ (int) $order_id ] ) ? true : $skip;
    }

    /**
     * "Am I the active grouping source?" Exactly one grouping policy may
     * originate Hold Groups at a time (securehold_multi_hold_grouping_source) —
     * this self-check is what stops this policy from also acting once it is
     * reactivated alongside another registered policy (e.g. WooCommerce
     * Native) that is not the chosen source.
     *
     * @return bool
     */
    private static function is_active_source() {
        return class_exists( 'Securehold_Multi_Hold' ) && Securehold_Multi_Hold::is_active_grouping_source( self::POLICY_KEY );
    }

    /**
     * @param array       $config
     * @param WC_Order    $order
     * @param string|null $group_key
     * @return array
     */
    public static function maybe_override_config( $config, $order, $group_key ) {
        if ( $group_key === null || ! $order || ! self::is_active_source() ) {
            return $config;
        }

        // A WP-Cron-deferred retry (e.g. the missing_stripe_data fallback in
        // Securehold_Scheduler) runs in its own PHP process, where this
        // per-request memo is still empty even though this exact order was
        // already resolved once earlier. Without this lazy re-resolve, this
        // filter silently no-ops and the retried group falls back to the
        // order-wide config instead of its own Hold Group's amount/timing.
        // resolve_groups_for_order() is pure (re-derives from the DB/bookings,
        // no side effect), so recomputing it here is safe and idempotent.
        $groups = self::ensure_resolved_groups( $order->get_id() );

        foreach ( $groups as $group ) {
            if ( $group['group_key'] === $group_key ) {
                // item_config already carries the same field shape compute()
                // returns (source/source_id/source_label/timing/delay_days/
                // date_field_key/scheduled_days/scheduled_direction/
                // trigger_status/fallbacks) via
                // Securehold_Deposit_Computation_Service::resolve_item_config() —
                // merged over $config so no key a downstream reader expects
                // is ever missing, then the qty-adjusted amount and a clear
                // aggregation_mode marker are applied last.
                return array_merge( $config, $group['item_config'], array(
                    'deposit_amount'          => (string) round( $group['amount'], 2 ),
                    'deposit_amount_resolved' => round( $group['amount'], 2 ),
                    'aggregation_mode'        => 'booking',
                    'metadata'                => self::build_metadata( $group['booking'] ),
                ) );
            }
        }

        return $config;
    }

    /**
     * Resolve this order's Hold Groups (memoized per request) and, for the
     * 'immediate'/'manual' groups, let them run through the normal engine
     * entry point. 'scheduled' groups are scheduled directly here (see class
     * docblock for why). Does nothing at all unless multi-hold booking is
     * actually enabled end to end.
     *
     * @param int $order_id
     * @return void
     */
    public static function maybe_create_hold_groups( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || array_key_exists( $order_id, self::$resolved ) ) {
            return; // already resolved earlier in this request (one of the four WC hooks already ran it)
        }
        if ( ! self::is_active_source() ) {
            return; // Declared, but not the chosen grouping source — stay inert.
        }

        $groups = self::ensure_resolved_groups( $order_id );

        if ( empty( $groups ) ) {
            return; // nothing resolved — order falls through to the untouched default single-hold path
        }

        if ( ! class_exists( 'Securehold_Scheduler' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/cron/class-securehold-wp-scheduler.php';
        }
        $scheduler = new Securehold_Scheduler();

        foreach ( $groups as $group ) {
            if ( $group['timing'] === 'scheduled' ) {
                self::schedule_group( $order_id, $group );
                continue;
            }
            // 'immediate', 'manual', 'delayed' and 'status': the normal engine
            // entry point, which calls back into maybe_override_config() above
            // for this specific group_key's amount/timing, then (for delayed/
            // status) into PRO's group-aware strategy engine via the
            // securehold_create_hold_strategy filter.
            $scheduler->create_hold_for_order( $order_id, false, $group['group_key'] );
        }
    }

    /**
     * Resolve this order's Hold Groups if not already memoized in this
     * request, and memoize the result either way (null when multi-hold
     * booking does not apply at all, so future calls in the same request
     * short-circuit instead of re-checking may_create_multiple_groups()).
     *
     * Pure memo-and-resolve, no hold-creation side effect — safe to call
     * from a read path (maybe_override_config()) as well as the
     * write/orchestration path (maybe_create_hold_groups()).
     *
     * @since 3.4.7
     * @param int $order_id
     * @return array Empty when not applicable to this order.
     */
    private static function ensure_resolved_groups( $order_id ) {
        $order_id = (int) $order_id;

        if ( array_key_exists( $order_id, self::$resolved ) ) {
            return ! empty( self::$resolved[ $order_id ] ) ? self::$resolved[ $order_id ] : array();
        }

        if ( $order_id <= 0 || ! class_exists( 'Securehold_Multi_Hold' ) || ! Securehold_Multi_Hold::may_create_multiple_groups() ) {
            self::$resolved[ $order_id ] = null;
            return array();
        }

        $groups = self::resolve_groups_for_order( $order_id );
        self::$resolved[ $order_id ] = $groups;

        return $groups;
    }

    /**
     * Resolve every Hold Group this order should have, or an empty array if
     * this order does not qualify for booking multi-hold at all (no active
     * bookings, an ambiguous order item, an unresolvable product/amount, or
     * a resolved strategy this policy cannot schedule per-group).
     *
     * Whole-order fallback is deliberate (mission section 6): mixing some
     * items in booking multi-hold and one ambiguous item in the legacy path
     * on the SAME order would be confusing and is explicitly disallowed.
     *
     * @param int $order_id
     * @return array
     */
    private static function resolve_groups_for_order( $order_id ) {
        if ( ! class_exists( 'Securehold_Booking_Integration_Manager' ) ) {
            return array();
        }

        $bookings = array_filter(
            Securehold_Booking_Integration_Manager::get_bookings_for_order( $order_id ),
            function ( $b ) { return $b->is_active(); }
        );

        if ( empty( $bookings ) ) {
            return array();
        }

        // ── Ambiguity guard: more than one active booking on the same order item ──
        $by_item = array();
        foreach ( $bookings as $booking ) {
            $by_item[ $booking->order_item_id ][] = $booking;
        }
        foreach ( $by_item as $item_id => $item_bookings ) {
            if ( count( $item_bookings ) > 1 ) {
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( sprintf(
                        'Booking multi-hold skipped: order item %d contains %d active bookings; amount allocation is ambiguous. Falling back to legacy single-hold behavior.',
                        $item_id,
                        count( $item_bookings )
                    ), array(
                        'order_id'         => $order_id,
                        'order_item_id'    => $item_id,
                        'active_bookings'  => count( $item_bookings ),
                    ), 'warning' );
                }
                return array();
            }
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array();
        }

        if ( ! class_exists( 'Securehold_Deposit_Computation_Service' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/services/class-securehold-wp-computation-service.php';
        }
        if ( ! class_exists( 'Securehold_Config_Resolver' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-config-resolver.php';
        }

        $policy = Securehold_Config_Resolver::get_active_policy();
        $engine = Securehold_Config_Resolver::get_engine_version();
        $groups = array();

        foreach ( $bookings as $booking ) {
            $item = $order->get_item( $booking->order_item_id );
            $product_id = $item ? $item->get_product_id() : 0;

            if ( ! $product_id ) {
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Booking multi-hold skipped: order item has no resolvable product. Falling back to legacy single-hold behavior.', array(
                        'order_id'      => $order_id,
                        'order_item_id' => $booking->order_item_id,
                        'provider'      => $booking->provider,
                        'booking_id'    => $booking->booking_id,
                    ), 'warning' );
                }
                return array();
            }

            // The ONE reuse point of the existing Rule Engine — never a
            // second implementation. Same call compute_per_item_aggregated()
            // itself makes for a plain order-level per-item aggregation.
            $item_config = Securehold_Deposit_Computation_Service::resolve_item_config( $product_id, $order, $policy, $engine );
            $unit_amount = (float) $item_config['deposit_amount_resolved'];
            $qty         = $item->get_quantity();
            $amount      = $unit_amount * $qty;

            if ( $amount <= 0 ) {
                // Nothing to hold for this booking (excluded product, zero
                // rule, etc.) — not ambiguous, just genuinely zero. Skip it
                // without affecting the rest of the order's booking groups.
                continue;
            }

            $timing = $item_config['timing'];

            if ( ! in_array( $timing, array( 'immediate', 'manual', 'scheduled', 'delayed', 'status' ), true ) ) {
                // Any strategy this policy still cannot schedule per-group
                // (none today) — whole-order fallback rather than a silently
                // wrong schedule. 'delayed'/'status' are handled below like
                // 'immediate'/'manual': dispatched to create_hold_for_order()
                // with this group's own group_key, which now reaches PRO's
                // group-aware strategy engine (@since 3.4.7).
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( sprintf(
                        "Booking multi-hold skipped: resolved timing strategy '%s' for order item %d cannot be scheduled per Hold Group in this MVP. Falling back to legacy single-hold behavior.",
                        $timing,
                        $booking->order_item_id
                    ), array( 'order_id' => $order_id, 'order_item_id' => $booking->order_item_id, 'timing' => $timing ), 'warning' );
                }
                return array();
            }

            $scheduled_timestamp = null;
            if ( $timing === 'scheduled' ) {
                $scheduled_timestamp = self::resolve_scheduled_timestamp( $booking->start_datetime, $item_config );
                if ( $scheduled_timestamp === null ) {
                    if ( function_exists( 'securehold_log' ) ) {
                        securehold_log( 'Booking multi-hold skipped: booking start date could not be resolved to a schedulable timestamp. Falling back to legacy single-hold behavior.', array(
                            'order_id'   => $order_id,
                            'provider'   => $booking->provider,
                            'booking_id' => $booking->booking_id,
                        ), 'warning' );
                    }
                    return array();
                }
            }

            $groups[] = array(
                'group_key'  => $booking->group_key(),
                'booking'    => $booking,
                'amount'     => $amount,
                'currency'   => $order->get_currency(),
                'item_config' => $item_config,
                'timing'     => $timing,
                'scheduled_timestamp' => $scheduled_timestamp,
            );

            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Integrations: Hold Group resolved', array(
                    'order_id'      => $order_id,
                    'provider'      => $booking->provider,
                    'booking_id'    => $booking->booking_id,
                    'order_item_id' => $booking->order_item_id,
                    'group_key'     => $booking->group_key(),
                    'amount'        => $amount,
                    'timing'        => $timing,
                    'booking_start' => $booking->start_datetime,
                    'scheduled_for' => $scheduled_timestamp ? gmdate( 'Y-m-d H:i:s', $scheduled_timestamp ) : null,
                ), 'info' );
            }
        }

        return $groups;
    }

    /**
     * Booking start date -> a real Unix timestamp, applying the same
     * scheduled_days/scheduled_direction offset the order-level Scheduled
     * strategy already supports (Securehold_Config_Resolver / PRO's own
     * schedule_scheduled_hold() use the identical arithmetic) — not a second
     * implementation of Scheduled, the same offset semantics reapplied to a
     * per-booking base date instead of an order-level one.
     *
     * Relies entirely on the provider adapter having already produced a
     * fully-qualified datetime string (timezone offset included) — this
     * class has no provider-specific timezone knowledge, by design.
     *
     * @param string|null $start_datetime  ISO 8601 string with offset, or null.
     * @param array       $item_config
     * @return int|null
     */
    private static function resolve_scheduled_timestamp( $start_datetime, $item_config ) {
        if ( empty( $start_datetime ) ) {
            return null;
        }

        try {
            $base = new DateTime( $start_datetime );
        } catch ( \Exception $e ) {
            return null;
        }

        $days      = isset( $item_config['scheduled_days'] ) ? (int) $item_config['scheduled_days'] : 0;
        $direction = isset( $item_config['scheduled_direction'] ) ? $item_config['scheduled_direction'] : 'before';

        $timestamp = $base->getTimestamp();

        if ( $days > 0 ) {
            $offset    = $days * DAY_IN_SECONDS;
            $timestamp = ( 'after' === $direction ) ? ( $timestamp + $offset ) : ( $timestamp - $offset );
        }

        return $timestamp;
    }

    /**
     * Directly create the 'scheduled' placeholder row and its WP-Cron event
     * for one Hold Group, using the group-aware primitives the Multi-Hold
     * engine already exposes (Securehold_Scheduler::schedule_hold_event(),
     * SecureHold_DB::insert_deposit()) — the same shape PRO's own
     * schedule_scheduled_hold() produces for the default group, reused here
     * rather than duplicated, minus the PRO dependency this policy cannot
     * take (see class docblock).
     *
     * @param int   $order_id
     * @param array $group
     * @return void
     */
    private static function schedule_group( $order_id, array $group ) {
        if ( ! class_exists( 'SecureHold_DB' ) || ! class_exists( 'Securehold_Scheduler' ) ) {
            return;
        }

        $existing = SecureHold_DB::get_hold_for_group( $order_id, $group['group_key'] );
        if ( $existing ) {
            return; // Already has a row for this group (re-entrant WC hook firing) — do not duplicate.
        }

        SecureHold_DB::insert_deposit( array(
            'order_id'          => $order_id,
            'group_key'         => $group['group_key'],
            'customer_id'       => 'pending',
            'intent_id'         => 'scheduled_' . $order_id . '_' . substr( md5( $group['group_key'] ), 0, 12 ) . '_' . time(),
            'payment_method_id' => 'pending',
            'amount'            => $group['amount'],
            'captured_amount'   => 0,
            'currency'          => $group['currency'],
            'status'            => 'scheduled',
            'scheduled_for'     => gmdate( 'Y-m-d H:i:s', $group['scheduled_timestamp'] ),
            'notes'             => sprintf(
                __( 'Scheduled from %1$s booking #%2$s.', 'securehold-security-deposit-holds' ),
                $group['booking']->provider,
                $group['booking']->booking_id
            ),
            'metadata'          => self::build_metadata( $group['booking'] ),
            'created_at'        => current_time( 'mysql' ),
        ) );

        Securehold_Scheduler::schedule_hold_event( $group['scheduled_timestamp'], $order_id, $group['group_key'] );
    }

    /**
     * Diagnostic-only JSON for the hold row's metadata column (Phase 13).
     * Never read by grouping/amount/timing/capture logic — admin UI and
     * support only. No new DB column: reuses the existing dormant one.
     *
     * @param Securehold_Normalized_Booking $booking
     * @return array
     */
    private static function build_metadata( Securehold_Normalized_Booking $booking ) {
        $metadata = array(
            'provider'      => $booking->provider,
            'booking_id'    => $booking->booking_id,
            'order_item_id' => $booking->order_item_id,
            'booking_start' => $booking->start_datetime, // display-only, admin UI (Phase 14)
        );

        if ( ! empty( $booking->metadata['booking_group_id'] ) ) {
            $metadata['booking_group_id'] = $booking->metadata['booking_group_id'];
        }

        return $metadata;
    }
}
