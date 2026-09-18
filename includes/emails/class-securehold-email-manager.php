<?php
/**
 * SecureHold Email Manager
 *
 * Single entry point for firing all SecureHold email notifications.
 * Centralizes gate checks, anti-duplicate logic, and debug logging.
 *
 * Usage:
 *   Securehold_Email_Manager::fire_email( 'securehold_deposit_authorized', $order_id, $context );
 *
 * WooCommerce is the SINGLE source of truth for:
 *   - enabled / disabled
 *   - subject, heading
 *   - recipient (admin emails)
 * Stored in: woocommerce_{email_id}_settings
 *
 * SecureHold options (securehold_email_{legacy_key}) only hold:
 *   - body (HTML template)
 *   - footer
 *
 * Master toggles (WP options):
 *   - securehold_notifications_enabled        (All)
 *   - securehold_notifications_client_enabled  (Customer)
 *   - securehold_notifications_admin_enabled   (Admin)
 *
 * @package SecureHold
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Email_Manager {

    /**
     * Mapping: WC email ID => SecureHold legacy key.
     * Every implemented email MUST appear here.
     */
    const EMAIL_MAP = array(
        'securehold_deposit_authorized'  => 'customer_hold_created',
        'securehold_deposit_captured'    => 'customer_hold_captured',
        'securehold_deposit_released'    => 'customer_hold_released',
        'securehold_deposit_failed'      => 'customer_hold_failed',
        'securehold_admin_hold_created'  => 'admin_hold_created',
        'securehold_admin_hold_failed'   => 'admin_hold_failed',
        'securehold_admin_hold_captured' => 'admin_hold_captured',
        'securehold_admin_hold_released' => 'admin_hold_released',
    );

    /**
     * WC email ID currently being rendered, or null when idle.
     *
     * Set at the very top of every get_content_html() / get_content_plain()
     * call and cleared unconditionally in the accompanying finally block.
     * Consumed by Securehold_Emails::maybe_suppress_email_footer_text() to
     * decide whether to suppress the WC global footer for this render pass.
     *
     * @var string|null
     */
    protected static $current_email_id = null;

    /**
     * Mark the start of a SecureHold email render pass.
     *
     * @param string|null $id  WC email ID (e.g. 'securehold_deposit_authorized'),
     *                         or null to clear after the render pass completes.
     */
    public static function set_current_email_id( $id ) {
        self::$current_email_id = $id;
    }

    /**
     * Return the ID of the SecureHold email currently being rendered.
     *
     * @return string|null
     */
    public static function get_current_email_id() {
        return self::$current_email_id;
    }

    /**
     * Customer email IDs (checked against the client master toggle).
     */
    const CUSTOMER_EMAILS = array(
        'securehold_deposit_authorized',
        'securehold_deposit_captured',
        'securehold_deposit_released',
        'securehold_deposit_failed',
    );

    /**
     * Fire an email notification through WooCommerce.
     *
     * Gate check order:
     *   1. Master kill switch  (securehold_notifications_enabled)
     *   2. Category toggle     (client / admin)
     *   3. Per-email enabled   (WC_Email::is_enabled())
     *
     * After passing all gates, calls $email_obj->trigger( $order_id, $context ).
     *
     * @param string            $email_id  WC email ID, e.g. 'securehold_deposit_authorized'.
     * @param int               $order_id  WC order ID.
     * @param object|array|null $context   Hold payload (amount, currency, captured_amount).
     * @return bool  True if trigger() was called; false if blocked by a gate.
     */
    public static function fire_email( $email_id, $order_id, $context = null ) {

        // ── Gate 1: Master kill switch ────────────────────────────────────
        if ( get_option( 'securehold_notifications_enabled', 'yes' ) !== 'yes' ) {
            self::debug_log( 'fire_email: blocked by master toggle', array(
                'email_id' => $email_id,
                'order_id' => $order_id,
            ) );
            return false;
        }

        // ── Gate 2: Category toggle (customer / admin) ───────────────────
        if ( in_array( $email_id, self::CUSTOMER_EMAILS, true ) ) {
            if ( get_option( 'securehold_notifications_client_enabled', 'yes' ) !== 'yes' ) {
                self::debug_log( 'fire_email: blocked by customer toggle', array(
                    'email_id' => $email_id,
                ) );
                return false;
            }
        } else {
            // Admin emails: respect the admin master toggle only.
            if ( get_option( 'securehold_notifications_admin_enabled', 'yes' ) !== 'yes' ) {
                self::debug_log( 'fire_email: blocked by admin toggle', array(
                    'email_id' => $email_id,
                ) );
                return false;
            }
        }

        // ── Resolve WC email object ──────────────────────────────────────
        $email_obj = self::get_wc_email( $email_id );

        if ( ! $email_obj ) {
            self::debug_log( 'fire_email: WC_Email object not found', array(
                'email_id' => $email_id,
            ), 'error' );
            return false;
        }

        // ── Gate 3: Per-email enabled (WC single source of truth) ────────
        if ( ! $email_obj->is_enabled() ) {
            self::debug_log( 'fire_email: email disabled in WC settings', array(
                'email_id' => $email_id,
                'enabled'  => $email_obj->enabled,
            ) );
            return false;
        }

        // ── All gates passed — fire ──────────────────────────────────────
        self::debug_log( 'fire_email: all gates passed, calling trigger()', array(
            'email_id' => $email_id,
            'order_id' => $order_id,
            'class'    => get_class( $email_obj ),
        ) );

        /**
         * Allow PRO to augment the email context before dispatch.
         *
         * PRO uses this to inject branding data, custom template variables,
         * or additional context into the email payload. The filter must
         * return the (potentially modified) $context.
         *
         * @since 4.5.0
         * @param object|array|null $context   Hold payload passed to trigger().
         * @param string            $email_id  WC email ID (e.g. 'securehold_deposit_authorized').
         * @param int               $order_id  WooCommerce order ID.
         */
        $context = apply_filters( 'securehold_email_dispatch', $context, $email_id, $order_id );

        $email_obj->trigger( $order_id, $context );
        return true;
    }

    /**
     * Get a WC_Email instance by its ID.
     *
     * @param  string        $email_id  e.g. 'securehold_deposit_authorized'.
     * @return WC_Email|null
     */
    public static function get_wc_email( $email_id ) {
        if ( ! function_exists( 'WC' ) || ! is_callable( array( WC(), 'mailer' ) ) ) {
            return null;
        }

        foreach ( WC()->mailer()->get_emails() as $email_obj ) {
            if ( $email_obj instanceof WC_Email && $email_obj->id === $email_id ) {
                return $email_obj;
            }
        }

        return null;
    }

    /**
     * Get the legacy key for a WC email ID.
     *
     * @param  string      $email_id
     * @return string|null
     */
    public static function get_legacy_key( $email_id ) {
        return isset( self::EMAIL_MAP[ $email_id ] ) ? self::EMAIL_MAP[ $email_id ] : null;
    }

    /**
     * Get the WC email ID for a legacy key.
     *
     * @param  string      $legacy_key  e.g. 'customer_hold_created'.
     * @return string|null              e.g. 'securehold_deposit_authorized'.
     */
    public static function get_wc_id( $legacy_key ) {
        $flipped = array_flip( self::EMAIL_MAP );
        return isset( $flipped[ $legacy_key ] ) ? $flipped[ $legacy_key ] : null;
    }

    /**
     * One-time migration: copy subject/heading/enabled from legacy
     * SecureHold options into WooCommerce email settings options.
     *
     * Rules:
     *  - Runs exactly once (guarded by 'securehold_email_migrated_to_wc_v5' flag).
     *  - NEVER overwrites a WC field that already has a non-empty value.
     *  - NEVER resets master toggle options.
     *  - Always logs whether it ran or was skipped, and at what level.
     */
    public static function migrate_legacy_settings() {
        // ── Guard: already ran ────────────────────────────────────────
        if ( get_option( 'securehold_email_migrated_to_wc_v5' ) ) {
            // No log here. This runs on every admin_init, and the one-time
            // migration is done on all but the first: recording "nothing to do"
            // hundreds of times a day is what pushed the real Stripe errors out
            // of the support bundle's export window.
            return;
        }

        self::debug_log( 'migrate_legacy_settings: starting first-run migration', array(
            'email_count' => count( self::EMAIL_MAP ),
        ) );

        foreach ( self::EMAIL_MAP as $wc_id => $legacy_key ) {
            $sh_option = get_option( 'securehold_email_' . $legacy_key, array() );

            if ( empty( $sh_option ) ) {
                self::debug_log( 'migrate_legacy_settings: no legacy data for ' . $legacy_key . ', skipping' );
                continue;
            }

            $wc_option_name = 'woocommerce_' . $wc_id . '_settings';
            $wc_option      = get_option( $wc_option_name, array() );
            $migrated       = array();

            // ── Migrate enabled (bool → 'yes'/'no') ──────────────────
            // NEVER overwrite if WC already has an 'enabled' value.
            if ( ! isset( $wc_option['enabled'] ) && isset( $sh_option['enabled'] ) ) {
                $wc_option['enabled'] = $sh_option['enabled'] ? 'yes' : 'no';
                $migrated[]           = 'enabled';
            }

            // ── Migrate subject ───────────────────────────────────────
            // NEVER overwrite if WC already has a non-empty subject.
            if ( empty( $wc_option['subject'] ) && ! empty( $sh_option['subject'] ) ) {
                $wc_option['subject'] = sanitize_text_field( $sh_option['subject'] );
                $migrated[]           = 'subject';
            }

            // ── Migrate heading ───────────────────────────────────────
            if ( empty( $wc_option['heading'] ) && ! empty( $sh_option['heading'] ) ) {
                $wc_option['heading'] = sanitize_text_field( $sh_option['heading'] );
                $migrated[]           = 'heading';
            }

            if ( ! empty( $migrated ) ) {
                update_option( $wc_option_name, $wc_option );
                self::debug_log( 'migrate_legacy_settings: wrote fields', array(
                    'legacy_key'    => $legacy_key,
                    'wc_option'     => $wc_option_name,
                    'fields_copied' => $migrated,
                ) );
            } else {
                self::debug_log( 'migrate_legacy_settings: WC option already fully set, nothing to copy', array(
                    'legacy_key' => $legacy_key,
                    'wc_option'  => $wc_option_name,
                ) );
            }
        }

        // ── Set flag — migration never runs again ─────────────────────
        update_option( 'securehold_email_migrated_to_wc_v5', '1' );
        self::debug_log( 'migrate_legacy_settings: complete, flag set' );
    }

    /**
     * Register woocommerce_email_enabled_{id} filters for all SecureHold emails.
     *
     * Called on woocommerce_init so that WC's own is_enabled() / send() path
     * respects the SecureHold master toggles — not just fire_email().
     * This makes WC test-email send, scheduled sends, and the WC email sidebar
     * all honour the master toggles without touching DB option values.
     */
    public static function register_master_toggle_filters() {
        foreach ( array_keys( self::EMAIL_MAP ) as $email_id ) {
            add_filter(
                'woocommerce_email_enabled_' . $email_id,
                static function ( $is_enabled ) use ( $email_id ) {
                    // Preserve any explicit 'disabled in WC settings' state.
                    if ( ! $is_enabled ) {
                        return false;
                    }
                    // Master kill switch.
                    if ( get_option( 'securehold_notifications_enabled', 'yes' ) !== 'yes' ) {
                        return false;
                    }
                    // Category toggle.
                    if ( in_array( $email_id, self::CUSTOMER_EMAILS, true ) ) {
                        return get_option( 'securehold_notifications_client_enabled', 'yes' ) === 'yes';
                    } else {
                        return get_option( 'securehold_notifications_admin_enabled', 'yes' ) === 'yes';
                    }
                },
                10,
                1
            );
        }
    }

    /**
     * Log a debug message (WP_DEBUG only).
     *
     * @param string $message
     * @param array  $data
     * @param string $level  'debug' or 'error'.
     */
    private static function debug_log( $message, $data = array(), $level = 'debug' ) {
        if ( 'error' === $level || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( $message, $data, $level );
            }
        }
    }
}

add_action( 'woocommerce_init', array( 'Securehold_Email_Manager', 'register_master_toggle_filters' ) );
