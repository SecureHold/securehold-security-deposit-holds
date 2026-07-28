<?php
/**
 * SecureHold Email — Deposit Captured (Customer)
 *
 * Sent to the customer when funds are captured from the security deposit hold.
 * This can happen via two paths:
 *  - Admin manual capture (wp_ajax_securehold_capture_hold)
 *  - Stripe webhook (payment_intent.succeeded)
 *
 * Uses an anti-double-send guard: once the email fires for a given order the
 * SENT_META flag is set so a concurrent webhook + admin-action race cannot
 * send two emails for the same capture event.
 *
 * @package SecureHold
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Guard: WC_Email must be loaded (guaranteed on woocommerce_email_classes hook).
if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

class Securehold_Email_Deposit_Captured extends WC_Email {

    /** @var object|array|null  Hold payload passed to trigger(). */
    protected $hold = null;

    /** @var string  Key in securehold_email_* options for this email's template. */
    const LEGACY_KEY = 'customer_hold_captured';

    /** @var string  Order-meta key used for the anti-double-send guard. */
    const SENT_META = '_securehold_email_captured_sent';

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct() {
        $this->id             = 'securehold_deposit_captured';
        $this->customer_email = true;
        $this->title          = __( 'SecureHold: Hold Captured', 'securehold-security-deposit-holds' );
        $this->description    = __( 'Sent to the customer when funds are captured from the security hold.', 'securehold-security-deposit-holds' );

        // Content is generated in get_content_html() — no .php template file needed.
        $this->template_html  = '';
        $this->template_plain = '';

        // WC-style placeholders used by format_string() for subject / heading / body.
        $this->placeholders = array(
            '{site_title}'      => $this->get_blogname(),
            '{site_name}'       => $this->get_blogname(),
            '{order_number}'    => '',
            '{customer_name}'   => '',
            '{hold_amount}'     => '',
            '{captured_amount}' => '',
            '{hold_status}'     => '',
        );

        parent::__construct();

        // Recipient is set dynamically in trigger().
        $this->recipient = '';
    }

    // ── Default subject / heading ─────────────────────────────────────

    public function get_default_subject() {
        return __( 'Payment Charged – Order #{order_number}', 'securehold-security-deposit-holds' );
    }

    public function get_default_heading() {
        return __( 'Security Deposit Captured', 'securehold-security-deposit-holds' );
    }

    // ── Trigger ───────────────────────────────────────────────────────
    // Gate checks (master toggles, per-email enabled) are handled by
    // Securehold_Email_Manager::fire_email(). trigger() focuses on
    // order validation, anti-duplicate, placeholder setup, and sending.

    /**
     * @param int               $order_id  WC order ID.
     * @param object|array|null $hold      Hold data. Expected keys/props:
     *                                     'amount'          – original hold amount (major units)
     *                                     'captured_amount' – actual captured amount (major units)
     *                                     'currency'        – ISO 4217 currency code
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
        // Prevents a race between admin capture and webhook both firing.
        if ( $this->object->get_meta( self::SENT_META, true ) ) {
            $this->restore_locale();
            return;
        }

        // ── Resolve amounts ───────────────────────────────────────────
        $this->hold      = $hold;
        $this->recipient = $this->object->get_billing_email();

        $currency        = $this->resolve_currency( $hold );
        $hold_amount_raw = $this->resolve_raw_amount( $hold, 'amount' );
        $cap_amount_raw  = $this->resolve_raw_amount( $hold, 'captured_amount' );

        // Fall back: if captured_amount not provided, use the hold amount.
        if ( $cap_amount_raw <= 0 ) {
            $cap_amount_raw = $hold_amount_raw;
        }

        $hold_amount_fmt = $hold_amount_raw > 0
            ? wc_price( $hold_amount_raw, array( 'currency' => $currency ) )
            : '';

        $cap_amount_fmt = $cap_amount_raw > 0
            ? wc_price( $cap_amount_raw, array( 'currency' => $currency ) )
            : '';

        // Persist captured amount to order meta (HPOS-compatible) so that
        // replace_variables() can resolve {captured_amount} for this order.
        if ( $cap_amount_raw > 0 ) {
            $this->object->update_meta_data( '_securehold_captured_amount', $cap_amount_raw );
            $this->object->save();

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'securehold_log' ) ) {
                securehold_log( 'deposit_captured: saved captured_amount meta', array(
                    'order_id'       => $order_id,
                    'captured_raw'   => $cap_amount_raw,
                    'captured_fmt'   => $cap_amount_fmt,
                ), 'debug' );
            }
        }

        // Populate WC placeholders (used by format_string() in subject / heading / body).
        $this->placeholders['{order_number}']    = $this->object->get_order_number();
        $this->placeholders['{customer_name}']   = $this->object->get_formatted_billing_full_name();
        $this->placeholders['{hold_amount}']     = $hold_amount_fmt;
        $this->placeholders['{captured_amount}'] = $cap_amount_fmt ?: $hold_amount_fmt;
        $this->placeholders['{hold_status}']     = __( 'Captured', 'securehold-security-deposit-holds' );
        $this->placeholders['{site_name}']       = $this->get_blogname();
        $this->placeholders['{site_title}']      = $this->get_blogname();

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
                // Mark as sent to prevent duplicate from concurrent webhook / admin race.
                $this->object->update_meta_data( self::SENT_META, current_time( 'mysql' ) );
                $this->object->save();

                if ( function_exists( 'securehold_log' ) ) {
                    securehold_log( 'Email sent: deposit_captured', array(
                        'order_id'  => $order_id,
                        'recipient' => $this->recipient,
                    ), 'info' );
                }
            }
        }

        $this->restore_locale();
    }

    // ── Content ───────────────────────────────────────────────────────

    public function get_content_html() {
        Securehold_Email_Manager::set_current_email_id( $this->id );
        try {
        $settings = get_option( 'securehold_email_' . self::LEGACY_KEY, array() );
        $body     = ! empty( $settings['body'] ) ? wp_kses_post( $settings['body'] ) : $this->get_default_content();
        $footer   = ! empty( $settings['footer'] ) ? $settings['footer'] : '';

        // 1. SecureHold {variable} replacement — HPOS-safe, with pre-resolved overrides
        //    so {hold_amount} and {captured_amount} never fall back to N/A.
        if ( $this->object && class_exists( 'Securehold_Emails' ) ) {
            $overrides = array(
                '{hold_amount}'     => $this->placeholders['{hold_amount}'],
                '{captured_amount}' => $this->placeholders['{captured_amount}'],
            );
            $body = Securehold_Emails::replace_variables( $body, $this->object->get_id(), $overrides );
            if ( $footer ) {
                $footer = Securehold_Emails::replace_variables( $footer, $this->object->get_id(), $overrides );
            }
        }

        // 2. WC format_string() handles any remaining {placeholder} in body/footer.
        $body = $this->format_string( $body );

        // 3. Wrap in WC email header / footer templates (respects theme overrides).
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
            $overrides = array(
                '{hold_amount}'     => $this->placeholders['{hold_amount}'],
                '{captured_amount}' => $this->placeholders['{captured_amount}'],
            );
            $body = Securehold_Emails::replace_variables( $body, $this->object->get_id(), $overrides );
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

    public function get_default_content() {
        if ( class_exists( 'Securehold_Emails' ) ) {
            return Securehold_Emails::get_default_template( self::LEGACY_KEY );
        }

        return '<p>Hi {customer_first_name},</p>
<p>We have charged a payment from your security deposit for order #{order_number}.</p>
<p><strong>Amount charged:</strong> {captured_amount}</p>
<p>If you have any questions about this charge, please contact us.</p>
<p><a href="{order_url}">View your order</a></p>';
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Resolve a raw float amount from the hold payload by key name.
     *
     * @param  object|array|null $hold
     * @param  string            $key  Property/key to read ('amount' or 'captured_amount').
     * @return float
     */
    private function resolve_raw_amount( $hold, $key ) {
        if ( is_object( $hold ) && isset( $hold->{ $key } ) ) {
            return floatval( $hold->{ $key } );
        }
        if ( is_array( $hold ) && isset( $hold[ $key ] ) ) {
            return floatval( $hold[ $key ] );
        }
        return 0.0;
    }

    /**
     * Resolve the currency code from the hold payload, falling back to the order currency.
     *
     * @param  object|array|null $hold
     * @return string
     */
    private function resolve_currency( $hold ) {
        if ( is_object( $hold ) && ! empty( $hold->currency ) ) {
            return $hold->currency;
        }
        if ( is_array( $hold ) && ! empty( $hold['currency'] ) ) {
            return $hold['currency'];
        }
        return $this->object ? $this->object->get_currency() : get_woocommerce_currency();
    }

    // ── WC Settings fields ────────────────────────────────────────────

    public function init_form_fields() {
        parent::init_form_fields();
        // Standard WC fields (enabled, subject, heading, email_type) are inherited.
        // Body/content is managed in SecureHold › Settings › Notifications.
    }
}
