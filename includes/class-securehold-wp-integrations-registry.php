<?php
/**
 * Integrations registry: a small, extensible declaration point for the
 * Settings > Integrations page.
 *
 * Deliberately minimal — this is a registry, not a framework. Anything that
 * wants a card on the Integrations page (a native dependency like
 * WooCommerce, a third-party plugin bridge like MagePeople, a future
 * grouping-policy provider like Booking Activities) adds one array to the
 * 'securehold_integrations' filter. The page template (integrations-tab.php)
 * owns all rendering and PRO-gating decisions; this class owns nothing but
 * collecting and lightly normalizing the declarations.
 *
 * Declaration shape (all keys optional except 'slug' and 'name'):
 *   'slug'                 => string   Unique key, e.g. 'woocommerce'.
 *   'name'                 => string   Display name.
 *   'description'          => string   One or two sentences, plain text.
 *   'is_native'             => bool     True for a hard dependency of the plugin
 *                                       (WooCommerce) — never presented as an
 *                                       optional/experimental third-party add-on.
 *   'plugin_detected'       => bool|null  Whether the underlying plugin is
 *                                       active on this site (null when not
 *                                       applicable / always available).
 *   'tier'                  => string  'free' | 'pro'.
 *   'multi_hold_capable'    => bool     Whether this integration can act as a
 *                                       Multi-Hold grouping SOURCE.
 *   'grouping_source_key'   => string|null  Matches
 *                                       Securehold_Multi_Hold::grouping_source()
 *                                       values when multi_hold_capable is true.
 *   'status'                => string  'available' | 'coming_soon' | 'not_detected'.
 *   'status_label'          => string|null  Override for the status badge text.
 *   'config_note'           => string|null  Short note shown under the card
 *                                       (e.g. what capability is/isn't active).
 *   'hide_capabilities'     => bool     True for a card that is not a Hold Group
 *                                       source (e.g. an external link): the
 *                                       plugin-detected and Multi-Hold status
 *                                       lines are not rendered.
 *   'cta'                   => array|null  External link button:
 *                                       array( 'label' => string, 'url' => string ).
 *
 * @package SecureHold
 * @since   3.5.0 (WooCommerce Native Multi-Hold / Integrations page)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Integrations_Registry {

    /**
     * Every declared integration, normalized (missing keys filled with
     * safe defaults so the template never has to isset()-check them all).
     *
     * @return array
     */
    public static function get_all() {
        $integrations = apply_filters( 'securehold_integrations', array() );
        if ( ! is_array( $integrations ) ) {
            return array();
        }

        $normalized = array();
        foreach ( $integrations as $integration ) {
            if ( ! is_array( $integration ) || empty( $integration['slug'] ) || empty( $integration['name'] ) ) {
                continue; // malformed declaration — skip rather than render a broken card
            }
            $normalized[ $integration['slug'] ] = wp_parse_args( $integration, array(
                'description'        => '',
                'is_native'          => false,
                'plugin_detected'    => null,
                'tier'               => 'free',
                'multi_hold_capable' => false,
                'grouping_source_key' => null,
                'status'             => 'available',
                'status_label'       => null,
                'config_note'        => null,
                'hide_capabilities'  => false,
                'cta'                => null,
            ) );
        }

        return $normalized;
    }

    /**
     * Register FREE's own known integration declarations (WooCommerce,
     * MagePeople, Booking Activities). Called once from plugin bootstrap.
     *
     * @return void
     */
    public static function register_core_integrations() {
        add_filter( 'securehold_integrations', array( __CLASS__, 'declare_core_integrations' ) );
    }

    /**
     * @param array $integrations
     * @return array
     */
    public static function declare_core_integrations( $integrations ) {
        // WooCommerce — a hard dependency, never presented as an optional
        // third-party integration (mission section 11).
        $integrations[] = array(
            'slug'                => 'woocommerce',
            'name'                => __( 'WooCommerce', 'securehold-security-deposit-holds' ),
            'description'         => __( 'Native integration. SecureHold is a WooCommerce extension: this is always available, not an optional add-on.', 'securehold-security-deposit-holds' ),
            'is_native'           => true,
            'plugin_detected'     => class_exists( 'WooCommerce' ),
            'tier'                => 'free',
            'multi_hold_capable'  => true,
            'grouping_source_key' => 'woocommerce_order_item',
            'status'              => 'available',
        );

        // MagePeople — audited (WooCommerce Native Multi-Hold mission,
        // 2026-09-24, re-verified after rebasing this workspace onto
        // release/free-3.4.11): a real, opt-in bridge DOES exist —
        // Securehold_Config_Resolver::get_magepeople_settings(), gated by
        // its own 'securehold_magepeople_deposit_enabled' option (Settings
        // > Deposit Rules > Third-Party Compatibility), independent of the
        // PRO Rule Engine gate. Fixed-amount Rent Item deposits only;
        // percentage-type deposits are detected and ignored (fall through
        // to Category/Global). Deliberately NOT Multi-Hold capable — this
        // bridge resolves a single deposit candidate for the Rule Engine,
        // it does not group order items into Hold Groups (mission section
        // 15: "Ne lui attribue PAS automatiquement le Multi-Hold").
        $integrations[] = array(
            'slug'               => 'magepeople',
            'name'               => __( 'MagePeople Booking and Rental Manager', 'securehold-security-deposit-holds' ),
            'description'        => __( 'Optional bridge: when enabled, a Rent Item\'s fixed-amount Security Deposit is used automatically if no SecureHold Product Rule matches. Percentage-type deposits are not supported and are ignored.', 'securehold-security-deposit-holds' ),
            'is_native'          => false,
            // RBFW_Woocommerse is the class SecureHold's own bridge code
            // already references (class-securehold-wp-config-resolver.php
            // docblock, 'rbfw_' postmeta keys, 'rbfw_security_deposit'
            // filter in class-securehold-wp-woo.php) — reusing the same
            // verified name rather than guessing a new one.
            'plugin_detected'    => class_exists( 'RBFW_Woocommerse' ) || is_plugin_active_by_slug_guess( 'booking-and-rental-manager' ),
            'tier'               => 'free',
            'multi_hold_capable' => false,
            'status'             => ( get_option( 'securehold_magepeople_deposit_enabled', 'no' ) === 'yes' ) ? 'available' : 'not_detected',
            'status_label'       => ( get_option( 'securehold_magepeople_deposit_enabled', 'no' ) === 'yes' )
                ? __( 'Bridge active', 'securehold-security-deposit-holds' )
                : __( 'Bridge available, not enabled', 'securehold-security-deposit-holds' ),
            // Renders as a checkbox directly on this card (integrations-tab.php).
            // Same option, same save logic as before this moved off the Deposit
            // Rules tab — see settings-page.php's save handler.
            'toggle'             => array(
                'option'      => 'securehold_magepeople_deposit_enabled',
                'label'       => __( 'Use MagePeople security deposit amounts', 'securehold-security-deposit-holds' ),
                'description' => __( 'When a Rent Item (Booking and Rental Manager by MagePeople) has a fixed-amount Security Deposit configured, use it automatically instead of requiring a separate SecureHold Product Rule. An explicit SecureHold Product Rule always takes priority over this. Percentage-type MagePeople deposits are not supported yet and are ignored.', 'securehold-security-deposit-holds' ),
            ),
        );

        // Booking Activities — code present in the repo (see
        // includes/booking-integrations/), deliberately NOT loaded nor
        // registered in this release pending review. No claim of
        // partnership, certification or readiness (mission section 16).
        $integrations[] = array(
            'slug'               => 'booking-activities',
            'name'               => __( 'Booking Activities', 'securehold-security-deposit-holds' ),
            'description'        => __( 'A native Multi-Hold grouping source for Booking Activities bookings is in development.', 'securehold-security-deposit-holds' ),
            'is_native'          => false,
            'plugin_detected'    => function_exists( 'bookacti_wc_get_order_items_bookings' ),
            'tier'               => 'pro',
            'multi_hold_capable' => false, // not registered/active in this release — see status
            'grouping_source_key' => 'booking_activities',
            'status'             => 'coming_soon',
            'status_label'       => __( 'Coming soon', 'securehold-security-deposit-holds' ),
        );

        // SecureHold Stripe App — optional, external. No install detection:
        // the app has no backend and WordPress cannot know whether it is
        // installed in the merchant's Stripe account.
        $integrations[] = array(
            'slug'              => 'stripe-app',
            'name'              => __( 'SecureHold for Stripe Dashboard', 'securehold-security-deposit-holds' ),
            'description'       => __( 'View and manage SecureHold security deposits directly from the Stripe Dashboard. SecureHold works without it.', 'securehold-security-deposit-holds' ),
            'is_native'         => false,
            'plugin_detected'   => null,
            'tier'              => 'free',
            'multi_hold_capable' => false,
            'status'            => 'available',
            'status_label'      => __( 'Optional', 'securehold-security-deposit-holds' ),
            'hide_capabilities' => true,
            'cta'               => array(
                'label' => __( 'Install Stripe App', 'securehold-security-deposit-holds' ),
                'url'   => SECUREHOLD_WP_URL_STRIPE_APP,
            ),
        );

        return $integrations;
    }
}

if ( ! function_exists( 'is_plugin_active_by_slug_guess' ) ) {
    /**
     * Best-effort, side-effect-free guess at whether a plugin folder is
     * active, without assuming any specific MagePeople main-file naming
     * (none is documented/verified — see class docblock). Never asserts a
     * capability the code does not have; used purely for the "plugin
     * detected" display line.
     *
     * @param string $slug_fragment Case-insensitive substring to match against active plugin paths.
     * @return bool
     */
    function is_plugin_active_by_slug_guess( $slug_fragment ) {
        if ( ! function_exists( 'get_option' ) ) {
            return false;
        }
        $active = (array) get_option( 'active_plugins', array() );
        foreach ( $active as $plugin_path ) {
            if ( stripos( $plugin_path, $slug_fragment ) !== false ) {
                return true;
            }
        }
        return false;
    }
}
