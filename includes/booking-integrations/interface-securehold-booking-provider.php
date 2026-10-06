<?php
/**
 * Contract every booking provider adapter must implement.
 *
 * An adapter's job stops at detecting its own plugin and translating its
 * own data into Securehold_Normalized_Booking objects. It must never: create
 * a hold, decide grouping, call Stripe, touch a lock, or capture/release —
 * those stay the exclusive responsibility of the Multi-Hold engine and the
 * Booking Grouping Policy, reached only through their existing public APIs.
 *
 * @package SecureHold
 * @since   1.4.0 (Multi-Hold engine, Booking Integrations increment)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Securehold_Booking_Provider_Interface {

    /**
     * Is this provider's plugin active and its functions callable right now?
     *
     * Checked on every call, not cached at registration — a plugin can be
     * deactivated mid-request-lifetime in ways that matter (e.g. between two
     * admin-ajax calls). Must never throw or fatal even if the underlying
     * plugin's functions are missing entirely.
     *
     * @return bool
     */
    public function is_active();

    /**
     * Opaque provider identifier used as the group_key prefix and stored in
     * hold metadata. Stable forever once shipped — changing it would orphan
     * every existing group_key.
     *
     * @return string e.g. 'booking-activities'
     */
    public function get_provider_key();

    /**
     * Every ACTIVE booking this provider knows about for one order.
     *
     * Must return an empty array — never throw, never emit a warning — when
     * the provider is inactive, the order has no bookings, or the provider's
     * own data is incomplete/unreadable. A caller must be able to call this
     * unconditionally without checking is_active() itself first, though it
     * should for efficiency.
     *
     * @param int $order_id
     * @return Securehold_Normalized_Booking[]
     */
    public function get_bookings_for_order( $order_id );
}
