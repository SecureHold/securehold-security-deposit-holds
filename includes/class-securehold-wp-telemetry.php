<?php
/**
 * Opt-in usage telemetry.
 *
 * Off by default. Nothing here runs — no id is generated, no request is
 * built — until an administrator explicitly opts in from the notice or the
 * Connection tab. A refusal is permanent (no re-prompting) and never blocks
 * any SecureHold feature; a failure to reach secureholdwp.com is always
 * silent and never surfaces to the merchant.
 *
 * What is sent (see build_payload()): a random site id, plain version
 * strings, and three booleans. Never a domain, an email, a Stripe key, a
 * license key, an order id, or anything WooCommerce/Stripe object-shaped.
 * See PRIVACY.md-equivalent notes inline on build_payload().
 *
 * @package SecureHold_WP
 * @since   3.4.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Wp_Telemetry {

    /** wp_options keys — all autoload=false, none needed on a storefront request. */
    const OPT_ENABLED           = 'securehold_telemetry_enabled';
    const OPT_SITE_ID           = 'securehold_telemetry_site_id';
    const OPT_ENABLED_AT        = 'securehold_telemetry_enabled_at';
    const OPT_NOTICE_DISMISSED  = 'securehold_telemetry_notice_dismissed';
    const OPT_FIRST_HOLD        = 'securehold_telemetry_first_hold_created';

    const CRON_HOOK = 'securehold_telemetry_heartbeat';

    public function __construct() {
        // The opt-in prompt: rendered in the same slot PRO uses for its
        // license notice, so it shows on every SecureHold admin screen
        // rather than only the WP dashboard.
        add_action( 'securehold_after_page_header', array( $this, 'maybe_show_optin_notice' ) );

        add_action( 'wp_ajax_securehold_telemetry_optin',   array( $this, 'ajax_optin' ) );
        add_action( 'wp_ajax_securehold_telemetry_dismiss', array( $this, 'ajax_dismiss' ) );

        // Keeps the cron schedule in step with the current setting on every
        // admin load — cheap (one option read once correctly scheduled) and
        // guarantees an opt-out started from Settings, not the notice, still
        // unschedules the next heartbeat.
        add_action( 'admin_init', array( $this, 'maybe_sync_schedule' ) );
        add_action( self::CRON_HOOK, array( $this, 'send_heartbeat' ) );

        // Fired once, by class-securehold-wp-hold-state.php, the first time
        // any hold anywhere on this site reaches 'authorized'. Never a DB
        // count — see mark_first_hold_created().
        add_action( 'securehold_hold_first_authorized', array( $this, 'mark_first_hold_created' ) );
    }

    // =========================================================================
    // State
    // =========================================================================

    /** @return bool */
    public static function is_enabled() {
        return get_option( self::OPT_ENABLED, 'no' ) === 'yes';
    }

    /**
     * Existing site id, or null if telemetry was never opted into. Never
     * generated as a side effect of merely checking — only opt_in() creates
     * one.
     *
     * @return string|null
     */
    protected function site_id() {
        $id = get_option( self::OPT_SITE_ID, '' );
        return ( '' !== $id ) ? $id : null;
    }

    // =========================================================================
    // Opt-in / opt-out
    // =========================================================================

    /**
     * Turn telemetry on: create the site id if this is the first time ever,
     * record consent, schedule the heartbeat, and send one immediately so
     * the dashboard reflects the new site without waiting a full day.
     *
     * @return void
     */
    public function opt_in() {
        if ( null === $this->site_id() ) {
            // wp_generate_uuid4() is WordPress core (wp-includes/functions.php),
            // backed by random_bytes(). Not derived from anything about this
            // site — no domain, no email, no key enters it.
            add_option( self::OPT_SITE_ID, wp_generate_uuid4(), '', false );
        }

        update_option( self::OPT_ENABLED, 'yes' );
        update_option( self::OPT_ENABLED_AT, current_time( 'mysql' ) );
        update_option( self::OPT_NOTICE_DISMISSED, 'yes' );

        $this->maybe_sync_schedule();
        $this->send_heartbeat();
    }

    /**
     * Turn telemetry off.
     *
     * The site id is kept, not deleted — it is an inert random string with
     * no value once nothing is sent under it, and keeping it means a later
     * re-opt-in resumes the same dashboard row (first_seen_at intact)
     * instead of manufacturing a second "site" out of one re-toggle. See
     * README/telemetry notes for the fuller reasoning; nothing that leaves
     * this site changes once is_enabled() is false, which is the property
     * that actually matters for privacy here.
     *
     * No 'telemetry_disabled' event is sent: opting out should stop
     * outbound requests immediately, and a final call-out to report the
     * opt-out would itself be one more request sent without the consent it
     * is announcing the withdrawal of.
     *
     * @return void
     */
    public function opt_out() {
        update_option( self::OPT_ENABLED, 'no' );
        $this->maybe_sync_schedule();
    }

    // =========================================================================
    // Admin notice
    // =========================================================================

    /**
     * The opt-in prompt. Shown once (until a choice is made) on SecureHold's
     * own admin screens, never as a blocking modal.
     *
     * @return void
     */
    public function maybe_show_optin_notice() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( self::is_enabled() ) {
            return;
        }

        if ( get_option( self::OPT_NOTICE_DISMISSED, 'no' ) === 'yes' ) {
            return;
        }

        ?>
        <div class="notice notice-info securehold-telemetry-notice" id="securehold-telemetry-notice">
            <div style="display: flex; align-items: center; gap: 15px; padding: 10px 0;">
                <div style="flex-shrink: 0;">
                    <span class="dashicons dashicons-chart-bar" style="font-size: 28px; color: #2563eb; width: 28px; height: 28px;"></span>
                </div>
                <div style="flex-grow: 1;">
                    <p style="margin: 0 0 8px 0; font-size: 14px; line-height: 1.5;">
                        <?php esc_html_e( 'Help us improve SecureHold by sharing minimal, pseudonymous usage data — never customer data, payments, or keys.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                        <button type="button" class="button button-primary securehold-telemetry-optin" data-nonce="<?php echo esc_attr( wp_create_nonce( 'securehold_telemetry_optin' ) ); ?>">
                            <?php esc_html_e( 'Allow usage tracking', 'securehold-security-deposit-holds' ); ?>
                        </button>
                        <button type="button" class="button button-secondary securehold-telemetry-dismiss" data-nonce="<?php echo esc_attr( wp_create_nonce( 'securehold_telemetry_dismiss' ) ); ?>">
                            <?php esc_html_e( 'No thanks', 'securehold-security-deposit-holds' ); ?>
                        </button>
                        <a href="https://secureholdwp.com/privacy-policy/" target="_blank" rel="noopener noreferrer" style="font-size: 13px;">
                            <?php esc_html_e( 'Learn more', 'securehold-security-deposit-holds' ); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // Click handling lives in assets/js/admin-telemetry-notice.js, loaded
        // only on SecureHold's own admin pages (see enqueue in class-securehold-wp-admin.php).
    }

    /**
     * AJAX: "Allow usage tracking".
     * Action: securehold_telemetry_optin
     */
    public function ajax_optin() {
        check_ajax_referer( 'securehold_telemetry_optin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'securehold-security-deposit-holds' ) ) );
        }

        $this->opt_in();

        wp_send_json_success( array( 'message' => __( 'Thank you — usage tracking is now on. You can turn it off any time from Settings.', 'securehold-security-deposit-holds' ) ) );
    }

    /**
     * AJAX: "No thanks" — dismisses the notice without enabling telemetry.
     * Action: securehold_telemetry_dismiss
     */
    public function ajax_dismiss() {
        check_ajax_referer( 'securehold_telemetry_dismiss', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'securehold-security-deposit-holds' ) ) );
        }

        update_option( self::OPT_NOTICE_DISMISSED, 'yes' );

        wp_send_json_success( array( 'message' => __( 'No problem — SecureHold works exactly the same either way.', 'securehold-security-deposit-holds' ) ) );
    }

    // =========================================================================
    // Heartbeat
    // =========================================================================

    /**
     * Ensure the cron schedule matches the current setting. Safe to call on
     * every admin_init: wp_next_scheduled() is a single indexed option read.
     *
     * @return void
     */
    public function maybe_sync_schedule() {
        $scheduled = wp_next_scheduled( self::CRON_HOOK );

        if ( self::is_enabled() ) {
            if ( ! $scheduled ) {
                wp_schedule_event( time(), 'daily', self::CRON_HOOK );
            }
            return;
        }

        if ( $scheduled ) {
            wp_unschedule_event( $scheduled, self::CRON_HOOK );
        }
    }

    /**
     * Send one heartbeat. Never throws, never surfaces anything to the
     * merchant: a network failure here is exactly as visible to the store
     * owner as no failure at all.
     *
     * @return void
     */
    public function send_heartbeat() {
        if ( ! self::is_enabled() ) {
            return;
        }

        $site_id = $this->site_id();
        if ( null === $site_id ) {
            // Opted in without ever generating an id should not happen
            // (opt_in() always creates one first), but this is the seam a
            // test can use to prove no request fires without one.
            return;
        }

        $endpoint = defined( 'SECUREHOLD_TELEMETRY_API_URL' ) ? SECUREHOLD_TELEMETRY_API_URL : '';
        if ( '' === $endpoint ) {
            return;
        }

        wp_remote_post( $endpoint, array(
            'timeout'   => 5, // Short on purpose — this must never be why an admin page feels slow.
            'blocking'  => false, // Fire-and-forget: no response is read, so a slow/unreachable
                                   // endpoint cannot add latency to the request that triggered this.
            'sslverify' => true,
            'headers'   => array( 'Content-Type' => 'application/json' ),
            'body'      => wp_json_encode( $this->build_payload( $site_id ) ),
        ) );
        // No error handling beyond this: wp_remote_post() with 'blocking' => false
        // never returns a WP_Error for a downstream failure, and even a
        // blocking failure would be silently discarded on purpose (see
        // class docblock). No retry: the next scheduled heartbeat is the retry.
    }

    /**
     * Everything a heartbeat carries.
     *
     * Deliberately hand-built rather than looping over $_POST-shaped data:
     * this is the one place that decides what the client will ever
     * transmit, so listing every field explicitly is what keeps a future
     * change from silently adding one.
     *
     * NEVER add: site_url, home_url, an IP, an email, a name, a license
     * key, a Stripe key/account id, an order id, a PaymentIntent/Customer
     * id, an amount, a currency, a cookie, or anything from the Support
     * Bundle. last_seen_at is deliberately absent — the server's own
     * receipt time is more trustworthy than a client clock.
     *
     * @param  string $site_id
     * @return array
     */
    protected function build_payload( $site_id ) {
        global $wp_version;

        return array(
            'site_id'             => $site_id,
            'securehold_version'  => defined( 'SECUREHOLD_VERSION' ) ? SECUREHOLD_VERSION : '',
            'wordpress_version'   => isset( $wp_version ) ? $wp_version : '',
            'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : '',
            'activated_at'        => $this->activated_at(),
            'telemetry_enabled_at' => get_option( self::OPT_ENABLED_AT, null ),
            'stripe_configured'   => $this->stripe_configured(),
            'first_hold_created'  => get_option( self::OPT_FIRST_HOLD, 'no' ) === 'yes',
        );
    }

    /**
     * The site's own first-activation timestamp, or null when it was never
     * recorded (an install that upgraded through an older version). Never
     * substituted with "now" — a guessed date would misrepresent every
     * upgrading site as newly activated.
     *
     * @return string|null
     */
    protected function activated_at() {
        $value = get_option( 'securehold_activated_at', '' );
        return ( '' !== $value ) ? $value : null;
    }

    /**
     * Whether a Stripe configuration compatible with SecureHold appears to
     * be present.
     *
     * Signal used: both Stripe keys for the active mode (test or live) are
     * non-empty in options — the exact same check the Support Bundle already
     * reports as 'stripe_credentials_configured' (see
     * class-securehold-wp-support-bundle.php::collect_configuration()).
     * Deliberately not the deeper 'stripe_credentials_valid' check: that one
     * calls Securehold_Stripe_Context::get(), which can reach the Stripe API.
     * A heartbeat must stay a pure local read, and "keys are present" is
     * already a fair proxy for "Stripe looks configured" without ever
     * talking to Stripe on telemetry's behalf.
     *
     * @return bool
     */
    protected function stripe_configured() {
        $keys = function_exists( 'securehold_get_stripe_keys' ) ? securehold_get_stripe_keys() : array();
        return ! empty( $keys['secret'] ) && ! empty( $keys['publishable'] );
    }

    // =========================================================================
    // First hold created
    // =========================================================================

    /**
     * Record, once and permanently, that this site has had at least one
     * hold actually authorized by Stripe.
     *
     * add_option() is a no-op when the key already exists, so however many
     * times 'securehold_hold_first_authorized' fires over the site's
     * lifetime, only the first call ever writes anything — no per-heartbeat
     * COUNT(*) against the holds table, and nothing here records which
     * order, hold, or amount triggered it.
     *
     * @return void
     */
    public function mark_first_hold_created() {
        add_option( self::OPT_FIRST_HOLD, 'yes', '', false );
    }
}
