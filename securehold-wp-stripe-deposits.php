<?php
/**
 * Plugin Name:       SecureHold Security Deposit Holds with Stripe for WooCommerce
 * Plugin URI:        https://secureholdwp.com
 * Description:       Automatically create Stripe pre-authorizations (security deposits) for WooCommerce bookings without charging customers. Perfect for vacation rentals, equipment rentals, and service bookings.
 * Version:           3.5.1
 * Author:            SecureHold WP
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       securehold-security-deposit-holds
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 5.0
 * WC tested up to:   11.1
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * Without this declaration, WooCommerce displays an incompatibility warning in the admin.
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

// Plugin version
define('SECUREHOLD_VERSION', '3.5.1');

/**
 * Opt-in usage telemetry endpoint. Never contacted unless an administrator
 * has explicitly turned tracking on — see class-securehold-wp-telemetry.php.
 */
define( 'SECUREHOLD_TELEMETRY_API_URL', 'https://secureholdwp.com/wp-json/securehold/v1/telemetry' );

// Plugin paths
define('SECUREHOLD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SECUREHOLD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SECUREHOLD_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Official SecureHold WP URLs — centralised so every admin view uses the same destination.
// Use defined() guard to prevent fatal errors on double-include.
defined( 'SECUREHOLD_WP_URL_SITE' )                   || define( 'SECUREHOLD_WP_URL_SITE',                   'https://secureholdwp.com/' );
defined( 'SECUREHOLD_WP_URL_PRICING' )                || define( 'SECUREHOLD_WP_URL_PRICING',                'https://secureholdwp.com/pricing/' );
defined( 'SECUREHOLD_WP_URL_DOCS' )                   || define( 'SECUREHOLD_WP_URL_DOCS',                   'https://secureholdwp.com/docs/' );
defined( 'SECUREHOLD_WP_URL_SUPPORT' )                || define( 'SECUREHOLD_WP_URL_SUPPORT',                'https://secureholdwp.com/support/' );
defined( 'SECUREHOLD_WP_URL_ACCOUNT' )                || define( 'SECUREHOLD_WP_URL_ACCOUNT',                'https://secureholdwp.com/account/' );
defined( 'SECUREHOLD_WP_SUPPORT_EMAIL' )              || define( 'SECUREHOLD_WP_SUPPORT_EMAIL',              'support@secureholdwp.com' );
defined( 'SECUREHOLD_WP_URL_DOCS_GETTING_STARTED' )   || define( 'SECUREHOLD_WP_URL_DOCS_GETTING_STARTED',   'https://secureholdwp.com/docs/#getting-started' );
defined( 'SECUREHOLD_WP_URL_DOCS_CONFIGURE_DEPOSITS' )|| define( 'SECUREHOLD_WP_URL_DOCS_CONFIGURE_DEPOSITS','https://secureholdwp.com/docs/#configure-deposits' );
defined( 'SECUREHOLD_WP_URL_DOCS_TROUBLESHOOTING' )   || define( 'SECUREHOLD_WP_URL_DOCS_TROUBLESHOOTING',   'https://secureholdwp.com/docs/#troubleshooting' );
defined( 'SECUREHOLD_WP_URL_STRIPE_APP' )              || define( 'SECUREHOLD_WP_URL_STRIPE_APP',              'https://marketplace.stripe.com/apps/securehold-security-deposits' );

/**
 * Load the bundled Stripe PHP SDK.
 *
 * As of v3.5.0, the Stripe SDK is scoped to the SecureHoldWP\Vendor namespace at
 * build time using PHP-Scoper (prefix: SecureHoldWP\Vendor\Stripe\*).  This makes
 * it a completely distinct namespace from any other plugin's Stripe SDK, so no
 * class-collision fatal can occur regardless of plugin load order.
 *
 * Loading is therefore unconditional — we never need to check whether another
 * plugin's Stripe is already present.  If the vendor/ directory is missing the
 * plugin was installed incorrectly; an admin notice is shown and the plugin aborts.
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-stripe-installer.php';
if ( ! Securehold_Stripe_Installer::load() ) {
    add_action( 'admin_notices', function() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        printf(
            '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
            esc_html__( 'SecureHold WP — Stripe SDK missing.', 'securehold-security-deposit-holds' ),
            esc_html__( 'The bundled Stripe SDK was not found. Please reinstall the plugin from your account dashboard.', 'securehold-security-deposit-holds' )
        );
    } );
}

/**
 * Activation hook
 */
function securehold_activate() {
    // Note: We don't check requirements here anymore
    // The Setup Wizard will handle installing missing dependencies
    require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-activator.php';
    Securehold_Activator::activate();
}
register_activation_hook(__FILE__, 'securehold_activate');

/**
 * Clear scheduled cron events on plugin deactivation.
 * Prevents orphaned cron jobs from running against unloaded plugin code.
 */
function securehold_deactivate() {
    wp_clear_scheduled_hook( 'securehold_auto_release_cron' );
    wp_clear_scheduled_hook( 'securehold_maintenance_cron' );
    wp_clear_scheduled_hook( 'securehold_trigger_scheduled_hold' );
    wp_clear_scheduled_hook( 'securehold_license_check' ); // PRO-owned; safe to clear here
    wp_clear_scheduled_hook( 'securehold_telemetry_heartbeat' );
}
register_deactivation_hook( __FILE__, 'securehold_deactivate' );


/**
 * Load core plugin class
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-core.php';

/**
 * Load error handler
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-error-handler.php';

/**
 * Load scheduled date resolver (multi-source fallback chain)
 * @since 4.0.0
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-date-resolver.php';

/**
 * Load the Multi-Hold engine's native "Hold Structure" option / rollout gate.
 * @since 1.4.0
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-multi-hold.php';

/**
 * Load the Booking Integrations layer — generic infrastructure only
 * (normalized booking value object, provider interface, integration
 * manager). This infra has no effect on any commande by itself: it only
 * defines shapes and a provider registry with nothing registered in it.
 * @since 1.4.0
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/booking-integrations/class-securehold-normalized-booking.php';
require_once SECUREHOLD_PLUGIN_DIR . 'includes/booking-integrations/interface-securehold-booking-provider.php';
require_once SECUREHOLD_PLUGIN_DIR . 'includes/booking-integrations/class-securehold-booking-integration-manager.php';

/**
 * NOT loaded/registered in this release: the Booking Activities grouping
 * policy and provider adapter. This is deliberate — the Booking Activities
 * integration is still pending Yoan's review and must ship in a later
 * release, not this one. The code is kept in the repo (see
 * includes/booking-integrations/class-securehold-booking-grouping-policy.php
 * and includes/booking-integrations/providers/class-securehold-booking-activities-provider.php)
 * so re-enabling it later is a two-line change, but until then:
 *   - no 'bookacti_*' hook is ever registered by SecureHold;
 *   - Securehold_Multi_Hold::has_grouping_policy() has nothing to return,
 *     so may_create_multiple_groups() stays false on this axis regardless
 *     of the "Hold Structure" setting or PRO's own gate.
 * Since the WooCommerce Native Multi-Hold increment, PRO registers its own
 * grouping policy (Securehold_Pro_Woocommerce_Native_Grouping_Policy) as the
 * default active grouping_source() — so has_grouping_policy() can be true
 * without Booking Activities. This block staying commented out only means
 * Booking Activities specifically can never become the active source yet.
 * @since 1.4.0
 */
// require_once SECUREHOLD_PLUGIN_DIR . 'includes/booking-integrations/class-securehold-booking-grouping-policy.php';
// require_once SECUREHOLD_PLUGIN_DIR . 'includes/booking-integrations/providers/class-securehold-booking-activities-provider.php';
// add_action( 'plugins_loaded', function () {
//     Securehold_Booking_Grouping_Policy::register();
//     Securehold_Booking_Activities_Provider::register();
// }, 20 );

/**
 * Integrations registry (Settings > Integrations page). Declaring
 * WooCommerce/MagePeople/Booking Activities here has no side effect beyond
 * making their cards available to the admin page template — no hook of
 * theirs is touched.
 * @since 3.5.0
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-integrations-registry.php';
Securehold_Integrations_Registry::register_core_integrations();

/**
 * Load checkout handler (forces payment method saving)
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-checkout.php';

/**
 * Load product-specific settings handler
 */
require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-product-settings.php';

require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-emails.php';

/**
 * Load centralized email manager (gate checks, single fire_email entry point)
 * @since 5.0.0
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/emails/class-securehold-email-manager.php';

/**
 * Load frontend manager (checkout messages, My Account, shortcodes)
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-frontend-manager.php';


/**
 * Admin notice: WooCommerce is not active.
 *
 * Shown only to administrators on any admin page when WooCommerce is missing.
 * The plugin will not bootstrap in this state, so no WC-dependent code runs.
 */
function securehold_woocommerce_missing_notice() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    $install_url = admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' );
    printf(
        '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a>.</p></div>',
        esc_html__( 'SecureHold WP is inactive.', 'securehold-security-deposit-holds' ),
        esc_html__( 'WooCommerce must be installed and active.', 'securehold-security-deposit-holds' ),
        esc_url( $install_url ),
        esc_html__( 'Install WooCommerce', 'securehold-security-deposit-holds' )
    );
}

/**
 * Initialize the plugin.
 *
 * Runs on plugins_loaded so all plugins (including WooCommerce) are available.
 * Boots nothing if WooCommerce is absent — shows an admin notice instead.
 */
function securehold_run() {
    // WooCommerce dependency guard.
    // WooCommerce registers the 'WooCommerce' class in its main file, which is
    // loaded before plugins_loaded fires, so this check is reliable here.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'securehold_woocommerce_missing_notice' );
        return;
    }

    $plugin = new Securehold_Core();
    $plugin->run();

    // Initialize frontend manager for checkout and My Account features
    if ( class_exists( 'Securehold_Frontend_Manager' ) ) {
        new Securehold_Frontend_Manager();
    }
}
add_action('plugins_loaded', 'securehold_run');

/**
 * Bring the database schema up to date.
 *
 * Deliberately on admin_init and not on activation alone: activation runs only
 * when someone toggles the plugin, so a site updated through WordPress would
 * otherwise keep its old schema indefinitely. The check costs one option read
 * once the schema is current, and never runs on the front end, so no shopper
 * ever waits on it.
 *
 * @since 3.4.4
 */
add_action( 'admin_init', function() {
    require_once SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-migrator.php';
    Securehold_DB_Migrator::maybe_migrate();
}, 5 );

/**
 * Daily log maintenance.
 *
 * The callback is attached on every request so WP-Cron can fire it; the
 * schedule is topped up from the admin only, the same safety net the
 * auto-release cron uses. Nothing is purged during a page load — the hook does
 * the work, on its own schedule.
 *
 * @since 3.4.4
 */
require_once SECUREHOLD_PLUGIN_DIR . 'includes/cron/class-securehold-wp-log-retention.php';
Securehold_Log_Retention::register();

add_action( 'admin_init', function() {
    Securehold_Log_Retention::schedule();
}, 6 );

add_action( 'admin_notices', function() {
    if ( class_exists( 'Securehold_DB_Migrator' ) ) {
        Securehold_DB_Migrator::maybe_show_failure_notice();
    }
} );

/**
 * One-time migration: copy legacy SecureHold email options into WooCommerce email settings.
 * Runs once on admin_init and sets a flag so it never repeats.
 * @since 5.0.0
 */
add_action( 'admin_init', function() {
    if ( class_exists( 'Securehold_Email_Manager' ) ) {
        Securehold_Email_Manager::migrate_legacy_settings();
    }
}, 20 );

/**
 * One-time migration: promote the webhook secret written by the settings page
 * (securehold_stripe_webhook_secret) to the canonical option name read by the
 * webhook handler (securehold_webhook_secret), then delete the stale option.
 *
 * Targets stores where a merchant rotated the webhook secret via the settings
 * page before this fix, leaving the webhook handler reading a stale value.
 *
 * @since 3.5.0
 */
add_action( 'admin_init', function() {
    if ( get_option( 'securehold_migration_webhook_secret_v1' ) ) {
        return;
    }

    $stale_value   = get_option( 'securehold_stripe_webhook_secret', '' );
    $current_value = get_option( 'securehold_webhook_secret', '' );

    if ( ! empty( $stale_value ) && $stale_value !== $current_value ) {
        update_option( 'securehold_webhook_secret', $stale_value );
    }

    delete_option( 'securehold_stripe_webhook_secret' );
    update_option( 'securehold_migration_webhook_secret_v1', true );
}, 25 );

/**
 * The one-shot WooCommerce/SecureHold credential divergence notice was removed in
 * 3.4.4.
 *
 * It compared the two secret keys as plain strings, ran exactly once behind a
 * permanent flag, and dismissed itself on first display — so it could not report
 * a divergence introduced afterwards, which is when divergence actually happens.
 * It also had nothing to say when the WooCommerce gateway is connected through
 * OAuth and stores no key locally.
 *
 * Securehold_Stripe_Context replaces it: continuously evaluated, account-aware,
 * and able to tell an unreadable object from a genuinely different account. It is
 * the single source of truth for Stripe context, surfaced in the Health Check,
 * the Setup Wizard and the support bundle.
 *
 * securehold_migration_wc_stripe_notice_v1 and securehold_wc_stripe_divergence_notice
 * are no longer written. Both stay in uninstall.php so existing installs are
 * still cleaned up; neither warrants a migration of its own.
 */

/**
 * Add settings link on plugin page
 */
function securehold_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=securehold-settings') . '">' . __('Settings', 'securehold-security-deposit-holds') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . SECUREHOLD_PLUGIN_BASENAME, 'securehold_add_settings_link');

