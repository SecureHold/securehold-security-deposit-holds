<?php
/**
 * Database access layer for hold records.
 *
 * All reads and writes to the securehold_holds table go through this class.
 * Methods are static to allow calls without instantiation.
 *
 * @package SecureHold_WP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SecureHold_DB {
    
    /**
     * Insert a new hold record into the database.
     *
     * Accepts either 'intent_id' or the legacy 'stripe_payment_intent_id' key.
     * Sets expires_at automatically for authorized holds based on auto-release settings.
     *
     * @since 1.0.0
     *
     * @param array      $data  Hold data. Keys: order_id, amount, captured_amount, currency,
     *                          status, intent_id, customer_id, payment_method_id, notes, created_at.
     * @return int|false        The new row ID on success, false on failure.
     */
    /**
     * When an authorization placed now should expire.
     *
     * Stripe cancels an uncaptured PaymentIntent after seven days, so the stored
     * preference is clamped to that window rather than trusted blindly. Shared
     * with Securehold_Hold_State so an insert and a transition cannot disagree
     * about the same deadline.
     *
     * @since 3.4.5
     *
     * @return string MySQL datetime in UTC.
     */
    public static function authorization_expiry() {
        $days = (int) get_option('securehold_auto_release_days', 7);
        if ($days < 1) { $days = 1; }
        if ($days > 7) { $days = 7; } // Stripe hard limit

        return gmdate('Y-m-d H:i:s', strtotime('+' . $days . ' days'));
    }

    public static function insert_deposit($data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';
        
        // Defaults for optional fields not always passed by the caller.
        $defaults = array(
            'order_id' => 0,
            'amount' => 0,
            'captured_amount' => 0,
            'currency' => 'USD',
            'status' => 'pending',
            'intent_id' => '',
            'stripe_payment_intent_id' => '',
            'customer_id' => '',
            'payment_method_id' => '',
            'notes' => '',
            'created_at' => current_time('mysql')
        );
        
        $data = wp_parse_args($data, $defaults);
        
        // Resolve intent ID: callers may pass either key; normalize to a single variable.
        $intent_id = !empty($data['intent_id']) ? $data['intent_id'] : $data['stripe_payment_intent_id'];

        // Build the insert array with the columns that are always present.
        $insert_data = array(
            'order_id'          => $data['order_id'],
            'amount'            => $data['amount'],
            'captured_amount'   => $data['captured_amount'],
            'currency'          => $data['currency'],
            'status'            => $data['status'],
            'intent_id'         => $intent_id,
            'customer_id'       => $data['customer_id'],
            'payment_method_id' => $data['payment_method_id'],
            'created_at'        => $data['created_at']
        );

        // Include notes only when provided — critical for diagnosing failed holds.
        if (!empty($data['notes'])) {
            $insert_data['notes'] = sanitize_textarea_field($data['notes']);
        }

        // Format list matching the insert array column order above — must stay in sync.
        $formats = array(
            '%d', // order_id
            '%f', // amount
            '%f', // captured_amount
            '%s', // currency
            '%s', // status
            '%s', // intent_id
            '%s', // customer_id
            '%s', // payment_method_id
            '%s'  // created_at
        );

        // Append the format placeholder only when notes are included, to keep the format/data arrays in sync.
        if (isset($insert_data['notes'])) {
            $formats[] = '%s';
        }

        // Set expires_at only for authorized holds — Stripe cancels uncaptured PaymentIntents after 7 days.
        if ($data['status'] === 'authorized') {
            $insert_data['expires_at'] = self::authorization_expiry();
            $formats[] = '%s'; // expires_at appended conditionally — only present for authorized holds
        }

        // authorized_at is persisted only when the caller states it. This layer
        // knows a status, not a business event: 'authorized' in an insert can
        // describe a hold Stripe just approved, or a row being backfilled from
        // something that happened earlier. Deriving the moment from the status
        // would have this primitive invent history. The scheduler knows when the
        // authorization actually happened and says so; failed, pending,
        // pending_manual and scheduled rows never carry one.
        if (!empty($data['authorized_at'])) {
            $insert_data['authorized_at'] = $data['authorized_at'];
            $formats[] = '%s';
        }

        // Multi-Hold engine: group_key/scheduled_for are optional and, like
        // notes/expires_at/authorized_at above, only present in the insert
        // when the caller actually has one — a caller that never passes
        // group_key produces the exact same row shape as before this column
        // existed (NULL = the implicit default group).
        if (array_key_exists('group_key', $data) && $data['group_key'] !== null && $data['group_key'] !== '') {
            $insert_data['group_key'] = (string) $data['group_key'];
            $formats[] = '%s';
        }
        if (!empty($data['scheduled_for'])) {
            $insert_data['scheduled_for'] = $data['scheduled_for'];
            $formats[] = '%s';
        }

        // Diagnostic-only JSON (provider, booking_id, order_item_id...) —
        // never read by any grouping/amount/timing/capture logic, only by
        // the admin UI and support. Accepts either an already-encoded
        // string or an array/object it encodes itself, so a caller never
        // needs to know this column's storage format.
        if (array_key_exists('metadata', $data) && $data['metadata'] !== null && $data['metadata'] !== '') {
            $insert_data['metadata'] = is_string($data['metadata']) ? $data['metadata'] : wp_json_encode($data['metadata']);
            $formats[] = '%s';
        }

        $result = $wpdb->insert($table_name, $insert_data, $formats);
        
        if (function_exists('securehold_log')) {
            if ($result === false) {
                securehold_log('DB Insert Error: ' . $wpdb->last_error, $insert_data);
            } else {
                securehold_log('DB Insert Succeeded', ['id' => $wpdb->insert_id, 'intent' => $intent_id]);
            }
        }

        return $result;
    }

    /**
     * Update a hold record by WooCommerce order ID.
     *
     * Normalizes the legacy stripe_payment_intent_id key to intent_id if passed.
     *
     * @since 1.0.0
     *
     * @param int        $order_id  WooCommerce order ID used as the lookup key.
     * @param array      $data      Columns to update (key => value pairs).
     * @return int|false             Number of rows updated, or false on error.
     */
    public static function update_deposit($order_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';
        
        if (isset($data['stripe_payment_intent_id'])) {
            $data['intent_id'] = $data['stripe_payment_intent_id'];
            unset($data['stripe_payment_intent_id']);
        }

        return $wpdb->update(
            $table_name,
            $data,
            array('order_id' => $order_id)
        );
    }

    /**
     * Retrieve a hold record by WooCommerce order ID.
     *
     * Returns null if the table does not exist (e.g., site before plugin activation).
     *
     * LEGACY SINGLE-HOLD HELPER — since the Multi-Hold engine (Phase A, 1.3.0).
     * order_id is not a unique key on securehold_holds: a commande using
     * Multiple Hold Groups can have more than one row, and this method has no
     * way to say which one it means — it returns whichever row MySQL happens
     * to return first, with no ORDER BY to make that deterministic. Safe to
     * keep calling on any code path that is known to only ever see a
     * Single-Hold-per-Order commande (the default and, today, only shipped
     * behaviour). On a path that must target one specific hold — capture,
     * release, anything keyed by an id the caller already has — use
     * get_hold( $hold_id ) instead. On a path that must reason about every
     * hold of an order, use get_holds_for_order( $order_id ).
     *
     * @since 1.0.0
     *
     * @param int         $order_id  WooCommerce order ID.
     * @return object|null            Hold row object, or null if not found.
     */
    public static function get_deposit($order_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) !== $table_name ) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE order_id = %d", $order_id));
    }
    
    /**
     * Retrieve a hold record by Stripe PaymentIntent ID.
     *
     * Used by the webhook handler to look up the hold associated with an incoming Stripe event.
     *
     * @since 1.0.0
     *
     * @param string      $intent_id  Stripe PaymentIntent ID (pi_...).
     * @return object|null             Hold row object, or null if not found.
     */
    public static function get_deposit_by_intent($intent_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE intent_id = %s", $intent_id));
    }

    /**
     * Retrieve a hold record by WooCommerce order ID.
     *
     * Alias for get_deposit() — kept for backward compatibility with the order metabox.
     *
     * @since 1.0.0
     *
     * @param int         $order_id  WooCommerce order ID.
     * @return object|null            Hold row object, or null if not found.
     */
    public static function get_hold_by_order($order_id) {
        return self::get_deposit($order_id);
    }

    /**
     * Update a hold by its row ID.
     *
     * Used by the order metabox and the auto-release cron to update status and notes.
     *
     * @since 1.0.0
     *
     * @param int        $hold_id  Row ID from the securehold_holds table.
     * @param array      $data     Columns to update (key => value pairs).
     * @return int|false            Number of rows updated, or false on error.
     */
    public static function update_hold($hold_id, $data) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        return $wpdb->update(
            $table_name,
            $data,
            array('id' => $hold_id)
        );
    }

    /**
     * Retrieve a hold by its own primary key.
     *
     * The unambiguous read: it can never return a different row than the one
     * asked for, whatever else exists for the same order. Every path that
     * already knows which hold it means to act on — a capture, a release, a
     * re-read taken after acquiring a lock — should read through this, not
     * through get_deposit( $order_id ).
     *
     * @since 1.3.0 (Multi-Hold engine, Phase A)
     *
     * @param int $hold_id Row ID from the securehold_holds table.
     * @return object|null Hold row object, or null if not found.
     */
    public static function get_hold( $hold_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        $hold_id = (int) $hold_id;
        if ( $hold_id <= 0 ) {
            return null;
        }

        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE id = %d", $hold_id ) );
    }

    /**
     * Retrieve every hold row belonging to a WooCommerce order.
     *
     * Unlike get_deposit(), this never hides a second hold: a commande with a
     * single historical hold gets a one-element array, a commande using
     * Multiple Hold Groups gets all of them, oldest first (creation order).
     *
     * @since 1.3.0 (Multi-Hold engine, Phase A)
     *
     * @param int $order_id WooCommerce order ID.
     * @return object[] Array of hold row objects, oldest first. Empty array if none or table absent.
     */
    public static function get_holds_for_order( $order_id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return array();
        }

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
            return array();
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY id ASC",
            $order_id
        ) );
    }

    /**
     * Retrieve the hold for one specific Hold Group within an order.
     *
     * $group_key = null targets the implicit default group that every
     * historical row and every Single-Hold-per-Order commande already is —
     * the same row get_deposit() would return, but disambiguated with
     * ORDER BY ... LIMIT 1 instead of an arbitrary first match. A non-null
     * key targets one specific group and never falls back to a different one.
     *
     * @since 1.3.0 (Multi-Hold engine, Phase A)
     *
     * @param int         $order_id  WooCommerce order ID.
     * @param string|null $group_key Opaque group identifier, or null for the default group.
     * @return object|null Hold row object, or null if not found.
     */
    public static function get_hold_for_group( $order_id, $group_key = null ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return null;
        }

        if ( $group_key === null ) {
            return $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM $table_name WHERE order_id = %d AND group_key IS NULL ORDER BY id DESC LIMIT 1",
                $order_id
            ) );
        }

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d AND group_key = %s ORDER BY id DESC LIMIT 1",
            $order_id,
            $group_key
        ) );
    }

    /**
     * Retrieve deposits linked to a set of order IDs.
     *
     * Used by the My Account front-end to fetch all deposits belonging
     * to a customer via their WooCommerce order IDs (HPOS-compatible).
     *
     * @since 5.4.0
     *
     * @param int[] $order_ids  Array of WooCommerce order IDs.
     * @return object[]         Array of deposit rows, newest first.
     */
    public static function get_deposits_by_order_ids( $order_ids ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        if ( empty( $order_ids ) ) {
            return array();
        }

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
            return array();
        }

        $order_ids    = array_map( 'absint', $order_ids );
        $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id IN ($placeholders) ORDER BY created_at DESC",
            $order_ids
        ) );
    }

    /**
     * Get all authorized holds that have passed their expiration date.
     *
     * Called by the auto-release cron to determine which holds to cancel on Stripe.
     *
     * @since 2.0.0
     *
     * @return object[]  Array of hold row objects ordered by expiration date ascending.
     */
    public static function get_expiring_holds() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
            return array();
        }

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s ORDER BY expires_at ASC",
            'authorized',
            current_time('mysql')
        ));
    }
}