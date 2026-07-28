<?php
/**
 * Order-level Stripe data collection and hold trigger hooks.
 *
 * Fires on WooCommerce order events to extract and persist the Stripe customer ID
 * and payment method ID from the checkout PaymentIntent. Also handles hold creation
 * for the immediate and status-based timing strategies.
 *
 * @package SecureHold_WP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Order {
    
    /**
     * Retrieve the Stripe customer ID from the checkout PaymentIntent and store it on the order.
     *
     * Runs after checkout. Skips non-Stripe gateways and orders where the customer ID
     * was already saved to avoid redundant API calls.
     *
     * @since 1.0.0
     *
     * @param int $order_id  WooCommerce order ID.
     * @return void
     */
    public function save_stripe_customer_id($order_id) {
        securehold_log('save_stripe_customer_id() called', ['order_id' => $order_id]);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            securehold_log('Order not found', ['order_id' => $order_id]);
            return;
        }
        
        $payment_method = $order->get_payment_method();
        securehold_log('Payment method detected', ['order_id' => $order_id, 'method' => $payment_method]);
        
        // Support stripe, stripe_card, and other Stripe variants
        if (strpos($payment_method, 'stripe') === false) {
            securehold_log('Not a Stripe payment', ['order_id' => $order_id, 'method' => $payment_method]);
            return;
        }
        
        $existing_customer_id = $order->get_meta('_stripe_customer_id');
        if (!empty($existing_customer_id)) {
            securehold_log('Customer ID already exists', ['order_id' => $order_id, 'customer_id' => $existing_customer_id]);
            return;
        }
        
        if (!class_exists('\Stripe\Stripe')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'vendor/autoload.php';
        }
        
        $keys = securehold_get_woocommerce_stripe_keys();
        
        if (empty($keys['secret'])) {
            securehold_log('Stripe secret key not found in WooCommerce settings', ['order_id' => $order_id]);
            return;
        }
        
        \Stripe\Stripe::setApiKey($keys['secret']);
        
        $payment_intent_id = $order->get_meta('_stripe_intent_id');
        if (empty($payment_intent_id)) {
            securehold_log('Payment Intent ID not found in order meta', ['order_id' => $order_id]);
            return;
        }
        
        securehold_log('Retrieving PaymentIntent from Stripe', ['order_id' => $order_id, 'intent_id' => $payment_intent_id]);
        
        try {
            $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            $customer_id = $intent->customer;
            
            if ($customer_id) {
                $order->update_meta_data('_stripe_customer_id', $customer_id);
                $order->save();
                
                $user_id = $order->get_user_id();
                if ($user_id) {
                    update_user_meta($user_id, '_stripe_customer_id', $customer_id);
                }
                
                securehold_log('Customer ID saved successfully', [
                    'order_id' => $order_id,
                    'customer_id' => $customer_id,
                    'intent_id' => $payment_intent_id
                ]);
            } else {
                securehold_log('PaymentIntent has no customer_id', [
                    'order_id' => $order_id,
                    'intent_id' => $payment_intent_id
                ]);
            }
        } catch (\Exception $e) {
            securehold_log('Error retrieving customer_id from Stripe', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Retrieve the payment method ID from the checkout PaymentIntent and store it on the order.
     *
     * Required so SecureHold can reuse the same payment method for off-session hold creation.
     * Skips non-Stripe gateways and orders where the ID was already saved.
     *
     * @since 1.0.0
     *
     * @param int $order_id  WooCommerce order ID.
     * @return void
     */
    public function save_payment_method_id($order_id) {
        securehold_log('save_payment_method_id() called', ['order_id' => $order_id]);
        
        $order = wc_get_order($order_id);
        if (!$order) {
            securehold_log('Order not found', ['order_id' => $order_id]);
            return;
        }
        
        $payment_method = $order->get_payment_method();
        
        // Support stripe, stripe_card, and other Stripe variants
        if (strpos($payment_method, 'stripe') === false) {
            securehold_log('Not a Stripe payment', ['order_id' => $order_id, 'method' => $payment_method]);
            return;
        }
        
        $existing_pm = $order->get_meta('_stripe_payment_method_id');
        if (!empty($existing_pm)) {
            securehold_log('Payment method ID already exists', ['order_id' => $order_id, 'payment_method' => $existing_pm]);
            return;
        }
        
        if (!class_exists('\Stripe\Stripe')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'vendor/autoload.php';
        }
        
        $keys = securehold_get_woocommerce_stripe_keys();
        
        if (empty($keys['secret'])) {
            securehold_log('Stripe secret key not found in WooCommerce settings', ['order_id' => $order_id]);
            return;
        }
        
        \Stripe\Stripe::setApiKey($keys['secret']);
        
        $payment_intent_id = $order->get_meta('_stripe_intent_id');
        if (empty($payment_intent_id)) {
            securehold_log('Payment Intent ID not found in order meta', ['order_id' => $order_id]);
            return;
        }
        
        securehold_log('Retrieving PaymentIntent from Stripe', ['order_id' => $order_id, 'intent_id' => $payment_intent_id]);
        
        try {
            $intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
            $payment_method_id = $intent->payment_method;
            
            if ($payment_method_id) {
                $order->update_meta_data('_stripe_payment_method_id', $payment_method_id);
                $order->update_meta_data('_stripe_payment_method', $payment_method_id);
                $order->save();
                
                securehold_log('Payment method ID saved successfully', [
                    'order_id' => $order_id,
                    'payment_method' => $payment_method_id,
                    'intent_id' => $payment_intent_id
                ]);
            } else {
                securehold_log('PaymentIntent has no payment_method', [
                    'order_id' => $order_id,
                    'intent_id' => $payment_intent_id
                ]);
            }
        } catch (\Exception $e) {
            securehold_log('Error retrieving payment_method from Stripe', [
                'order_id' => $order_id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Trigger hold creation immediately after a successful Stripe payment.
     *
     * Only fires when the capture timing strategy is 'immediate'. Skips orders
     * where a hold has already been created (empreinte_effectuee === 'oui').
     *
     * @since 1.0.0
     *
     * @param int $order_id  WooCommerce order ID.
     * @return void
     */
    public function create_immediate_hold($order_id) {
        securehold_log('create_immediate_hold() CALLED', ['order_id' => $order_id]);
        
        $timing_strategy = get_option('securehold_capture_timing', 'immediate');
        securehold_log('Timing strategy check', ['strategy' => $timing_strategy]);
        
        if ($timing_strategy !== 'immediate') {
            securehold_log('EXIT: Strategy is not immediate', ['strategy' => $timing_strategy]);
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            securehold_log('EXIT: Order not found', ['order_id' => $order_id]);
            return;
        }
        
        $empreinte = $order->get_meta('empreinte_effectuee');
        securehold_log('Empreinte check', ['empreinte_effectuee' => $empreinte]);
        
        if ($empreinte === 'oui') {
            securehold_log('EXIT: Hold already created', ['order_id' => $order_id]);
            return;
        }
        
        $payment_method = $order->get_payment_method();
        securehold_log('Payment method check', ['method' => $payment_method]);
        
        // Support stripe, stripe_card, and other Stripe variants
        if (strpos($payment_method, 'stripe') === false) {
            securehold_log('EXIT: Not a Stripe payment', ['method' => $payment_method]);
            return;
        }
        
        securehold_log('All conditions passed — creating hold', ['order_id' => $order_id]);
        
        $scheduler = new Securehold_Scheduler();
        $scheduler->create_hold_for_order($order_id);
        
        securehold_log('create_hold_for_order() completed', ['order_id' => $order_id]);
    }

    /**
     * Trigger hold creation when an order reaches the configured trigger status.
     *
     * Only fires when the capture timing strategy is 'status'. Delegates to
     * Securehold_Scheduler::create_hold_for_order() once all conditions are met.
     *
     * @since 1.0.0
     *
     * @param int      $order_id   WooCommerce order ID.
     * @param string   $old_status Previous order status (without wc- prefix).
     * @param string   $new_status New order status (without wc- prefix).
     * @param WC_Order $order      WooCommerce order object.
     * @return void
     */
    public function handle_status_change($order_id, $old_status, $new_status, $order) {
        $timing_strategy = get_option('securehold_capture_timing', 'immediate');
        if ($timing_strategy !== 'status') return;

        $target_status = get_option('securehold_trigger_status', 'processing');
        if ($new_status !== $target_status) return;

        $empreinte_done = $order->get_meta('empreinte_effectuee');
        if ($empreinte_done === 'oui') return;

        $scheduler = new Securehold_Scheduler();
        $scheduler->create_hold_for_order($order_id);
        
        securehold_log('Status trigger fired', ['order_id' => $order_id, 'status' => $new_status]);
    }
}