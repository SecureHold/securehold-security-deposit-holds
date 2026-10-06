<?php
/**
 * Booking Integration Manager — the one place that knows about registered
 * booking provider adapters, independent of which ones actually ship.
 *
 * Owns: provider registration/detection, normalized-booking retrieval,
 * order-item collision prevention across simultaneous providers, and
 * routing a provider's own reschedule/cancel events to the engine through
 * the existing Multi-Hold public API. Never calls Stripe directly.
 *
 * @package SecureHold
 * @since   1.4.0 (Multi-Hold engine, Booking Integrations increment)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Booking_Integration_Manager {

    /** @var Securehold_Booking_Provider_Interface[] */
    private static $providers = array();

    /**
     * Register a provider adapter. Safe to call unconditionally at plugin
     * bootstrap — registration is not the same as being active; that is
     * checked per-call via is_active().
     *
     * @param Securehold_Booking_Provider_Interface $provider
     * @return void
     */
    public static function register_provider( Securehold_Booking_Provider_Interface $provider ) {
        self::$providers[ $provider->get_provider_key() ] = $provider;
    }

    /** @return Securehold_Booking_Provider_Interface[] Every registered provider, active or not. */
    public static function get_registered_providers() {
        return self::$providers;
    }

    /** @return Securehold_Booking_Provider_Interface[] Only providers currently active. */
    public static function get_active_providers() {
        return array_filter( self::$providers, function ( $provider ) {
            try {
                return (bool) $provider->is_active();
            } catch ( \Throwable $e ) {
                // A misbehaving adapter must never break checkout or admin.
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Booking Integrations: provider is_active() threw', array(
                        'provider' => $provider->get_provider_key(),
                        'error'    => $e->getMessage(),
                    ), 'error' );
                }
                return false;
            }
        } );
    }

    /**
     * Every active booking for one order, across every active provider,
     * with order_item collisions between providers detected and excluded
     * rather than silently double-counted.
     *
     * @param int $order_id
     * @return Securehold_Normalized_Booking[]  Keyed numerically, not by order_item_id
     *                                          (an order_item can carry more than one booking).
     */
    public static function get_bookings_for_order( $order_id ) {
        $order_id = (int) $order_id;

        // Two passes on purpose: a collision can only be known once every
        // provider has been asked, so a single pass that excludes on first
        // sight of a conflict would still leave the FIRST provider's
        // booking for that item already added — this collects everything
        // first, then drops any order_item claimed by more than one
        // distinct provider, before anything is returned.
        $by_item = array(); // order_item_id => [ Securehold_Normalized_Booking, ... ]

        foreach ( self::get_active_providers() as $provider ) {
            try {
                $provider_bookings = $provider->get_bookings_for_order( $order_id );
            } catch ( \Throwable $e ) {
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Booking Integrations: provider get_bookings_for_order() threw', array(
                        'provider' => $provider->get_provider_key(),
                        'order_id' => $order_id,
                        'error'    => $e->getMessage(),
                    ), 'error' );
                }
                continue;
            }

            if ( ! is_array( $provider_bookings ) ) {
                continue;
            }

            foreach ( $provider_bookings as $booking ) {
                if ( ! ( $booking instanceof Securehold_Normalized_Booking ) ) {
                    continue;
                }
                $by_item[ $booking->order_item_id ][] = $booking;
            }
        }

        $bookings = array();

        foreach ( $by_item as $item_id => $item_bookings ) {
            $providers_for_item = array_unique( array_map( function ( $b ) { return $b->provider; }, $item_bookings ) );

            if ( count( $providers_for_item ) > 1 ) {
                // Two different providers both claim the same order item.
                // Phase 11: never guess which one is "right" — exclude every
                // booking on it rather than risk double-processing or a
                // wrong amount resolution. The order still falls back safely
                // through the normal single-hold path for this item.
                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Booking Integrations: order item claimed by more than one provider, excluded', array(
                        'order_id'      => $order_id,
                        'order_item_id' => $item_id,
                        'providers'     => array_values( $providers_for_item ),
                    ), 'warning' );
                }
                continue;
            }

            foreach ( $item_bookings as $booking ) {
                $bookings[] = $booking;
            }
        }

        return $bookings;
    }

    /**
     * Retrieve the hold row for one specific booking, via the Multi-Hold
     * engine's own unambiguous group lookup — never a bare order_id read.
     *
     * @param string     $provider
     * @param int|string $booking_id
     * @param int        $order_id
     * @return object|null  Row from securehold_holds, or null if none exists yet.
     */
    public static function get_hold_for_booking( $provider, $booking_id, $order_id ) {
        if ( ! class_exists( 'SecureHold_DB' ) ) {
            return null;
        }
        $group_key = 'bk:' . $provider . ':' . $booking_id;
        return SecureHold_DB::get_hold_for_group( (int) $order_id, $group_key );
    }

    /**
     * Route a provider's "this booking was rescheduled" event to the engine.
     *
     * No financial automation lives here — only the scheduled-vs-not-yet
     * distinction the Multi-Hold engine's own status model already makes.
     *
     * @param string     $provider
     * @param int|string $booking_id
     * @param int        $order_id
     * @param int|null   $new_timestamp  Unix timestamp already resolved by the caller
     *                                   (a provider adapter, which alone knows how to
     *                                   read its own datetime/timezone correctly).
     *                                   Null means "could not resolve a new schedule
     *                                   time" — the event is only logged, nothing acted on.
     * @return void
     */
    public static function handle_booking_rescheduled( $provider, $booking_id, $order_id, $new_timestamp ) {
        $hold = self::get_hold_for_booking( $provider, $booking_id, $order_id );

        if ( ! $hold ) {
            return; // No Hold Group exists yet for this booking — nothing to reschedule.
        }

        $group_key = 'bk:' . $provider . ':' . $booking_id;

        if ( $hold->status === 'scheduled' && $new_timestamp !== null && class_exists( 'Securehold_Scheduler' ) ) {
            Securehold_Scheduler::reschedule_hold( (int) $order_id, (int) $new_timestamp, $group_key );
            if ( class_exists( 'SecureHold_DB' ) ) {
                // scheduled_for drives the actual cron re-fire and was already
                // correct; the diagnostic-only metadata JSON (admin display,
                // e.g. "Booking start:") is a separate column update_hold()
                // never merges automatically, so it stayed frozen at whatever
                // the hold's original creation set it to. Re-encode it here
                // with the same shape build_metadata() produces, updating only
                // booking_start — every other key (provider, booking_id,
                // order_item_id, booking_group_id) is preserved as-is.
                $metadata = json_decode( $hold->metadata, true );
                if ( ! is_array( $metadata ) ) {
                    $metadata = array();
                }
                $metadata['booking_start'] = gmdate( 'c', $new_timestamp );

                SecureHold_DB::update_hold( $hold->id, array(
                    'scheduled_for' => gmdate( 'Y-m-d H:i:s', $new_timestamp ),
                    'metadata'      => wp_json_encode( $metadata ),
                ) );
            }
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Integrations: reschedule applied', array(
                    'order_id'   => $order_id,
                    'hold_id'    => $hold->id,
                    'provider'   => $provider,
                    'booking_id' => $booking_id,
                    'group_key'  => $group_key,
                    'new_run_at' => $new_timestamp,
                ), 'info' );
            }
            return;
        }

        if ( in_array( $hold->status, array( 'authorized', 'captured', 'released', 'failed', 'cancelled' ), true ) ) {
            // No financial automation, ever, for a booking moved after its
            // hold left the 'scheduled' state — a human decides what (if
            // anything) happens next.
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Integrations: booking rescheduled after hold left scheduled state — no automatic action taken', array(
                    'order_id'    => $order_id,
                    'hold_id'     => $hold->id,
                    'hold_status' => $hold->status,
                    'provider'    => $provider,
                    'booking_id'  => $booking_id,
                ), 'warning' );
            }
        }
    }

    /**
     * Route a provider's "this booking was cancelled" event to the engine.
     *
     * @param string     $provider
     * @param int|string $booking_id
     * @param int        $order_id
     * @return void
     */
    public static function handle_booking_cancelled( $provider, $booking_id, $order_id ) {
        $hold = self::get_hold_for_booking( $provider, $booking_id, $order_id );

        if ( ! $hold ) {
            return;
        }

        $group_key = 'bk:' . $provider . ':' . $booking_id;

        if ( $hold->status === 'scheduled' && class_exists( 'Securehold_Scheduler' ) && class_exists( 'SecureHold_DB' ) ) {
            Securehold_Scheduler::cancel_scheduled_hold( (int) $order_id, $group_key );
            SecureHold_DB::update_hold( $hold->id, array(
                'status' => 'cancelled',
                'notes'  => __( 'Cancelled: the underlying booking was cancelled before this hold was created.', 'securehold-security-deposit-holds' ),
            ) );
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Integrations: booking cancelled, scheduled hold cancelled', array(
                    'order_id'   => $order_id,
                    'hold_id'    => $hold->id,
                    'provider'   => $provider,
                    'booking_id' => $booking_id,
                ), 'info' );
            }
            return;
        }

        // authorized / captured / released / failed: no financial automation.
        // Logged so support/admin can decide, per the mission's explicit rule.
        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Booking Integrations: booking cancelled after hold left scheduled state — no automatic action taken', array(
                'order_id'    => $order_id,
                'hold_id'     => $hold->id,
                'hold_status' => $hold->status,
                'provider'    => $provider,
                'booking_id'  => $booking_id,
            ), 'warning' );
        }
    }
}
