<?php
declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

/**
 * PHP-Scoper configuration for SecureHold WP (FREE plugin).
 *
 * Prefix : SecureHoldWP\Vendor
 * Targets: Stripe PHP SDK (vendor/stripe/stripe-php/) and the four source files
 *          that contain direct \Stripe\* references.
 *
 * Run from the securehold-wp/ directory:
 *   php vendor/bin/php-scoper add-prefix --output-dir=../build/scoped-wp --force
 *
 * The output directory contains ONLY the processed files listed in finders below.
 * The build script overlays these onto the staging copy of the full plugin.
 *
 * IMPORTANT: This file is a dev/build artefact and is excluded from the
 * distribution ZIP by build-plugin-zip.ps1 (matched by .distignore).
 */
return [
    'prefix' => 'SecureHoldWP\\Vendor',

    'finders' => [
        // ── Stripe PHP SDK ──────────────────────────────────────────────────────
        // All class files inside lib/ (hundreds of files — Stripe's main library)
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/stripe/stripe-php/lib')
            ->name('*.php'),

        // init.php — entry point that eager-requires every file in lib/.
        // PHP-Scoper will rewrite the namespace declarations it finds via require'd files.
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/stripe/stripe-php')
            ->depth('== 0')
            ->name('init.php'),

        // ── SecureHold source files that reference \Stripe\* directly ───────────
        // includes/stripe/ — primary Stripe integration + webhook handler
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes/stripe')
            ->name('*.php'),

        // Checkout engine
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes')
            ->depth('== 0')
            ->name('class-securehold-wp-checkout.php'),

        // Order hooks — direct \Stripe\* calls must be scoped to avoid class-not-found fatals
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes/woocommerce')
            ->depth('== 0')
            ->name('class-securehold-wp-order.php'),

        // ── Stripe CA certificate bundle ────────────────────────────────────────
        // Non-PHP data file: PHP-Scoper copies it verbatim to the scoped output.
        // Without this, the build drops vendor/stripe/stripe-php/data/ entirely,
        // Stripe::getDefaultCABundlePath() returns false, and cURL fails with
        // errno 77 (CURLE_SSL_CACERT_BADFILE) on every API call.
        Finder::create()
            ->files()
            ->in(__DIR__ . '/vendor/stripe/stripe-php/data')
            ->name('ca-certificates.crt'),

        // Admin class (has Stripe\Balance::retrieve() for diagnostics)
        Finder::create()
            ->files()
            ->in(__DIR__ . '/admin')
            ->depth('== 0')
            ->name('class-securehold-wp-admin.php'),

        // Setup wizard (direct Stripe API calls: setApiKey, Customer, PaymentIntent, Refund, Exception\*)
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes')
            ->depth('== 0')
            ->name('class-securehold-wp-setup-wizard.php'),

        // Webhook configurator (WebhookEndpoint API calls and Exception\ApiErrorException)
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes')
            ->depth('== 0')
            ->name('class-securehold-wp-webhook-configurator.php'),

        // Health check (Balance::retrieve diagnostic call)
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes')
            ->depth('== 0')
            ->name('class-securehold-wp-health-check.php'),

        // Error handler (instanceof \Stripe\Exception\* checks — must match scoped exceptions)
        Finder::create()
            ->files()
            ->in(__DIR__ . '/includes')
            ->depth('== 0')
            ->name('class-securehold-wp-error-handler.php'),
    ],

    // Prevent scoping WooCommerce/WordPress namespaces if they appear in included files.
    'exclude-namespaces' => [
        'Automattic',
        'WooCommerce',
    ],

    // Plugin classes that live in the global namespace and must NOT be prefixed.
    // These classes are loaded by the main plugin file BEFORE the scoped files run.
    // Without this exclusion, PHP-Scoper rewrites e.g. \Securehold_Stripe_Installer
    // to \SecureHoldWP\Vendor\Securehold_Stripe_Installer inside scoped files,
    // causing a fatal "class not found" error at runtime.
    //
    // WordPress / WooCommerce global classes must also be excluded: PHP-Scoper injects
    // "namespace SecureHoldWP\Vendor;" at the top of every processed file, so any
    // unqualified class reference (new WC_Product_Simple(), is_a($o, 'WC_Order'), etc.)
    // resolves to SecureHoldWP\Vendor\ClassName instead of the global namespace.
    // exclude-namespaces is insufficient because these classes have no PHP namespace.
    'exclude-classes' => [
        'Securehold_Stripe_Installer',
        // WordPress / WooCommerce global classes used in scoped files
        'WC_Product_Simple',
        'WC_Gateway_Stripe',
        'WC_Order',
        'WP_Error',
        'WP_Post',
        'WP_Query',
        'WC_Email',
        // WooCommerce main class — global namespace, must not be prefixed
        'WooCommerce',
        // SecureHold plugin classes that live in the global namespace.
        // Their source files are NOT in the scoper finders, so PHP-Scoper
        // must not rewrite references to them inside scoped files.
        'Securehold_Email_Manager',
        'Securehold_Scheduler',
        'Securehold_Config_Resolver',
        'SecureHold_DB',
    ],

    // Global functions that must NOT be prefixed.
    // PHP-Scoper rewrites unqualified function references inside namespaced files;
    // listing them here keeps their string form and call form in the global namespace.
    'exclude-functions' => [
        // WooCommerce global functions
        'WC',
        'is_checkout',
        'wc_get_checkout_url',
        // WordPress global function
        'wp_is_writable',
        // SecureHold plugin-level helpers (defined in includes/helpers.php, not scoped)
        'securehold_log',
        'securehold_get_stripe_keys',
        'securehold_get_woocommerce_stripe_keys',
        'securehold_get_stripe_mode_status',
    ],

    'patchers' => [
        static function (string $filePath, string $prefix, string $contents): string {
            // PHP-Scoper prefixes global function calls with \ in namespaced files,
            // producing if (!\defined('ABSPATH')). Plugin Check's
            // missing_direct_file_access_protection sniff expects the canonical
            // WordPress form without the backslash prefix.
            return str_replace(
                "if (!\\defined('ABSPATH')) {",
                "if ( ! defined( 'ABSPATH' ) ) {",
                $contents
            );
        },
    ],
];
