<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Webhook {

    public function register_routes() {
        register_rest_route('securehold/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            // '__return_true' is correct here: Stripe is an external service and cannot
            // authenticate via the WordPress user system. Request legitimacy is enforced
            // entirely by Stripe signature verification in handle_webhook() (constructEvent()).
            'permission_callback' => '__return_true'
        ));
    }

    public function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        $webhook_secret = get_option('securehold_webhook_secret');

        Securehold_Stripe::init_stripe();

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\Exception $e) {
            securehold_log('Webhook signature verification failed', array('error' => $e->getMessage()));
            return new WP_Error('webhook_error', $e->getMessage(), array('status' => 400));
        }

        $this->process_event($event);

        return rest_ensure_response(array('received' => true));
    }

    private function process_event($event) {
        $intent = $event->data->object;

        switch ($event->type) {
            case 'payment_intent.amount_capturable_updated':
                $this->handle_authorization($intent);
                break;

            case 'payment_intent.succeeded':
                $this->handle_capture($intent);
                break;

            case 'payment_intent.canceled':
                $this->handle_cancellation($intent);
                break;

            case 'payment_intent.payment_failed':
                $this->handle_failure($intent);
                break;
        }
    }

    /**
     * Check if a PaymentIntent belongs to SecureHold (exists in holds table).
     * Returns the hold row if found, null otherwise.
     *
     * This guard prevents processing checkout PaymentIntents (WooCommerce Stripe Gateway)
     * which are NOT SecureHold holds.
     *
     * @param string $intent_id Stripe PaymentIntent ID
     * @return object|null Hold row or null
     */
    private function find_securehold_hold($intent_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'securehold_holds';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE intent_id = %s", $intent_id));
    }

    private function handle_authorization($intent) {
        $hold = $this->find_securehold_hold($intent->id);
        if (!$hold) {
            // Not a SecureHold PI — ignore silently (likely a checkout PI)
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'securehold_holds';

        $wpdb->update(
            $table,
            array(
                'status' => 'authorized',
                'authorized_at' => current_time('mysql'),
                'expires_at' => gmdate('Y-m-d H:i:s', strtotime('+7 days'))
            ),
            array('intent_id' => $intent->id)
        );

        securehold_log('Hold authorized via webhook', array('intent_id' => $intent->id, 'order_id' => $hold->order_id));
    }

    private function handle_capture($intent) {
        $hold = $this->find_securehold_hold($intent->id);
        if (!$hold) {
            // Not a SecureHold PI — ignore silently (likely a checkout PI)
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'securehold_holds';

        $new_captured_total = 0;
        if (isset($intent->amount_received)) {
            $new_captured_total = $intent->amount_received / 100;
        }

        $previous_captured = floatval($hold->captured_amount);
        $delta_captured = $new_captured_total - $previous_captured;

        $wpdb->update(
            $table,
            array(
                'status' => 'captured',
                'captured_amount' => $new_captured_total,
                'captured_at' => current_time('mysql')
            ),
            array('intent_id' => $intent->id),
            array('%s', '%f', '%s'),
            array('%s')
        );

        if ($delta_captured > 0) {
            $order = wc_get_order($hold->order_id);
            if ($order) {
                $reference_id = !empty($intent->latest_charge) ? $intent->latest_charge : $intent->id;
                $formatted_amount = wc_price($delta_captured, array('currency' => $hold->currency));

                $note = sprintf(
                    /* translators: %1$s is the formatted currency amount, %2$s is the Stripe reference ID */
                    __('SecureHold WP: Security deposit captured: %1$s. Reference: %2$s', 'securehold-security-deposit-holds'),
                    $formatted_amount,
                    $reference_id
                );

                $order->add_order_note($note);
            }

            $captured_payload = (object) array(
                'amount'          => floatval( $hold->amount ),
                'captured_amount' => $new_captured_total,
                'currency'        => $hold->currency,
            );

            // Fire captured email notifications via centralized manager.
            if ( class_exists( 'Securehold_Email_Manager' ) ) {
                Securehold_Email_Manager::fire_email( 'securehold_deposit_captured', $hold->order_id, $captured_payload );
                Securehold_Email_Manager::fire_email( 'securehold_admin_hold_captured', $hold->order_id, $captured_payload );
            }
        }

        securehold_log('Hold captured via webhook', array(
            'intent_id'      => $intent->id,
            'order_id'       => $hold->order_id,
            'total_captured' => $new_captured_total,
            'delta_captured' => $delta_captured,
        ));
    }

    private function handle_cancellation($intent) {
        $hold = $this->find_securehold_hold($intent->id);
        if (!$hold) {
            // Not a SecureHold PI — ignore silently
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'securehold_holds';

        $wpdb->update(
            $table,
            array(
                'status' => 'released',
                'released_at' => current_time('mysql')
            ),
            array('intent_id' => $intent->id)
        );

        securehold_log('Hold released via webhook', array('intent_id' => $intent->id, 'order_id' => $hold->order_id));

        $order = wc_get_order( $hold->order_id );
        if ( $order ) {
            $order->add_order_note( __( 'SecureHold WP: Security deposit released (cancelled) on Stripe.', 'securehold-security-deposit-holds' ) );
        }

        $release_payload = (object) array(
            'amount'   => floatval( $hold->amount ),
            'currency' => $hold->currency,
        );

        // Notify customer of the release.
        if ( class_exists( 'Securehold_Email_Manager' ) ) {
            Securehold_Email_Manager::fire_email( 'securehold_deposit_released', $hold->order_id, $release_payload );
            Securehold_Email_Manager::fire_email( 'securehold_admin_hold_released', $hold->order_id, $release_payload );
        }
    }

    private function handle_failure($intent) {
        $hold = $this->find_securehold_hold($intent->id);
        if (!$hold) {
            // Not a SecureHold PI — ignore silently
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'securehold_holds';

        // Extract actual Stripe error details (never invent)
        $error_message = '';
        $error_code = '';
        $error_type = '';
        if (isset($intent->last_payment_error)) {
            $lpe = $intent->last_payment_error;
            $error_message = isset($lpe->message) ? $lpe->message : '';
            $error_code    = isset($lpe->code) ? $lpe->code : '';
            $error_type    = isset($lpe->type) ? $lpe->type : '';
        }

        // Build notes from real Stripe data only
        $notes = '';
        if (!empty($error_message)) {
            $notes = sprintf('Stripe error: %s', $error_message);
            if (!empty($error_code)) {
                $notes .= sprintf(' [code: %s]', $error_code);
            }
            if (!empty($error_type)) {
                $notes .= sprintf(' [type: %s]', $error_type);
            }
        }

        $update_data = array('status' => 'failed');
        if (!empty($notes)) {
            $update_data['notes'] = sanitize_textarea_field($notes);
        }

        $wpdb->update(
            $table,
            $update_data,
            array('intent_id' => $intent->id)
        );

        securehold_log('Hold failed via webhook', array(
            'intent_id'     => $intent->id,
            'order_id'      => $hold->order_id,
            'error_message' => $error_message,
            'error_code'    => $error_code,
            'error_type'    => $error_type,
        ));
    }
}
