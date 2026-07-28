<?php
/**
 * Handles category-specific deposit settings logic.
 * Stores all rules in a single wp_options row: securehold_category_rules
 *
 * Field structure matches Product Settings for uniform handling:
 *   deposit_amount, capture_timing, delay_days, date_field_key,
 *   scheduled_days, scheduled_direction, trigger_status
 *
 * @since 3.11.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Category_Settings {

    /**
     * Option key in wp_options table.
     * Value is an associative array keyed by term_id.
     */
    private static $option_key = 'securehold_category_rules';

    /**
     * Get settings for a specific category term_id.
     *
     * @param int $term_id
     * @return array Settings array (may be empty if not configured).
     */
    public static function get_settings( $term_id ) {
        $all = get_option( self::$option_key, array() );
        if ( ! is_array( $all ) || ! isset( $all[ $term_id ] ) ) {
            return array();
        }
        return $all[ $term_id ];
    }

    /**
     * Save settings for a specific category.
     *
     * @param int   $term_id
     * @param array $data Keys: deposit_amount, capture_timing, delay_days,
     *                     date_field_key, scheduled_days, scheduled_direction,
     *                     trigger_status
     */
    public static function save_settings( $term_id, $data ) {
        $all = get_option( self::$option_key, array() );
        if ( ! is_array( $all ) ) {
            $all = array();
        }

        $sanitized = array(
            'deposit_amount'      => sanitize_text_field( isset( $data['deposit_amount'] ) ? $data['deposit_amount'] : '' ),
            'capture_timing'      => sanitize_text_field( isset( $data['capture_timing'] ) ? $data['capture_timing'] : '' ),
            'delay_days'          => sanitize_text_field( isset( $data['delay_days'] ) ? $data['delay_days'] : '' ),
            'date_field_key'      => sanitize_text_field( isset( $data['date_field_key'] ) ? $data['date_field_key'] : '' ),
            'scheduled_days'      => sanitize_text_field( isset( $data['scheduled_days'] ) ? $data['scheduled_days'] : '' ),
            'scheduled_direction' => sanitize_text_field( isset( $data['scheduled_direction'] ) ? $data['scheduled_direction'] : '' ),
            'trigger_status'      => sanitize_text_field( isset( $data['trigger_status'] ) ? $data['trigger_status'] : '' ),
        );

        // Validate scheduled_days range (1-365, or 0 only for same_day)
        if ( $sanitized['scheduled_days'] !== '' ) {
            $days = absint( $sanitized['scheduled_days'] );
            if ( $days < 1 && $sanitized['scheduled_direction'] !== 'same_day' ) {
                $days = 1;
            }
            if ( $days > 365 ) {
                $days = 365;
            }
            $sanitized['scheduled_days'] = (string) $days;
        }

        // Validate scheduled_direction
        if ( $sanitized['scheduled_direction'] !== '' && ! in_array( $sanitized['scheduled_direction'], array( 'before', 'after', 'same_day' ), true ) ) {
            $sanitized['scheduled_direction'] = '';
        }

        $all[ (int) $term_id ] = $sanitized;
        update_option( self::$option_key, $all );
    }

    /**
     * Delete settings for a category (reset to global).
     *
     * @param int $term_id
     */
    public static function delete_settings( $term_id ) {
        $all = get_option( self::$option_key, array() );
        if ( ! is_array( $all ) ) {
            return;
        }
        unset( $all[ (int) $term_id ] );
        update_option( self::$option_key, $all );
    }

    /**
     * Get all configured categories with their settings.
     *
     * @return array Array of { term_id: int, name: string, settings: array }
     */
    public static function get_all_configured_categories() {
        $all = get_option( self::$option_key, array() );
        if ( ! is_array( $all ) || empty( $all ) ) {
            return array();
        }

        $result = array();
        foreach ( $all as $term_id => $settings ) {
            $term = get_term( (int) $term_id, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                continue;
            }
            $result[] = array(
                'term_id'  => (int) $term_id,
                'name'     => $term->name,
                'settings' => $settings,
            );
        }
        return $result;
    }

    /**
     * Find the first matching category rule for a given product_id.
     * Checks product categories sorted by term_id ASC for determinism.
     *
     * @param int $product_id
     * @return array|null Returns { term_id, name, settings } or null if no match.
     */
    public static function get_matching_rule_for_product( $product_id ) {
        $all = get_option( self::$option_key, array() );
        if ( ! is_array( $all ) || empty( $all ) ) {
            return null;
        }

        $terms = wp_get_object_terms( $product_id, 'product_cat', array(
            'fields'  => 'ids',
            'orderby' => 'term_id',
            'order'   => 'ASC',
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return null;
        }

        foreach ( $terms as $tid ) {
            if ( isset( $all[ $tid ] ) && ! empty( $all[ $tid ]['capture_timing'] ) ) {
                $term = get_term( $tid, 'product_cat' );
                return array(
                    'term_id'  => (int) $tid,
                    'name'     => ( $term && ! is_wp_error( $term ) ) ? $term->name : '#' . $tid,
                    'settings' => $all[ $tid ],
                );
            }
        }

        return null;
    }
}
