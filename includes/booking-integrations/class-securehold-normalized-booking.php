<?php
/**
 * Normalized booking — the one shape every booking provider adapter must
 * translate its own data into.
 *
 * Deliberately minimal: only fields with a demonstrated, current need in
 * SecureHold carry their own property. Anything specific to one provider
 * (Booking Activities' booking_group_id, WooCommerce Bookings' resource_id,
 * a human-readable label, etc.) belongs in $metadata, not as a new field
 * here — adding a field to this class is a decision for every future
 * provider, not just one.
 *
 * Treated as a simple, immutable data structure: no setters, no financial
 * logic, no Stripe/DB awareness. A provider builds one, hands it to the
 * Booking Integration Manager, and is done with it.
 *
 * @package SecureHold
 * @since   1.4.0 (Multi-Hold engine, Booking Integrations increment)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Securehold_Normalized_Booking {

    /** @var string  Opaque provider key, e.g. 'booking-activities'. */
    public $provider;

    /** @var int|string  Booking identifier, stable and unique within its provider. */
    public $booking_id;

    /** @var int  WooCommerce order ID. */
    public $order_id;

    /** @var int  WooCommerce order item ID this booking belongs to. */
    public $order_item_id;

    /** @var string|null  Booking's own start date/time, in the provider's native string form (not yet resolved to a timestamp — that is the grouping policy's job, provider-aware). Null if unknown. */
    public $start_datetime;

    /** @var string  Normalized status: 'active' or 'cancelled'. Provider-specific status vocabulary is translated by the adapter, not exposed here. */
    public $status;

    /** @var array  Provider-specific extra data (e.g. booking_group_id) for diagnostics/admin display only — never read by grouping/amount/timing logic. */
    public $metadata;

    /**
     * @param string      $provider
     * @param int|string  $booking_id
     * @param int         $order_id
     * @param int         $order_item_id
     * @param string|null $start_datetime
     * @param string      $status
     * @param array       $metadata
     */
    public function __construct( $provider, $booking_id, $order_id, $order_item_id, $start_datetime, $status, array $metadata = array() ) {
        $this->provider       = (string) $provider;
        $this->booking_id     = $booking_id;
        $this->order_id       = (int) $order_id;
        $this->order_item_id  = (int) $order_item_id;
        $this->start_datetime = $start_datetime;
        $this->status         = (string) $status;
        $this->metadata       = $metadata;
    }

    /**
     * Stable, deterministic, collision-free group_key for this booking.
     *
     * Format: "bk:{provider}:{booking_id}". The provider prefix rules out a
     * collision between two different providers that happen to reuse the
     * same booking_id on the same order (Phase 11: simultaneous providers).
     * Well within the 191-character varchar(191) group_key column — a
     * booking_id would have to be an ~180-character string for this to ever
     * approach the limit.
     *
     * @return string
     */
    public function group_key() {
        return 'bk:' . $this->provider . ':' . $this->booking_id;
    }

    /** @return bool */
    public function is_active() {
        return $this->status === 'active';
    }
}
