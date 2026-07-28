<?php
/**
 * SecureHold Email — Deposit Failed (Customer)
 *
 * Sent to the customer when Stripe cannot authorize the security deposit hold.
 * Common causes: insufficient funds, expired card, card limit reached.
 *
 * Note: No anti-double-send deduplication for this event — if the admin
 * retries the hold and it fails again, the customer should be re-notified
 * so they can update their payment method.
 *
 * @package SecureHold
 * @since   4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

class Securehold_Email_Deposit_Failed extends WC_Email {

    /** @var object|array|null  Hold payload passed to trigger(). */
    protected $hold = null;

    const LEGACY_KEY = 'customer_hold_failed';

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct() {
        $this->id             = 'securehold_deposit_failed';
        $this->customer_email = true;
        $this->title          = __( 'SecureHold: Hold Failed', 'securehold-security-deposit-holds' );
        $this->description    = __( 'Sent to the customer when the security hold authorization fails.', 'securehold-security-deposit-holds' );
        $this->template_html  = '';
        $this->template_plain = '';

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
        $this->recipient = '';
    }

    // ── Default subject / heading ─────────────────────────────────────

    public function get_default_subject() {
        return __( 'Payment Authorization Failed – Order #{order_number}', 'securehold-security-deposit-holds' );
    }

    public function get_default_heading() {
        return __( 'Security Deposit Authorization Failed', 'securehold-security-deposit-holds' );
    }

    // ── Trigger ───────────────────────────────────────────────────────
    // Gate checks (master toggles, per-email enabled) are handled by
    // Securehold_Email_Manager::fire_email(). trigger() focuses on
    // order validation, anti-duplicate, placeholder setup, and sending.

    /**
     * @param int               $order_id
     * @param object|array|null $hold  May contain 'amount' and 'currency'.
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

        // Note: No anti-double-send guard for failed emails.
        // If the hold is retried and fails again, the customer should be notified.

        // ── Set up context ────────────────────────────────────────────
        $this->hold      = $hold;
        $this->recipient = $this->object->get_billing_email();

        $hold_amount = $this->resolve_hold_amount( $hold );

        $this->placeholders['{order_number}']   = $this->object->get_order_number();
        $this->placeholders['{customer_name}']  = $this->object->get_formatted_billing_full_name();
        $this->placeholders['{hold_amount}']    = $hold_amount;  // canonical; used by replace_variables() override
        $this->placeholders['{deposit_amount}'] = $hold_amount;  // backward-compat alias
        $this->placeholders['{hold_status}']    = __( 'Failed', 'securehold-security-deposit-holds' );
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

            if ( $sent && function_exists( 'securehold_log' ) ) {
                securehold_log( 'Email sent: deposit_failed', array(
                    'order_id'  => $order_id,
                    'recipient' => $this->recipient,
                ), 'info' );
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

        if ( $this->object && class_exists( 'Securehold_Emails' ) ) {
            // Pass the pre-resolved hold_amount so {hold_amount} never shows N/A.
            // For the failed event, _securehold_hold_amount meta is not written to
            // the order — the amount comes only from the $hold object passed to trigger().
            $overrides = array( '{hold_amount}' => $this->placeholders['{hold_amount}'] );
            $body      = Securehold_Emails::replace_variables( $body, $this->object->get_id(), $overrides );
            if ( $footer ) {
                $footer = Securehold_Emails::replace_variables( $footer, $this->object->get_id(), $overrides );
            }
        }

        $body = $this->format_string( $body );

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

    public function get_default_content() {
        if ( class_exists( 'Securehold_Emails' ) ) {
            return Securehold_Emails::get_default_template( self::LEGACY_KEY );
        }

        return '<p>Hi {customer_first_name},</p>
<p>We were unable to authorize a security deposit for order #{order_number}.</p>
<p>This can happen due to:</p>
<ul>
<li>Insufficient funds</li>
<li>Card limit reached</li>
<li>Expired or invalid card</li>
</ul>
<p>Please update your payment method or contact your bank, then reach out to us so we can retry.</p>
<p><a href="{order_url}">View your order</a></p>';
    }

    // ── Helpers ───────────────────────────────────────────────────────

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

    public function init_form_fields() {
        parent::init_form_fields();
    }
}
