<?php
/**
 * SecureHold Email — Deposit Authorized (Customer)
 *
 * Sent to the customer when a security deposit hold is successfully
 * authorized on Stripe. Extends WC_Email so it:
 *  - appears in WooCommerce › Settings › Emails
 *  - uses WC's template system (theme overrides in woocommerce/emails/)
 *  - respects WC mailer hooks (logging, test emails from WC admin, etc.)
 *
 * WooCommerce is the SINGLE source of truth for enabled/subject/heading
 * (stored in woocommerce_securehold_deposit_authorized_settings).
 * Body template is stored in SecureHold option (securehold_email_customer_hold_created).
 *
 * @package SecureHold
 * @since   5.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Guard: WC_Email must be loaded (will be available on woocommerce_email_classes hook).
if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

class Securehold_Email_Deposit_Authorized extends WC_Email {

    /** @var object|array|null  DB hold row or ad-hoc object passed to trigger(). */
    protected $hold = null;

    /** @var string  Key in securehold_email_* options that holds this email's template. */
    const LEGACY_KEY = 'customer_hold_created';

    /** @var string  Order-meta key used for the anti-double-send guard. */
    const SENT_META = '_securehold_email_authorized_sent';

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct() {
        $this->id             = 'securehold_deposit_authorized';
        $this->customer_email = true;
        $this->title          = __( 'SecureHold: Hold Created', 'securehold-security-deposit-holds' );
        $this->description    = __( 'Sent to the customer when a security hold is successfully authorized.', 'securehold-security-deposit-holds' );

        // No .php template file — content generated in get_content_html().
        $this->template_html  = '';
        $this->template_plain = '';

        // WC-style placeholders for format_string() (subject / heading / body).
        $this->placeholders = array(
            '{site_title}'     => $this->get_blogname(),
            '{site_name}'      => $this->get_blogname(),
            '{order_number}'   => '',
            '{customer_name}'  => '',
            '{hold_amount}'    => '',   // canonical name — matches {hold_amount} in body templates
            '{deposit_amount}' => '',   // kept for backward compatibility with older saved bodies
            '{hold_status}'    => '',
        );

        parent::__construct();

        // Default recipient is empty; set dynamically in trigger().
        $this->recipient = '';
    }

    // ── Default subject / heading ─────────────────────────────────────

    public function get_default_subject() {
        return __( 'Security Hold Confirmed – Order #{order_number}', 'securehold-security-deposit-holds' );
    }

    public function get_default_heading() {
        return __( 'Security Deposit Authorized', 'securehold-security-deposit-holds' );
    }

    // ── Trigger ───────────────────────────────────────────────────────
    // Gate checks (master toggles, per-email enabled) are handled by
    // Securehold_Email_Manager::fire_email(). trigger() focuses on
    // order validation, anti-duplicate, placeholder setup, and sending.

    /**
     * @param int               $order_id  WC order ID.
     * @param object|array|null $hold      Hold data — needs 'amount' and 'currency' keys/props.
     */
    public function trigger( $order_id, $hold = null ) {
        $this->setup_locale();

        $this->object = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $this->object ) {
            $this->restore_locale();
            return;
        }

        // ── Guard 4: Skip cancelled orders ───────────────────────────
        if ( $this->object->has_status( 'cancelled' ) ) {
            $this->restore_locale();
            return;
        }

        // ── Guard 5: Anti-double-send ─────────────────────────────────
        if ( $this->object->get_meta( self::SENT_META, true ) ) {
            $this->restore_locale();
            return;
        }

        // ── Set up context ────────────────────────────────────────────
        $this->hold      = $hold;
        $this->recipient = $this->object->get_billing_email();

        $hold_amount = $this->resolve_hold_amount( $hold );

        $this->placeholders['{order_number}']   = $this->object->get_order_number();
        $this->placeholders['{customer_name}']  = $this->object->get_formatted_billing_full_name();
        $this->placeholders['{hold_amount}']    = $hold_amount;  // canonical; used by replace_variables() override
        $this->placeholders['{deposit_amount}'] = $hold_amount;  // backward-compat alias
        $this->placeholders['{hold_status}']    = __( 'Authorized', 'securehold-security-deposit-holds' );
        $this->placeholders['{site_name}']      = $this->get_blogname();
        $this->placeholders['{site_title}']     = $this->get_blogname();

        // ── Send ──────────────────────────────────────────────────────
        if ( $this->get_recipient() ) {
            $sent = $this->send(
                $this->get_recipient(),
                $this->get_subject(),
                $this->get_content(),
                $this->get_headers(),
                $this->get_attachments()
            );

            if ( $sent ) {
                // Mark as sent — prevents duplicate on re-trigger.
                $this->object->update_meta_data( self::SENT_META, current_time( 'mysql' ) );
                $this->object->save();

                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Email sent: deposit_authorized', array(
                        'order_id'  => $order_id,
                        'recipient' => $this->recipient,
                    ), 'info' );
                }
            }
        }

        $this->restore_locale();
    }

    // ── Content ───────────────────────────────────────────────────────

    /**
     * HTML content: reads body from SecureHold option, replaces variables,
     * then wraps in the WC email-header / email-footer templates.
     */
    public function get_content_html() {
        Securehold_Email_Manager::set_current_email_id( $this->id );
        try {
        $settings = get_option( 'securehold_email_' . self::LEGACY_KEY, array() );
        $body     = ! empty( $settings['body'] ) ? wp_kses_post( $settings['body'] ) : $this->get_default_content();
        $footer   = ! empty( $settings['footer'] ) ? $settings['footer'] : '';

        // 1. SecureHold {variable} replacement — HPOS-safe, with pre-resolved override
        //    so {hold_amount} never falls back to N/A even when the HPOS cache is stale.
        if ( $this->object && class_exists( 'Securehold_Emails' ) ) {
            $overrides = array( '{hold_amount}' => $this->placeholders['{hold_amount}'] );
            $body      = Securehold_Emails::replace_variables( $body, $this->object->get_id(), $overrides );
            if ( $footer ) {
                $footer = Securehold_Emails::replace_variables( $footer, $this->object->get_id(), $overrides );
            }
        }

        // 2. WC-style {placeholder} replacement for any remaining tokens in body/footer.
        $body = $this->format_string( $body );

        // 3. Wrap in WC email template (respects woocommerce/ theme overrides).
        ob_start();
        wc_get_template(
            'emails/email-header.php',
            array(
                'email_heading' => $this->get_heading(),
                'email'         => $this,
            )
        );
        echo wp_kses_post( wpautop( $body ) );
        if ( $footer ) {
            echo '<div style="margin-top:20px;padding-top:10px;border-top:1px solid #e0e0e0;">'
                . wp_kses_post( wpautop( $this->format_string( $footer ) ) )
                . '</div>';
        }
        // Always call email-footer.php to close the table structure from
        // email-header.php. The woocommerce_email_footer_text filter suppresses
        // the footer text when the "Hide WC global footer" toggle is on.
        wc_get_template( 'emails/email-footer.php', array( 'email' => $this ) );

        return ob_get_clean();
        } finally {
            Securehold_Email_Manager::set_current_email_id( null );
        }
    }

    public function get_content_plain() {
        Securehold_Email_Manager::set_current_email_id( $this->id );
        try {
        $settings = get_option( 'securehold_email_' . self::LEGACY_KEY, array() );
        $body     = ! empty( $settings['body'] ) ? $settings['body'] : $this->get_default_content();
        $footer   = ! empty( $settings['footer'] ) ? $settings['footer'] : '';

        if ( $this->object && class_exists( 'Securehold_Emails' ) ) {
            $overrides = array( '{hold_amount}' => $this->placeholders['{hold_amount}'] );
            $body      = Securehold_Emails::replace_variables( $body, $this->object->get_id(), $overrides );
            if ( $footer ) {
                $footer = Securehold_Emails::replace_variables( $footer, $this->object->get_id(), $overrides );
            }
        }

        $text = wordwrap( wp_strip_all_tags( $this->format_string( $body ) ), 70 );
        if ( $footer ) {
            $text .= "\n\n" . wordwrap( wp_strip_all_tags( $this->format_string( $footer ) ), 70 );
        }
        return $text;
        } finally {
            Securehold_Email_Manager::set_current_email_id( null );
        }
    }

    /**
     * Default body template (used when SecureHold option has no body stored).
     */
    public function get_default_content() {
        if ( class_exists( 'Securehold_Emails' ) ) {
            return Securehold_Emails::get_default_template( self::LEGACY_KEY );
        }

        return '<p>Hi {customer_first_name},</p>
<p>Your security deposit of {hold_amount} has been authorized for order #{order_number}.</p>
<p>No funds have been charged — this is a temporary hold only. It will be released automatically once your booking/rental is completed without issues.</p>
<p><a href="{order_url}">View your order</a></p>';
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Resolve a formatted hold-amount string from the various shapes $hold can take.
     * Falls back to the _securehold_hold_amount order meta if $hold is empty.
     *
     * @param  object|array|null $hold
     * @return string  Formatted price HTML (empty string if amount cannot be resolved).
     */
    private function resolve_hold_amount( $hold ) {
        $amount   = 0;
        $currency = $this->object ? $this->object->get_currency() : get_woocommerce_currency();

        if ( is_object( $hold ) && isset( $hold->amount ) ) {
            $amount   = floatval( $hold->amount );
            $currency = ! empty( $hold->currency ) ? $hold->currency : $currency;
        } elseif ( is_array( $hold ) && isset( $hold['amount'] ) ) {
            $amount   = floatval( $hold['amount'] );
            $currency = ! empty( $hold['currency'] ) ? $hold['currency'] : $currency;
        } elseif ( $this->object ) {
            $stored = $this->object->get_meta( '_securehold_hold_amount', true );
            if ( $stored ) {
                $amount = floatval( $stored );
            }
        }

        return $amount > 0 ? wc_price( $amount, array( 'currency' => $currency ) ) : '';
    }

    // ── WC Settings fields ────────────────────────────────────────────

    public function init_form_fields() {
        parent::init_form_fields();
        // Standard WC fields (enabled, subject, heading, email_type) are inherited.
        // Note: body/content is managed in SecureHold › Settings › Notifications.
    }
}
