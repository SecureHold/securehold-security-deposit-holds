<?php
/**
 * SecureHold Email — Admin: Hold Captured
 *
 * Sent to the site admin when funds are captured from a security deposit hold,
 * via either admin manual capture or Stripe webhook (payment_intent.succeeded).
 *
 * Default: disabled (opt-in) — many stores prefer not to receive notifications
 * for every capture action they themselves initiate.
 *
 * @package SecureHold
 * @since   4.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
    return;
}

class Securehold_Email_Admin_Hold_Captured extends WC_Email {

    /** @var object|array|null  Hold payload passed to trigger(). */
    protected $hold = null;

    const LEGACY_KEY = 'admin_hold_captured';

    // ── Constructor ───────────────────────────────────────────────────

    public function __construct() {
        $this->id             = 'securehold_admin_hold_captured';
        $this->customer_email = false;
        $this->title          = __( 'SecureHold: Admin – Hold Captured', 'securehold-security-deposit-holds' );
        $this->description    = __( 'Sent to admin when funds are captured from a security deposit hold.', 'securehold-security-deposit-holds' );
        $this->template_html  = '';
        $this->template_plain = '';

        $this->placeholders = array(
            '{site_title}'      => $this->get_blogname(),
            '{site_name}'       => $this->get_blogname(),
            '{order_number}'    => '',
            '{customer_name}'   => '',
            '{hold_amount}'     => '',
            '{captured_amount}' => '',
            '{hold_status}'     => '',
        );

        $this->enabled = 'yes';

        parent::__construct();

        $this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
    }

    // ── Default subject / heading ─────────────────────────────────────

    public function get_default_subject() {
        return __( 'Funds Captured – Order #{order_number}', 'securehold-security-deposit-holds' );
    }

    public function get_default_heading() {
        return __( 'Security Deposit Captured', 'securehold-security-deposit-holds' );
    }

    // ── Trigger ───────────────────────────────────────────────────────
    // Gate checks (master toggles, per-email enabled) are handled by
    // Securehold_Email_Manager::fire_email(). trigger() focuses on
    // order validation, anti-duplicate, placeholder setup, and sending.

    /**
     * @param int               $order_id
     * @param object|array|null $hold  May contain 'amount', 'captured_amount', and 'currency'.
     */
    public function trigger( $order_id, $hold = null ) {
        $this->setup_locale();

        $this->object = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $this->object ) {
            $this->restore_locale();
            return;
        }

        // ── Set up context ────────────────────────────────────────────
        $this->hold       = $hold;
        $hold_amount      = $this->resolve_raw_amount( $hold, 'amount' );
        $captured_amount  = $this->resolve_raw_amount( $hold, 'captured_amount' );
        $currency         = $this->resolve_currency( $hold );

        $hold_fmt     = $hold_amount    > 0 ? wc_price( $hold_amount,    array( 'currency' => $currency ) ) : '';
        $captured_fmt = $captured_amount > 0 ? wc_price( $captured_amount, array( 'currency' => $currency ) ) : $hold_fmt;

        $this->placeholders['{order_number}']    = $this->object->get_order_number();
        $this->placeholders['{customer_name}']   = $this->object->get_formatted_billing_full_name();
        $this->placeholders['{hold_amount}']     = $hold_fmt;
        $this->placeholders['{captured_amount}'] = $captured_fmt;
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

            if ( $sent && function_exists( 'securehold_log' ) ) {
                securehold_log( 'Email sent: admin_hold_captured', array(
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
            $overrides = array(
                '{hold_amount}'     => $this->placeholders['{hold_amount}'],
                '{captured_amount}' => $this->placeholders['{captured_amount}'],
            );
            $body = Securehold_Emails::replace_variables( $body, $this->object->get_id(), $overrides );
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

        return '<p>Funds have been captured from a security deposit hold.</p>
<h3>Order Details</h3>
<ul>
    <li><strong>Order:</strong> #{order_number}</li>
    <li><strong>Customer:</strong> {customer_name}</li>
    <li><strong>Original Hold:</strong> {hold_amount}</li>
    <li><strong>Amount Captured:</strong> {captured_amount}</li>
    <li><strong>Date:</strong> {order_date}</li>
</ul>
<p><a href="{order_url}">View order in admin</a></p>';
    }

    // ── Helpers ───────────────────────────────────────────────────────

    /**
     * Read a numeric field from the $hold payload by key.
     *
     * @param  object|array|null $hold
     * @param  string            $key  e.g. 'amount' or 'captured_amount'
     * @return float
     */
    private function resolve_raw_amount( $hold, $key ) {
        if ( is_object( $hold ) && isset( $hold->{ $key } ) ) {
            return floatval( $hold->{ $key } );
        }
        if ( is_array( $hold ) && isset( $hold[ $key ] ) ) {
            return floatval( $hold[ $key ] );
        }
        if ( $this->object && $key === 'amount' ) {
            $stored = $this->object->get_meta( '_securehold_hold_amount', true );
            return $stored ? floatval( $stored ) : 0;
        }
        return 0;
    }

    private function resolve_currency( $hold ) {
        if ( is_object( $hold ) && ! empty( $hold->currency ) ) {
            return $hold->currency;
        }
        if ( is_array( $hold ) && ! empty( $hold['currency'] ) ) {
            return $hold['currency'];
        }
        return $this->object ? $this->object->get_currency() : get_woocommerce_currency();
    }

    public function init_form_fields() {
        parent::init_form_fields();
        $this->form_fields['recipient'] = array(
            'title'       => __( 'Recipient(s)', 'securehold-security-deposit-holds' ),
            'type'        => 'text',
            'description' => sprintf(
                /* translators: %s is the admin email address */
                __( 'Enter recipients (comma separated). Defaults to %s.', 'securehold-security-deposit-holds' ),
                '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>'
            ),
            'placeholder' => '',
            'default'     => '',
            'desc_tip'    => true,
        );
    }
}
