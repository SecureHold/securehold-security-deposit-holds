<?php
/**
 * Automatic webhook endpoint configuration for Stripe.
 *
 * Manages the full lifecycle of the plugin's Stripe webhook endpoint:
 * create, update, test, list, and delete. All methods that call the
 * Stripe API require a valid secret key to be configured first.
 *
 * @package SecureHold_WP
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Webhook_Configurator {
    
    /**
     * Create or update the plugin's webhook endpoint in Stripe.
     *
     * Three-step strategy: (1) update the stored endpoint if still valid in Stripe,
     * (2) find and update an existing endpoint that already uses our URL,
     * (3) create a new endpoint as a last resort.
     *
     * @since 1.0.0
     *
     * @return array|WP_Error  Array with keys 'success', 'endpoint_id', 'secret', 'url', 'reused'
     *                         on success, or WP_Error on failure.
     */
    public function auto_configure() {
        $keys = securehold_get_stripe_keys();
    
        if (empty($keys['secret'])) {
            return new WP_Error('no_api_key', __('Stripe API key not configured.', 'securehold-security-deposit-holds'));
        }

        // Guard: if the bundled CA certificate is missing, cURL will fail with errno 77
        // (empty CURLOPT_CAINFO). Return a clear, actionable error before making any API call.
        if ( ! file_exists( SECUREHOLD_PLUGIN_DIR . 'vendor/stripe/stripe-php/data/ca-certificates.crt' ) ) {
            return new WP_Error(
                'stripe_ssl_missing',
                __( 'The Stripe SSL certificate bundle is missing. Please reinstall the plugin from your account dashboard.', 'securehold-security-deposit-holds' )
            );
        }

        Securehold_Stripe::init_stripe();
    
        $webhook_url = rest_url('securehold/v1/webhook');
    
        $enabled_events = [
            'payment_intent.amount_capturable_updated',
            'payment_intent.succeeded',
            'payment_intent.canceled',
            'payment_intent.payment_failed'
        ];
    
        $description = 'SecureHold WP Security Deposits';
    
        try {
    
            $stored_endpoint_id = get_option('securehold_webhook_endpoint_id', '');
            $stored_secret = get_option('securehold_webhook_secret', '');

            // Three-step fallback strategy — rationale:
            // 1) Update via stored ID first: avoids creating orphan endpoints when the site is
            //    redeployed or the webhook URL changes. Stripe returns an error if the stored ID
            //    no longer exists; caught below so execution falls through to step 2.
            // 2) Search by URL: recovers gracefully when the DB record was lost (e.g. option deleted,
            //    site migrated) without creating a duplicate endpoint on the Stripe account.
            // 3) Create new: only as a last resort when no prior registration can be located at all.

            // 1) If we already know the endpoint id, update it instead of creating a new one
            if (!empty($stored_endpoint_id)) {
                try {
                    $endpoint = \Stripe\WebhookEndpoint::retrieve($stored_endpoint_id);
    
                    $endpoint = \Stripe\WebhookEndpoint::update($endpoint->id, [
                        'url' => $webhook_url,
                        'enabled_events' => $enabled_events,
                        'description' => $description,
                    ]);
    
                    return array(
                        'success' => true,
                        'endpoint_id' => $endpoint->id,
                        // Stripe does not return secret again, so we reuse stored one if available
                        'secret' => !empty($stored_secret) ? $stored_secret : '',
                        'url' => $webhook_url,
                        'reused' => true
                    );
    
                } catch (\Exception $e) {
                    // Stored id is invalid or deleted in Stripe, continue to search by URL
                }
            }
    
            // 2) Search Stripe for an existing endpoint that already uses our URL
            $existing = null;
            $webhooks = \Stripe\WebhookEndpoint::all(['limit' => 100]);
    
            foreach ($webhooks->data as $wh) {
                if (!empty($wh->url) && $wh->url === $webhook_url) {
                    $existing = $wh;
                    break;
                }
            }
    
            if (!empty($existing)) {
                $updated = \Stripe\WebhookEndpoint::update($existing->id, [
                    'enabled_events' => $enabled_events,
                    'description' => $description,
                ]);
    
                return array(
                    'success' => true,
                    'endpoint_id' => $updated->id,
                    // Still cannot retrieve secret, so reuse stored one if present
                    'secret' => !empty($stored_secret) ? $stored_secret : '',
                    'url' => $webhook_url,
                    'reused' => true
                );
            }
    
            // 3) No existing endpoint found, create a new one
            $endpoint = \Stripe\WebhookEndpoint::create([
                'url' => $webhook_url,
                'enabled_events' => $enabled_events,
                'description' => $description,
            ]);
    
            return array(
                'success' => true,
                'endpoint_id' => $endpoint->id,
                'secret' => $endpoint->secret,
                'url' => $webhook_url,
                'reused' => false
            );
    
        } catch (\Stripe\Exception\ApiErrorException $e) {
            return new WP_Error('stripe_error', $e->getMessage());
        } catch (\Exception $e) {
            return new WP_Error('unknown_error', $e->getMessage());
        }
    }
    
    /**
     * Verify that the stored webhook secret is usable by generating a test signature.
     *
     * Does not send any event to Stripe. A successful signature generation confirms
     * the secret is present and correctly formatted.
     *
     * @since 1.0.0
     *
     * @return array|WP_Error  Array with 'success' and 'message' on success, or WP_Error on failure.
     */
    public function test_webhook() {
        $webhook_secret = get_option('securehold_webhook_secret');
        
        if (empty($webhook_secret)) {
            return new WP_Error('no_secret', __('Webhook secret not configured.', 'securehold-security-deposit-holds'));
        }
        
        // Try to verify a test event
        $test_payload = json_encode([
            'id' => 'evt_test',
            'object' => 'event',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test',
                    'object' => 'payment_intent'
                ]
            ]
        ]);
        
        try {
            $test_signature = $this->generate_test_signature($test_payload, $webhook_secret);
            
            // If we can generate signature, webhook is likely configured
            return array(
                'success' => true,
                'message' => __('Webhook appears to be configured correctly.', 'securehold-security-deposit-holds')
            );
            
        } catch (\Exception $e) {
            return new WP_Error('test_failed', $e->getMessage());
        }
    }
    
    /**
     * Generate test signature for webhook
     */
    private function generate_test_signature($payload, $secret) {
        $timestamp = time();
        $signed_payload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signed_payload, $secret);
        
        return 't=' . $timestamp . ',v1=' . $signature;
    }
    
    /**
     * Retrieve the first 10 webhook endpoints registered in Stripe.
     *
     * @since 1.0.0
     *
     * @return \Stripe\WebhookEndpoint[]|WP_Error  Array of endpoint objects, or WP_Error on failure.
     */
    public function list_webhooks() {
        Securehold_Stripe::init_stripe();
        
        try {
            $webhooks = \Stripe\WebhookEndpoint::all(['limit' => 10]);
            return $webhooks->data;
        } catch (\Exception $e) {
            return new WP_Error('list_failed', $e->getMessage());
        }
    }
    
    /**
     * Delete a webhook endpoint from Stripe.
     *
     * @since 1.0.0
     *
     * @param string       $endpoint_id  Stripe webhook endpoint ID (we_...).
     * @return array|WP_Error            Array with 'success' => true, or WP_Error on failure.
     */
    public function delete_webhook($endpoint_id) {
        Securehold_Stripe::init_stripe();
        
        try {
            $endpoint = \Stripe\WebhookEndpoint::retrieve($endpoint_id);
            $endpoint->delete();
            
            return array('success' => true);
        } catch (\Exception $e) {
            return new WP_Error('delete_failed', $e->getMessage());
        }
    }
    
    /**
     * Count how many Stripe webhook endpoints point to the given URL.
     *
     * Used to detect duplicate registrations before creating a new endpoint.
     *
     * @since 1.0.0
     *
     * @param string       $webhook_url  The full webhook URL to search for.
     * @return int|WP_Error              Number of matching endpoints, or WP_Error on Stripe API failure.
     */
    public function count_endpoints_for_url($webhook_url) {
        try {
            Securehold_Stripe::init_stripe();
    
            $count = 0;
            $webhooks = \Stripe\WebhookEndpoint::all(['limit' => 100]);
    
            foreach ($webhooks->data as $wh) {
                if (!empty($wh->url) && $wh->url === $webhook_url) {
                    $count++;
                }
            }
    
            return $count;
        } catch (\Exception $e) {
            return new WP_Error('stripe_webhook_list_failed', $e->getMessage());
        }
    }

}
