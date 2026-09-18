<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SecureHold_Stripe {
    
    public static function init_stripe() {
        // The main plugin file loads the Stripe SDK at boot via Securehold_Stripe_Installer::load().
        // This call is an idempotent safety fallback only (require_once is a no-op if already loaded).
        // In the distribution build, \Stripe\Stripe below is rewritten by PHP-Scoper
        // to \SecureHoldWP\Vendor\Stripe\Stripe.
        Securehold_Stripe_Installer::load();

        // Set the CA bundle path explicitly using SECUREHOLD_PLUGIN_DIR so that
        // the correct absolute path is used without relying on realpath(), which
        // returns false when the file is absent and silently produces an empty
        // CURLOPT_CAINFO — causing cURL errno 77 on every API call.
        // In the distribution build, \Stripe\Stripe is rewritten by PHP-Scoper
        // to \SecureHoldWP\Vendor\Stripe\Stripe.
        $ca_bundle = SECUREHOLD_PLUGIN_DIR . 'vendor/stripe/stripe-php/data/ca-certificates.crt';
        if ( file_exists( $ca_bundle ) ) {
            \Stripe\Stripe::setCABundlePath( $ca_bundle );
        }

        $keys = securehold_get_stripe_keys();
        if ( empty( $keys['secret'] ) ) {
            return false;
        }

        \Stripe\Stripe::setApiKey( $keys['secret'] );
        return true;
    }
    
    /**
     * Ensure a PaymentMethod is attached to a Customer on Stripe.
     *
     * Stripe requires a PM to be explicitly attached to a Customer before it can
     * be reused for off-session payments. WC Stripe Gateway uses PMs with
     * PaymentIntents but may not attach them to the Customer object.
     *
     * If the PM was used in a PaymentIntent without setup_future_usage, Stripe
     * considers it "single-use" and will refuse attach/reuse. In that case we
     * attempt to clone the PM from the original PaymentIntent.
     *
     * @param string $payment_method_id  Stripe PM ID (pm_...)
     * @param string $customer_id        Stripe Customer ID (cus_...)
     * @param int    $order_id           WooCommerce order ID (for fallback cloning)
     * @return true|WP_Error
     */
    public static function ensure_payment_method_attached($payment_method_id, $customer_id, $order_id = 0) {
        if (!self::init_stripe()) {
            return new WP_Error('stripe_config_error', __('Stripe API keys are missing.', 'securehold-security-deposit-holds'));
        }

        try {
            $pm = \Stripe\PaymentMethod::retrieve($payment_method_id);

            // ── Diagnostic: Log PM reusability state ──
            $pm_customer = !empty($pm->customer) ? $pm->customer : '';
            $pm_type = !empty($pm->type) ? $pm->type : 'unknown';

            if (function_exists('securehold_log')) {
                securehold_log('PM Diagnostic', array(
                    'pm' => $payment_method_id,
                    'type' => $pm_type,
                    'attached_to_customer' => $pm_customer ?: '(none)',
                    'target_customer' => $customer_id,
                    'order_id' => $order_id,
                ));
            }

            // Check if already attached to the correct customer
            if ($pm_customer === $customer_id) {
                if (function_exists('securehold_log')) {
                    securehold_log('Stripe: PM already attached to customer', array(
                        'pm' => $payment_method_id,
                        'customer' => $customer_id,
                    ));
                }
                return true;
            }

            // PM not attached or attached to wrong customer — detach first if needed
            if (!empty($pm_customer)) {
                try {
                    $pm->detach();
                    if (function_exists('securehold_log')) {
                        securehold_log('Stripe: Detached PM from previous customer', array(
                            'pm' => $payment_method_id,
                            'previous_customer' => $pm_customer,
                        ));
                    }
                } catch (\Exception $e) {
                    // Non-fatal: if detach fails, try attach anyway
                    if (function_exists('securehold_log')) {
                        securehold_log('Stripe: Detach failed (non-fatal)', array(
                            'error' => $e->getMessage(),
                        ));
                    }
                }
            }

            // Attach to the target customer
            $pm->attach(array('customer' => $customer_id));

            if (function_exists('securehold_log')) {
                securehold_log('Stripe: PM attached to customer', array(
                    'pm' => $payment_method_id,
                    'customer' => $customer_id,
                ));
            }

            // Optionally set as default payment method on the customer
            try {
                \Stripe\Customer::update($customer_id, array(
                    'invoice_settings' => array(
                        'default_payment_method' => $payment_method_id,
                    ),
                ));
            } catch (\Exception $e) {
                // Non-fatal: default PM is optional
            }

            return true;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $error_msg = $e->getMessage();

            // Handle "already been attached" gracefully
            if (strpos($error_msg, 'already been attached') !== false) {
                if (function_exists('securehold_log')) {
                    securehold_log('Stripe: PM already attached (confirmed by API)', array(
                        'pm' => $payment_method_id,
                        'customer' => $customer_id,
                    ));
                }
                return true;
            }

            // ── Single-use PM detection ──
            // Stripe returns this error when a PM was used without setup_future_usage
            $is_single_use = (
                strpos($error_msg, 'previously used without being attached') !== false
                || strpos($error_msg, 'may not be used again') !== false
                || strpos($error_msg, 'was detached from a Customer') !== false
            );

            if ($is_single_use) {
                if (function_exists('securehold_log')) {
                    securehold_log('pm_single_use_detected: PM was consumed by checkout without setup_future_usage', array(
                        'pm' => $payment_method_id,
                        'customer' => $customer_id,
                        'order_id' => $order_id,
                        'error' => $error_msg,
                    ), 'warning');
                }

                // ── Fallback: Clone PM from original PaymentIntent ──
                // When the checkout PaymentIntent used setup_future_usage, the PM's
                // generated_from.payment_method_details can be used to create a new PM.
                // We retrieve the original intent and use its latest_charge to clone.
                $cloned = self::clone_pm_from_order_intent($customer_id, $order_id);
                if (!is_wp_error($cloned)) {
                    return $cloned; // Returns ['payment_method_id' => 'pm_new...']
                }

                if (function_exists('securehold_log')) {
                    securehold_log('PM clone fallback also failed', array(
                        'order_id' => $order_id,
                        'clone_error' => $cloned->get_error_message(),
                    ), 'error');
                }

                /* translators: %1$s is the payment method ID, %2$s is the error message */
                return new WP_Error('pm_single_use', sprintf(
                    __('Payment method %1$s is single-use (checkout did not set setup_future_usage). Clone fallback failed: %2$s', 'securehold-security-deposit-holds'),
                    $payment_method_id,
                    $cloned->get_error_message()
                ));
            }

            if (function_exists('securehold_log')) {
                securehold_log('Stripe: Failed to attach PM to customer', array(
                    'pm' => $payment_method_id,
                    'customer' => $customer_id,
                    'error' => $error_msg,
                ));
            }

            return new WP_Error('pm_attach_failed', $error_msg);

        } catch (\Exception $e) {
            if (function_exists('securehold_log')) {
                securehold_log('Stripe: General error attaching PM', array(
                    'error' => $e->getMessage(),
                ));
            }
            return new WP_Error('pm_attach_error', $e->getMessage());
        }
    }

    /**
     * Clone a PaymentMethod from the original checkout PaymentIntent.
     *
     * When the original PM is single-use, we can retrieve the checkout
     * PaymentIntent and use its payment_method (which Stripe may have
     * auto-generated as reusable if setup_future_usage was set), or
     * look at the customer's payment methods list for a recently saved PM.
     *
     * @param string $customer_id Stripe Customer ID
     * @param int    $order_id    WooCommerce order ID
     * @return array|WP_Error  ['payment_method_id' => 'pm_...'] on success
     */
    public static function clone_pm_from_order_intent($customer_id, $order_id) {
        if (!self::init_stripe() || empty($order_id)) {
            return new WP_Error('no_order', 'No order ID for PM clone fallback');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found');
        }

        // Get the original checkout PaymentIntent
        $intent_id = $order->get_meta('_stripe_intent_id', true);
        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            return new WP_Error('no_intent', 'No checkout PaymentIntent found on order');
        }

        try {
            $intent = \Stripe\PaymentIntent::retrieve($intent_id, array(
                'expand' => array('latest_charge', 'payment_method'),
            ));

            // Strategy 1: If the intent's PM is now attached to the customer, use it
            if (!empty($intent->payment_method)) {
                $intent_pm_id = is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method;
                $intent_pm = \Stripe\PaymentMethod::retrieve($intent_pm_id);

                if (!empty($intent_pm->customer) && $intent_pm->customer === $customer_id) {
                    if (function_exists('securehold_log')) {
                        securehold_log('PM clone: Intent PM is already attached to customer', array(
                            'pm' => $intent_pm_id,
                            'customer' => $customer_id,
                        ));
                    }

                    // Update order meta with the correct PM
                    $order->update_meta_data('_stripe_payment_method', $intent_pm_id);
                    $order->save();

                    return array('payment_method_id' => $intent_pm_id);
                }
            }

            // Strategy 2: List customer's payment methods and find a recently saved one
            if (!empty($customer_id)) {
                $customer_pms = \Stripe\PaymentMethod::all(array(
                    'customer' => $customer_id,
                    'type' => 'card',
                    'limit' => 5,
                ));

                if (!empty($customer_pms->data) && count($customer_pms->data) > 0) {
                    // Use the most recent PM (first in the list)
                    $newest_pm = $customer_pms->data[0];

                    if (function_exists('securehold_log')) {
                        securehold_log('PM clone: Using customer\'s latest saved PM', array(
                            'new_pm' => $newest_pm->id,
                            'customer' => $customer_id,
                            'card_last4' => !empty($newest_pm->card->last4) ? $newest_pm->card->last4 : 'N/A',
                        ));
                    }

                    // Update order meta
                    $order->update_meta_data('_stripe_payment_method', $newest_pm->id);
                    $order->save();

                    return array('payment_method_id' => $newest_pm->id);
                }
            }

            return new WP_Error('no_reusable_pm', 'No reusable payment method found on customer or intent');

        } catch (\Exception $e) {
            return new WP_Error('clone_error', $e->getMessage());
        }
    }

    /**
     * Create a PaymentIntent (Authorization only)
     *
     * Before creating the intent:
     * 1. Checks the checkout PI diagnosis for reusability
     * 2. Ensures the PM is properly attached to the Customer
     * 3. If the PM is single-use, attempts clone fallback
     * 4. Applies developer filter `securehold_hold_payment_intent_args`
     *
     * If PM is definitively single-use and no fallback works, returns a WP_Error
     * with code `pm_single_use` and an actionable message. The caller (scheduler)
     * should mark the deposit as `failed` with the error message as note.
     *
     * @since 4.1.0 Added PI pre-diagnosis check, developer filter, actionable error messages
     */
    public static function create_payment_intent($amount, $currency, $customer_id, $payment_method_id, $order_id) {
        if (!self::init_stripe()) {
            return new WP_Error('stripe_config_error', __('Stripe API keys are missing.', 'securehold-security-deposit-holds'));
        }

        // ── Mode preflight: block if SecureHold and WooCommerce Stripe Gateway are on different modes ──
        if ( function_exists( 'securehold_get_stripe_mode_status' ) ) {
            $mode_status = securehold_get_stripe_mode_status();
            if ( $mode_status['gateway_active'] && ! $mode_status['aligned'] ) {
                return new WP_Error(
                    'stripe_mode_mismatch',
                    sprintf(
                        /* translators: 1: SecureHold mode, 2: WooCommerce Stripe mode */
                        __( 'Security deposit blocked: SecureHold is in %1$s mode but the WooCommerce Stripe Gateway is in %2$s mode. Align both to the same mode in SecureHold → Settings.', 'securehold-security-deposit-holds' ),
                        ucfirst( $mode_status['securehold'] ),
                        ucfirst( $mode_status['woocommerce'] )
                    )
                );
            }
        }

        $order = wc_get_order($order_id);

        // ── Pre-flight 0: Check PI diagnosis from checkout ──
        // If we already diagnosed this order's checkout PI, use the result to warn early.
        if ($order) {
            $diagnosis = $order->get_meta('_securehold_pi_diagnosis_result', true);
            $pm_reusable = $order->get_meta('_securehold_pm_reusable', true);

            if ($diagnosis && $diagnosis !== 'reusable' && $pm_reusable === 'no') {
                if (function_exists('securehold_log')) {
                    securehold_log('Pre-flight: PM diagnosed as non-reusable, attempting fallback', array(
                        'order_id' => $order_id,
                        'diagnosis' => $diagnosis,
                        'pm_reusable' => $pm_reusable,
                    ), 'warning');
                }
                // Don't fail yet — let ensure_payment_method_attached + clone fallback try
            }
        }

        // ── Pre-flight 1: Ensure PM is attached to Customer ──
        // Required for off-session payments. Guest checkout PMs may not be attached.
        // Pass order_id so the fallback clone can look up the original PaymentIntent.
        if (!empty($payment_method_id) && strpos($payment_method_id, 'pm_') === 0 && !empty($customer_id)) {
            $attach_result = self::ensure_payment_method_attached($payment_method_id, $customer_id, $order_id);

            if (is_wp_error($attach_result)) {
                $error_code = $attach_result->get_error_code();

                // ── Single-use PM: Return actionable error ──
                if ($error_code === 'pm_single_use') {
                    $actionable_msg = sprintf(
                        'Security deposit creation failed for Order #%2$s: The payment method (%1$s) used at checkout is single-use and cannot be reused for an off-session hold. ' .
                        'SecureHold WP automatically configures setup_future_usage=off_session via checkout engine filters, but the payment method was not made reusable. ' .
                        'Some payment methods (wallets, bank redirects) have additional reusability constraints. Run the SecureHold checkout diagnostic for details.',
                        $payment_method_id,
                        $order_id
                    );

                    if (function_exists('securehold_log')) {
                        securehold_log('Hold creation blocked: PM is single-use, all fallbacks exhausted', array(
                            'order_id' => $order_id,
                            'pm' => $payment_method_id,
                            'customer' => $customer_id,
                            'original_error' => $attach_result->get_error_message(),
                        ), 'error');
                    }

                    // Store failure reason on order meta for admin UI
                    if ($order) {
                        $order->update_meta_data('_securehold_hold_failure_reason', 'pm_single_use');
                        $order->update_meta_data('_securehold_hold_failure_message', $actionable_msg);
                        $order->save();
                    }

                    return new WP_Error('pm_single_use', $actionable_msg);
                }

                // ── Inaccessible Stripe object ──
                // "No such PaymentMethod: 'pm_...'" is thrown here, during attach,
                // before PaymentIntent::create() is ever called, so the main catch
                // block never runs. The classifier qualifies the message with the
                // Stripe context diagnosis instead of assuming a wrong account.
                $attach_error_msg = $attach_result->get_error_message();
                $access_code      = self::classify_stripe_access_error( $attach_error_msg );

                if ( $access_code ) {
                    if ( $order ) {
                        $order->update_meta_data( '_securehold_hold_failure_reason',  $access_code );
                        $order->update_meta_data( '_securehold_hold_failure_message', $attach_error_msg );
                        $order->save();
                    }
                    return new WP_Error( $access_code, $attach_error_msg );
                }

                return $attach_result; // Other attach errors
            }

            // If attach returned a cloned PM (array with payment_method_id), use it
            if (is_array($attach_result) && !empty($attach_result['payment_method_id'])) {
                $old_pm = $payment_method_id;
                $payment_method_id = $attach_result['payment_method_id'];

                if (function_exists('securehold_log')) {
                    securehold_log('Using cloned PM for hold creation', array(
                        'original_pm' => $old_pm,
                        'cloned_pm' => $payment_method_id,
                        'order_id' => $order_id,
                    ));
                }
            }
        }

        try {
            $amount_cents = (int) round($amount * 100);

            $intent_args = array(
                'amount' => $amount_cents,
                'currency' => strtolower($currency),
                'customer' => $customer_id,
                'payment_method' => $payment_method_id,
                'capture_method' => 'manual', // IMPORTANT: This creates the hold
                'confirm' => true,
                'off_session' => true, // Required: hold is created after customer left checkout
                /* translators: %s is the WooCommerce order ID */
                'description' => sprintf(__('Security Deposit for Order #%s', 'securehold-security-deposit-holds'), $order_id),
                'metadata' => array(
                    'order_id' => $order_id,
                    'plugin' => 'securehold'
                )
            );

            // Add return_url for 3DS fallback
            $intent_args['return_url'] = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url();

            // ── Developer Filter: securehold_hold_payment_intent_args ──
            // Allows developers to modify the hold PaymentIntent args before creation.
            // Context: 'hold_creation' (vs. future contexts like 'retry', etc.)
            // Example: add_filter('securehold_hold_payment_intent_args', function($args, $order, $context) {
            //     $args['metadata']['custom_key'] = 'custom_value';
            //     return $args;
            // }, 10, 3);
            $intent_args_before = $intent_args;
            $filter_context = 'hold_creation';
            $intent_args = apply_filters('securehold_hold_payment_intent_args', $intent_args, $order, $filter_context);

            // Log if the filter modified anything
            if ($intent_args !== $intent_args_before && function_exists('securehold_log')) {
                // Compute what changed (without exposing full args)
                $changed_keys = array();
                foreach ($intent_args as $key => $val) {
                    if (!isset($intent_args_before[$key]) || $intent_args_before[$key] !== $val) {
                        $changed_keys[] = $key;
                    }
                }
                securehold_log('Developer filter modified hold PI args', array(
                    'order_id' => $order_id,
                    'filter' => 'securehold_hold_payment_intent_args',
                    'changed_keys' => $changed_keys,
                ));
            }

            if (function_exists('securehold_log')) {
                securehold_log('Creating hold PaymentIntent', array(
                    'order_id' => $order_id,
                    'customer' => $customer_id,
                    'pm' => $payment_method_id,
                    'amount_cents' => $amount_cents,
                    'currency' => $currency,
                    'filtered' => ($intent_args !== $intent_args_before),
                ));
            }

            $intent = \Stripe\PaymentIntent::create($intent_args);

            if (function_exists('securehold_log')) {
                securehold_log('Hold PaymentIntent created', array(
                    'intent_id' => $intent->id,
                    'status' => $intent->status,
                    'order_id' => $order_id,
                ));
            }

            // Clear any previous failure meta
            if ($order) {
                $order->delete_meta_data('_securehold_hold_failure_reason');
                $order->delete_meta_data('_securehold_hold_failure_message');
                $order->save();
            }

            return $intent;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            $error_msg = $e->getMessage();
            $error_code = 'stripe_error';

            // ── Detect single-use PM error at intent creation level ──
            $is_single_use_error = (
                strpos($error_msg, 'previously used without being attached') !== false
                || strpos($error_msg, 'may not be used again') !== false
                || strpos($error_msg, 'was detached from a Customer') !== false
                || strpos($error_msg, 'not attached to a customer') !== false
            );

            if ($is_single_use_error) {
                $error_code = 'pm_single_use';
                $error_msg = sprintf(
                    'Security deposit failed: Payment method %s is single-use and cannot be reused for an off-session hold. ' .
                    'SecureHold WP automatically configures reusability via checkout engine filters, but this payment method was not made reusable. ' .
                    'Some payment methods (wallets, bank redirects) have reusability constraints. Run the SecureHold checkout diagnostic for details.',
                    $payment_method_id
                );

                // Store failure on order
                if ($order) {
                    $order->update_meta_data('_securehold_hold_failure_reason', 'pm_single_use');
                    $order->update_meta_data('_securehold_hold_failure_message', $error_msg);
                    $order->save();
                }
            }

            // ── Inaccessible Stripe object ──
            // "No such X" means this identifier cannot be resolved with the
            // current credentials. That is a fact; a wrong Stripe account is only
            // one of its causes. The classifier weighs it against the Stripe
            // context diagnosis before naming the failure.
            $access_code = $is_single_use_error ? null : self::classify_stripe_access_error( $error_msg );

            if ( $access_code ) {
                $error_code = $access_code;

                if ( $order ) {
                    $order->update_meta_data( '_securehold_hold_failure_reason',  $access_code );
                    $order->update_meta_data( '_securehold_hold_failure_message', $e->getMessage() );
                    $order->save();
                }
            }

            if (function_exists('securehold_log')) {
                securehold_log('Stripe Create PaymentIntent Error', array(
                    'error' => $e->getMessage(),
                    'error_code' => $error_code,
                    'is_single_use' => $is_single_use_error,
                    'order_id' => $order_id,
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                    'amount' => $amount,
                    'currency' => $currency,
                ), 'error');
            }
            return new WP_Error($error_code, $error_msg);
        } catch (Exception $e) {
            if (function_exists('securehold_log')) {
                securehold_log('Stripe Create PaymentIntent General Error', array(
                    'error' => $e->getMessage(),
                    'order_id' => $order_id,
                ), 'error');
            }
            return new WP_Error('general_error', $e->getMessage());
        }
    }

    /**
     * Diagnose the Stripe state of an order for Health Check / Debug tool.
     *
     * Queries Stripe API to retrieve the checkout PaymentIntent, PaymentMethod,
     * and Customer. Returns a structured array of facts without modifying anything.
     *
     * @since 4.1.0
     * @param int $order_id WooCommerce order ID
     * @return array|WP_Error Diagnosis facts array or error
     */
    public static function diagnose_order_stripe_state($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', __('Order not found.', 'securehold-security-deposit-holds'));
        }

        if (strpos($order->get_payment_method(), 'stripe') === false) {
            return new WP_Error('not_stripe', __('This order does not use Stripe payment.', 'securehold-security-deposit-holds'));
        }

        if (!self::init_stripe()) {
            return new WP_Error('stripe_config_error', __('Stripe API keys are missing.', 'securehold-security-deposit-holds'));
        }

        $result = array(
            'order_id' => $order_id,
            'is_guest' => ($order->get_customer_id() == 0),
            'wc_payment_method' => $order->get_payment_method(),
            'sources' => array(),
            'intent' => null,
            'payment_method' => null,
            'customer' => null,
            'diagnosis' => 'unknown',
            'can_create_hold' => false,
            'recommendations' => array(),
        );

        // ── Collect order meta sources ──
        $meta_keys = array(
            '_stripe_intent_id', '_stripe_customer_id', '_stripe_payment_method',
            '_stripe_source_id', '_stripe_card_id', '_stripe_charge_id',
            '_securehold_pi_diagnosed', '_securehold_pi_diagnosis_result',
            '_securehold_pi_setup_future_usage', '_securehold_pm_reusable',
            '_securehold_hold_failure_reason', '_securehold_hold_failure_message',
        );
        foreach ($meta_keys as $key) {
            $val = $order->get_meta($key, true);
            if (!empty($val)) {
                $result['sources'][$key] = $val;
            }
        }

        // ── Retrieve checkout PaymentIntent from Stripe ──
        $intent_id = $order->get_meta('_stripe_intent_id', true);
        if (empty($intent_id)) {
            $charge_id = $order->get_meta('_stripe_charge_id', true);
            if (!empty($charge_id)) {
                try {
                    $charge = \Stripe\Charge::retrieve($charge_id);
                    $intent_id = !empty($charge->payment_intent) ? $charge->payment_intent : '';
                } catch (\Exception $e) { /* ignore */ }
            }
        }

        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            $result['diagnosis'] = 'no_intent_id';
            $result['recommendations'][] = __('No Stripe PaymentIntent found on this order. The payment gateway may not have stored the intent ID.', 'securehold-security-deposit-holds');
            return $result;
        }

        try {
            $intent = \Stripe\PaymentIntent::retrieve($intent_id, array(
                'expand' => array('payment_method', 'customer'),
            ));

            $result['intent'] = array(
                'id' => $intent->id,
                'status' => $intent->status,
                'amount' => $intent->amount,
                'currency' => $intent->currency,
                'setup_future_usage' => !empty($intent->setup_future_usage) ? $intent->setup_future_usage : null,
                'customer' => !empty($intent->customer) ? (is_object($intent->customer) ? $intent->customer->id : $intent->customer) : null,
                'payment_method' => !empty($intent->payment_method) ? (is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method) : null,
                'created' => gmdate('Y-m-d H:i:s', $intent->created),
            );

            // ── Retrieve PaymentMethod details ──
            $pm_id = $result['intent']['payment_method'];
            if ($pm_id) {
                try {
                    $pm = is_object($intent->payment_method) ? $intent->payment_method : \Stripe\PaymentMethod::retrieve($pm_id);
                    $result['payment_method'] = array(
                        'id' => $pm->id,
                        'type' => !empty($pm->type) ? $pm->type : 'unknown',
                        'customer' => !empty($pm->customer) ? $pm->customer : null,
                        'card_brand' => !empty($pm->card->brand) ? $pm->card->brand : null,
                        'card_last4' => !empty($pm->card->last4) ? $pm->card->last4 : null,
                        'card_exp' => !empty($pm->card->exp_month) ? sprintf('%02d/%d', $pm->card->exp_month, $pm->card->exp_year) : null,
                    );
                } catch (\Exception $e) {
                    $result['payment_method'] = array('id' => $pm_id, 'error' => $e->getMessage());
                }
            }

            // ── Retrieve Customer details ──
            $cus_id = $result['intent']['customer'];
            if ($cus_id) {
                try {
                    $cus = is_object($intent->customer) ? $intent->customer : \Stripe\Customer::retrieve($cus_id);
                    $result['customer'] = array(
                        'id' => $cus->id,
                        'email' => !empty($cus->email) ? $cus->email : null,
                        'name' => !empty($cus->name) ? $cus->name : null,
                        'default_pm' => !empty($cus->invoice_settings->default_payment_method) ? $cus->invoice_settings->default_payment_method : null,
                    );
                } catch (\Exception $e) {
                    $result['customer'] = array('id' => $cus_id, 'error' => $e->getMessage());
                }
            }

            // ── Build diagnosis ──
            $sfu = $result['intent']['setup_future_usage'];
            $pm_attached_to_customer = (
                isset($result['payment_method']['customer'])
                && !empty($result['intent']['customer'])
                && $result['payment_method']['customer'] === $result['intent']['customer']
            );

            if (!empty($sfu) && $pm_attached_to_customer) {
                $result['diagnosis'] = 'reusable';
                $result['can_create_hold'] = true;
            } elseif (empty($sfu)) {
                $result['diagnosis'] = 'no_setup_future_usage';
                $result['can_create_hold'] = false;
                $result['recommendations'][] = __(
                    'The checkout PaymentIntent does NOT have setup_future_usage set. The payment method is single-use and cannot be reused for an off-session security deposit.',
                    'securehold-security-deposit-holds'
                );
                $result['recommendations'][] = __(
                    'SecureHold WP automatically configures setup_future_usage=off_session via checkout engine filters. If this diagnosis appears, the payment method type may have reusability constraints (e.g. wallets, bank redirects). Run the SecureHold checkout diagnostic in Tools for detailed layer-by-layer analysis.',
                    'securehold-security-deposit-holds'
                );
            } elseif (!$pm_attached_to_customer) {
                $result['diagnosis'] = 'pm_not_attached';
                $result['can_create_hold'] = false;
                $result['recommendations'][] = __(
                    'The payment method is not attached to the customer. SecureHold will attempt to attach it automatically when creating the hold.',
                    'securehold-security-deposit-holds'
                );
            }

            // Check if clone fallback might work
            if (!$result['can_create_hold'] && $cus_id) {
                try {
                    $customer_pms = \Stripe\PaymentMethod::all(array(
                        'customer' => $cus_id,
                        'type' => 'card',
                        'limit' => 3,
                    ));
                    if (!empty($customer_pms->data) && count($customer_pms->data) > 0) {
                        $result['can_create_hold'] = true; // Clone fallback available
                        $result['recommendations'][] = sprintf(
                            /* translators: %d is the number of saved payment methods */
                            __('However, the customer has %d saved payment method(s). SecureHold may use one as a fallback.', 'securehold-security-deposit-holds'),
                            count($customer_pms->data)
                        );
                    }
                } catch (\Exception $e) { /* ignore */ }
            }

        } catch (\Exception $e) {
            $result['diagnosis'] = 'api_error';
            $result['recommendations'][] = sprintf(
                /* translators: %s is the error message from Stripe */
                __('Could not retrieve PaymentIntent from Stripe: %s', 'securehold-security-deposit-holds'),
                $e->getMessage()
            );
        }

        return $result;
    }

    /**
     * Meta keys that may carry a payment reference, in resolution order.
     *
     * @since 3.4.4
     */
    const PAYMENT_REFERENCE_META = array(
        '_stripe_payment_method',
        '_stripe_payment_method_id',
        '_stripe_source_id',
        '_stripe_card_id',
    );

    /**
     * Resolve the payment reference stored on an order, and say what it is.
     *
     * Four fallback orders used to coexist — here, in the checkout engine, in the
     * scheduler's legacy path, and in PRO — and none of them read
     * _stripe_payment_method_id even though the setup wizard writes it. Worse,
     * only _stripe_payment_method was checked for a pm_ prefix: a legacy src_ or
     * card_ sitting in _stripe_source_id was passed straight to
     * PaymentMethod::retrieve(), which addresses /v1/payment_methods and cannot
     * resolve a Source or a Card. Stripe answered "No such PaymentMethod", and
     * SecureHold read that as proof of a wrong Stripe account.
     *
     * Pure classification: reads meta, performs no API call, writes nothing. The
     * caller decides what to do with an unusable reference — normally fall
     * through to the checkout PaymentIntent, which yields a real pm_.
     *
     * @since 3.4.4
     *
     * @param WC_Order $order Order to inspect.
     * @return array {
     *     @type string      $value       The stored identifier, or ''.
     *     @type string|null $source_meta Meta key it came from.
     *     @type string      $object_type payment_method|legacy_source|legacy_card|unknown|none.
     *     @type bool        $usable      Whether it can be used for an off-session hold.
     *     @type string|null $reason      Why not, when unusable.
     * }
     */
    public static function resolve_order_payment_reference( $order ) {
        $none = array(
            'value'       => '',
            'source_meta' => null,
            'object_type' => 'none',
            'usable'      => false,
            'reason'      => 'no_payment_reference',
        );

        if ( ! $order || ! method_exists( $order, 'get_meta' ) ) {
            return $none;
        }

        $first_unusable = null;

        foreach ( self::PAYMENT_REFERENCE_META as $meta_key ) {
            $value = (string) $order->get_meta( $meta_key, true );

            if ( $value === '' ) {
                continue;
            }

            $type = self::classify_payment_reference( $value );

            if ( $type === 'payment_method' ) {
                return array(
                    'value'       => $value,
                    'source_meta' => $meta_key,
                    'object_type' => 'payment_method',
                    'usable'      => true,
                    'reason'      => null,
                );
            }

            // Remember the first unusable reference so the caller can explain
            // the failure precisely, but keep scanning: a modern pm_ may still
            // be stored under a later key.
            if ( $first_unusable === null ) {
                $first_unusable = array(
                    'value'       => $value,
                    'source_meta' => $meta_key,
                    'object_type' => $type,
                    'usable'      => false,
                    'reason'      => ( $type === 'unknown' ) ? 'invalid_payment_reference' : 'legacy_payment_method',
                );
            }
        }

        return ( $first_unusable !== null ) ? $first_unusable : $none;
    }

    /**
     * Object type behind a Stripe identifier, from its prefix.
     *
     * @since 3.4.4
     *
     * @param string $value Identifier.
     * @return string payment_method|legacy_source|legacy_card|unknown.
     */
    public static function classify_payment_reference( $value ) {
        $value = (string) $value;

        if ( strpos( $value, 'pm_' ) === 0 ) {
            return 'payment_method';
        }

        if ( strpos( $value, 'src_' ) === 0 ) {
            return 'legacy_source';
        }

        if ( strpos( $value, 'card_' ) === 0 ) {
            return 'legacy_card';
        }

        return 'unknown';
    }

    /**
     * Classify a "No such ..." style Stripe error without over-claiming.
     *
     * An inaccessible object used to be reported as account_mismatch on its own.
     * That conclusion holds only when the account really differs, and several
     * ordinary situations produce the same message: a customer deleted from the
     * dashboard, an intent id from an imported order, a legacy reference that
     * was never a PaymentMethod to begin with.
     *
     * Securehold_Stripe_Context already knows whether the two plugins share a
     * Stripe context, so the verdict is qualified with it. Its cached result is
     * reused — no extra network call is made to classify an error.
     *
     * @since 3.4.4
     *
     * @param string $error_message Raw Stripe message.
     * @return string|null One of the diagnostic codes, or null when the message
     *                     is not about an inaccessible object.
     */
    public static function classify_stripe_access_error( $error_message ) {
        $needles = array(
            'No such PaymentMethod',
            'No such payment_method',
            'No such payment_intent',
            'No such PaymentIntent',
            'No such customer',
            'No such Customer',
            'connected account',
        );

        $matched = false;
        foreach ( $needles as $needle ) {
            if ( stripos( $error_message, $needle ) !== false ) {
                $matched = true;
                break;
            }
        }

        if ( ! $matched ) {
            return null;
        }

        if ( ! class_exists( 'Securehold_Stripe_Context' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe-context.php';
        }

        if ( ! class_exists( 'Securehold_Stripe_Context' ) ) {
            return 'stripe_context_mismatch_suspected';
        }

        $context = Securehold_Stripe_Context::get();
        $status  = isset( $context['status'] ) ? $context['status'] : Securehold_Stripe_Context::STATUS_UNKNOWN;

        switch ( $status ) {
            case Securehold_Stripe_Context::STATUS_ACCOUNT_MISMATCH:
            case Securehold_Stripe_Context::STATUS_CONTEXT_INCOMPATIBLE:
            case Securehold_Stripe_Context::STATUS_MODE_MISMATCH:
                // The diagnosis independently established the mismatch.
                return 'stripe_context_mismatch_confirmed';

            case Securehold_Stripe_Context::STATUS_COMPATIBLE:
                // SecureHold demonstrably reads what WooCommerce wrote, so the
                // account is not the problem: this single object is gone.
                return 'stripe_object_not_accessible';

            default:
                return 'stripe_context_mismatch_suspected';
        }
    }

    /**
     * Classify a Stripe exception from what the SDK actually reports.
     *
     * The SDK carries structured fields — HTTP status, Stripe error code, the
     * offending parameter, the request id — and reading them beats matching on
     * message text. It also removes a real misdiagnosis: RateLimitException
     * extends InvalidRequestException, so a catch on the latter silently absorbs
     * rate limiting and reported it as whatever that branch happened to say.
     *
     * Returns only fields that are safe to log. getError() is deliberately not
     * touched: it can carry whole PaymentIntent and PaymentMethod objects, and
     * getHttpBody() can carry the raw request payload.
     *
     * @since 3.4.4
     *
     * @param Exception $e Exception thrown by the Stripe SDK.
     * @return array {
     *     @type string $code     Diagnostic code.
     *     @type string $severity Log severity.
     *     @type array  $detail   Safe structured context.
     * }
     */
    public static function classify_stripe_exception( $e ) {
        $detail = array();

        if ( method_exists( $e, 'getHttpStatus' ) ) {
            $detail['http_status'] = $e->getHttpStatus();
        }
        if ( method_exists( $e, 'getStripeCode' ) ) {
            $detail['stripe_code'] = $e->getStripeCode();
        }
        if ( method_exists( $e, 'getStripeParam' ) ) {
            $detail['stripe_param'] = $e->getStripeParam();
        }
        if ( method_exists( $e, 'getRequestId' ) ) {
            $detail['request_id'] = $e->getRequestId();
        }

        $message     = $e->getMessage();
        $stripe_code = isset( $detail['stripe_code'] ) ? (string) $detail['stripe_code'] : '';
        $param       = isset( $detail['stripe_param'] ) ? (string) $detail['stripe_param'] : '';

        // Order matters: the narrower subclasses are tested before their parents.
        if ( $e instanceof \Stripe\Exception\AuthenticationException ) {
            return array( 'code' => 'stripe_credentials_rejected', 'severity' => 'error', 'detail' => $detail );
        }

        if ( $e instanceof \Stripe\Exception\PermissionException ) {
            return array( 'code' => 'stripe_permission_denied', 'severity' => 'warning', 'detail' => $detail );
        }

        if ( $e instanceof \Stripe\Exception\ApiConnectionException
            || $e instanceof \Stripe\Exception\RateLimitException ) {
            return array( 'code' => 'stripe_api_unreachable', 'severity' => 'warning', 'detail' => $detail );
        }

        // The object cannot be resolved with these credentials. Qualified against
        // the context diagnosis rather than assumed to be a wrong account.
        if ( $stripe_code === 'resource_missing' || self::classify_stripe_access_error( $message ) !== null ) {
            $access = self::classify_stripe_access_error( $message );

            if ( $access === null ) {
                // resource_missing without a recognisable phrase: still an
                // inaccessible object, still not proof of anything more.
                $access = 'stripe_object_not_accessible';
            }

            return array(
                'code'     => $access,
                'severity' => ( $access === 'stripe_context_mismatch_confirmed' ) ? 'error' : 'warning',
                'detail'   => $detail,
            );
        }

        // Only Stripe saying so earns the wording about the PaymentIntent state.
        if ( $stripe_code === 'payment_intent_unexpected_state' ) {
            return array( 'code' => 'stripe_state_conflict', 'severity' => 'warning', 'detail' => $detail );
        }

        if ( $param === 'setup_future_usage' ) {
            return array( 'code' => 'stripe_sfu_update_refused', 'severity' => 'warning', 'detail' => $detail );
        }

        if ( $param !== '' || $stripe_code === 'payment_intent_invalid_parameter' ) {
            return array( 'code' => 'stripe_parameter_rejected', 'severity' => 'warning', 'detail' => $detail );
        }

        return array( 'code' => 'stripe_unknown_error', 'severity' => 'warning', 'detail' => $detail );
    }

    /**
     * Retrieve a usable PaymentMethod token from an Order.
     *
     * Returns only references that can actually be used for an off-session hold.
     * A legacy src_ or card_ resolves to '' so the caller falls through to the
     * checkout PaymentIntent instead of sending an identifier to an endpoint
     * that cannot resolve it.
     *
     * @since 3.4.4 Delegates to resolve_order_payment_reference().
     *
     * @param WC_Order $order Order to inspect.
     * @return string A pm_ identifier, or ''.
     */
    public static function get_payment_method_token_from_order($order) {
        $reference = self::resolve_order_payment_reference( $order );

        return $reference['usable'] ? $reference['value'] : '';
    }

    /**
     * Resolve Stripe customer_id and payment_method_id for an order.
     *
     * This is the central resolution method used by the scheduler when order meta
     * is incomplete (common for guest checkouts). Resolution chain:
     * 1. Read directly from order meta (fastest, works for logged-in users)
     * 2. Retrieve from PaymentIntent (works for all Stripe orders including guests)
     * 3. Create a Stripe Customer from billing data if needed (guest fallback)
     *
     * @param WC_Order $order
     * @return array ['customer_id' => string, 'payment_method_id' => string] or ['customer_id' => '', 'payment_method_id' => '']
     */
    public static function resolve_stripe_data_for_order($order) {
        $result = array(
            'customer_id' => '',
            'payment_method_id' => '',
            'source' => 'none',
        );

        // ── Step 1: Try order meta directly ──
        $customer_id = $order->get_meta('_stripe_customer_id', true);
        $reference   = self::resolve_order_payment_reference( $order );
        $payment_method_id = $reference['usable'] ? $reference['value'] : '';

        // A stored reference that cannot be used is worth recording: it explains
        // why the PaymentIntent fallback below is doing the work, and it is the
        // signal that distinguishes a legacy order from a credentials problem.
        if ( ! $reference['usable'] && $reference['value'] !== '' && function_exists( 'securehold_log' ) ) {
            securehold_log( 'Stored payment reference is not usable for an off-session hold', array(
                'order_id'    => method_exists( $order, 'get_id' ) ? $order->get_id() : 0,
                'source_meta' => $reference['source_meta'],
                'object_type' => $reference['object_type'],
                'reason'      => $reference['reason'],
            ), 'warning' );
        }

        if (!empty($customer_id) && !empty($payment_method_id)) {
            return array(
                'customer_id' => $customer_id,
                'payment_method_id' => $payment_method_id,
                'source' => 'order_meta',
                'reference' => $reference,
            );
        }

        // ── Step 2: Retrieve from PaymentIntent ──
        $intent_data = self::retrieve_stripe_data_from_intent($order);

        if ($intent_data) {
            $customer_id = !empty($customer_id) ? $customer_id : $intent_data['customer_id'];
            $payment_method_id = !empty($payment_method_id) ? $payment_method_id : $intent_data['payment_method_id'];

            // Store resolved data on the order for future use
            if (!empty($customer_id) && empty($order->get_meta('_stripe_customer_id', true))) {
                $order->update_meta_data('_stripe_customer_id', $customer_id);
            }
            if (!empty($payment_method_id) && empty($order->get_meta('_stripe_payment_method', true))) {
                $order->update_meta_data('_stripe_payment_method', $payment_method_id);
            }
            $order->save();
        }

        // ── Step 3: Create Stripe Customer if still missing (guest fallback) ──
        if (empty($customer_id) && !empty($payment_method_id)) {
            $customer_id = self::create_guest_customer($order);

            if (!empty($customer_id)) {
                // Attach PM to customer for off-session usage
                try {
                    if (!self::init_stripe()) throw new \Exception('Stripe not initialized');
                    $pm = \Stripe\PaymentMethod::retrieve($payment_method_id);
                    $pm->attach(array('customer' => $customer_id));
                } catch (\Exception $e) {
                    if (function_exists('securehold_log')) {
                        securehold_log('Stripe: Could not attach PM to guest customer', array(
                            'error' => $e->getMessage(),
                        ));
                    }
                }

                $order->update_meta_data('_stripe_customer_id', $customer_id);
                $order->save();
            }
        }

        $result['customer_id'] = $customer_id;
        $result['payment_method_id'] = $payment_method_id;
        $result['source'] = !empty($customer_id) && !empty($payment_method_id) ? 'resolved' : 'incomplete';

        return $result;
    }

    /**
     * Retrieve customer_id and payment_method_id from the order's Stripe PaymentIntent.
     *
     * @param WC_Order $order
     * @return array|false ['customer_id' => string, 'payment_method_id' => string]
     */
    public static function retrieve_stripe_data_from_intent($order) {
        if (!self::init_stripe()) return false;

        // Get the PaymentIntent ID
        $intent_id = $order->get_meta('_stripe_intent_id', true);

        if (empty($intent_id)) {
            // Try to get intent ID from charge
            $charge_id = $order->get_meta('_stripe_charge_id', true);
            if (!empty($charge_id)) {
                try {
                    $charge = \Stripe\Charge::retrieve($charge_id);
                    $intent_id = !empty($charge->payment_intent) ? $charge->payment_intent : '';
                } catch (\Exception $e) {
                    $intent_id = '';
                }
            }
        }

        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            return false;
        }

        try {
            $intent = \Stripe\PaymentIntent::retrieve($intent_id, array(
                'expand' => array('payment_method'),
            ));

            $customer_id = '';
            $payment_method_id = '';

            if (!empty($intent->customer)) {
                $customer_id = is_object($intent->customer) ? $intent->customer->id : $intent->customer;
            }

            if (!empty($intent->payment_method)) {
                $payment_method_id = is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method;
            }

            if (!empty($customer_id) || !empty($payment_method_id)) {
                return array(
                    'customer_id' => $customer_id,
                    'payment_method_id' => $payment_method_id,
                );
            }

            return false;

        } catch (\Exception $e) {
            if (function_exists('securehold_log')) {
                securehold_log('Stripe: Failed to retrieve PaymentIntent for resolution', array(
                    'intent_id' => $intent_id,
                    'order_id' => $order->get_id(),
                    'error' => $e->getMessage(),
                ));
            }
            return false;
        }
    }

    /**
     * Create a Stripe Customer from order billing data (for guest orders).
     *
     * @param WC_Order $order
     * @return string Stripe customer ID or empty string
     */
    public static function create_guest_customer($order) {
        if (!self::init_stripe()) return '';

        try {
            $args = array(
                'email' => $order->get_billing_email(),
                'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'metadata' => array(
                    'wc_order_id' => $order->get_id(),
                    'source' => 'securehold_guest_checkout',
                ),
            );

            $phone = $order->get_billing_phone();
            if (!empty($phone)) {
                $args['phone'] = $phone;
            }

            $address = array_filter(array(
                'line1'       => $order->get_billing_address_1(),
                'line2'       => $order->get_billing_address_2(),
                'city'        => $order->get_billing_city(),
                'state'       => $order->get_billing_state(),
                'postal_code' => $order->get_billing_postcode(),
                'country'     => $order->get_billing_country(),
            ));
            if (!empty($address['line1'])) {
                $args['address'] = $address;
            }

            $customer = \Stripe\Customer::create($args);

            if (function_exists('securehold_log')) {
                securehold_log('Stripe: Created guest customer', array(
                    'order_id' => $order->get_id(),
                    'customer_id' => $customer->id,
                    'email' => $order->get_billing_email(),
                ));
            }

            return $customer->id;

        } catch (\Exception $e) {
            if (function_exists('securehold_log')) {
                securehold_log('Stripe: Failed to create guest customer', array(
                    'order_id' => $order->get_id(),
                    'error' => $e->getMessage(),
                ));
            }
            return '';
        }
    }

    /**
     * Cancel (Release) a PaymentIntent on Stripe
     * Used for manual release and auto-release before expiration
     *
     * @param string $intent_id Stripe Intent ID (pi_...)
     * @return \Stripe\PaymentIntent|WP_Error
     */
    public static function cancel_payment_intent($intent_id) {
        if (!self::init_stripe()) {
            return new WP_Error('stripe_config_error', __('Stripe API keys are missing.', 'securehold-security-deposit-holds'));
        }

        try {
            $intent = \Stripe\PaymentIntent::retrieve($intent_id);

            // Only cancel if status allows it
            if ($intent->status === 'canceled') {
                return new WP_Error('already_canceled', __('This hold has already been canceled/released.', 'securehold-security-deposit-holds'));
            }

            if ($intent->status === 'succeeded') {
                return new WP_Error('already_captured', __('This hold has already been captured and cannot be released.', 'securehold-security-deposit-holds'));
            }

            if (!in_array($intent->status, ['requires_capture', 'requires_confirmation', 'requires_action', 'requires_payment_method'])) {
                /* translators: %s is the Stripe PaymentIntent status */
                return new WP_Error('invalid_status', sprintf(__('Cannot cancel intent with status: %s', 'securehold-security-deposit-holds'), $intent->status));
            }

            $canceled_intent = $intent->cancel();

            if (function_exists('securehold_log')) {
                securehold_log('Stripe PaymentIntent canceled', ['intent_id' => $intent_id, 'status' => $canceled_intent->status]);
            }

            return $canceled_intent;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            if (function_exists('securehold_log')) {
                securehold_log('Stripe Cancel Error', ['intent' => $intent_id, 'error' => $e->getMessage()]);
            }
            return new WP_Error('stripe_error', $e->getMessage());
        } catch (Exception $e) {
            return new WP_Error('general_error', $e->getMessage());
        }
    }

    /**
     * Capture a PaymentIntent (Full or Partial)
     * * @param string $intent_id Stripe Intent ID (pi_...)
     * @param int $amount_cents Amount to capture in cents
     * @return \Stripe\PaymentIntent|WP_Error
     */
    public static function capture_payment_intent($intent_id, $amount_cents) {
        if (!self::init_stripe()) {
            return new WP_Error('stripe_config_error', __('Stripe API keys are missing.', 'securehold-security-deposit-holds'));
        }

        try {
            $intent = \Stripe\PaymentIntent::retrieve($intent_id);
            
            // Check status validity
            if ($intent->status === 'canceled') {
                return new WP_Error('canceled', __('This hold has been canceled/released.', 'securehold-security-deposit-holds'));
            }
            // Note: 'succeeded' usually means fully captured, but could be partial if manually handled previously.
            // Stripe API handles the amount check logic mostly, but we catch errors.

            $capture_args = [
                'amount_to_capture' => $amount_cents
            ];

            // Perform capture
            $captured_intent = $intent->capture($capture_args);

            return $captured_intent;

        } catch (\Stripe\Exception\ApiErrorException $e) {
            securehold_log('Stripe Capture Error', ['intent' => $intent_id, 'error' => $e->getMessage()]);
            return new WP_Error('stripe_error', $e->getMessage());
        } catch (Exception $e) {
            return new WP_Error('general_error', $e->getMessage());
        }
    }
}