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

                // Stripe returns a signing secret only when an endpoint is
                // created — never on retrieve, list or update. An endpoint found
                // here can be re-pointed at the right events, but its secret
                // cannot be read back.
                //
                // Reaching this branch means the stored endpoint id did not
                // resolve, which is exactly what happens once the credentials
                // move to another Stripe account, environment or sandbox. Any
                // secret still stored locally belongs to the previous context
                // and will fail every signature check. Returning it alongside
                // success produced a webhook that looked configured and verified
                // nothing.
                $matches_stored_endpoint = ( ! empty($stored_endpoint_id) && $stored_endpoint_id === $updated->id );

                if ( ! empty($stored_secret) && $matches_stored_endpoint ) {
                    return array(
                        'success'     => true,
                        'endpoint_id' => $updated->id,
                        'secret'      => $stored_secret,
                        'url'         => $webhook_url,
                        'reused'      => true,
                    );
                }

                return array(
                    'success'         => false,
                    'endpoint_id'     => $updated->id,
                    'secret'          => '',
                    'url'             => $webhook_url,
                    'reused'          => true,
                    'repair_required' => true,
                    'reason'          => 'signing_secret_unavailable',
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


    // =========================================================================
    // Read-only diagnosis
    // =========================================================================

    /** Endpoint resolves, URL matches, and a signing secret is held. */
    const STATUS_CONFIGURED = 'configured';

    /** Nothing registered yet. */
    const STATUS_NOT_CONFIGURED = 'not_configured';

    /** A secret is held but no endpoint id, or the reverse. */
    const STATUS_INCOMPLETE = 'incomplete';

    /** Endpoint resolves but no signing secret is held locally. */
    const STATUS_SECRET_MISSING = 'secret_missing';

    /** The stored endpoint does not exist under the current credentials. */
    const STATUS_ENDPOINT_INACCESSIBLE = 'endpoint_inaccessible';

    /** Reconfiguration is needed and possible. */
    const STATUS_REPAIR_REQUIRED = 'repair_required';

    /** The check could not complete. Never reported as a fault. */
    const STATUS_UNKNOWN = 'unknown';

    /** Transient holding the last webhook diagnosis. */
    const STATUS_CACHE_KEY = 'securehold_webhook_status';

    /**
     * Cache lifetime, in seconds.
     *
     * The Health Check runs on every page load, and diagnose() costs a Stripe
     * round-trip. Ten minutes keeps the admin responsive while still reflecting
     * a change within one working session — and a credential change invalidates
     * the entry immediately regardless, so a rotation is never masked by a stale
     * verdict.
     */
    const STATUS_CACHE_TTL = 600;

    /**
     * Cached webhook diagnosis.
     *
     * @since 3.4.4
     *
     * @param bool $force_refresh Bypass and rewrite the cache.
     * @return array See diagnose() for the shape.
     */
    public static function get_status( $force_refresh = false ) {
        $cached = get_transient( self::STATUS_CACHE_KEY );

        if (
            ! $force_refresh
            && is_array( $cached )
            && isset( $cached['fingerprint'] )
            && hash_equals( (string) $cached['fingerprint'], self::status_fingerprint() )
        ) {
            $cached['cached'] = true;
            return $cached;
        }

        // Late static binding, so a subclass that overrides the Stripe seams can
        // be driven through this façade as well.
        $configurator = new static();
        $status       = $configurator->diagnose();

        $status['fingerprint'] = self::status_fingerprint();
        $status['cached']      = false;

        set_transient( self::STATUS_CACHE_KEY, $status, self::STATUS_CACHE_TTL );

        return $status;
    }

    /**
     * Discard the cached diagnosis.
     *
     * @since 3.4.4
     * @return void
     */
    public static function flush_status() {
        delete_transient( self::STATUS_CACHE_KEY );
    }

    /**
     * Fingerprint of the configuration the cached verdict was computed from.
     *
     * Covers the Stripe secret key, the mode, and both webhook options, so
     * rotating keys, switching test/live or re-registering the endpoint all
     * invalidate the entry on the next read.
     *
     * A one-way digest, used only to notice change: the secrets themselves are
     * never written to the cache, never displayed and never logged. diagnose()
     * returns booleans and a shortened endpoint id, so the cached payload holds
     * no credential either.
     *
     * @since 3.4.4
     * @return string
     */
    protected static function status_fingerprint() {
        $keys = function_exists( 'securehold_get_stripe_keys' ) ? securehold_get_stripe_keys() : array();

        return md5( implode( '|', array(
            get_option( 'securehold_stripe_mode', 'test' ),
            isset( $keys['secret'] ) ? $keys['secret'] : '',
            (string) get_option( 'securehold_webhook_endpoint_id', '' ),
            (string) get_option( 'securehold_webhook_secret', '' ),
        ) ) );
    }

    /**
     * Report the webhook state without changing anything.
     *
     * The Health Check used to answer "is a whsec_ stored?", which says nothing
     * about whether that secret still belongs to the Stripe context in use. A
     * secret kept from a previous account verifies no signature at all, while
     * the screen showed green.
     *
     * Strictly read-only: it retrieves and lists, and never creates, updates or
     * deletes. Writing belongs to the configurator and the wizard, not to a page
     * the merchant may refresh at will.
     *
     * @since 3.4.4
     *
     * @return array status, url, endpoint_id (masked), endpoint_accessible,
     *               url_matches, secret_configured, repair_required, reason.
     */
    public function diagnose() {
        $url               = function_exists('securehold_get_webhook_url') ? securehold_get_webhook_url() : rest_url('securehold/v1/webhook');
        $stored_endpoint   = (string) get_option('securehold_webhook_endpoint_id', '');
        $secret_configured = (bool) get_option('securehold_webhook_secret', '');

        $result = array(
            'status'              => self::STATUS_UNKNOWN,
            'url'                 => $url,
            'endpoint_id'         => $stored_endpoint !== '' ? $this->mask($stored_endpoint) : null,
            'endpoint_accessible' => null,
            'url_matches'         => null,
            'secret_configured'   => $secret_configured,
            'repair_required'     => false,
            'reason'              => null,
        );

        if ($stored_endpoint === '') {
            // A merchant can paste a secret for an endpoint they created by hand,
            // so a secret without an endpoint id is incomplete, not broken.
            $result['status'] = $secret_configured ? self::STATUS_INCOMPLETE : self::STATUS_NOT_CONFIGURED;
            $result['reason'] = $secret_configured ? 'endpoint_id_unknown' : 'nothing_registered';
            return $result;
        }

        $endpoint = $this->fetch_endpoint($stored_endpoint);

        if (is_wp_error($endpoint)) {
            $code = $endpoint->get_error_code();

            if ($code !== 'not_found') {
                // Authentication, permission or network: state unknown, and no
                // repair should be suggested on the strength of a failed check.
                $result['reason'] = $code;
                return $result;
            }

            // The stored endpoint does not exist under these credentials — the
            // signature of a credentials change.
            $result['endpoint_accessible'] = false;
            $result['repair_required']     = true;
            $result['status']              = self::STATUS_ENDPOINT_INACCESSIBLE;
            $result['reason']              = $this->find_endpoint_by_url($url)
                ? 'endpoint_exists_for_url_secret_unavailable'
                : 'endpoint_missing_in_current_context';

            return $result;
        }

        $result['endpoint_accessible'] = true;
        $result['url_matches']         = ( isset($endpoint['url']) && $endpoint['url'] === $url );

        if ( ! $result['url_matches'] ) {
            $result['status']          = self::STATUS_REPAIR_REQUIRED;
            $result['repair_required'] = true;
            $result['reason']          = 'endpoint_url_mismatch';
            return $result;
        }

        if ( ! $secret_configured ) {
            // The endpoint is right, but Stripe only ever hands out the signing
            // secret at creation, so it cannot be recovered — it must be pasted
            // or a new endpoint created.
            $result['status']          = self::STATUS_SECRET_MISSING;
            $result['repair_required'] = true;
            $result['reason']          = 'signing_secret_unavailable';
            return $result;
        }

        $result['status'] = self::STATUS_CONFIGURED;

        return $result;
    }

    /**
     * Shorten an identifier for display.
     *
     * @since 3.4.4
     *
     * @param string $id Identifier.
     * @return string
     */
    protected function mask($id) {
        return class_exists('Securehold_Stripe_Context')
            ? Securehold_Stripe_Context::mask_id($id)
            : substr((string) $id, 0, 6) . '...';
    }

    /**
     * Retrieve one webhook endpoint. Seam for tests.
     *
     * @since 3.4.4
     *
     * @param string $endpoint_id Stripe endpoint id.
     * @return array|WP_Error array( 'id', 'url' ), or error coded not_found|auth|permission|api.
     */
    protected function fetch_endpoint($endpoint_id) {
        if ( ! class_exists('Securehold_Stripe') || ! Securehold_Stripe::init_stripe() ) {
            return new WP_Error('api', 'Stripe SDK unavailable');
        }

        try {
            $endpoint = \Stripe\WebhookEndpoint::retrieve($endpoint_id);
            return array( 'id' => $endpoint->id, 'url' => $endpoint->url );

        } catch (\Stripe\Exception\AuthenticationException $e) {
            return new WP_Error('auth', $e->getMessage());

        } catch (\Stripe\Exception\PermissionException $e) {
            return new WP_Error('permission', $e->getMessage());

        } catch (\Exception $e) {
            $stripe_code = method_exists($e, 'getStripeCode') ? (string) $e->getStripeCode() : '';

            if ($stripe_code === 'resource_missing' || stripos($e->getMessage(), 'No such webhook endpoint') !== false) {
                return new WP_Error('not_found', $e->getMessage());
            }

            return new WP_Error('api', $e->getMessage());
        }
    }

    /**
     * Whether an endpoint already serves this URL. Seam for tests.
     *
     * @since 3.4.4
     *
     * @param string $url Webhook URL.
     * @return bool
     */
    protected function find_endpoint_by_url($url) {
        if ( ! class_exists('Securehold_Stripe') || ! Securehold_Stripe::init_stripe() ) {
            return false;
        }

        try {
            foreach (\Stripe\WebhookEndpoint::all(['limit' => 100])->data as $endpoint) {
                if ( ! empty($endpoint->url) && $endpoint->url === $url ) {
                    return true;
                }
            }
        } catch (\Exception $e) {
            return false;
        }

        return false;
    }

}
