<?php
/**
 * Multi-Hold engine: native "Hold Structure" option and rollout gate.
 *
 * This class decides nothing about WHICH line items become which Hold
 * Group — that is a grouping policy's job, and no grouping policy ships in
 * SecureHold core. It only answers whether the engine is even allowed to
 * originate more than the one default group a Single-Hold-per-Order
 * commande has always had.
 *
 * Selecting "Multiple Hold Groups" in Settings does not, by itself, split
 * any order: may_create_multiple_groups() is also false until some
 * extension registers a grouping policy via the
 * 'securehold_multi_hold_grouping_policies' filter AND that same policy is
 * the active grouping_source(). A fresh install's behaviour therefore never
 * changes without four independent, explicit things happening — the
 * option, the engine flag, a real policy registered as the active source,
 * and the PRO 'multi_hold_groups' capability.
 *
 * @package SecureHold
 * @since   1.4.0 (Multi-Hold engine, admin UI / rollout increment)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Multi_Hold {

    /**
     * Feature flag for the engine's automatic multi-group behaviour.
     *
     * The locking, scheduling, Data Access and Stripe primitives added by
     * the Multi-Hold engine are always present and safe — every one of them
     * defaults to the exact legacy single-hold key/behaviour when called
     * with no group_key, which every production call site still does. This
     * flag gates the one thing that is NOT dormant by construction: whether
     * a future grouping policy is allowed to originate a non-default
     * group_key for an ordinary order at all.
     *
     * @return bool
     */
    public static function is_engine_enabled() {
        return (bool) apply_filters( 'securehold_multi_hold_engine_enabled', true );
    }

    /**
     * The native "Hold Structure" setting.
     *
     * @return string 'single_order_hold' (default) or 'multiple_hold_groups'.
     */
    public static function hold_structure() {
        $value = get_option( 'securehold_hold_structure', 'single_order_hold' );
        return in_array( $value, array( 'single_order_hold', 'multiple_hold_groups' ), true )
            ? $value
            : 'single_order_hold';
    }

    /**
     * @return bool True when the merchant selected "Multiple Hold Groups".
     */
    public static function is_multi_hold_selected() {
        return self::hold_structure() === 'multiple_hold_groups';
    }

    /**
     * The active grouping SOURCE — which registered policy, if any, is
     * allowed to originate Hold Groups for this site right now.
     *
     * Exactly one source can be active at a time. This is what stops
     * WooCommerce Native and (when eventually reactivated) Booking
     * Activities from both trying to split the same order: every grouping
     * policy's own hook callbacks self-check
     * `self::grouping_source() === self::POLICY_KEY` before doing anything,
     * so a policy that is merely *registered* (declared via the
     * 'securehold_multi_hold_grouping_policies' filter) but not the active
     * source stays fully inert.
     *
     * Default: 'woocommerce_order_item' — the only grouping policy this
     * release ships enabled, so choosing "Multiple Hold Groups" + PRO with
     * no explicit choice yet made still does something reasonable instead
     * of silently splitting nothing (mission section 13).
     *
     * @since 3.5.0
     * @return string
     */
    public static function grouping_source() {
        $value = get_option( 'securehold_multi_hold_grouping_source', 'woocommerce_order_item' );
        return ( is_string( $value ) && $value !== '' ) ? $value : 'woocommerce_order_item';
    }

    /**
     * Every grouping policy currently DECLARED (registered on the filter),
     * regardless of whether it is the active source.
     *
     * @since 3.5.0
     * @return array List of {key, label} arrays.
     */
    public static function declared_grouping_policies() {
        $policies = apply_filters( 'securehold_multi_hold_grouping_policies', array() );
        return is_array( $policies ) ? array_values( array_filter( $policies, function ( $p ) {
            return is_array( $p ) && ! empty( $p['key'] );
        } ) ) : array();
    }

    /**
     * Whether the ACTIVE grouping source (see grouping_source()) is itself
     * one of the declared policies.
     *
     * Intentionally source-aware, not "is any policy registered at all": a
     * site could have more than one grouping policy declared (e.g. once
     * Booking Activities is reactivated alongside WooCommerce Native) while
     * only one of them is the chosen source. Checking the match here, not
     * just presence, is what keeps "exactly one active source" true even if
     * a future misconfiguration leaves grouping_source() pointing at a
     * source nothing declares — May_create_multiple_groups() then correctly
     * stays false instead of falling back to whichever policy happens to be
     * registered.
     *
     * @return bool
     */
    public static function has_grouping_policy() {
        $source = self::grouping_source();
        foreach ( self::declared_grouping_policies() as $policy ) {
            if ( $policy['key'] === $source ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convenience for a grouping policy's own hook callbacks: "am I the
     * active source?" A policy must call this (or equivalent) before
     * originating any Hold Group — see class docblocks on
     * Securehold_Pro_Woocommerce_Native_Grouping_Policy and
     * Securehold_Booking_Grouping_Policy.
     *
     * @since 3.5.0
     * @param string $policy_key
     * @return bool
     */
    public static function is_active_grouping_source( $policy_key ) {
        return self::grouping_source() === $policy_key;
    }

    /**
     * Whether the engine may actually originate more than one Hold Group
     * for an order right now.
     *
     * This is the SINGLE point that gates the CREATION of new Multi-Hold
     * structures. It never gates reading, capturing, releasing, cancelling
     * a schedule, logging, emailing, or the cron/retry lifecycle of a hold
     * that already exists — those all go through SecureHold_DB /
     * Securehold_Scheduler directly and stay available with no PRO check,
     * by design (see class-securehold-wp-db.php, class-securehold-wp-scheduler.php).
     *
     * All four of the following must be true:
     *   - the engine feature flag (developer-only, defaults true)
     *   - the admin's own "Multiple Hold Groups" opt-in
     *   - a real grouping policy being registered
     *   - the 'multi_hold_groups' PRO capability, resolved through the
     *     existing securehold_feature_enabled() resolver — no separate
     *     license check is duplicated here; PRO enables this slug the same
     *     way it already enables 'rule_engine', 'deposit_automation', etc.
     *     (securehold-pro/admin/class-securehold-pro-admin.php::enable_pro_features()).
     *
     * Any single provider/policy that tries to originate a non-default
     * group_key goes through Securehold_Booking_Grouping_Policy (or any
     * future policy) → ensure_resolved_groups() → this method. There is no
     * other path to a new Hold Group, so a provider cannot bypass the PRO
     * capability by calling SecureHold_DB / Securehold_Scheduler directly —
     * doing so does not, by itself, register a group_key anyone reads.
     *
     * @return bool
     */
    public static function may_create_multiple_groups() {
        return self::is_engine_enabled()
            && self::is_multi_hold_selected()
            && self::has_grouping_policy()
            && securehold_feature_enabled( 'multi_hold_groups' );
    }
}
