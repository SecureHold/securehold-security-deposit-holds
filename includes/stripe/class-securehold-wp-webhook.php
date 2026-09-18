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
            // A refused signature is a security event, not routine chatter. It
            // stays below 'error' because a probe, a bot or a malformed request
            // against a public endpoint is not a product failure.
            securehold_log('Webhook signature verification failed', array('error' => $e->getMessage()), 'warning');
            return new WP_Error('webhook_error', $e->getMessage(), array('status' => 400));
        }

        $this->process_event($event);

        return rest_ensure_response(array('received' => true));
    }

    private function process_event($event) {
        $intent = $event->data->object;

        switch ($event->type) {
            case 'payment_intent.amount_capturable_updated':
                // The moment the funds actually became capturable, which is the
                // authorization itself. The intent's own created is when it was
                // opened — the same second for a hold confirmed in one call, but
                // not when confirmation comes later, after a 3DS challenge or a
                // scheduled strategy.
                $this->handle_authorization($intent, isset($event->created) ? (int) $event->created : null);
                break;

            case 'payment_intent.succeeded':
                // The event's own moment, not the intent's. A deposit's intent
                // was created when the hold was authorized, days before the
                // capture this event announces.
                $this->handle_capture($intent, isset($event->created) ? (int) $event->created : null);
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

    private function handle_authorization($intent, $event_created = null) {
        $hold = $this->find_securehold_hold($intent->id);
        if (!$hold) {
            // Not a SecureHold PI — ignore silently (likely a checkout PI)
            return;
        }

        $r = Securehold_Hold_State::transition( $hold, 'authorized', array(
            'occurred_at' => $event_created,
            'source'      => 'webhook',
        ) );

        if ( ! $r['applied'] ) {
            return;
        }

    }

    private function handle_capture($intent, $event_created = null) {
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

        // The delta guard below already kept the note and the emails from
        // repeating; what it never protected was captured_at, which a webhook
        // arriving after an admin capture would rewrite. The state helper keeps
        // a terminal timestamp once written.
        $r = Securehold_Hold_State::transition( $hold, 'captured', array(
            'captured_amount' => $new_captured_total,
            'occurred_at'     => $event_created,
            'source'          => 'webhook',
        ) );

        if ( ! $r['applied'] && $delta_captured <= 0 ) {
            return;
        }

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

        // The admin release usually gets here first; the webhook then arrives a
        // second later. It used to rewrite released_at, add a second note and
        // send the customer a second email. Now it recognises the state is
        // already what it came to announce, and stops.
        $r = Securehold_Hold_State::transition( $hold, 'released', array(
            'occurred_at' => isset( $intent->canceled_at ) ? (int) $intent->canceled_at : null,
            'source'      => 'webhook',
        ) );

        if ( ! $r['applied'] ) {
            return;
        }


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

        $r = Securehold_Hold_State::transition( $hold, 'failed', array(
            'source' => 'webhook',
        ) );

        if ( ! $r['applied'] ) {
            return;
        }

        securehold_log('Hold failed via webhook', array(
            'intent_id'     => $intent->id,
            'order_id'      => $hold->order_id,
            'error_message' => $error_message,
            'error_code'    => $error_code,
            'error_type'    => $error_type,
        ));
    }
}
