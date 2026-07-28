<?php
/**
 * Stripe SDK presence checker for SecureHold WP.
 *
 * The Stripe PHP SDK is bundled inside the plugin distribution ZIP
 * (vendor/stripe/stripe-php/) and scoped to the SecureHoldWP\Vendor namespace at
 * build time using PHP-Scoper. It no longer needs to be downloaded at runtime.
 *
 * This class is retained for:
 *  - Health Check page: is_installed() / get_status_message()
 *  - Admin Stripe-key save guard: is_installed()
 *  - Setup Wizard step: is_installed() / get_status_message()
 *
 * The install() method is intentionally disabled.  If it is ever called
 * (e.g. by an upgrade path that still references it), it returns a clear WP_Error
 * rather than downloading an unscoped SDK that would be incompatible with the
 * scoped build.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Stripe_Installer {

    /**
     * Absolute path to the Stripe SDK entry point bundled with this plugin.
     * In the scoped distribution build this file declares SecureHoldWP\Vendor\Stripe\*;
     * in the dev environment it declares the standard Stripe\* namespace.
     *
     * @return string
     */
    private static function sdk_init_path() {
        return SECUREHOLD_PLUGIN_DIR . 'vendor/stripe/stripe-php/init.php';
    }

    /**
     * Check whether the bundled Stripe SDK is present on disk.
     *
     * @return bool
     */
    public static function is_installed() {
        return file_exists( self::sdk_init_path() );
    }

    /**
     * Load the Stripe SDK if not already loaded. Idempotent (require_once).
     *
     * @return bool True on success, false if the SDK file is missing.
     */
    public static function load() {
        $path = self::sdk_init_path();
        if ( ! file_exists( $path ) ) {
            return false;
        }
        require_once $path;
        return true;
    }

    /**
     * Return the active Stripe base-class name available at runtime, or null if not loaded.
     *
     * Scoped distribution build : '\SecureHoldWP\Vendor\Stripe\Stripe'
     * Dev / unscoped environment : '\Stripe\Stripe'
     *
     * @return string|null
     */
    public static function get_stripe_class() {
        if ( class_exists( '\SecureHoldWP\Vendor\Stripe\Stripe' ) ) {
            return '\SecureHoldWP\Vendor\Stripe\Stripe';
        }
        if ( class_exists( '\Stripe\Stripe' ) ) {
            return '\Stripe\Stripe';
        }
        return null;
    }

    /**
     * Disabled — Stripe SDK is bundled in the plugin ZIP.
     *
     * Returns a WP_Error so any legacy caller fails gracefully with a clear message
     * instead of attempting a runtime download of an unscoped (incompatible) SDK.
     *
     * @return WP_Error
     */
    public static function install() {
        return new WP_Error(
            'sdk_bundled',
            __( 'The Stripe SDK is bundled in this version of SecureHold WP and does not require a separate download. If the SDK files are missing, please reinstall the plugin from your account dashboard.', 'securehold-security-deposit-holds' )
        );
    }

    /**
     * Return a status array for display in the Setup Wizard and Health Check.
     *
     * @return array{status: string, message: string}
     */
    public static function get_status_message() {
        if ( self::is_installed() ) {
            return array(
                'status'  => 'success',
                'message' => __( 'Stripe SDK is bundled and ready.', 'securehold-security-deposit-holds' ),
            );
        }

        return array(
            'status'  => 'error',
            'message' => __( 'Stripe SDK files not found. Please reinstall the plugin from your account dashboard.', 'securehold-security-deposit-holds' ),
        );
    }
}
