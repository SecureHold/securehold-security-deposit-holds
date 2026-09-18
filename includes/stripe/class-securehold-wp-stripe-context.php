<?php
/**
 * Stripe context diagnosis.
 *
 * SecureHold keeps its own Stripe credentials, separate from the WooCommerce
 * Stripe gateway's. Nothing checked that the two point at the same place, and
 * nothing could: Balance::retrieve() — the call every existing validation path
 * relies on — succeeds for any valid key of any account, any sandbox. A store
 * could therefore show a green Health Check while SecureHold was reading a
 * Stripe context that did not contain the PaymentIntents WooCommerce had just
 * created, and the first symptom the merchant saw was "No such PaymentMethod"
 * at hold creation.
 *
 * This service answers the question that actually matters — can SecureHold read
 * what WooCommerce wrote? — and reports it as one of five states.
 *
 * Read-only by construction: it retrieves, never creates, updates or deletes,
 * and touches no order and no Stripe object.
 *
 * @package SecureHold_WP
 * @since   3.4.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Stripe_Context {

    /** Credentials missing, malformed, or rejected by Stripe. */
    const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';

    /** SecureHold and the WooCommerce gateway are on different modes (test vs live). */
    const STATUS_MODE_MISMATCH = 'mode_mismatch';

    /** Both sides answer, but they are two different Stripe accounts. */
    const STATUS_ACCOUNT_MISMATCH = 'account_mismatch';

    /**
     * Same account — or an account comparison that could not be made — yet a
     * PaymentIntent created by WooCommerce is not visible to SecureHold's keys.
     * This is the sandbox / separate-test-environment case: one account can hold
     * several isolated environments whose objects never cross over.
     */
    const STATUS_CONTEXT_INCOMPATIBLE = 'context_incompatible';

    /** A WooCommerce PaymentIntent was retrieved with SecureHold's keys. */
    const STATUS_COMPATIBLE = 'compatible';

    /** Not enough evidence to conclude — never reported as a failure. */
    const STATUS_UNKNOWN = 'unknown';

    /** Transient holding the last evaluation. */
    const CACHE_KEY = 'securehold_stripe_context';

    /**
     * Cache lifetime, in seconds.
     *
     * Evaluation costs up to three Stripe round-trips, and the Health Check page
     * runs on every load. Fifteen minutes keeps the page responsive while still
     * reflecting a configuration change within one working session. A credential
     * change invalidates the entry immediately regardless — see cache_fingerprint().
     */
    const CACHE_TTL = 900;

    /** Recent orders scanned when looking for a WooCommerce PaymentIntent. */
    const PROBE_SCAN_LIMIT = 20;

    /**
     * PaymentIntents probed before concluding.
     *
     * More than one on purpose: a single missing intent is not proof of an
     * incompatible context. Test data can be wiped from a sandbox, and an
     * imported or migrated order can carry an intent id that never existed on
     * this account. Three agreeing failures make that coincidence implausible.
     */
    const PROBE_MAX_INTENTS = 3;

    /**
     * Cached evaluation, computed on first call.
     *
     * @since 3.4.4
     *
     * @param bool $force_refresh Bypass and rewrite the cache.
     * @return array See evaluate() for the shape.
     */
    public static function get( $force_refresh = false ) {
        $cached = get_transient( self::CACHE_KEY );

        if (
            ! $force_refresh
            && is_array( $cached )
            && isset( $cached['fingerprint'] )
            && hash_equals( (string) $cached['fingerprint'], self::cache_fingerprint() )
        ) {
            $cached['cached'] = true;
            return $cached;
        }

        $context = new self();
        $result  = $context->evaluate();

        $result['fingerprint'] = self::cache_fingerprint();
        $result['cached']      = false;

        set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );

        return $result;
    }

    /**
     * Discard the cached evaluation.
     *
     * @since 3.4.4
     * @return void
     */
    public static function flush() {
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Fingerprint of the credentials the cached result was computed from.
     *
     * A one-way digest used only to notice that credentials changed, so a stale
     * verdict is never shown after a key swap. It is never displayed, never
     * logged, and never leaves the options table. Digesting is what keeps the
     * secrets out of the cache payload — the raw keys are never stored here.
     *
     * @since 3.4.4
     * @return string
     */
    protected static function cache_fingerprint() {
        $sh = function_exists( 'securehold_get_stripe_keys' ) ? securehold_get_stripe_keys() : array();
        $wc = function_exists( 'securehold_get_woocommerce_stripe_keys' ) ? securehold_get_woocommerce_stripe_keys() : array();

        return md5( implode( '|', array(
            get_option( 'securehold_stripe_mode', 'test' ),
            isset( $sh['secret'] ) ? $sh['secret'] : '',
            isset( $wc['secret'] ) ? $wc['secret'] : '',
        ) ) );
    }

    /**
     * Run the diagnosis.
     *
     * Checks run cheapest-first only where that does not change the verdict: the
     * credential probe comes first because an unusable key makes every later
     * answer meaningless.
     *
     * @since 3.4.4
     *
     * @return array {
     *     @type string $status      One of the STATUS_* constants.
     *     @type array  $mode        securehold, woocommerce, gateway_active, aligned.
     *     @type array  $accounts    securehold, woocommerce — masked ids or null.
     *     @type array  $probe       tested, order_id, intent, result.
     *     @type array  $notes       Short machine-readable reason codes.
     *     @type int    $checked_at  Unix timestamp.
     * }
     */
    public function evaluate() {
        $result = array(
            'status'     => self::STATUS_UNKNOWN,
            'mode'       => array(),
            'accounts'   => array( 'securehold' => null, 'woocommerce' => null ),
            'probe'      => array( 'tested' => 0, 'order_id' => null, 'intent' => null, 'result' => 'none' ),
            'notes'      => array(),
            'checked_at' => time(),
        );

        // ── 1. Credentials present ──
        $sh_keys = $this->get_securehold_keys();

        if ( empty( $sh_keys['secret'] ) ) {
            $result['status']  = self::STATUS_INVALID_CREDENTIALS;
            $result['notes'][] = 'securehold_secret_missing';
            return $result;
        }

        // ── 2. SecureHold identity ──
        $sh_account = $this->retrieve_account( $sh_keys['secret'] );

        if ( is_wp_error( $sh_account ) ) {
            $code = $sh_account->get_error_code();

            if ( $code === 'authentication' ) {
                $result['status']  = self::STATUS_INVALID_CREDENTIALS;
                $result['notes'][] = 'securehold_key_rejected';
                return $result;
            }

            // Restricted keys (rk_...) may be denied Account access while still
            // being perfectly able to create holds. Not a failure — just an
            // identity we cannot read. The object probe below still decides.
            $result['notes'][] = ( $code === 'permission' )
                ? 'securehold_account_permission_denied'
                : 'securehold_account_unavailable';
        } else {
            $result['accounts']['securehold'] = self::mask_id( $sh_account );
        }

        // ── 3. Mode alignment (no API call) ──
        $mode           = $this->get_mode_status();
        $result['mode'] = $mode;

        if ( ! empty( $mode['gateway_active'] ) && empty( $mode['aligned'] ) ) {
            $result['status']  = self::STATUS_MODE_MISMATCH;
            $result['notes'][] = 'mode_' . $mode['securehold'] . '_vs_' . $mode['woocommerce'];
            return $result;
        }

        // ── 4. WooCommerce identity, when its key is readable ──
        $wc_keys = $this->get_woocommerce_keys();

        if ( empty( $wc_keys['secret'] ) ) {
            // Expected whenever the gateway is connected through OAuth rather
            // than pasted keys. Degrades to the object probe, which needs no
            // WooCommerce credential at all.
            $result['notes'][] = 'woocommerce_secret_unavailable';
        } else {
            $wc_account = $this->retrieve_account( $wc_keys['secret'] );

            if ( is_wp_error( $wc_account ) ) {
                $result['notes'][] = 'woocommerce_account_unavailable';
            } else {
                $result['accounts']['woocommerce'] = self::mask_id( $wc_account );

                if ( ! is_wp_error( $sh_account ) && $sh_account !== $wc_account ) {
                    $result['status']  = self::STATUS_ACCOUNT_MISMATCH;
                    $result['notes'][] = 'accounts_differ';
                    return $result;
                }
            }
        }

        // ── 5. The question that matters: can we read WooCommerce's objects? ──
        $intents = $this->get_recent_wc_intents();

        if ( empty( $intents ) ) {
            // Nothing to probe. Common on a fresh install, and not a fault.
            $result['status']  = self::STATUS_UNKNOWN;
            $result['notes'][] = 'no_stripe_orders';
            return $result;
        }

        $not_found = 0;
        $errors    = 0;

        foreach ( $intents as $candidate ) {
            $result['probe']['tested']++;
            $probe = $this->retrieve_payment_intent( $candidate['intent_id'] );

            if ( ! is_wp_error( $probe ) ) {
                $result['status']            = self::STATUS_COMPATIBLE;
                $result['probe']['order_id'] = $candidate['order_id'];
                $result['probe']['intent']   = self::mask_id( $candidate['intent_id'] );
                $result['probe']['result']   = 'ok';
                return $result;
            }

            if ( $probe->get_error_code() === 'not_found' ) {
                $not_found++;
                $result['probe']['order_id'] = $candidate['order_id'];
                $result['probe']['intent']   = self::mask_id( $candidate['intent_id'] );
            } else {
                $errors++;
            }
        }

        if ( $not_found > 0 && $errors === 0 ) {
            $result['status']          = self::STATUS_CONTEXT_INCOMPATIBLE;
            $result['probe']['result'] = 'not_found';
            $result['notes'][]         = 'wc_intents_not_visible';
            return $result;
        }

        // Network trouble, rate limiting, anything else: we know nothing.
        $result['status']          = self::STATUS_UNKNOWN;
        $result['probe']['result'] = 'error';
        $result['notes'][]         = 'probe_inconclusive';

        return $result;
    }

    /**
     * Human-readable label for a status.
     *
     * @since 3.4.4
     *
     * @param string $status One of the STATUS_* constants.
     * @return string
     */
    public static function describe( $status ) {
        switch ( $status ) {
            case self::STATUS_INVALID_CREDENTIALS:
                return __( 'Stripe credentials invalid', 'securehold-security-deposit-holds' );
            case self::STATUS_MODE_MISMATCH:
                return __( 'Stripe mode mismatch', 'securehold-security-deposit-holds' );
            case self::STATUS_ACCOUNT_MISMATCH:
                return __( 'Stripe account mismatch', 'securehold-security-deposit-holds' );
            case self::STATUS_CONTEXT_INCOMPATIBLE:
                return __( 'Stripe context incompatible', 'securehold-security-deposit-holds' );
            case self::STATUS_COMPATIBLE:
                return __( 'Stripe context compatible', 'securehold-security-deposit-holds' );
            default:
                return __( 'Stripe context not verified', 'securehold-security-deposit-holds' );
        }
    }

    /**
     * Shorten a Stripe identifier for display.
     *
     * Stripe object ids are not secrets, but there is no reason to print them
     * whole in an admin screen or a support bundle.
     *
     * @since 3.4.4
     *
     * @param string $id Stripe identifier.
     * @return string Masked form, e.g. acct_1U6FD…t6oc.
     */
    public static function mask_id( $id ) {
        $id = (string) $id;

        if ( strlen( $id ) <= 14 ) {
            return $id;
        }

        return substr( $id, 0, 10 ) . '…' . substr( $id, -4 );
    }

    // =========================================================================
    // Seams — overridden by the test harness so the diagnosis can be exercised
    // without a network or a WordPress install.
    // =========================================================================

    /**
     * SecureHold's own Stripe credentials.
     *
     * @since 3.4.4
     * @return array
     */
    protected function get_securehold_keys() {
        return function_exists( 'securehold_get_stripe_keys' ) ? securehold_get_stripe_keys() : array();
    }

    /**
     * The WooCommerce Stripe gateway's credentials, when stored as plain keys.
     *
     * @since 3.4.4
     * @return array
     */
    protected function get_woocommerce_keys() {
        return function_exists( 'securehold_get_woocommerce_stripe_keys' )
            ? securehold_get_woocommerce_stripe_keys()
            : array();
    }

    /**
     * SecureHold mode versus WooCommerce gateway mode.
     *
     * @since 3.4.4
     * @return array
     */
    protected function get_mode_status() {
        return function_exists( 'securehold_get_stripe_mode_status' )
            ? securehold_get_stripe_mode_status()
            : array( 'securehold' => null, 'woocommerce' => null, 'gateway_active' => false, 'aligned' => false );
    }

    /**
     * Resolve the Stripe account behind a secret key.
     *
     * The key is passed per request rather than through Stripe::setApiKey(), so
     * reading WooCommerce's identity never disturbs the global key SecureHold
     * uses everywhere else.
     *
     * @since 3.4.4
     *
     * @param string $secret_key Stripe secret or restricted key.
     * @return string|WP_Error Account id, or an error coded
     *                         authentication|permission|api.
     */
    protected function retrieve_account( $secret_key ) {
        if ( ! class_exists( 'SecureHold_Stripe' ) || ! SecureHold_Stripe::init_stripe() ) {
            return new WP_Error( 'api', 'Stripe SDK unavailable' );
        }

        try {
            $account = \Stripe\Account::retrieve( null, array( 'api_key' => $secret_key ) );
            return $account->id;

        } catch ( \Stripe\Exception\AuthenticationException $e ) {
            return new WP_Error( 'authentication', $e->getMessage() );

        } catch ( \Stripe\Exception\PermissionException $e ) {
            return new WP_Error( 'permission', $e->getMessage() );

        } catch ( \Exception $e ) {
            // Message only. The exception object carries request context that has
            // no business reaching a log.
            return new WP_Error( 'api', $e->getMessage() );
        }
    }

    /**
     * Read a PaymentIntent with SecureHold's own credentials.
     *
     * @since 3.4.4
     *
     * @param string $intent_id PaymentIntent id.
     * @return true|WP_Error True when visible; error coded not_found|api otherwise.
     */
    protected function retrieve_payment_intent( $intent_id ) {
        if ( ! class_exists( 'SecureHold_Stripe' ) || ! SecureHold_Stripe::init_stripe() ) {
            return new WP_Error( 'api', 'Stripe SDK unavailable' );
        }

        try {
            \Stripe\PaymentIntent::retrieve( $intent_id );
            return true;

        } catch ( \Exception $e ) {
            $message = $e->getMessage();

            if ( stripos( $message, 'No such payment_intent' ) !== false
                || stripos( $message, 'No such PaymentIntent' ) !== false ) {
                return new WP_Error( 'not_found', $message );
            }

            return new WP_Error( 'api', $message );
        }
    }

    /**
     * Recent WooCommerce PaymentIntents to probe, newest first.
     *
     * Goes through wc_get_orders() so the same code serves HPOS and legacy post
     * storage without touching either table directly.
     *
     * @since 3.4.4
     *
     * @return array List of array( 'order_id' => int, 'intent_id' => string ).
     */
    protected function get_recent_wc_intents() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $order_ids = wc_get_orders( array(
            'limit'   => self::PROBE_SCAN_LIMIT,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
            'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
        ) );

        if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
            return array();
        }

        $found = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order || strpos( (string) $order->get_payment_method(), 'stripe' ) === false ) {
                continue;
            }

            $intent_id = (string) $order->get_meta( '_stripe_intent_id', true );

            if ( strpos( $intent_id, 'pi_' ) !== 0 ) {
                continue;
            }

            $found[] = array(
                'order_id'  => (int) $order_id,
                'intent_id' => $intent_id,
            );

            if ( count( $found ) >= self::PROBE_MAX_INTENTS ) {
                break;
            }
        }

        return $found;
    }
}
