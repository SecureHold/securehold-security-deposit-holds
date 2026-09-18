<?php
/**
 * SecureHold Checkout Engine - SFU injection, 3-layer architecture (FROZEN v4.2.0)
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
/*
 * SecureHold Checkout Engine
 * Forces setup_future_usage=off_session on checkout PaymentIntents.
 *
 * ============================================================================
 * ARCHITECTURE — FROZEN (v4.1.0)
 * ============================================================================
 *
 * This file implements the 3-layer SFU injection strategy that ensures
 * every WooCommerce Stripe checkout (guest AND logged-in) produces a
 * reusable PaymentMethod that SecureHold can use for off-session holds.
 *
 * WHY ALL 3 LAYERS EXIST:
 *   Stripe's WooCommerce gateway has evolved across versions v5–v8 with
 *   different code paths (legacy, UPE, Payment Element). No single hook
 *   covers all paths. Each layer acts as a progressively deeper safety net:
 *
 *   Layer 1 — WC Stripe Gateway PHP filters (priority 9999)
 *     Hooks every known filter name used by WC Stripe Gateway v5–v8 to
 *     inject setup_future_usage into PaymentIntent args BEFORE they are
 *     sent to Stripe. Works when the gateway uses server-side PHP to build
 *     the PI. Does NOT work when UPE/Payment Element creates the PI via
 *     client-side JS and the server merely confirms it.
 *
 *   Layer 2 — WordPress HTTP API interception (priority 9999)
 *     Hooks `http_request_args` to inspect every outgoing HTTP request.
 *     If the request targets api.stripe.com/v1/payment_intents (POST) AND
 *     is a payment (not a SecureHold hold), we inject setup_future_usage
 *     directly into the raw request body. Works regardless of how the
 *     gateway builds the request. Catches UPE AJAX server-side confirms.
 *
 *   Layer 3 — Post-payment Stripe API update (after payment_complete)
 *     If after payment the PI still has setup_future_usage=null, we call
 *     Stripe API to UPDATE the PaymentIntent with setup_future_usage.
 *     Last-resort because Stripe only allows this on certain PI statuses,
 *     but it covers edge cases missed by Layers 1-2.
 *
 * EXECUTION ORDER:
 *   1. Constructor registers all hooks (Layers 1+2+3)
 *   2. During checkout, Layer 1 fires first (gateway filter callbacks)
 *   3. Layer 2 fires next (HTTP request interception, just before cURL)
 *   4. After payment completes, Layer 3 verifies and patches if needed
 *   5. Post-payment diagnosis confirms PM reusability
 *
 * WHAT YOU MUST NOT MODIFY:
 *   - Do NOT remove any Layer. All 3 are required for full coverage.
 *   - Do NOT lower the priority below 9999 on Layer 1/2 hooks.
 *   - Do NOT remove the `setup_future_usage = 'off_session'` assignment.
 *   - Do NOT remove the metadata[plugin]=securehold skip logic in Layer 2
 *     (this prevents infinite loops on SecureHold's own hold creation).
 *   - Do NOT remove the SFU integrity guard (verify_sfu_integrity).
 *
 * WHY setup_future_usage MUST ALWAYS BE FORCED:
 *   Without setup_future_usage=off_session, Stripe treats the PaymentMethod
 *   as single-use. It cannot be reused for off-session holds. Guest checkout
 *   is especially fragile: no saved PM tokens exist, so the checkout PI's
 *   PM is the ONLY way to create a subsequent hold. If SFU is missing,
 *   hold creation fails with "pm_single_use" and requires manual retry.
 *
 * WHY SecureHold HOLDS ARE EXCLUDED VIA METADATA:
 *   Layer 2 intercepts ALL outgoing Stripe PI requests. SecureHold's own
 *   hold-creation requests also go through the HTTP API. To prevent Layer 2
 *   from modifying hold PIs (which use capture_method=manual + off_session),
 *   we check for metadata[plugin]=securehold and skip those requests.
 *
 * @since 4.0.0 - Guest-first rewrite
 * @since 4.1.0 - Post-payment PI diagnostic + architecture freeze
 * @since 4.2.0 - 3-layer server-side SFU injection + HTTP interception
 */

/**
 * Checkout Engine architecture version.
 * Logged at boot. Bump only when the architecture itself changes.
 * Do NOT confuse with SECUREHOLD_VERSION (plugin release version).
 */
if (!defined('SECUREHOLD_CHECKOUT_ARCH_VERSION')) {
    define('SECUREHOLD_CHECKOUT_ARCH_VERSION', '4.1.0');
}

class Securehold_Checkout {

    /**
     * Tracks whether any Layer-1 hook actually fired during this request.
     * If none fired, Layer-2 HTTP interception becomes the primary mechanism.
     * @var bool
     */
    private $layer1_hook_fired = false;

    /**
     * Tracks whether Layer-2 HTTP interception injected SFU.
     * @var bool
     */
    private $layer2_http_injected = false;

    /** Sentinel: the deposit question has not been asked yet in this request. */
    const SFU_GATE_UNSET = 'unset';

    /** @var bool|null|string Request-local answer to the deposit question. */
    private $sfu_gate_answer = self::SFU_GATE_UNSET;

    public function __construct() {

        // ── Boot log ──
        if (function_exists('securehold_log')) {
            securehold_log('SecureHold Checkout Engine v' . SECUREHOLD_CHECKOUT_ARCH_VERSION . ' loaded', array(), 'debug');
        }

        // =====================================================================
        // LAYER 1 — WC Stripe Gateway PHP filters (all known hook names)
        // DO NOT REMOVE — required for reusable PM in guest checkout.
        // Removing this will break off-session holds.
        // Priority 9999 = run AFTER the gateway builds its args, so we have
        // the last word before the request goes out.
        // =====================================================================

        // Force the gateway to save the payment source (works for legacy flows)
        // DO NOT REMOVE — required for reusable PM in guest checkout
        add_filter('wc_stripe_force_save_source', '__return_true', 9999);
        add_filter('wc_stripe_save_payment_method', '__return_true', 9999);

        // ── Every known PI-args filter in WC Stripe Gateway v5–v8 ──
        // DO NOT REMOVE any filter from this list — each covers a different gateway version/path
        $pi_filters = array(
            // Legacy card element flow (v5.x)
            'wc_stripe_payment_intent_args',
            // Legacy generate_payment_request (v5.x)
            'wc_stripe_generate_payment_request',
            // UPE create-intent server-side (v6.x–v7.x)
            'wc_stripe_payment_intent_create_args',
            // UPE update-intent server-side
            'wc_stripe_payment_intent_update_args',
            // Generate payment request (v8.x)
            'wc_stripe_generate_payment_intent_request',
            // Older variants
            'wc_stripe_intent_args',
            'wc_stripe_create_intent_args',
            // UPE confirm server-side
            'wc_stripe_confirm_intent_args',
        );

        foreach ($pi_filters as $filter_name) {
            add_filter($filter_name, array($this, 'layer1_inject_sfu'), 9999, 2);
        }

        // UPE JS-params filter (frontend localization)
        // DO NOT REMOVE — influences client-side PI creation in UPE flows
        add_filter('wc_stripe_upe_params', array($this, 'layer1_inject_sfu_upe_params'), 9999, 2);

        // =====================================================================
        // SFU INTEGRITY GUARD
        // Runs AFTER Layer 1 at priority 10000 to detect and repair any
        // external filter that removes setup_future_usage after our injection.
        // =====================================================================
        foreach ($pi_filters as $filter_name) {
            add_filter($filter_name, array($this, 'verify_sfu_integrity'), 10000, 2);
        }

        // =====================================================================
        // LAYER 2 — WordPress HTTP API interception
        // DO NOT REMOVE — required for reusable PM in guest checkout.
        // Removing this will break off-session holds for UPE/Payment Element flows.
        // Intercept the raw HTTP request to api.stripe.com BEFORE it leaves PHP.
        // This catches every PI creation/update/confirm regardless of gateway code.
        // =====================================================================
        add_filter('http_request_args', array($this, 'layer2_http_intercept'), 9999, 2);

        // =====================================================================
        // LAYER 3 — Post-payment: Update PI via Stripe API if SFU still missing
        // DO NOT REMOVE — required for reusable PM in guest checkout.
        // Removing this will break off-session holds when Layers 1-2 miss.
        // =====================================================================
        add_action('woocommerce_payment_complete', array($this, 'layer3_post_payment_sfu_update'), 4);
        add_action('woocommerce_order_status_processing', array($this, 'layer3_post_payment_sfu_update'), 4);

        // ── Post-payment: Ensure Stripe metadata is stored on the order ──
        add_action('woocommerce_payment_complete', array($this, 'ensure_stripe_data_on_order'), 5);
        add_action('woocommerce_order_status_processing', array($this, 'ensure_stripe_data_on_order'), 5);
        add_action('woocommerce_order_status_completed', array($this, 'ensure_stripe_data_on_order'), 5);
        add_action('woocommerce_order_status_on-hold', array($this, 'ensure_stripe_data_on_order'), 5);

        // ── Post-payment: Diagnose PI reusability via Stripe API ──
        add_action('woocommerce_payment_complete', array($this, 'diagnose_checkout_intent'), 6);
        add_action('woocommerce_order_status_processing', array($this, 'diagnose_checkout_intent'), 6);

        // ── Checkout notice ──
        add_action('woocommerce_review_order_before_payment', array($this, 'display_deposit_info_notice'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_styles'));
    }

    // =========================================================================
    // SFU INTEGRITY GUARD
    // Detects external filters that remove setup_future_usage after Layer 1.
    // If SFU was stripped, re-injects it and logs a WARNING.
    // =========================================================================

    /**
     * Verify that setup_future_usage has not been removed by an external filter
     * running between Layer 1 (priority 9999) and this guard (priority 10000).
     *
     * @param array|mixed $args  PaymentIntent args
     * @param mixed       $order WooCommerce order or other 2nd arg
     * @return array|mixed
     */
    public function verify_sfu_integrity($args, $order = null) {
        if (!is_array($args)) {
            return $args;
        }

        // Only guard if Layer 1 already fired (meaning we set SFU and something removed it)
        if (!$this->layer1_hook_fired) {
            return $args;
        }

        $current_sfu = !empty($args['setup_future_usage']) ? $args['setup_future_usage'] : null;

        if ($current_sfu !== 'off_session') {
            // SFU was stripped by an external filter — re-inject and warn
            $args['setup_future_usage'] = 'off_session';

            if (function_exists('securehold_log')) {
                $filter_name = current_filter();
                securehold_log('SFU integrity guard: setup_future_usage was removed by an external filter — re-injected', array(
                    'filter'     => $filter_name,
                    'sfu_found'  => $current_sfu ?: '(removed)',
                    'sfu_forced' => 'off_session',
                ), 'warning');
            }
        }

        return $args;
    }

    // =========================================================================
    // LAYER 1 — WC Stripe Gateway PHP filter injection
    // DO NOT REMOVE — required for reusable PM in guest checkout
    // Removing this will break off-session holds
    // =========================================================================

    /**
     * Inject setup_future_usage=off_session into PI args.
     *
     * Attached to every known WC Stripe Gateway filter at priority 9999.
     * Logs precisely which filter fired for debugging.
     *
     * @param array|mixed $args  PaymentIntent args
     * @param mixed       $order WooCommerce order or other 2nd arg
     * @return array|mixed
     */
    public function layer1_inject_sfu($args, $order = null) {
        if (!is_array($args)) {
            return $args;
        }

        $filter_name = current_filter();
        $this->layer1_hook_fired = true;

        // Snapshot before
        $had_sfu_before = !empty($args['setup_future_usage']) ? $args['setup_future_usage'] : null;

        // Inject
        $args['setup_future_usage'] = 'off_session';

        // Extract order_id for logging
        $order_id = 0;
        if ($order && is_object($order) && method_exists($order, 'get_id')) {
            $order_id = $order->get_id();
        }

        if (function_exists('securehold_log')) {
            // DEBUG: verbose Layer1 injection detail — only when debug logging enabled
            securehold_log('[Layer1] Server filter fired — SFU injected', array(
                'filter'           => $filter_name,
                'order_id'         => $order_id,
                'sfu_before'       => $had_sfu_before ?: '(not set)',
                'sfu_after'        => 'off_session',
                'has_customer'     => !empty($args['customer']),
                'has_pm'           => !empty($args['payment_method']),
                'has_amount'       => !empty($args['amount']),
                'capture_method'   => !empty($args['capture_method']) ? $args['capture_method'] : '(not set)',
            ), 'debug');
        }

        return $args;
    }

    /**
     * Inject SFU into UPE JS-params (frontend localization).
     *
     * @param array|mixed $params UPE params
     * @param mixed       $order
     * @return array|mixed
     */
    public function layer1_inject_sfu_upe_params($params, $order = null) {
        if (!is_array($params)) {
            return $params;
        }

        $this->layer1_hook_fired = true;

        // Inject at every possible level
        if (isset($params['intent']) && is_array($params['intent'])) {
            $params['intent']['setup_future_usage'] = 'off_session';
        }
        $params['setup_future_usage'] = 'off_session';

        // Some UPE versions use create_params
        if (isset($params['create_params']) && is_array($params['create_params'])) {
            $params['create_params']['setup_future_usage'] = 'off_session';
        }

        if (function_exists('securehold_log')) {
            // DEBUG: verbose UPE params injection detail
            securehold_log('[Layer1] UPE params filter fired — SFU injected at all levels', array(
                'filter'     => 'wc_stripe_upe_params',
                'keys_found' => array_keys($params),
            ), 'debug');
        }

        return $params;
    }

    // =========================================================================
    // LAYER 2 — HTTP API interception (the nuclear option)
    // DO NOT REMOVE — required for reusable PM in guest checkout
    // Removing this will break off-session holds for UPE/Payment Element flows
    // =========================================================================

    /**
     * Intercept outgoing HTTP requests to Stripe API.
     *
     * Targets ONLY POST requests to:
     *   - api.stripe.com/v1/payment_intents (create)
     *   - api.stripe.com/v1/payment_intents/pi_xxx (update)
     *   - api.stripe.com/v1/payment_intents/pi_xxx/confirm (confirm)
     *
     * Does NOT intercept:
     *   - SecureHold's own hold creation (has metadata[plugin]=securehold)
     *   - GET requests
     *   - Non-Stripe URLs
     *
     * @param array  $parsed_args HTTP request args (including 'body')
     * @param string $url         Target URL
     * @return array Modified args
     */
    public function layer2_http_intercept($parsed_args, $url) {
        // ── Quick exit: only target the two Stripe endpoints that mint a PI ──
        //
        // WooCommerce Stripe 10.9.0 creates the checkout PaymentIntent one of two
        // ways. Classic and Blocks POST /v1/payment_intents, which this layer has
        // always covered. Optimized Checkout POSTs /v1/checkout/sessions and lets
        // Stripe mint the PaymentIntent inside the session — no /v1/payment_intents
        // request is ever made, so the historic guard let that flow through
        // untouched. Optimized Checkout is switched on by default since the
        // gateway's 10.8 upgrade routine, so that is not a rare path.
        $endpoint = $this->classify_stripe_endpoint($url);

        if ($endpoint === null) {
            return $parsed_args;
        }

        // Only POST (create/update/confirm)
        $method = !empty($parsed_args['method']) ? strtoupper($parsed_args['method']) : 'GET';
        if ($method !== 'POST') {
            return $parsed_args;
        }

        // ── Parse the request body ──
        $body = '';
        if (!empty($parsed_args['body'])) {
            $body = $parsed_args['body'];
        }

        // Body can be a string (URL-encoded) or array
        $body_is_string = is_string($body);
        $body_array = array();

        if ($body_is_string && !empty($body)) {
            parse_str($body, $body_array);
        } elseif (is_array($body)) {
            $body_array = $body;
        }

        // ── Skip SecureHold's own hold creation requests ──
        // Our hold PIs have metadata[plugin]=securehold
        if (!empty($body_array['metadata']) && is_array($body_array['metadata'])) {
            if (!empty($body_array['metadata']['plugin']) && $body_array['metadata']['plugin'] === 'securehold') {
                return $parsed_args;
            }
        }
        // URL-encoded form: metadata[plugin]=securehold
        if ($body_is_string && strpos($body, 'metadata%5Bplugin%5D=securehold') !== false) {
            return $parsed_args;
        }
        if ($body_is_string && strpos($body, 'metadata[plugin]=securehold') !== false) {
            return $parsed_args;
        }

        // ── Skip if capture_method=manual (that's a SecureHold hold, not checkout) ──
        if (!empty($body_array['capture_method']) && $body_array['capture_method'] === 'manual') {
            // Could be SecureHold hold — skip to be safe
            // But also check: if off_session=true, definitely a hold
            if (!empty($body_array['off_session'])) {
                return $parsed_args;
            }
        }

        // ── Only touch checkouts that actually need a SecureHold deposit ──
        //
        // This layer used to rewrite every Stripe checkout request the site made,
        // whether or not the cart carried a deposit. Asking Stripe to keep a
        // payment method reusable is not free of consequence for the shopper, so
        // it has no business happening on a plain purchase.
        $needs_deposit = $this->should_inject_sfu_for_current_checkout();

        if ($needs_deposit === false) {
            return $parsed_args;
        }

        // ── Checkout Sessions take a different shape ──
        if ($endpoint === 'checkout_session') {
            return $this->layer2_inject_into_checkout_session($parsed_args, $url, $body, $body_array, $body_is_string);
        }

        // ── Check if SFU is already set ──
        $current_sfu = !empty($body_array['setup_future_usage']) ? $body_array['setup_future_usage'] : null;

        if ($current_sfu === 'off_session') {
            // Already set — just log and return
            if (function_exists('securehold_log')) {
                // DEBUG: SFU was already present, no injection needed
                securehold_log('[Layer2] HTTP intercept: SFU already present', array(
                    'url'     => $this->sanitize_stripe_url($url),
                    'sfu'     => $current_sfu,
                ), 'debug');
            }
            return $parsed_args;
        }

        // ── INJECT setup_future_usage=off_session ──
        if ($body_is_string) {
            // URL-encoded string: append parameter
            $separator = empty($body) ? '' : '&';
            $body .= $separator . 'setup_future_usage=off_session';
            $parsed_args['body'] = $body;
        } else {
            // Array body
            $body_array['setup_future_usage'] = 'off_session';
            $parsed_args['body'] = $body_array;
        }

        $this->layer2_http_injected = true;

        // ── Detailed log ──
        if (function_exists('securehold_log')) {
            // Extract some context from body for logging (NO secrets)
            $log_context = array(
                'url'              => $this->sanitize_stripe_url($url),
                'method'           => $method,
                'sfu_before'       => $current_sfu ?: '(not set)',
                'sfu_after'        => 'off_session',
                'body_type'        => $body_is_string ? 'string' : 'array',
                'has_customer'     => !empty($body_array['customer']),
                'has_pm'           => !empty($body_array['payment_method']),
                'has_amount'       => !empty($body_array['amount']),
                'capture_method'   => !empty($body_array['capture_method']) ? $body_array['capture_method'] : '(not set)',
                'layer1_fired'     => $this->layer1_hook_fired,
            );

            // Try to extract order_id from metadata
            if (!empty($body_array['metadata']) && is_array($body_array['metadata']) && !empty($body_array['metadata']['order_id'])) {
                $log_context['order_id'] = $body_array['metadata']['order_id'];
            }

            // DEBUG: verbose Layer2 injection detail — only when debug logging enabled
            securehold_log('[Layer2] HTTP intercept: SFU INJECTED into Stripe request', $log_context, 'debug');
        }

        return $parsed_args;
    }

    /**
     * Which PaymentIntent-minting Stripe endpoint a URL targets, if any.
     *
     * Only the creation endpoints qualify. /v1/checkout/sessions/{id} updates an
     * existing session, where setup_future_usage is no longer ours to set, so the
     * match is anchored to the collection path.
     *
     * @since 3.4.4
     *
     * @param string $url Outgoing request URL.
     * @return string|null 'payment_intent', 'checkout_session', or null.
     */
    private function classify_stripe_endpoint($url) {
        if (empty($url) || !is_string($url)) {
            return null;
        }

        if (strpos($url, 'api.stripe.com/v1/payment_intents') !== false) {
            return 'payment_intent';
        }

        // Creation only: nothing may follow the path but a query string.
        if (preg_match('#api\.stripe\.com/v1/checkout/sessions(\?|$)#', $url)) {
            return 'checkout_session';
        }

        return null;
    }

    /**
     * Whether the checkout being paid for right now carries a SecureHold deposit.
     *
     * Layer 2 rewrites outbound Stripe requests, and it used to do so for every
     * checkout on the site. Injecting setup_future_usage tells Stripe to keep the
     * shopper's payment method reusable, which is only justified when SecureHold
     * will later need it to place a hold. On a cart with no deposit it is a side
     * effect on someone else's payment.
     *
     * The answer comes from the same computation the cart and the scheduler use,
     * so a deposit that is filtered out by an exclusion rule, or by the minimum
     * cart amount, correctly reads as "no deposit" here too. This deliberately
     * does not test whether the plugin is active or whether some product carries
     * a meta key — neither answers the question.
     *
     * @return bool|null true when a deposit is due, false when none is,
     *                   null when this request has no cart to judge by.
     */
    private function should_inject_sfu_for_current_checkout() {
        // Request-local only. The answer describes the cart being paid for in
        // this request, so it must never outlive it — no transient, no option.
        if ($this->sfu_gate_answer !== self::SFU_GATE_UNSET) {
            return $this->sfu_gate_answer;
        }

        $this->sfu_gate_answer = $this->resolve_sfu_gate();

        if ($this->sfu_gate_answer === null && function_exists('securehold_log')) {
            // Worth a line: a Stripe checkout request with no cart behind it is
            // the order-pay page or a flow we have not mapped. Logged once per
            // request, and only for the undetermined case — a checkout without a
            // deposit is ordinary and must stay silent.
            securehold_log('[Layer2] No cart context for this checkout; injecting as before', array(
                'is_ajax'  => function_exists('wp_doing_ajax') ? wp_doing_ajax() : false,
                'is_rest'  => defined('REST_REQUEST') && REST_REQUEST,
            ), 'warning');
        }

        return $this->sfu_gate_answer;
    }

    /**
     * Resolve the deposit question for this request. See the caller for context.
     *
     * @return bool|null
     */
    private function resolve_sfu_gate() {
        if (!function_exists('WC')) {
            return null;
        }

        $wc = WC();
        if (empty($wc->cart)) {
            return null;
        }

        // An empty cart is not "no deposit": it means the payment was started
        // somewhere other than a cart checkout — the order-pay page, most often.
        // Answering false there would strip the reusable payment method from a
        // deposit order, so the question stays undetermined instead.
        $cart_items = $wc->cart->get_cart();
        if (empty($cart_items)) {
            return null;
        }

        if (!class_exists('Securehold_Deposit_Computation_Service')) {
            if (!defined('SECUREHOLD_PLUGIN_DIR')) {
                return null;
            }
            $service = SECUREHOLD_PLUGIN_DIR . 'includes/services/class-securehold-wp-computation-service.php';
            if (!file_exists($service)) {
                return null;
            }
            require_once $service;
        }

        $result = Securehold_Deposit_Computation_Service::compute_for_cart($cart_items);

        return !empty($result['has_hold']);
    }

    /**
     * Inject setup_future_usage into a Stripe Checkout Session request.
     *
     * Optimized Checkout hands Stripe a session and lets it mint the
     * PaymentIntent, so there is no /v1/payment_intents request to amend. The
     * session accepts payment_intent_data, which is where the value belongs.
     *
     * Scope is deliberately identical to the PaymentIntent path: every checkout
     * payment, not only orders that will carry a deposit. Layer 2 has never had
     * an order-level guard and cannot have one — the session is built from the
     * cart, before any WooCommerce order exists — and a payment method that was
     * not made reusable at checkout cannot be made reusable afterwards.
     *
     * @since 3.4.4
     *
     * @param array  $parsed_args    HTTP request args.
     * @param string $url            Request URL.
     * @param mixed  $body           Raw body.
     * @param array  $body_array     Parsed body.
     * @param bool   $body_is_string Whether the raw body is a string.
     * @return array Modified args.
     */
    private function layer2_inject_into_checkout_session($parsed_args, $url, $body, $body_array, $body_is_string) {

        // payment_intent_data only applies to sessions that create a
        // PaymentIntent. Both creation sites in the gateway use mode=payment;
        // anything else is left alone rather than assumed compatible.
        $mode = isset($body_array['mode']) ? $body_array['mode'] : null;

        if ($mode !== 'payment') {
            return $parsed_args;
        }

        $intent_data = isset($body_array['payment_intent_data']) && is_array($body_array['payment_intent_data'])
            ? $body_array['payment_intent_data']
            : array();

        $current_sfu = isset($intent_data['setup_future_usage']) ? $intent_data['setup_future_usage'] : null;

        if ($current_sfu === 'off_session') {
            return $parsed_args;
        }

        if (!empty($current_sfu)) {
            // Something else asked for a different reusability. Overwriting it
            // silently would start a fight between plugins that the merchant
            // could never see; the value stands and the disagreement is recorded.
            if (function_exists('securehold_log')) {
                securehold_log('[Layer2] Checkout Session already requests a different setup_future_usage — left unchanged', array(
                    'url'         => $this->sanitize_stripe_url($url),
                    'sfu_present' => $current_sfu,
                ), 'warning');
            }
            return $parsed_args;
        }

        if ($body_is_string) {
            // Bracket notation, encoded the way WordPress encodes a body, so the
            // existing keys of payment_intent_data — the gateway's metadata among
            // them — are untouched.
            $separator = empty($body) ? '' : '&';
            $parsed_args['body'] = $body . $separator . 'payment_intent_data%5Bsetup_future_usage%5D=off_session';
        } else {
            $intent_data['setup_future_usage'] = 'off_session';
            $body_array['payment_intent_data'] = $intent_data;
            $parsed_args['body'] = $body_array;
        }

        $this->layer2_http_injected = true;

        if (function_exists('securehold_log')) {
            securehold_log('[Layer2] setup_future_usage injected into Stripe Checkout Session payment_intent_data', array(
                'url'       => $this->sanitize_stripe_url($url),
                'body_type' => $body_is_string ? 'string' : 'array',
            ), 'debug');
        }

        return $parsed_args;
    }

    /**
     * Sanitize Stripe URL for logging (keep path, mask version-specific params).
     * @param string $url
     * @return string
     */
    private function sanitize_stripe_url($url) {
        // Keep domain + path only
        $parsed = wp_parse_url($url);
        return (!empty($parsed['host']) ? $parsed['host'] : '') . (!empty($parsed['path']) ? $parsed['path'] : '');
    }

    // =========================================================================
    // LAYER 3 — Post-payment Stripe API update
    // DO NOT REMOVE — required for reusable PM in guest checkout
    // Removing this will break off-session holds when Layers 1-2 miss
    // =========================================================================

    /**
     * After payment completes, check if setup_future_usage was set.
     * If not, attempt to UPDATE the PaymentIntent via Stripe API.
     *
     * Stripe allows updating setup_future_usage on a PI in certain statuses
     * (requires_capture, processing, succeeded). This is the safety net.
     *
     * @param int $order_id
     */
    public function layer3_post_payment_sfu_update($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if (strpos($order->get_payment_method(), 'stripe') === false) return;

        // Only run once
        if ($order->get_meta('_securehold_sfu_update_attempted', true) === 'yes') return;
        $order->update_meta_data('_securehold_sfu_update_attempted', 'yes');
        $order->save();

        $intent_id = $order->get_meta('_stripe_intent_id', true);
        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            // Try from charge
            $charge_id = $order->get_meta('_stripe_charge_id', true);
            if (!empty($charge_id)) {
                $intent_id = $this->get_intent_from_charge($charge_id);
            }
        }

        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            if (function_exists('securehold_log')) {
                securehold_log('[Layer3] No PI found — cannot update SFU', array(
                    'order_id' => $order_id,
                ), 'warning');
            }
            return;
        }

        // Which call was in flight when it failed. "No such payment_intent" from
        // the retrieve below used to land in the update's catch block and be
        // reported as an unsupported PaymentIntent status — a statement about a
        // PaymentIntent that had never been read.
        $stage = 'retrieve';

        try {
            if (class_exists('SecureHold_Stripe')) {
                SecureHold_Stripe::init_stripe();
            } else {
                $keys = function_exists('securehold_get_stripe_keys') ? securehold_get_stripe_keys() : array();
                if (empty($keys['secret'])) return;
                \Stripe\Stripe::setApiKey($keys['secret']);
            }

            // Retrieve the PI to check current state
            $intent = \Stripe\PaymentIntent::retrieve($intent_id);
            $current_sfu = !empty($intent->setup_future_usage) ? $intent->setup_future_usage : null;

            if ($current_sfu === 'off_session') {
                // Already set — log success
                if (function_exists('securehold_log')) {
                    // DEBUG: detailed PI state check — verbose
                    securehold_log('[Layer3] PI already has SFU=off_session — no update needed', array(
                        'order_id'  => $order_id,
                        'intent_id' => $intent_id,
                        'sfu'       => $current_sfu,
                        'layer1_fired'     => $this->layer1_hook_fired,
                        'layer2_injected'  => $this->layer2_http_injected,
                    ), 'debug');
                }
                $order->update_meta_data('_securehold_sfu_injection_layer', $this->layer2_http_injected ? 'layer2_http' : ($this->layer1_hook_fired ? 'layer1_filter' : 'gateway_native'));
                $order->update_meta_data('_securehold_sfu_update_result', 'already_off_session');
                $order->save();
                return;
            }

            // ── SFU is still null — attempt Stripe API update ──
            if (function_exists('securehold_log')) {
                securehold_log('[Layer3] PI has SFU=null — attempting Stripe API update', array(
                    'order_id'         => $order_id,
                    'intent_id'        => $intent_id,
                    'pi_status'        => $intent->status,
                    'layer1_fired'     => $this->layer1_hook_fired,
                    'layer2_injected'  => $this->layer2_http_injected,
                ), 'warning');
            }

            // Try to update the PI
            $stage = 'update';
            $updated_intent = \Stripe\PaymentIntent::update($intent_id, array(
                'setup_future_usage' => 'off_session',
            ));

            $new_sfu = !empty($updated_intent->setup_future_usage) ? $updated_intent->setup_future_usage : null;

            if ($new_sfu === 'off_session') {
                if (function_exists('securehold_log')) {
                    // INFO: key lifecycle event — PI updated via Layer3 fallback
                    securehold_log('[Layer3] Successfully updated PI with SFU=off_session via Stripe API', array(
                        'order_id'  => $order_id,
                        'intent_id' => $intent_id,
                        'pi_status' => $updated_intent->status,
                    ), 'info');
                }
                $order->update_meta_data('_securehold_sfu_injection_layer', 'layer3_api_update');
                $order->update_meta_data('_securehold_sfu_update_result', 'updated');
                $order->save();
            } else {
                if (function_exists('securehold_log')) {
                    securehold_log('[Layer3] Stripe accepted update but SFU still not off_session', array(
                        'order_id'  => $order_id,
                        'intent_id' => $intent_id,
                        'sfu_after_update' => $new_sfu,
                    ), 'warning');
                }
                $order->update_meta_data('_securehold_sfu_update_result', 'accepted_but_not_applied');
                $order->save();
            }

        } catch (\Exception $e) {
            // One catch, then classify from what the SDK reports. The previous
            // two-branch version guessed from the exception class alone, which
            // could not tell an inaccessible PaymentIntent from a PaymentIntent
            // whose state refused the change — and quietly swallowed rate
            // limiting, since RateLimitException extends InvalidRequestException.
            $this->log_layer3_failure($order, $order_id, $intent_id, $stage, $e);
        }
    }

    /**
     * Report a Layer 3 failure for what it is.
     *
     * Records the outcome on the order under _securehold_sfu_update_result, in
     * the same family as _securehold_sfu_update_attempted and
     * _securehold_sfu_injection_layer, so the support bundle picks it up without
     * a new mechanism.
     *
     * @since 3.4.4
     *
     * @param WC_Order  $order     Order being processed.
     * @param int       $order_id  Order ID.
     * @param string    $intent_id PaymentIntent ID.
     * @param string    $stage     retrieve|update — which call failed.
     * @param Exception $e         Exception thrown by the SDK.
     * @return void
     */
    private function log_layer3_failure($order, $order_id, $intent_id, $stage, $e) {

        $classified = class_exists('SecureHold_Stripe') && method_exists('SecureHold_Stripe', 'classify_stripe_exception')
            ? SecureHold_Stripe::classify_stripe_exception($e)
            : array( 'code' => 'stripe_unknown_error', 'severity' => 'warning', 'detail' => array() );

        $code = $classified['code'];

        switch ($code) {
            case 'stripe_context_mismatch_confirmed':
                $message = '[Layer3] WooCommerce PaymentIntent is not accessible with the current SecureHold Stripe context';
                break;

            case 'stripe_context_mismatch_suspected':
            case 'stripe_object_not_accessible':
                $message = '[Layer3] WooCommerce PaymentIntent could not be accessed with the current SecureHold credentials — Stripe context not confirmed';
                break;

            case 'stripe_credentials_rejected':
                $message = '[Layer3] Stripe rejected the SecureHold credentials';
                break;

            case 'stripe_permission_denied':
                $message = '[Layer3] The SecureHold key is not permitted to perform this operation';
                break;

            case 'stripe_state_conflict':
                // Stripe returned payment_intent_unexpected_state, so this is the
                // one case where talking about the PaymentIntent state is factual.
                $message = '[Layer3] Stripe rejected the setup_future_usage update because the PaymentIntent state does not allow this operation';
                break;

            case 'stripe_sfu_update_refused':
                $message = '[Layer3] Stripe refused the setup_future_usage value for this PaymentIntent';
                break;

            case 'stripe_api_unreachable':
                $message = '[Layer3] Stripe API could not be reached while attempting the post-payment SFU update';
                break;

            case 'stripe_parameter_rejected':
                $message = '[Layer3] Stripe rejected a parameter of the setup_future_usage update';
                break;

            default:
                $message = '[Layer3] The post-payment setup_future_usage update failed';
                break;
        }

        // Stripe composes its own messages, so redact before the row is written
        // rather than relying on the support bundle to clean it at export time:
        // the credential should never reach the database in the first place.
        $safe_error = $e->getMessage();

        if (!class_exists('Securehold_Support_Bundle') && defined('SECUREHOLD_PLUGIN_DIR')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-support-bundle.php';
        }
        if (class_exists('Securehold_Support_Bundle')) {
            $safe_error = Securehold_Support_Bundle::redact($safe_error);
        }

        if (function_exists('securehold_log')) {
            // Message text only — never the exception object, which can carry the
            // full PaymentIntent and PaymentMethod payloads.
            securehold_log($message, array_merge(array(
                'order_id'   => $order_id,
                'intent_id'  => $intent_id,
                'stage'      => $stage,
                'diagnostic' => $code,
                'error'      => $safe_error,
            ), $classified['detail']), $classified['severity']);
        }

        if ($order) {
            $order->update_meta_data('_securehold_sfu_update_result', $code);
            $order->save();
        }
    }

    // =========================================================================
    // POST-PAYMENT DIAGNOSIS (Source of truth — queries Stripe API directly)
    // =========================================================================

    /**
     * Diagnose the checkout PaymentIntent via Stripe API after payment.
     *
     * This is the AUTHORITATIVE check. Whatever the gateway did (or didn't do),
     * we query Stripe to see the actual state of:
     * - setup_future_usage on the PI
     * - PaymentMethod attachment to Customer
     * - PM reusability
     *
     * Results are stored as order meta for the hold engine and admin UI.
     *
     * @param int $order_id WooCommerce order ID
     */
    public function diagnose_checkout_intent($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if (strpos($order->get_payment_method(), 'stripe') === false) return;

        // Skip if already diagnosed
        if ($order->get_meta('_securehold_pi_diagnosed', true) === 'yes') return;

        $intent_id = $order->get_meta('_stripe_intent_id', true);
        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            // Try to get from charge
            $charge_id = $order->get_meta('_stripe_charge_id', true);
            if (!empty($charge_id)) {
                $intent_id = $this->get_intent_from_charge($charge_id);
            }
        }

        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            if (function_exists('securehold_log')) {
                securehold_log('PI Diagnosis: No PaymentIntent ID found on order', array(
                    'order_id' => $order_id,
                ), 'warning');
            }
            $order->update_meta_data('_securehold_pi_diagnosed', 'yes');
            $order->update_meta_data('_securehold_pi_diagnosis_result', 'no_intent_id');
            $order->save();
            return;
        }

        try {
            if (class_exists('SecureHold_Stripe')) {
                SecureHold_Stripe::init_stripe();
            } else {
                $keys = function_exists('securehold_get_stripe_keys') ? securehold_get_stripe_keys() : array();
                if (empty($keys['secret'])) return;
                \Stripe\Stripe::setApiKey($keys['secret']);
            }

            $intent = \Stripe\PaymentIntent::retrieve($intent_id, array(
                'expand' => array('payment_method'),
            ));

            // ── Extract key diagnostic values ──
            $sfu = !empty($intent->setup_future_usage) ? $intent->setup_future_usage : null;
            $pi_customer = !empty($intent->customer)
                ? (is_object($intent->customer) ? $intent->customer->id : $intent->customer)
                : null;
            $pi_pm_id = !empty($intent->payment_method)
                ? (is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method)
                : null;

            // ── Check PM attachment state ──
            $pm_customer = null;
            $pm_type = null;
            $pm_reusable = false;

            if ($pi_pm_id) {
                try {
                    $pm_obj = is_object($intent->payment_method) ? $intent->payment_method : \Stripe\PaymentMethod::retrieve($pi_pm_id);
                    $pm_customer = !empty($pm_obj->customer) ? $pm_obj->customer : null;
                    $pm_type = !empty($pm_obj->type) ? $pm_obj->type : 'unknown';

                    // PM is reusable if:
                    // 1. It is attached to the same customer as the PI, AND
                    // 2. setup_future_usage was set (so Stripe didn't consume it as single-use)
                    $pm_reusable = (
                        !empty($pm_customer)
                        && !empty($pi_customer)
                        && $pm_customer === $pi_customer
                        && !empty($sfu)
                    );
                } catch (\Exception $e) {
                    $pm_customer = 'error';
                }
            }

            // ── Store diagnosis on order meta ──
            $order->update_meta_data('_securehold_pi_diagnosed', 'yes');
            $order->update_meta_data('_securehold_pi_setup_future_usage', $sfu ?: '(not set)');
            $order->update_meta_data('_securehold_pi_customer', $pi_customer ?: '(none)');
            $order->update_meta_data('_securehold_pi_payment_method', $pi_pm_id ?: '(none)');
            $order->update_meta_data('_securehold_pm_customer', $pm_customer ?: '(not attached)');
            $order->update_meta_data('_securehold_pm_type', $pm_type ?: 'unknown');
            $order->update_meta_data('_securehold_pm_reusable', $pm_reusable ? 'yes' : 'no');

            // Which layer succeeded?
            $injection_layer = $order->get_meta('_securehold_sfu_injection_layer', true);

            // Summary
            if ($pm_reusable) {
                $order->update_meta_data('_securehold_pi_diagnosis_result', 'reusable');
            } elseif (empty($sfu)) {
                $order->update_meta_data('_securehold_pi_diagnosis_result', 'no_setup_future_usage');
            } elseif (empty($pm_customer) || $pm_customer !== $pi_customer) {
                $order->update_meta_data('_securehold_pi_diagnosis_result', 'pm_not_attached');
            } else {
                $order->update_meta_data('_securehold_pi_diagnosis_result', 'unknown_issue');
            }

            $order->save();

            // ── Log the full diagnosis ──
            if (function_exists('securehold_log')) {
                if ($pm_reusable) {
                    // INFO: key lifecycle event — PM confirmed reusable
                    securehold_log('PI Diagnosis complete — PM is reusable', array(
                        'order_id'           => $order_id,
                        'intent_id'          => $intent_id,
                        'setup_future_usage' => $sfu ?: '(not set)',
                        'pm_reusable'        => true,
                        'diagnosis'          => $order->get_meta('_securehold_pi_diagnosis_result', true),
                        'injection_layer'    => $injection_layer ?: '(none recorded)',
                    ), 'info');

                    // DEBUG: full diagnostic dump
                    securehold_log('PI Diagnosis — full detail', array(
                        'order_id'            => $order_id,
                        'intent_id'           => $intent_id,
                        'setup_future_usage'  => $sfu ?: '(not set)',
                        'pi_customer'         => $pi_customer ?: '(none)',
                        'pi_pm'               => $pi_pm_id ?: '(none)',
                        'pm_customer'         => $pm_customer ?: '(not attached)',
                        'pm_type'             => $pm_type ?: 'unknown',
                        'pm_reusable'         => $pm_reusable,
                        'is_guest'            => ($order->get_customer_id() == 0),
                        'diagnosis'           => $order->get_meta('_securehold_pi_diagnosis_result', true),
                        'injection_layer'     => $injection_layer ?: '(none recorded)',
                        'layer1_fired'        => $this->layer1_hook_fired,
                        'layer2_injected'     => $this->layer2_http_injected,
                    ), 'debug');
                } else {
                    // WARNING: PM not reusable — always logged
                    securehold_log('PI Diagnosis complete — PM is NOT reusable', array(
                        'order_id'           => $order_id,
                        'intent_id'          => $intent_id,
                        'setup_future_usage' => $sfu ?: '(not set)',
                        'pm_reusable'        => false,
                        'diagnosis'          => $order->get_meta('_securehold_pi_diagnosis_result', true),
                        'injection_layer'    => $injection_layer ?: '(none recorded)',
                    ), 'warning');

                    securehold_log('PM is NOT reusable — hold creation will require fallback or will fail', array(
                        'order_id' => $order_id,
                        'reason' => empty($sfu)
                            ? 'Checkout PaymentIntent has setup_future_usage=null. All 3 injection layers failed or were not applicable.'
                            : 'PM not attached to the expected customer after checkout.',
                    ), 'warning');
                }
            }

        } catch (\Exception $e) {
            $order->update_meta_data('_securehold_pi_diagnosed', 'yes');
            $order->update_meta_data('_securehold_pi_diagnosis_result', 'api_error');
            $order->save();

            if (function_exists('securehold_log')) {
                securehold_log('PI Diagnosis failed (Stripe API error)', array(
                    'order_id' => $order_id,
                    'intent_id' => $intent_id,
                    'error' => $e->getMessage(),
                ), 'error');
            }
        }
    }

    // =========================================================================
    // POST-PAYMENT DATA RESOLUTION (existing, unchanged logic)
    // =========================================================================

    /**
     * Ensure Stripe metadata (customer_id + payment_method_id) exists on order.
     */
    public function ensure_stripe_data_on_order($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $payment_method = $order->get_payment_method();
        if (strpos($payment_method, 'stripe') === false) return;

        // Prevent duplicate processing
        $already_resolved = $order->get_meta('_securehold_stripe_resolved', true);
        if ($already_resolved === 'yes') return;

        $customer_id = $order->get_meta('_stripe_customer_id', true);
        $payment_method_id = $this->get_payment_method_from_order($order);

        $needs_resolution = empty($customer_id) || empty($payment_method_id);

        if ($needs_resolution) {
            if (function_exists('securehold_log')) {
                // DEBUG: verbose resolution attempt detail
                securehold_log('Checkout: Stripe data incomplete, attempting resolution', array(
                    'order_id' => $order_id,
                    'has_customer_id' => !empty($customer_id),
                    'has_payment_method' => !empty($payment_method_id),
                    'is_guest' => $order->get_customer_id() == 0,
                ), 'debug');
            }

            $resolved = $this->resolve_stripe_data_from_intent($order);

            if ($resolved) {
                $customer_id = !empty($customer_id) ? $customer_id : $resolved['customer_id'];
                $payment_method_id = !empty($payment_method_id) ? $payment_method_id : $resolved['payment_method_id'];

                if (!empty($customer_id) && empty($order->get_meta('_stripe_customer_id', true))) {
                    $order->update_meta_data('_stripe_customer_id', $customer_id);
                }
                if (!empty($payment_method_id) && empty($order->get_meta('_stripe_payment_method', true))) {
                    $order->update_meta_data('_stripe_payment_method', $payment_method_id);
                }

                $order->save();

                if (function_exists('securehold_log')) {
                    // DEBUG: resolution success detail
                    securehold_log('Checkout: Stripe data resolved from PaymentIntent', array(
                        'order_id' => $order_id,
                        'customer_id' => $customer_id,
                        'payment_method_id' => $payment_method_id,
                    ), 'debug');
                }
            } else {
                if (function_exists('securehold_log')) {
                    securehold_log('Checkout: Could not resolve Stripe data from PaymentIntent', array(
                        'order_id' => $order_id,
                    ), 'warning');
                }
            }
        }

        $order->update_meta_data('_securehold_stripe_resolved', 'yes');
        $order->save();
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Usable payment reference stored on the order.
     *
     * Delegates to the canonical resolver so this file no longer carries its own
     * fallback order. It used to return a legacy src_ or card_ as if it were a
     * payment method, which made the caller believe resolution was complete: the
     * PaymentIntent fallback was skipped, and the unusable reference was copied
     * into _stripe_payment_method, spreading it further.
     *
     * @since 3.4.4 Delegates to SecureHold_Stripe::get_payment_method_token_from_order().
     *
     * @param WC_Order $order Order to inspect.
     * @return string A pm_ identifier, or ''.
     */
    private function get_payment_method_from_order($order) {
        if (class_exists('SecureHold_Stripe') && method_exists('SecureHold_Stripe', 'get_payment_method_token_from_order')) {
            return SecureHold_Stripe::get_payment_method_token_from_order($order);
        }

        // Fallback if the Stripe wrapper is unavailable: modern key only, never a
        // legacy reference.
        $pm = $order->get_meta('_stripe_payment_method', true);

        return (!empty($pm) && strpos($pm, 'pm_') === 0) ? $pm : '';
    }

    private function resolve_stripe_data_from_intent($order) {
        $intent_id = $order->get_meta('_stripe_intent_id', true);

        if (empty($intent_id)) {
            $charge_id = $order->get_meta('_stripe_charge_id', true);
            if (!empty($charge_id)) {
                $intent_id = $this->get_intent_from_charge($charge_id);
            }
        }

        if (empty($intent_id) || strpos($intent_id, 'pi_') !== 0) {
            return false;
        }

        try {
            if (class_exists('SecureHold_Stripe')) {
                SecureHold_Stripe::init_stripe();
            } else {
                $keys = function_exists('securehold_get_stripe_keys') ? securehold_get_stripe_keys() : array();
                if (empty($keys['secret'])) return false;
                \Stripe\Stripe::setApiKey($keys['secret']);
            }

            $intent = \Stripe\PaymentIntent::retrieve($intent_id, array(
                'expand' => array('payment_method', 'customer'),
            ));

            $customer_id = '';
            $payment_method_id = '';

            if (!empty($intent->customer)) {
                $customer_id = is_object($intent->customer) ? $intent->customer->id : $intent->customer;
            }

            if (!empty($intent->payment_method)) {
                $payment_method_id = is_object($intent->payment_method) ? $intent->payment_method->id : $intent->payment_method;
            }

            if (empty($customer_id) && !empty($payment_method_id)) {
                $customer_id = $this->create_stripe_customer_for_order($order);

                if (!empty($customer_id)) {
                    try {
                        $pm = \Stripe\PaymentMethod::retrieve($payment_method_id);
                        $pm->attach(array('customer' => $customer_id));
                    } catch (\Exception $e) {
                        if (function_exists('securehold_log')) {
                            securehold_log('Could not attach PM to customer during resolution', array(
                                'order_id' => $order->get_id(),
                                'error' => $e->getMessage(),
                                'pm' => $payment_method_id,
                                'customer' => $customer_id,
                            ), 'warning');
                        }
                    }
                }
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
                securehold_log('Checkout: Failed to retrieve PaymentIntent', array(
                    'intent_id' => $intent_id,
                    'error' => $e->getMessage(),
                ), 'error');
            }
            return false;
        }
    }

    private function get_intent_from_charge($charge_id) {
        try {
            if (class_exists('SecureHold_Stripe')) {
                SecureHold_Stripe::init_stripe();
            }
            $charge = \Stripe\Charge::retrieve($charge_id);
            return !empty($charge->payment_intent) ? $charge->payment_intent : '';
        } catch (\Exception $e) {
            return '';
        }
    }

    private function create_stripe_customer_for_order($order) {
        try {
            if (class_exists('SecureHold_Stripe')) {
                SecureHold_Stripe::init_stripe();
            }

            $customer_args = array(
                'email' => $order->get_billing_email(),
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'metadata' => array(
                    'wc_order_id' => $order->get_id(),
                    'source' => 'securehold_guest_checkout',
                ),
            );

            $phone = $order->get_billing_phone();
            if (!empty($phone)) {
                $customer_args['phone'] = $phone;
            }

            $address = array_filter(array(
                'line1' => $order->get_billing_address_1(),
                'line2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postal_code' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ));
            if (!empty($address['line1'])) {
                $customer_args['address'] = $address;
            }

            $customer = \Stripe\Customer::create($customer_args);

            if (function_exists('securehold_log')) {
                // DEBUG: verbose customer creation detail
                securehold_log('Created Stripe customer for guest order', array(
                    'order_id' => $order->get_id(),
                    'customer_id' => $customer->id,
                ), 'debug');
            }

            return $customer->id;

        } catch (\Exception $e) {
            if (function_exists('securehold_log')) {
                securehold_log('Failed to create Stripe customer', array(
                    'order_id' => $order->get_id(),
                    'error' => $e->getMessage(),
                ), 'error');
            }
            return '';
        }
    }

    // =========================================================================
    // UI
    // =========================================================================

    /**
     * Legacy deposit info notice — DEPRECATED.
     *
     * All checkout messaging is now handled by Securehold_Frontend_Manager::display_checkout_message().
     * This method is kept as a no-op so any remaining remove_action() calls don't fatal.
     *
     * @deprecated 5.4.0
     */
    public function display_deposit_info_notice() {
        // No-op: Securehold_Frontend_Manager is the single source of truth.
        return;
    }

    public function enqueue_frontend_styles() {
        wp_enqueue_style(
            'securehold-frontend',
            SECUREHOLD_PLUGIN_URL . 'assets/css/securehold-wp-frontend.css',
            array(),
            SECUREHOLD_VERSION,
            'all'
        );
    }
}

// Initialize
new Securehold_Checkout();
