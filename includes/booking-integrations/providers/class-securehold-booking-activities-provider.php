<?php
/**
 * Booking Activities adapter — as small as the interface allows.
 *
 * Everything Booking Activities-specific lives here: detecting the plugin,
 * calling its real public function, reading the real shape of its booking
 * objects (VERIFIED against the plugin's own source, WordPress.org SVN
 * trunk, 2026-09-18), and translating its business timezone setting into an
 * absolute datetime string. No grouping, amount, timing, Stripe or lock
 * logic lives here — that is the Manager's and the Grouping Policy's job.
 *
 * @package SecureHold
 * @since   1.4.0 (Multi-Hold engine, Booking Integrations increment)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Booking_Activities_Provider implements Securehold_Booking_Provider_Interface {

    const PROVIDER_KEY = 'booking-activities';

    /**
     * Wire this provider into the Booking Integrations layer. Called once
     * from plugin bootstrap. Registration alone changes nothing — every
     * hook below stays inert unless is_active() is also true AND
     * Securehold_Multi_Hold::may_create_multiple_groups() allows it.
     *
     * @return void
     */
    public static function register() {
        if ( ! class_exists( 'Securehold_Booking_Integration_Manager' ) ) {
            return;
        }

        Securehold_Booking_Integration_Manager::register_provider( new self() );

        // bookacti_bookings_rescheduled is a FILTER, not an action (VERIFIED,
        // controller/controller-bookings.php,
        // bookacti_controller_reschedule_bookings()) — fires AFTER the new
        // dates are persisted. Must return $updated unchanged.
        add_filter( 'bookacti_bookings_rescheduled', array( __CLASS__, 'on_bookings_rescheduled' ), 10, 3 );

        // Real do_action() hooks (VERIFIED), fired after the DB write, from
        // exactly three controller functions: cancel, refund, change-status.
        add_action( 'bookacti_booking_status_changed', array( __CLASS__, 'on_booking_status_changed' ), 10, 3 );
        add_action( 'bookacti_booking_group_status_changed', array( __CLASS__, 'on_booking_group_status_changed' ), 10, 4 );
    }

    /** @return bool */
    public function is_active() {
        return function_exists( 'bookacti_wc_get_order_items_bookings' );
    }

    /** @return string */
    public function get_provider_key() {
        return self::PROVIDER_KEY;
    }

    /**
     * @param int $order_id
     * @return Securehold_Normalized_Booking[]
     */
    public function get_bookings_for_order( $order_id ) {
        if ( ! $this->is_active() ) {
            return array();
        }

        try {
            // VERIFIED signature: bookacti_wc_get_order_items_bookings( $order_id, $filters = array() )
            // VERIFIED return shape: array keyed by order_item_id => array of
            // { id, type: 'single'|'group', bookings: [...], booking_group: [] }
            $order_items_bookings = bookacti_wc_get_order_items_bookings( (int) $order_id );
        } catch ( \Throwable $e ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Activities provider: bookacti_wc_get_order_items_bookings() threw', array(
                    'order_id' => $order_id,
                    'error'    => $e->getMessage(),
                ), 'error' );
            }
            return array();
        }

        if ( ! is_array( $order_items_bookings ) ) {
            return array();
        }

        $normalized = array();

        foreach ( $order_items_bookings as $order_item_id => $item_bookings ) {
            if ( ! is_array( $item_bookings ) ) {
                continue;
            }

            foreach ( $item_bookings as $entry ) {
                if ( empty( $entry['bookings'] ) || ! is_array( $entry['bookings'] ) ) {
                    continue;
                }

                // A 'group' entry's booking_group_id is diagnostic metadata
                // only (Phase 12/13) — each individual booking inside it
                // still becomes its own Securehold_Normalized_Booking, per
                // the MVP policy: never 1 booking_group = 1 Hold Group.
                $booking_group_id = ( 'group' === $entry['type'] && ! empty( $entry['id'] ) ) ? $entry['id'] : null;

                foreach ( $entry['bookings'] as $booking ) {
                    $normalized[] = $this->normalize_booking( $booking, (int) $order_item_id, (int) $order_id, $booking_group_id );
                }
            }
        }

        return $normalized;
    }

    /**
     * @param object   $booking            VERIFIED properties: id, order_id, group_id,
     *                                     event_id, event_start, event_end, state, quantity.
     * @param int      $order_item_id
     * @param int      $order_id
     * @param int|null $booking_group_id
     * @return Securehold_Normalized_Booking
     */
    private function normalize_booking( $booking, $order_item_id, $order_id, $booking_group_id ) {
        $status = ( isset( $booking->state ) && 'cancelled' === $booking->state ) ? 'cancelled' : 'active';

        $metadata = array();
        if ( $booking_group_id ) {
            $metadata['booking_group_id'] = $booking_group_id;
        }

        return new Securehold_Normalized_Booking(
            self::PROVIDER_KEY,
            isset( $booking->id ) ? (int) $booking->id : 0,
            $order_id,
            $order_item_id,
            $this->normalize_datetime( isset( $booking->event_start ) ? $booking->event_start : null ),
            $status,
            $metadata
        );
    }

    /**
     * Booking Activities' event_start/event_end are naive datetime strings
     * (no offset) interpreted against ONE merchant-configured business
     * timezone — NOT WordPress's own site timezone, and NOT UTC (VERIFIED:
     * functions/functions-settings.php, setting key
     * bookacti_general_settings['timezone'], help text "Pick the timezone
     * corresponding to where your business takes place"; default falls back
     * to the server's PHP timezone at settings-init time, but the merchant
     * can set it independently afterward).
     *
     * Reads that setting through Booking Activities' own public accessor
     * (bookacti_get_setting_value(), VERIFIED to exist) rather than assuming
     * it equals WordPress's timezone. Falls back to WordPress's own site
     * timezone only if that setting is genuinely unavailable — a documented
     * fallback, not a blind assumption.
     *
     * @param string|null $event_start
     * @return string|null  ISO 8601 datetime WITH timezone offset, or null if unresolvable.
     */
    private function normalize_datetime( $event_start ) {
        if ( empty( $event_start ) ) {
            return null;
        }

        $timezone_name = function_exists( 'bookacti_get_setting_value' )
            ? bookacti_get_setting_value( 'bookacti_general_settings', 'timezone' )
            : null;

        try {
            $timezone = new DateTimeZone( $timezone_name ?: wp_timezone_string() );
            $datetime = new DateTime( $event_start, $timezone );
            return $datetime->format( DateTime::ATOM ); // ISO 8601 with offset, e.g. 2026-10-10T14:00:00+02:00
        } catch ( \Exception $e ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Activities provider: could not resolve booking timezone', array(
                    'event_start' => $event_start,
                    'timezone'    => $timezone_name,
                    'error'       => $e->getMessage(),
                ), 'warning' );
            }
            return null;
        }
    }

    /**
     * apply_filters( 'bookacti_bookings_rescheduled', $updated, $selected_bookings, $new_selected_bookings )
     * VERIFIED: this is a FILTER, fired after the reschedule is persisted.
     * $updated['bookings'][$booking_id] carries old_event_start/new_event_start
     * (VERIFIED verbatim source, controller-bookings.php).
     *
     * Must return $updated UNCHANGED — this is a translation step to the
     * Manager, not a data transform.
     *
     * @param array $updated
     * @param array $selected_bookings
     * @param array $new_selected_bookings
     * @return array
     */
    public static function on_bookings_rescheduled( $updated, $selected_bookings, $new_selected_bookings ) {
        if ( empty( $updated['bookings'] ) || ! is_array( $updated['bookings'] ) ) {
            return $updated;
        }

        foreach ( $updated['bookings'] as $booking_id => $change ) {
            $order_id = self::order_id_for_booking( $booking_id, $selected_bookings );
            if ( ! $order_id ) {
                continue;
            }

            $new_timestamp = self::resolve_new_timestamp( $change );

            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Activities: reschedule event received', array(
                    'order_id'        => $order_id,
                    'booking_id'      => $booking_id,
                    'old_event_start' => isset( $change['old_event_start'] ) ? $change['old_event_start'] : null,
                    'new_event_start' => isset( $change['new_event_start'] ) ? $change['new_event_start'] : null,
                    'new_timestamp'   => $new_timestamp,
                ), 'info' );
            }

            if ( class_exists( 'Securehold_Booking_Integration_Manager' ) ) {
                Securehold_Booking_Integration_Manager::handle_booking_rescheduled( self::PROVIDER_KEY, $booking_id, $order_id, $new_timestamp );
            }
        }

        return $updated;
    }

    /**
     * do_action( 'bookacti_booking_status_changed', $new_state, $booking, $args )
     * VERIFIED signature, fired after the DB write, from
     * bookacti_controller_cancel_bookings() / _refund_bookings() /
     * _change_bookings_status().
     *
     * @param string $new_state
     * @param object $booking  Has ->id, ->order_id (VERIFIED properties).
     * @param array  $args
     * @return void
     */
    public static function on_booking_status_changed( $new_state, $booking, $args = array() ) {
        if ( 'cancelled' !== $new_state || empty( $booking->id ) || empty( $booking->order_id ) ) {
            return;
        }

        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Booking Activities: booking cancelled', array(
                'order_id'   => $booking->order_id,
                'booking_id' => $booking->id,
                'context'    => isset( $args['context'] ) ? $args['context'] : null,
            ), 'info' );
        }

        if ( class_exists( 'Securehold_Booking_Integration_Manager' ) ) {
            Securehold_Booking_Integration_Manager::handle_booking_cancelled( self::PROVIDER_KEY, $booking->id, $booking->order_id );
        }
    }

    /**
     * do_action( 'bookacti_booking_group_status_changed', $new_state, $booking_group, $group_bookings, $args )
     * VERIFIED signature. Per the MVP policy, a booking group is NOT a Hold
     * Group — cancellation is applied to each individual booking within it.
     *
     * @param string $new_state
     * @param object $booking_group
     * @param array  $group_bookings  Individual booking objects in the group.
     * @param array  $args
     * @return void
     */
    public static function on_booking_group_status_changed( $new_state, $booking_group, $group_bookings, $args = array() ) {
        if ( 'cancelled' !== $new_state || empty( $group_bookings ) || ! is_array( $group_bookings ) ) {
            return;
        }

        foreach ( $group_bookings as $booking ) {
            if ( empty( $booking->id ) || empty( $booking->order_id ) ) {
                continue;
            }

            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'Booking Activities: booking cancelled (via group cancellation)', array(
                    'order_id'         => $booking->order_id,
                    'booking_id'       => $booking->id,
                    'booking_group_id' => isset( $booking_group->id ) ? $booking_group->id : null,
                    'context'          => isset( $args['context'] ) ? $args['context'] : null,
                ), 'info' );
            }

            if ( class_exists( 'Securehold_Booking_Integration_Manager' ) ) {
                Securehold_Booking_Integration_Manager::handle_booking_cancelled( self::PROVIDER_KEY, $booking->id, $booking->order_id );
            }
        }
    }

    /**
     * @param int|string $booking_id
     * @param array      $selected_bookings  bookacti_get_selected_bookings() shape: ['bookings' => [id => booking_obj]].
     * @return int|null
     */
    private static function order_id_for_booking( $booking_id, $selected_bookings ) {
        if ( ! empty( $selected_bookings['bookings'][ $booking_id ]->order_id ) ) {
            return (int) $selected_bookings['bookings'][ $booking_id ]->order_id;
        }
        return null;
    }

    /**
     * @param array $change  $updated['bookings'][$booking_id], carries new_event_start (VERIFIED key).
     * @return int|null
     */
    private static function resolve_new_timestamp( $change ) {
        if ( empty( $change['new_event_start'] ) ) {
            return null;
        }

        $timezone_name = function_exists( 'bookacti_get_setting_value' )
            ? bookacti_get_setting_value( 'bookacti_general_settings', 'timezone' )
            : null;

        try {
            $timezone = new DateTimeZone( $timezone_name ?: wp_timezone_string() );
            $datetime = new DateTime( $change['new_event_start'], $timezone );
            return $datetime->getTimestamp();
        } catch ( \Exception $e ) {
            return null;
        }
    }
}
