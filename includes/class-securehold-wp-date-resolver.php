<?php
/**
 * Scheduled Date Resolver
 *
 * Resolves the target date for the "scheduled" hold strategy by searching
 * multiple sources in a deterministic fallback chain:
 *
 *   1. Order meta (WC HPOS-compatible)
 *   2. Order item meta (line-item level)
 *   3. Product meta (WP post meta on the purchased product)
 *   4. Linked booking CPT (WooCommerce Bookings, YITH Booking, Amelia, etc.)
 *   5. Custom table resolver (configurable, disabled by default)
 *   6. Developer filter: securehold_resolve_scheduled_date
 *
 * Each step returns either a valid Unix timestamp or null, in which case
 * the next resolver in the chain is attempted.
 *
 * @package SecureHold_WP
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Date_Resolver {

    /**
     * Primary entry point.
     *
     * @param  WC_Order $order    The WooCommerce order.
     * @param  string   $meta_key The configured meta key to look for.
     * @param  array    $context  Optional context passed to filters / resolvers.
     *                            Keys: product_id, item_id, strategy_source ('global'|'product').
     * @return array {
     *     @type int|null    $timestamp  Unix timestamp or null on failure.
     *     @type string      $source     Human-readable label of the source that matched.
     *     @type string      $raw_value  The raw date string before parsing.
     * }
     */
    public static function resolve( WC_Order $order, $meta_key, $context = array() ) {

        $context = wp_parse_args( $context, array(
            'product_id'      => 0,
            'item_id'         => 0,
            'strategy_source' => 'global',
        ) );

        // Build the resolver chain.
        $resolvers = array(
            'order_meta'      => array( __CLASS__, 'resolve_from_order_meta' ),
            'order_item_meta' => array( __CLASS__, 'resolve_from_order_item_meta' ),
            'product_meta'    => array( __CLASS__, 'resolve_from_product_meta' ),
            'booking_cpt'     => array( __CLASS__, 'resolve_from_booking_cpt' ),
            'custom_table'    => array( __CLASS__, 'resolve_from_custom_table' ),
        );

        /**
         * Filter the resolver chain before execution.
         *
         * Allows developers to add, remove, or reorder resolvers.
         * Each resolver must be a callable that accepts ($order, $meta_key, $context)
         * and returns a Unix timestamp (int) or null.
         *
         * @since 4.0.0
         *
         * @param array    $resolvers Associative array of slug => callable.
         * @param WC_Order $order     The WooCommerce order.
         * @param string   $meta_key  The configured meta key.
         * @param array    $context   Additional context.
         */
        $resolvers = apply_filters( 'securehold_date_resolver_chain', $resolvers, $order, $meta_key, $context );

        foreach ( $resolvers as $slug => $callable ) {
            if ( ! is_callable( $callable ) ) {
                continue;
            }

            $result = call_user_func( $callable, $order, $meta_key, $context );

            if ( is_array( $result ) && ! empty( $result['timestamp'] ) ) {
                $result['source'] = isset( $result['source'] ) ? $result['source'] : $slug;

                self::log_resolution( $order->get_id(), $result, $slug );

                return $result;
            }

            // A resolver may also return a plain integer timestamp for simplicity.
            if ( is_int( $result ) && $result > 0 ) {
                $packed = array(
                    'timestamp' => $result,
                    'source'    => $slug,
                    'raw_value' => gmdate( 'Y-m-d H:i:s', $result ),
                );

                self::log_resolution( $order->get_id(), $packed, $slug );

                return $packed;
            }
        }

        // ──────────────────────────────────────────────
        // Final fallback: developer filter.
        // ──────────────────────────────────────────────

        /**
         * Last-resort filter for date resolution.
         *
         * If none of the built-in resolvers found a date, developers can
         * provide one here. Return a Unix timestamp (int) or an array with
         * 'timestamp', 'source', 'raw_value' keys.
         *
         * @since 4.0.0
         *
         * @param null     $timestamp Null by default (no date found).
         * @param WC_Order $order     The WooCommerce order.
         * @param string   $meta_key  The configured meta key.
         * @param array    $context   Additional context.
         */
        $custom = apply_filters( 'securehold_resolve_scheduled_date', null, $order, $meta_key, $context );

        if ( is_array( $custom ) && ! empty( $custom['timestamp'] ) ) {
            $custom['source'] = isset( $custom['source'] ) ? $custom['source'] : 'developer_filter';
            self::log_resolution( $order->get_id(), $custom, 'developer_filter' );
            return $custom;
        }

        if ( is_int( $custom ) && $custom > 0 ) {
            $packed = array(
                'timestamp' => $custom,
                'source'    => 'developer_filter',
                'raw_value' => gmdate( 'Y-m-d H:i:s', $custom ),
            );
            self::log_resolution( $order->get_id(), $packed, 'developer_filter' );
            return $packed;
        }

        // Nothing found anywhere.
        return array(
            'timestamp' => null,
            'source'    => 'none',
            'raw_value' => '',
        );
    }

    // ──────────────────────────────────────────────────────────────
    // RESOLVER 1 — Order Meta (existing behaviour, WC HPOS safe)
    // ──────────────────────────────────────────────────────────────

    /**
     * @param  WC_Order $order
     * @param  string   $meta_key
     * @param  array    $context
     * @return array|null
     */
    public static function resolve_from_order_meta( WC_Order $order, $meta_key, $context ) {
        $value = $order->get_meta( $meta_key, true );

        if ( empty( $value ) ) {
            return null;
        }

        $ts = self::parse_date( $value );
        if ( ! $ts ) {
            return null;
        }

        return array(
            'timestamp' => $ts,
            'source'    => 'order_meta',
            'raw_value' => $value,
        );
    }

    // ──────────────────────────────────────────────────────────────
    // RESOLVER 2 — Order Item Meta
    // ──────────────────────────────────────────────────────────────

    /**
     * Searches line items for the meta key.
     * Priority: the item matching $context['product_id'], else first match.
     *
     * @param  WC_Order $order
     * @param  string   $meta_key
     * @param  array    $context
     * @return array|null
     */
    public static function resolve_from_order_item_meta( WC_Order $order, $meta_key, $context ) {
        $items = $order->get_items();

        if ( empty( $items ) ) {
            return null;
        }

        // Try the targeted product first.
        $target_pid = absint( $context['product_id'] );

        foreach ( $items as $item ) {
            if ( $target_pid && $item->get_product_id() !== $target_pid ) {
                continue;
            }

            $value = $item->get_meta( $meta_key, true );
            if ( ! empty( $value ) ) {
                $ts = self::parse_date( $value );
                if ( $ts ) {
                    return array(
                        'timestamp' => $ts,
                        'source'    => 'order_item_meta',
                        'raw_value' => $value,
                    );
                }
            }
        }

        // Fallback: any item with the meta key.
        if ( $target_pid ) {
            foreach ( $items as $item ) {
                $value = $item->get_meta( $meta_key, true );
                if ( ! empty( $value ) ) {
                    $ts = self::parse_date( $value );
                    if ( $ts ) {
                        return array(
                            'timestamp' => $ts,
                            'source'    => 'order_item_meta',
                            'raw_value' => $value,
                        );
                    }
                }
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    // RESOLVER 3 — Product Meta (post meta on the WC product)
    // ──────────────────────────────────────────────────────────────

    /**
     * Some plugins store an "event date" or "booking date" on the product itself.
     * This resolver checks the purchased products for the configured meta key.
     *
     * @param  WC_Order $order
     * @param  string   $meta_key
     * @param  array    $context
     * @return array|null
     */
    public static function resolve_from_product_meta( WC_Order $order, $meta_key, $context ) {
        $target_pid = absint( $context['product_id'] );

        // If we know the product, check it first.
        if ( $target_pid ) {
            $value = get_post_meta( $target_pid, $meta_key, true );
            if ( ! empty( $value ) ) {
                $ts = self::parse_date( $value );
                if ( $ts ) {
                    return array(
                        'timestamp' => $ts,
                        'source'    => 'product_meta',
                        'raw_value' => $value,
                    );
                }
            }
        }

        // Otherwise iterate all items.
        foreach ( $order->get_items() as $item ) {
            $pid   = $item->get_product_id();
            $value = get_post_meta( $pid, $meta_key, true );
            if ( ! empty( $value ) ) {
                $ts = self::parse_date( $value );
                if ( $ts ) {
                    return array(
                        'timestamp' => $ts,
                        'source'    => 'product_meta',
                        'raw_value' => $value,
                    );
                }
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    // RESOLVER 4 — Linked Booking CPT
    // ──────────────────────────────────────────────────────────────

    /**
     * Detects WooCommerce Bookings, YITH Booking, and similar CPT-based
     * booking plugins that link a booking post to the order.
     *
     * Detection priority:
     *   a) WooCommerce Bookings (post type: wc_booking, meta: _booking_order_item_id)
     *   b) YITH Booking (post type: yith_booking, meta: _order_id)
     *   c) Generic: any CPT linked via _order_id with a start-date meta
     *
     * @param  WC_Order $order
     * @param  string   $meta_key  Not used directly; the booking date meta is plugin-specific.
     * @param  array    $context
     * @return array|null
     */
    public static function resolve_from_booking_cpt( WC_Order $order, $meta_key, $context ) {
        $order_id = $order->get_id();

        // ── a) WooCommerce Bookings ──
        if ( function_exists( 'WC_Bookings' ) || class_exists( 'WC_Booking' ) || post_type_exists( 'wc_booking' ) ) {
            $booking_ids = get_posts( array(
                'post_type'      => 'wc_booking',
                'posts_per_page' => 1,
                'post_status'    => array( 'confirmed', 'paid', 'complete', 'publish' ),
                'meta_query'     => array(
                    array(
                        'key'   => '_booking_order_item_id',
                        'value' => self::get_order_item_ids( $order ),
                        'compare' => 'IN',
                    ),
                ),
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'ASC',
            ) );

            if ( empty( $booking_ids ) ) {
                // Fallback: try linking via order ID directly.
                $booking_ids = get_posts( array(
                    'post_type'      => 'wc_booking',
                    'posts_per_page' => 1,
                    'post_status'    => array( 'confirmed', 'paid', 'complete', 'publish' ),
                    'post_parent'    => $order_id,
                    'fields'         => 'ids',
                    'orderby'        => 'date',
                    'order'          => 'ASC',
                ) );
            }

            if ( ! empty( $booking_ids ) ) {
                $booking_id = $booking_ids[0];
                // WC Bookings stores start as Unix timestamp.
                $start = get_post_meta( $booking_id, '_booking_start', true );
                $ts    = self::parse_date( $start );
                if ( $ts ) {
                    return array(
                        'timestamp' => $ts,
                        'source'    => 'booking_cpt:wc_booking',
                        'raw_value' => $start,
                    );
                }
            }
        }

        // ── b) YITH Booking ──
        if ( class_exists( 'YITH_WCBK' ) || post_type_exists( 'yith_booking' ) ) {
            $booking_ids = get_posts( array(
                'post_type'      => 'yith_booking',
                'posts_per_page' => 1,
                'meta_query'     => array(
                    array(
                        'key'   => '_order_id',
                        'value' => $order_id,
                    ),
                ),
                'fields'         => 'ids',
            ) );

            if ( ! empty( $booking_ids ) ) {
                $booking_id = $booking_ids[0];
                $start = get_post_meta( $booking_id, '_from', true );
                $ts    = self::parse_date( $start );
                if ( $ts ) {
                    return array(
                        'timestamp' => $ts,
                        'source'    => 'booking_cpt:yith_booking',
                        'raw_value' => $start,
                    );
                }
            }
        }

        // ── c) Amelia Booking (stores in order meta but also has CPT-like data) ──
        // Amelia typically stores the booking date in order item meta.
        // Handled by resolver 2, but we also check for a direct link.
        $amelia_booking_id = $order->get_meta( 'ameliaBookingId', true );
        if ( ! empty( $amelia_booking_id ) && class_exists( 'AmeliaBooking\Infrastructure\WP\InstallActions\AutoUpdateHook' ) ) {
            // Amelia stores bookings in its own tables. If we get here, we rely on
            // the custom_table resolver or the developer filter to fetch the date.
            // We don't query Amelia's tables directly in this resolver to keep it clean.
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    // RESOLVER 5 — Custom Table (opt-in, configurable)
    // ──────────────────────────────────────────────────────────────

    /**
     * Reads a date from a custom database table.
     *
     * This resolver is DISABLED by default and must be explicitly enabled
     * via the option `securehold_custom_table_resolver_enabled` = 'yes'.
     *
     * Configuration (WP options):
     *   securehold_custom_table_name       — e.g. 'wp_amelia_bookings'
     *   securehold_custom_table_date_col   — e.g. 'bookingStart'
     *   securehold_custom_table_join_col   — e.g. 'id' (the column joined to the join value)
     *   securehold_custom_table_join_meta  — e.g. 'ameliaBookingId' (order meta key holding the FK)
     *
     * The resulting query is:
     *   SELECT {date_col} FROM {table} WHERE {join_col} = {order->get_meta(join_meta)}
     *
     * @param  WC_Order $order
     * @param  string   $meta_key
     * @param  array    $context
     * @return array|null
     */
    public static function resolve_from_custom_table( WC_Order $order, $meta_key, $context ) {
        // Gate: must be explicitly enabled.
        if ( get_option( 'securehold_custom_table_resolver_enabled', 'no' ) !== 'yes' ) {
            return null;
        }

        $table_name = get_option( 'securehold_custom_table_name', '' );
        $date_col   = get_option( 'securehold_custom_table_date_col', '' );
        $join_col   = get_option( 'securehold_custom_table_join_col', '' );
        $join_meta  = get_option( 'securehold_custom_table_join_meta', '' );

        // All four must be configured.
        if ( empty( $table_name ) || empty( $date_col ) || empty( $join_col ) || empty( $join_meta ) ) {
            return null;
        }

        // Get the join value from order meta.
        $join_value = $order->get_meta( $join_meta, true );
        if ( empty( $join_value ) ) {
            return null;
        }

        global $wpdb;

        // Whitelist table/column names (only alphanumeric, underscores, and the WP prefix).
        if ( ! self::is_safe_identifier( $table_name ) || ! self::is_safe_identifier( $date_col ) || ! self::is_safe_identifier( $join_col ) ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( '❌ Custom table resolver: unsafe identifier detected', array(
                    'table'    => $table_name,
                    'date_col' => $date_col,
                    'join_col' => $join_col,
                ) );
            }
            return null;
        }

        // Prefix the table name with wpdb prefix if not already prefixed.
        $full_table = $table_name;
        if ( strpos( $table_name, $wpdb->prefix ) !== 0 ) {
            $full_table = $wpdb->prefix . $table_name;
        }

        // Verify the table actually exists.
        $table_exists = $wpdb->get_var( $wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $full_table
        ) );

        if ( $table_exists !== $full_table ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( '❌ Custom table resolver: table not found', array(
                    'table' => $full_table,
                ) );
            }
            return null;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // Table/column names cannot be parameterized; they are validated above.
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT `{$date_col}` FROM `{$full_table}` WHERE `{$join_col}` = %s ORDER BY `{$date_col}` ASC LIMIT 1",
            $join_value
        ) );
        // phpcs:enable

        if ( empty( $value ) ) {
            return null;
        }

        $ts = self::parse_date( $value );
        if ( ! $ts ) {
            return null;
        }

        return array(
            'timestamp' => $ts,
            'source'    => 'custom_table:' . $table_name,
            'raw_value' => $value,
        );
    }

    // ──────────────────────────────────────────────────────────────
    // DATE PARSING — timezone-aware, multi-format
    // ──────────────────────────────────────────────────────────────

    /**
     * Parse a date string or timestamp into a Unix timestamp.
     *
     * Supports:
     *   - Unix timestamps (int or numeric string)
     *   - YYYY-MM-DD, YYYY-MM-DD HH:MM:SS
     *   - DD/MM/YYYY, DD-MM-YYYY (common in EU locales)
     *   - MM/DD/YYYY (US locales — only when unambiguous or month > 12)
     *   - ISO 8601 with timezone offset
     *   - WooCommerce Bookings yyyyMMddHHmmss format
     *   - Anything PHP's DateTime can parse
     *
     * @param  mixed $value Date string, timestamp, or DateTime.
     * @return int|null Unix timestamp or null on failure.
     */
    public static function parse_date( $value ) {
        if ( empty( $value ) ) {
            return null;
        }

        // Already a DateTime.
        if ( $value instanceof \DateTimeInterface ) {
            return $value->getTimestamp();
        }

        // Pure numeric: Unix timestamp.
        if ( is_numeric( $value ) && (int) $value > 946684800 ) { // After 2000-01-01
            return (int) $value;
        }

        $value = trim( (string) $value );

        // WooCommerce Bookings format: yyyyMMddHHmmss (e.g., "20250715140000").
        if ( preg_match( '/^\d{14}$/', $value ) ) {
            $dt = \DateTime::createFromFormat( 'YmdHis', $value, wp_timezone() );
            if ( $dt ) {
                return $dt->getTimestamp();
            }
        }

        // yyyyMMdd without time (e.g., "20250715").
        if ( preg_match( '/^\d{8}$/', $value ) ) {
            $dt = \DateTime::createFromFormat( 'Ymd', $value, wp_timezone() );
            if ( $dt ) {
                $dt->setTime( 0, 0, 0 );
                return $dt->getTimestamp();
            }
        }

        // Standard ISO / MySQL formats first (unambiguous).
        $iso_formats = array(
            'Y-m-d H:i:s', // 2025-07-15 14:00:00
            'Y-m-d H:i',   // 2025-07-15 14:00
            'Y-m-d\TH:i:s', // 2025-07-15T14:00:00
            'Y-m-d\TH:i:sP', // 2025-07-15T14:00:00+02:00
            'Y-m-d',        // 2025-07-15
        );

        foreach ( $iso_formats as $format ) {
            $dt = \DateTime::createFromFormat( $format, $value, wp_timezone() );
            if ( $dt && $dt->format( $format ) === $value ) {
                return $dt->getTimestamp();
            }
            // Partial match (handles trailing chars gracefully).
            if ( $dt ) {
                return $dt->getTimestamp();
            }
        }

        // EU format: DD/MM/YYYY or DD-MM-YYYY or DD.MM.YYYY.
        if ( preg_match( '#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})(.*)$#', $value, $m ) ) {
            $day   = (int) $m[1];
            $month = (int) $m[2];
            $year  = (int) $m[3];

            // Disambiguate: if day > 12, it must be DD/MM/YYYY.
            // If month > 12, it must be MM/DD/YYYY (US format).
            if ( $day > 12 && $month <= 12 ) {
                // EU format confirmed.
                if ( checkdate( $month, $day, $year ) ) {
                    $dt = new \DateTime( sprintf( '%04d-%02d-%02d', $year, $month, $day ), wp_timezone() );
                    return $dt->getTimestamp();
                }
            } elseif ( $month > 12 && $day <= 12 ) {
                // US format: month is in position 1, day in position 2.
                if ( checkdate( $day, $month, $year ) ) {
                    $dt = new \DateTime( sprintf( '%04d-%02d-%02d', $year, $day, $month ), wp_timezone() );
                    return $dt->getTimestamp();
                }
            } elseif ( checkdate( $month, $day, $year ) ) {
                // Ambiguous but valid as DD/MM — prefer EU format.
                $dt = new \DateTime( sprintf( '%04d-%02d-%02d', $year, $month, $day ), wp_timezone() );
                return $dt->getTimestamp();
            }
        }

        // Last resort: PHP strtotime with WP timezone.
        try {
            $dt = new \DateTime( $value, wp_timezone() );
            $ts = $dt->getTimestamp();
            if ( $ts && $ts > 946684800 ) {
                return $ts;
            }
        } catch ( \Exception $e ) {
            // Silently fail — will return null.
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Get all order item IDs for an order (used for booking CPT lookups).
     *
     * @param  WC_Order $order
     * @return array Array of item IDs.
     */
    private static function get_order_item_ids( WC_Order $order ) {
        $ids = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $ids[] = $item_id;
        }
        return $ids;
    }

    /**
     * Validate that a string is a safe SQL identifier (table/column name).
     *
     * @param  string $name
     * @return bool
     */
    private static function is_safe_identifier( $name ) {
        return (bool) preg_match( '/^[a-zA-Z0-9_]+$/', $name );
    }

    /**
     * Log a successful resolution for diagnostics.
     *
     * @param int    $order_id
     * @param array  $result
     * @param string $resolver_slug
     */
    private static function log_resolution( $order_id, $result, $resolver_slug ) {
        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( '📅 Date resolved', array(
                'order_id' => $order_id,
                'resolver' => $resolver_slug,
                'source'   => $result['source'],
                'value'    => $result['raw_value'],
                'timestamp' => $result['timestamp'],
                'date_utc'  => gmdate( 'Y-m-d H:i:s', $result['timestamp'] ),
            ) );
        }
    }
}
