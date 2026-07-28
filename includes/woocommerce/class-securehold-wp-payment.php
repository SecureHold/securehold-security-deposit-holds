<?php
/**
 * SecureHold Payment Integration
 *
 * Legacy file — payment method saving is now handled entirely by Securehold_Checkout.
 * This class is kept for backward compatibility but contains no blocking logic.
 *
 * @since 4.0.0 - Guest-first rewrite (removed validation and forced checkbox)
 * @see includes/class-securehold-wp-checkout.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Payment {

    /**
     * Force save payment method (forwarded to wc_stripe_force_save_source filter).
     * This is a no-op now — the filter is set in Securehold_Checkout.
     */
    public function force_save_payment_method($force_save) {
        return true;
    }

    /**
     * Show save checkbox.
     * No longer needed — payment method saving is transparent.
     */
    public function show_save_checkbox($show) {
        return true;
    }
}
