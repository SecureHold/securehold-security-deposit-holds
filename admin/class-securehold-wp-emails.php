<?php
/**
 * SecureHold Email Settings UI Helper
 *
 * Provides data for the Notifications admin tab: email types, variables,
 * templates, and AJAX handlers for saving/previewing/testing.
 *
 * WooCommerce is the SINGLE source of truth for enabled/subject/heading.
 * Body/footer templates are stored in SecureHold options.
 *
 * Version: 5.1.0
 */

if (!defined('ABSPATH')) exit;

class Securehold_Emails {
    
    /**
     * Get all available email types
     */
    public static function get_email_types() {
        return array(
            // Customer emails
            'customer_hold_created' => array(
                'name'            => __( 'Customer: Hold Created', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to customer when a security hold is successfully created', 'securehold-security-deposit-holds' ),
                'recipient'       => 'customer',
                'default_subject' => __( 'Security Hold Confirmed - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'wc_id'           => 'securehold_deposit_authorized',
            ),
            'customer_hold_captured' => array(
                'name'            => __( 'Customer: Hold Captured', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to customer when funds are captured from the hold', 'securehold-security-deposit-holds' ),
                'recipient'       => 'customer',
                'default_subject' => __( 'Payment Charged - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'wc_id'           => 'securehold_deposit_captured',
            ),
            'customer_hold_released' => array(
                'name'            => __( 'Customer: Hold Released', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to customer when the hold is released without capture', 'securehold-security-deposit-holds' ),
                'recipient'       => 'customer',
                'default_subject' => __( 'Security Hold Released - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'wc_id'           => 'securehold_deposit_released',
            ),
            'customer_hold_failed' => array(
                'name'            => __( 'Customer: Hold Failed', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to customer when hold creation fails', 'securehold-security-deposit-holds' ),
                'recipient'       => 'customer',
                'default_subject' => __( 'Payment Authorization Failed - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'wc_id'           => 'securehold_deposit_failed',
            ),
            'customer_hold_extended' => array(
                'name'            => __( 'Customer: Hold Extended', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to customer when hold is extended', 'securehold-security-deposit-holds' ),
                'recipient'       => 'customer',
                'default_subject' => __( 'Security Hold Extended - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => false,
                'coming_soon'     => true,
            ),
            'customer_hold_expiring' => array(
                'name'            => __( 'Customer: Hold Expiring Soon', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to customer 24h before hold expires', 'securehold-security-deposit-holds' ),
                'recipient'       => 'customer',
                'default_subject' => __( 'Security Hold Expiring Soon - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => false,
                'coming_soon'     => true,
            ),

            // Admin emails
            'admin_hold_created' => array(
                'name'            => __( 'Admin: New Hold Created', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to admin when a new hold is created', 'securehold-security-deposit-holds' ),
                'recipient'       => 'admin',
                'default_subject' => __( 'New Security Hold - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => false,
                'wc_id'           => 'securehold_admin_hold_created',
            ),
            'admin_hold_failed' => array(
                'name'            => __( 'Admin: Hold Creation Failed', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to admin when hold creation fails', 'securehold-security-deposit-holds' ),
                'recipient'       => 'admin',
                'default_subject' => __( 'Hold Creation Failed - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'wc_id'           => 'securehold_admin_hold_failed',
            ),
            'admin_extension_failed' => array(
                'name'            => __( 'Admin: Extension Not Supported', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to admin when card does not support hold extensions', 'securehold-security-deposit-holds' ),
                'recipient'       => 'admin',
                'default_subject' => __( 'Hold Extension Not Available - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'coming_soon'     => true,
            ),
            'admin_hold_expiring' => array(
                'name'            => __( 'Admin: Hold Expiring Soon', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to admin 24h before hold expires', 'securehold-security-deposit-holds' ),
                'recipient'       => 'admin',
                'default_subject' => __( 'Action Required: Hold Expiring - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => true,
                'coming_soon'     => true,
            ),
            'admin_hold_captured' => array(
                'name'            => __( 'Admin: Hold Captured', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to admin when funds are captured', 'securehold-security-deposit-holds' ),
                'recipient'       => 'admin',
                'default_subject' => __( 'Funds Captured - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => false,
                'wc_id'           => 'securehold_admin_hold_captured',
            ),
            'admin_hold_released' => array(
                'name'            => __( 'Admin: Hold Released', 'securehold-security-deposit-holds' ),
                'description'     => __( 'Sent to admin when hold is released', 'securehold-security-deposit-holds' ),
                'recipient'       => 'admin',
                'default_subject' => __( 'Hold Released - Order #{order_number}', 'securehold-security-deposit-holds' ),
                'default_enabled' => false,
                'wc_id'           => 'securehold_admin_hold_released',
            ),
        );
    }

    /**
     * Map a SecureHold legacy_key to the WC_Email::$id for that email.
     * Returns null for emails that have no WC_Email class (coming-soon types).
     *
     * @param  string      $legacy_key  e.g. 'customer_hold_created'
     * @return string|null              e.g. 'securehold_deposit_authorized' or null
     */
    public static function get_wc_id_for_legacy_key( $legacy_key ) {
        $types = self::get_email_types();
        if ( isset( $types[ $legacy_key ]['wc_id'] ) ) {
            return $types[ $legacy_key ]['wc_id'];
        }
        return null;
    }
    
    /**
     * Normalize any truthy/falsy enabled value to canonical 'yes' or 'no'.
     * Accepts: true, false, 1, 0, 'true', 'false', 'yes', 'no', 'on', 'off', '1', '0'.
     *
     * @param  mixed  $value
     * @return string 'yes' | 'no'
     */
    public static function normalize_enabled( $value ) {
        $v = strtolower( trim( (string) $value ) );
        return in_array( $v, array( 'true', '1', 'yes', 'on' ), true ) ? 'yes' : 'no';
    }

    /**
     * Get available variables for email templates
     */
    public static function get_available_variables() {
        return array(
            'order' => array(
                'label' => __('Order Information', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{order_number}'    => __('Order number', 'securehold-security-deposit-holds'),
                    '{order_date}'      => __('Order date', 'securehold-security-deposit-holds'),
                    '{order_total}'     => __('Order total', 'securehold-security-deposit-holds'),
                    '{order_status}'    => __('Order status', 'securehold-security-deposit-holds'),
                    '{order_url}'       => __('Link to view order (customer)', 'securehold-security-deposit-holds'),
                    '{order_admin_url}' => __('Link to edit order (admin)', 'securehold-security-deposit-holds'),
                )
            ),
            'customer' => array(
                'label' => __('Customer Information', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{customer_name}' => __('Customer full name', 'securehold-security-deposit-holds'),
                    '{customer_first_name}' => __('Customer first name', 'securehold-security-deposit-holds'),
                    '{customer_last_name}' => __('Customer last name', 'securehold-security-deposit-holds'),
                    '{customer_email}' => __('Customer email', 'securehold-security-deposit-holds'),
                )
            ),
            'hold' => array(
                'label' => __('Hold Information', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{hold_amount}' => __('Hold amount', 'securehold-security-deposit-holds'),
                    '{hold_currency}' => __('Hold currency', 'securehold-security-deposit-holds'),
                    '{hold_created_date}' => __('Hold creation date', 'securehold-security-deposit-holds'),
                    '{hold_expiry_date}' => __('Hold expiry date', 'securehold-security-deposit-holds'),
                    '{hold_status}' => __('Hold status', 'securehold-security-deposit-holds'),
                    '{captured_amount}' => __('Captured amount (if applicable)', 'securehold-security-deposit-holds'),
                )
            ),
            'site' => array(
                'label' => __('Site Information', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{site_name}' => __('Site name', 'securehold-security-deposit-holds'),
                    '{site_url}' => __('Site URL', 'securehold-security-deposit-holds'),
                    '{admin_email}' => __('Admin email', 'securehold-security-deposit-holds'),
                )
            ),
        );
    }
    
    /**
     * Get email settings for a given email type.
     *
     * WooCommerce is the SINGLE source of truth for enabled/subject/heading.
     * Body and footer are stored in securehold_email_{type} option.
     *
     * @param  string     $email_type  e.g. 'customer_hold_created'
     * @return array|null
     */
    public static function get_email_settings( $email_type ) {
        $defaults = self::get_email_types();

        if ( ! isset( $defaults[ $email_type ] ) ) {
            return null;
        }

        $config = $defaults[ $email_type ];

        // ── Read enabled/subject/heading from WC options ─────────────
        $wc_id       = self::get_wc_id_for_legacy_key( $email_type );
        $wc_settings = $wc_id ? get_option( 'woocommerce_' . $wc_id . '_settings', array() ) : array();

        // Enabled: WC stores as 'yes'/'no'. Convert to bool for the UI.
        $default_enabled = $config['default_enabled'];
        if ( isset( $wc_settings['enabled'] ) ) {
            $enabled = ( 'yes' === $wc_settings['enabled'] );
        } else {
            $enabled = $default_enabled;
        }

        // Effective enabled: layer master toggles on top so the sidebar
        // indicator reflects whether the email will actually fire.
        if ( $enabled ) {
            if ( get_option( 'securehold_notifications_enabled', 'yes' ) !== 'yes' ) {
                $enabled = false;
            } elseif ( 'customer' === $config['recipient']
                       && get_option( 'securehold_notifications_client_enabled', 'yes' ) !== 'yes' ) {
                $enabled = false;
            } elseif ( 'admin' === $config['recipient']
                       && get_option( 'securehold_notifications_admin_enabled', 'yes' ) !== 'yes' ) {
                $enabled = false;
            }
        }

        // Subject/heading: from WC option, falling back to defaults.
        $subject = ! empty( $wc_settings['subject'] ) ? $wc_settings['subject'] : $config['default_subject'];
        $heading = ! empty( $wc_settings['heading'] ) ? $wc_settings['heading'] : '';

        // Recipient: for admin emails, may be overridden in WC settings.
        $recipient = '';
        if ( $config['recipient'] === 'admin' && ! empty( $wc_settings['recipient'] ) ) {
            $recipient = $wc_settings['recipient'];
        }

        // ── Read body/footer from SecureHold option ──────────────────
        $sh_option = get_option( 'securehold_email_' . $email_type, array() );
        $body      = ! empty( $sh_option['body'] )   ? $sh_option['body']   : self::get_default_template( $email_type );
        $footer    = ! empty( $sh_option['footer'] ) ? $sh_option['footer'] : '';

        return array(
            'enabled'   => $enabled,
            'subject'   => $subject,
            'heading'   => $heading,
            'body'      => $body,
            'footer'    => $footer,
            'recipient' => $recipient,
        );
    }
    
    /**
     * Save email settings — WooCommerce option is the SINGLE source of truth.
     *
     * Performs a safe merge: reads existing WC option, overlays new values,
     * writes back, then reads again to verify persistence.
     *
     * @param  string $email_type  Legacy key, e.g. 'customer_hold_created'.
     * @param  array  $settings    Keys: enabled (bool), subject, heading, body,
     *                             footer, and optionally recipient.
     * @return bool  True when the WC option read-back matches what was written.
     *               Always true when there is no WC_Email class for this type
     *               (coming-soon emails only have body/footer).
     */
    public static function save_email_settings( $email_type, $settings ) {
        $wc_id     = self::get_wc_id_for_legacy_key( $email_type );
        $wc_ok     = true;   // optimistic; set false on verified mismatch

        // ── Write enabled/subject/heading/recipient to WC option ─────
        if ( $wc_id ) {
            $wc_option_name = 'woocommerce_' . $wc_id . '_settings';

            // Safe merge: preserve any keys WooCommerce already stored
            // (e.g. email_type, additional_content) that we don't manage.
            $current = get_option( $wc_option_name, array() );

            $want_enabled = ( ! empty( $settings['enabled'] ) ) ? 'yes' : 'no';
            $want_subject = sanitize_text_field( $settings['subject'] );
            $want_heading = sanitize_text_field( $settings['heading'] );

            $new = array_merge( $current, array(
                'enabled' => $want_enabled,
                'subject' => $want_subject,
                'heading' => $want_heading,
            ) );

            // Recipient: validate comma-separated addresses (admin emails only).
            if ( isset( $settings['recipient'] ) ) {
                $validated = array();
                foreach ( explode( ',', sanitize_text_field( $settings['recipient'] ) ) as $addr ) {
                    $addr = trim( $addr );
                    if ( is_email( $addr ) ) {
                        $validated[] = $addr;
                    }
                }
                $new['recipient'] = implode( ', ', $validated );
            }

            // update_option returns false on no-op (value unchanged) OR error.
            // Both cases are handled by the read-back below.
            update_option( $wc_option_name, $new );

            // ── Read-back: definitive verification ───────────────────
            $readback = get_option( $wc_option_name, array() );

            $enabled_ok = isset( $readback['enabled'] ) && $readback['enabled'] === $want_enabled;
            $subject_ok = isset( $readback['subject'] ) && $readback['subject'] === $want_subject;
            $wc_ok      = $enabled_ok && $subject_ok;

            // Always log — 'error' level on mismatch so it appears even without WP_DEBUG.
            $log_level = $wc_ok ? 'debug' : 'error';
            if ( ( 'error' === $log_level || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) )
                && function_exists( 'securehold_log' ) ) {
                securehold_log( 'save_email_settings: WC option write', array(
                    'email_type'       => $email_type,
                    'wc_option'        => $wc_option_name,
                    'wrote_enabled'    => $want_enabled,
                    'wrote_subject'    => $want_subject,
                    'wrote_heading'    => $want_heading,
                    'readback_enabled' => $readback['enabled'] ?? '(missing)',
                    'readback_subject' => $readback['subject'] ?? '(missing)',
                    'enabled_ok'       => $enabled_ok ? 'YES' : 'NO',
                    'subject_ok'       => $subject_ok ? 'YES' : 'NO',
                    'verified'         => $wc_ok ? 'PASS' : 'FAIL — DB write did not persist',
                ), $log_level );
            }
        }

        // ── Write body/footer to SecureHold option ───────────────────
        $sh_option           = get_option( 'securehold_email_' . $email_type, array() );
        $sh_option['body']   = self::sanitize_email_content( $settings['body'] );
        $sh_option['footer'] = self::sanitize_email_content( $settings['footer'] );
        update_option( 'securehold_email_' . $email_type, $sh_option );

        return $wc_ok;
    }
    
    /**
     * Get default template for email type
     */
    public static function get_default_template($email_type) {
        $templates = array(
            'customer_hold_created' => '
                <p>Hi {customer_first_name},</p>
                <p>Thank you for your order! We have placed a temporary security hold on your payment method.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order Number:</strong> {order_number}</li>
                    <li><strong>Order Total:</strong> {order_total}</li>
                    <li><strong>Security Hold:</strong> {hold_amount}</li>
                </ul>
                <p><strong>Important:</strong> No money has been charged yet. We have only placed a temporary hold of {hold_amount} on your card as a security guarantee.</p>
                <p>This hold will be automatically released after your rental/booking is completed without any issues.</p>
                <p><a href="{order_url}">View your order</a></p>
            ',
            
            'customer_hold_captured' => '
                <p>Hi {customer_first_name},</p>
                <p>We have captured funds from the security hold on your order #{order_number}.</p>
                <h3>Payment Details</h3>
                <ul>
                    <li><strong>Amount Charged:</strong> {captured_amount}</li>
                    <li><strong>Order Number:</strong> {order_number}</li>
                    <li><strong>Date:</strong> {order_date}</li>
                </ul>
                <p>If you have any questions about this charge, please contact us.</p>
                <p><a href="{order_url}">View your order</a></p>
            ',
            
            'customer_hold_released' => '
                <p>Hi {customer_first_name},</p>
                <p>Good news! The security hold on your order has been released.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order Number:</strong> {order_number}</li>
                    <li><strong>Hold Amount:</strong> {hold_amount}</li>
                </ul>
                <p>The blocked amount of {hold_amount} is now available in your account again. No charge was made.</p>
                <p>Thank you for your business!</p>
            ',
            
            'customer_hold_failed' => '
                <p>Hi {customer_first_name},</p>
                <p>We were unable to authorize your payment for order #{order_number}.</p>
                <p>This could be due to:</p>
                <ul>
                    <li>Insufficient funds</li>
                    <li>Card limit reached</li>
                    <li>Card expired or invalid</li>
                </ul>
                <p>Please update your payment method or contact your bank for more information.</p>
                <p><a href="{order_url}">Update payment method</a></p>
            ',
            
            'admin_hold_failed' => '
                <p>A security hold creation failed for order #{order_number}.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order:</strong> #{order_number}</li>
                    <li><strong>Customer:</strong> {customer_name}</li>
                    <li><strong>Amount:</strong> {hold_amount}</li>
                    <li><strong>Date:</strong> {order_date}</li>
                </ul>
                <p><strong>Action Required:</strong> Please check the order and contact the customer if needed.</p>
                <p><a href="{order_url}">View order in admin</a></p>
            ',
            
            'admin_extension_failed' => '
                <p>The security hold for order #{order_number} cannot be extended.</p>
                <h3>Details</h3>
                <ul>
                    <li><strong>Order:</strong> #{order_number}</li>
                    <li><strong>Customer:</strong> {customer_name}</li>
                    <li><strong>Hold Amount:</strong> {hold_amount}</li>
                    <li><strong>Created:</strong> {hold_created_date}</li>
                    <li><strong>Expires:</strong> {hold_expiry_date}</li>
                </ul>
                <p><strong>Reason:</strong> The customer\'s card does not support incremental authorizations.</p>
                <p><strong>Action Required:</strong> You must capture or release this hold within 7 days, or contact the customer for an alternative payment method.</p>
                <p><a href="{order_url}">Manage hold</a></p>
            ',
            
            'admin_hold_expiring' => '
                <p><strong>Urgent:</strong> A security hold is expiring soon.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order:</strong> #{order_number}</li>
                    <li><strong>Customer:</strong> {customer_name}</li>
                    <li><strong>Hold Amount:</strong> {hold_amount}</li>
                    <li><strong>Expires:</strong> {hold_expiry_date}</li>
                </ul>
                <p><strong>Action Required:</strong> Please capture or release this hold before it expires, or the funds will be automatically released.</p>
                <p><a href="{order_admin_url}">Take action now &rarr;</a></p>
            ',

            'admin_hold_created' => '
                <p>A new security deposit hold has been successfully created.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order:</strong> <a href="{order_admin_url}">#{order_number}</a></li>
                    <li><strong>Customer:</strong> {customer_name} &lt;{customer_email}&gt;</li>
                    <li><strong>Hold Amount:</strong> {hold_amount}</li>
                    <li><strong>Order Date:</strong> {order_date}</li>
                </ul>
                <p><a href="{order_admin_url}">View order in admin &rarr;</a></p>
            ',

            'admin_hold_captured' => '
                <p>Funds have been captured from a security deposit hold.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order:</strong> <a href="{order_admin_url}">#{order_number}</a></li>
                    <li><strong>Customer:</strong> {customer_name} &lt;{customer_email}&gt;</li>
                    <li><strong>Original Hold:</strong> {hold_amount}</li>
                    <li><strong>Amount Captured:</strong> {captured_amount}</li>
                    <li><strong>Date:</strong> {order_date}</li>
                </ul>
                <p><a href="{order_admin_url}">View order in admin &rarr;</a></p>
            ',

            'admin_hold_released' => '
                <p>A security deposit hold has been released without capture.</p>
                <h3>Order Details</h3>
                <ul>
                    <li><strong>Order:</strong> <a href="{order_admin_url}">#{order_number}</a></li>
                    <li><strong>Customer:</strong> {customer_name} &lt;{customer_email}&gt;</li>
                    <li><strong>Released Amount:</strong> {hold_amount}</li>
                    <li><strong>Date:</strong> {order_date}</li>
                </ul>
                <p>No charge was made to the customer.</p>
                <p><a href="{order_admin_url}">View order in admin &rarr;</a></p>
            ',
        );

        return isset($templates[$email_type]) ? $templates[$email_type] : '';
    }
    
    /**
     * Replace {variable} placeholders in an email template.
     *
     * HPOS-compatible: reads all order meta via WC_Order::get_meta() instead of
     * get_post_meta(), which returns empty data when HPOS is active (order meta
     * is stored in wp_wc_orders_meta, not wp_postmeta).
     *
     * @param string $template   Raw template string with {placeholder} tokens.
     * @param int    $order_id   WC order ID.
     * @param array  $overrides  Optional map of '{placeholder}' => 'already-formatted value'.
     *                           Overrides take priority over meta reads. Use this to pass
     *                           amounts already resolved by the email class trigger() method —
     *                           prevents N/A when meta is not yet persisted (e.g. failed event)
     *                           or when the HPOS cache has not been refreshed.
     * @return string
     */
    public static function replace_variables( $template, $order_id, $overrides = array() ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return $template;
        }

        // ── Read hold info via HPOS-compatible API ────────────────────
        // get_meta() works for both classic (wp_postmeta) and HPOS (wp_wc_orders_meta).
        $hold_amount_raw     = $order->get_meta( '_securehold_hold_amount', true );
        $hold_currency       = $order->get_currency();
        $hold_created_raw    = $order->get_meta( '_securehold_hold_created_date', true );
        $hold_expiry_raw     = $order->get_meta( '_securehold_hold_expiry_date', true );
        $captured_amount_raw = $order->get_meta( '_securehold_captured_amount', true );
        $hold_status_raw     = $order->get_meta( '_securehold_hold_status', true );

        // Format monetary amounts with the order's currency.
        $hold_amount_fmt     = $hold_amount_raw
            ? wc_price( floatval( $hold_amount_raw ), array( 'currency' => $hold_currency ) )
            : '';
        $captured_amount_fmt = $captured_amount_raw
            ? wc_price( floatval( $captured_amount_raw ), array( 'currency' => $hold_currency ) )
            : '';

        // ── Build replacement map ─────────────────────────────────────
        $date_created = $order->get_date_created();

        $replacements = array(
            // Order
            '{order_number}' => $order->get_order_number(),
            '{order_date}'   => $date_created ? $date_created->date( 'F j, Y' ) : '',
            '{order_total}'  => wc_price( $order->get_total() ),
            '{order_status}' => wc_get_order_status_name( $order->get_status() ),
            '{order_url}'       => $order->get_view_order_url(),
            '{order_admin_url}' => function_exists( 'wc_get_order_admin_url' )
                ? wc_get_order_admin_url( $order->get_id() )
                : ( get_edit_post_link( $order->get_id(), 'raw' )
                    ?: admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $order->get_id() ) ),

            // Customer
            '{customer_name}'       => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{customer_first_name}' => $order->get_billing_first_name(),
            '{customer_last_name}'  => $order->get_billing_last_name(),
            '{customer_email}'      => $order->get_billing_email(),

            // Hold — formatted with currency; 'N/A' only when truly no source exists.
            '{hold_amount}'       => $hold_amount_fmt     ?: __( 'Not available', 'securehold-security-deposit-holds' ),
            '{hold_currency}'     => $hold_currency,
            '{hold_created_date}' => $hold_created_raw ? gmdate( 'F j, Y', strtotime( $hold_created_raw ) ) : __( 'Not available', 'securehold-security-deposit-holds' ),
            '{hold_expiry_date}'  => $hold_expiry_raw  ? gmdate( 'F j, Y', strtotime( $hold_expiry_raw ) )  : __( 'Not available', 'securehold-security-deposit-holds' ),
            '{hold_status}'       => $hold_status_raw  ?: '',
            '{captured_amount}'   => $captured_amount_fmt ?: __( 'Not available', 'securehold-security-deposit-holds' ),

            // Site
            '{site_name}'   => get_bloginfo( 'name' ),
            '{site_url}'    => home_url(),
            '{admin_email}' => get_option( 'admin_email' ),
        );

        // ── Apply caller-supplied overrides ───────────────────────────
        // Overrides are pre-resolved, formatted values from the email class trigger().
        // They take priority over the meta read above, guaranteeing the correct value
        // even when meta hasn't been saved yet (e.g. deposit_failed) or when the
        // HPOS object cache hasn't been refreshed after update_meta_data() + save().
        foreach ( $overrides as $placeholder => $value ) {
            if ( array_key_exists( $placeholder, $replacements ) && '' !== (string) $value ) {
                $replacements[ $placeholder ] = $value;
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'securehold_log' ) ) {
            securehold_log( 'replace_variables: resolving amounts', array(
                'order_id'            => $order_id,
                'hold_amount_raw'     => $hold_amount_raw,
                'captured_amount_raw' => $captured_amount_raw,
                'overrides_applied'   => array_keys( $overrides ),
                'hold_amount_final'   => $replacements['{hold_amount}'],
                'captured_final'      => $replacements['{captured_amount}'],
            ), 'debug' );
        }

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }
    
    /**
     * Legacy send_email method — DEPRECATED.
     * Use Securehold_Email_Manager::fire_email() instead, which routes
     * through WooCommerce WC_Email classes.
     * Kept temporarily for backward compatibility.
     *
     * @deprecated 5.0.0
     */
    public static function send_email( $email_type, $order_id, $additional_data = array() ) {
        $wc_id = self::get_wc_id_for_legacy_key( $email_type );
        if ( $wc_id && class_exists( 'Securehold_Email_Manager' ) ) {
            return Securehold_Email_Manager::fire_email( $wc_id, $order_id );
        }
        return false;
    }
    
    /**
     * Build email HTML with template
     */
    private static function build_email_html($heading, $body, $footer) {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
                .email-wrapper { max-width: 600px; margin: 20px auto; background: white; }
                .email-header { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); color: white; padding: 30px; text-align: center; }
                .email-header h1 { margin: 0; font-size: 24px; }
                .email-body { padding: 30px; }
                .email-body h3 { color: #2563eb; margin-top: 20px; }
                .email-body ul { background: #f9fafb; padding: 20px; border-radius: 8px; }
                .email-body a { color: #2563eb; text-decoration: none; font-weight: 600; }
                .email-footer { background: #f9fafb; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="email-wrapper">
                <?php if ($heading) : ?>
                <div class="email-header">
                    <h1><?php echo esc_html($heading); ?></h1>
                </div>
                <?php endif; ?>
                
                <div class="email-body">
                    <?php echo wp_kses_post( wpautop($body) ); ?>
                </div>
                
                <?php if ($footer) : ?>
                <div class="email-footer">
                    <?php echo wp_kses_post( wpautop($footer) ); ?>
                </div>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Register AJAX handlers
     */
    public static function register_ajax_handlers() {
        add_action( 'wp_ajax_securehold_send_test_email',           array( __CLASS__, 'ajax_send_test_email' ) );
        add_action( 'wp_ajax_securehold_save_email_settings',       array( __CLASS__, 'ajax_save_email_settings' ) );
        add_action( 'wp_ajax_securehold_save_email_enabled',        array( __CLASS__, 'ajax_save_email_enabled' ) );
        add_action( 'wp_ajax_securehold_reset_email_to_default',    array( __CLASS__, 'ajax_reset_email_to_default' ) );
        add_action( 'wp_ajax_securehold_get_email_preview',         array( __CLASS__, 'ajax_get_email_preview' ) );
        add_action( 'wp_ajax_securehold_preview_email',             array( __CLASS__, 'ajax_preview_email' ) );
        add_action( 'wp_ajax_securehold_save_notification_master',  array( __CLASS__, 'ajax_save_notification_master' ) );

        // ── Issue 3: Email centering fix ─────────────────────────────────────
        // Register plugin-level email template overrides so both preview AJAX
        // and real sends use the correct 3-column centering wrapper.
        // Priority 5 so it fires after themes (default 10) are checked first —
        // if the active theme has its own override we honour it.
        // Change priority to 20 to force the plugin template over the theme.
        add_filter( 'woocommerce_locate_template', array( __CLASS__, 'locate_email_template' ), 5, 3 );

        // Defense-in-depth: also post-process every outgoing WC email body so
        // the centering is correct even when a theme overrides the templates.
        add_filter( 'woocommerce_mail_content', array( __CLASS__, 'fix_outer_wrapper_centering' ) );

        // Task 1 — footer suppression: return '' for the WC global footer text
        // when the "Hide WC global footer" toggle is on AND the email currently
        // being rendered is a SecureHold email (tracked via set_current_email_id).
        add_filter( 'woocommerce_email_footer_text', array( __CLASS__, 'maybe_suppress_email_footer_text' ) );
    }

    /**
     * Register the plugin's templates/emails/ directory with WooCommerce.
     *
     * Only intercepts email-header.php and email-footer.php — the templates
     * that contain the outer_wrapper centering structure.  All other WC
     * templates are loaded from the active theme or WC core as normal.
     *
     * @param  string $template       Full path resolved so far.
     * @param  string $template_name  Relative template name (e.g. 'emails/email-header.php').
     * @param  string $template_path  WC template path (not used here).
     * @returns string
     */
    /**
     * Use the plugin's templates/emails/{file} ONLY when the active theme has
     * no WooCommerce override for that template.
     *
     * Load priority: theme woocommerce/ → plugin templates/ → WC core.
     * This respects theme customisations and only fills the gap when the
     * theme has not overridden the template itself.
     *
     * @param  string $template       Full resolved path (theme or WC core).
     * @param  string $template_name  Relative name, e.g. 'emails/email-header.php'.
     * @param  string $template_path  WC's own template path (unused here).
     * @returns string
     */
    public static function locate_email_template( $template, $template_name, $template_path ) {
        static $overridden = array( 'emails/email-header.php', 'emails/email-footer.php' );

        if ( ! in_array( $template_name, $overridden, true ) ) {
            return $template;
        }

        // If the active theme already provides an override, leave it alone.
        $wc_template_path = get_option( 'woocommerce_template_path', 'woocommerce/' );
        $theme_file       = locate_template( array(
            trailingslashit( $wc_template_path ) . $template_name,
            $template_name,
        ) );
        if ( $theme_file ) {
            return $template; // theme wins
        }

        // No theme override — use the plugin's version.
        $plugin_tpl = SECUREHOLD_PLUGIN_DIR . 'templates/' . $template_name;
        if ( file_exists( $plugin_tpl ) ) {
            return $plugin_tpl;
        }

        return $template;
    }

    /**
     * Defense-in-depth: ensure the outer_wrapper has the right spacer <td>.
     *
     * Fires via the `woocommerce_mail_content` filter for every outgoing
     * WC email.  When the plugin's own templates are in use this is a no-op
     * (the right spacer is already in the HTML).  When a theme template omits
     * the right spacer, this adds it.
     *
     * Strategy: the outer_wrapper closing sequence is always
     *   </td>   ← content column
     *   </tr>   ← outer row
     *   </table> ← outer_wrapper (the LAST </table> before </div id="wrapper">)
     *
     * We detect it by looking for </td></tr></table> that is directly followed
     * by either a footer-text <table> or </div> (end of the #wrapper div).
     * The `1` limit in preg_replace ensures only the first match is changed.
     *
     * @param  string $html  Full email HTML from WC mailer.
     * @returns string
     */
    public static function fix_outer_wrapper_centering( $html ) {
        if ( strpos( $html, 'outer_wrapper' ) === false ) {
            return $html; // not a WC-style email — skip
        }

        // Already has a right spacer (plugin templates or already-correct theme).
        // Check heuristically: if HTML already has 3 <td> in the outer row, skip.
        if ( preg_match( '/id=["\']outer_wrapper["\'][^>]*>[\s\S]{0,200}<td[^>]*>&nbsp;<\/td>[\s\S]{0,200}<td[^>]*>[\s\S]{0,200}<td[^>]*>&nbsp;<\/td>/i', $html ) ) {
            return $html;
        }

        // Insert the right spacer: match </td></tr></table> that is followed
        // immediately by either a new <table (footer text) or </div> (wrapper end).
        $fixed = preg_replace(
            '~(</td>)([ \t]*\r?\n[ \t]*</tr>[ \t]*\r?\n[ \t]*</table>[ \t]*\r?\n[ \t]*(?=<table|</div>))~i',
            '$1<td>&nbsp;</td>$2',
            $html,
            1  // replace only the first match = the outer_wrapper row close
        );

        return ( null !== $fixed ) ? $fixed : $html;
    }

    /**
     * AJAX: Render a body preview through the real WC email wrapper.
     *
     * No DB writes — variables are replaced against a real order when one
     * is available, then the result is wrapped in WC email-header/footer
     * templates and returned as full HTML for an iframe srcdoc.
     *
     * POST fields:
     *   nonce      – securehold_emails_nonce
     *   email_type – legacy key  e.g. 'customer_hold_created'
     *   body       – raw HTML body to preview (wp_kses_post sanitized)
     */
    // ── Task 4: Strict email-content sanitizer ────────────────────────────────

    /**
     * Sanitize email body/footer HTML with a strict allowlist.
     *
     * Allowed tags: p br strong em u ul ol li a span h1-h6.
     * Allowed attributes (per tag): href target rel style.
     * Allowed CSS properties (via style=""): color font-weight font-style
     *   text-decoration text-align.
     * Rejected CSS values: url() expression() javascript: background-image position.
     *
     * This replaces the generic wp_kses_post() so admins can use safe inline
     * styles without risking injection of arbitrary CSS.
     *
     * @param  string $html Untrusted HTML string.
     * @returns string      Sanitized HTML.
     */
    private static function sanitize_email_content( $html ) {
        // Pass 1: per-declaration CSS filter — runs BEFORE wp_kses so that a
        // safe property (e.g. color) is never discarded because it shares a
        // style attribute with a dangerous one (e.g. background-image:url(…)).
        // WordPress's safecss_filter_attr() can reject the entire attribute value
        // when it encounters url(javascript:…) anywhere in the string; running our
        // own per-declaration filter first ensures wp_kses only ever sees clean CSS.
        $html = preg_replace_callback(
            '/\bstyle\s*=\s*"([^"]*)"/i',
            function( $m ) {
                $safe = self::sanitize_css_value( $m[1] );
                return $safe !== '' ? 'style="' . esc_attr( $safe ) . '"' : '';
            },
            $html
        );

        // Pass 2: tag and attribute allowlist — by this point every style=""
        // attribute contains only pre-approved CSS properties, so wp_kses /
        // safecss_filter_attr() will pass them cleanly.
        $shared_attrs = array( 'style' => true );
        $allowed_tags = array(
            'p'      => $shared_attrs,
            'br'     => array(),
            'strong' => $shared_attrs,
            'em'     => $shared_attrs,
            'u'      => $shared_attrs,
            'ul'     => $shared_attrs,
            'ol'     => $shared_attrs,
            'li'     => $shared_attrs,
            'a'      => array_merge( $shared_attrs, array(
                'href'   => true,
                'target' => true,
                'rel'    => true,
            ) ),
            'span'   => $shared_attrs,
            'h1'     => $shared_attrs,
            'h2'     => $shared_attrs,
            'h3'     => $shared_attrs,
            'h4'     => $shared_attrs,
            'h5'     => $shared_attrs,
            'h6'     => $shared_attrs,
        );
        $html = wp_kses( $html, $allowed_tags, array( 'http', 'https', 'mailto' ) );

        return $html;
    }

    /**
     * Filter a CSS declaration block, keeping only safe properties and values.
     *
     * Allowed properties: color, font-weight, font-style, text-decoration, text-align.
     * Rejected value patterns: url(), expression(), javascript:, background-image,
     *   background, position (can escape from email layout).
     *
     * @param  string $css Raw CSS declarations (content of style="…").
     * @returns string     Safe CSS declarations.
     */
    private static function sanitize_css_value( $css ) {
        static $allowed_props = array(
            'color', 'font-weight', 'font-style', 'text-decoration', 'text-align',
        );

        $declarations = explode( ';', $css );
        $safe         = array();

        foreach ( $declarations as $decl ) {
            $decl = trim( $decl );
            if ( '' === $decl ) { continue; }

            $parts = explode( ':', $decl, 2 );
            if ( 2 !== count( $parts ) ) { continue; }

            $prop  = strtolower( trim( $parts[0] ) );
            $value = trim( $parts[1] );

            if ( ! in_array( $prop, $allowed_props, true ) ) { continue; }

            // Reject any value referencing external resources or JS execution.
            if ( preg_match( '/\burl\s*\(|\bexpression\s*\(|\bjavascript\s*:/i', $value ) ) {
                continue;
            }

            $safe[] = $prop . ':' . $value;
        }

        return implode( ';', $safe );
    }

    // ── Email Branding AJAX handlers removed in Phase 2B-DashboardLogs-Branding ──
    // The Email Branding UI and its AJAX handlers (save / live preview) were
    // removed entirely. The securehold_email_branding option may still exist
    // in wp_options from earlier saves; templates/emails/email-header.php and
    // email-footer.php read it via array_merge with defaults, so they keep
    // working with built-in defaults whether the option exists or not.


    /**
     * Filter: suppress the WC global email footer text for SecureHold emails
     * when the "Hide WC global footer" toggle is enabled.
     *
     * Returns an empty string only when ALL of the following hold:
     *   1. Option securehold_disable_wc_footer_for_emails === 'yes'.
     *   2. A SecureHold email render is in progress
     *      (Securehold_Email_Manager::$current_email_id is set by each
     *       get_content_html() / get_content_plain() call).
     *   3. The current email ID exists in Securehold_Email_Manager::EMAIL_MAP.
     *
     * Non-SecureHold WooCommerce emails are never affected.
     *
     * @param  string $text  WC global footer text (from woocommerce_email_footer_text option).
     * @return string        Empty string when suppressed; original value otherwise.
     */
    public static function maybe_suppress_email_footer_text( $text ) {
        if ( get_option( 'securehold_disable_wc_footer_for_emails', 'no' ) !== 'yes' ) {
            return $text; // Toggle is off — pass through unchanged.
        }

        if ( ! class_exists( 'Securehold_Email_Manager' ) ) {
            return $text;
        }

        $current_id = Securehold_Email_Manager::get_current_email_id();
        if ( null === $current_id ) {
            return $text; // Not inside a SecureHold email render — don't suppress.
        }

        // Suppress only for email IDs registered as SecureHold emails.
        return in_array( $current_id, array_keys( Securehold_Email_Manager::EMAIL_MAP ), true )
            ? ''
            : $text;
    }

    // ── Preview AJAX handler ───────────────────────────────────────────────────

    public static function ajax_preview_email() {
        // ── Nonce: manual check so a failure always returns JSON, never wp_die(-1) ──
        if ( ! wp_verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'securehold_emails_nonce'
        ) ) {
            if ( ob_get_length() ) { ob_clean(); }
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'securehold-security-deposit-holds' ) ), 403 );
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            if ( ob_get_length() ) { ob_clean(); }
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'securehold-security-deposit-holds' ) ), 403 );
            return;
        }

        $email_type  = isset( $_POST['email_type'] ) ? sanitize_text_field( wp_unslash( $_POST['email_type'] ) ) : '';
        $body_raw    = isset( $_POST['body'] )   ? wp_kses_post( wp_unslash( $_POST['body'] ) )   : '';
        // Accept the live (possibly unsaved) footer so the preview is accurate.
        $footer_raw  = isset( $_POST['footer'] ) ? wp_kses_post( wp_unslash( $_POST['footer'] ) ) : null;

        $types = self::get_email_types();
        if ( ! isset( $types[ $email_type ] ) ) {
            if ( ob_get_length() ) { ob_clean(); }
            wp_send_json_error( array( 'message' => __( 'Invalid email type.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        // Snapshot OB level so catch/finally can unwind any buffers we opened.
        $ob_level = ob_get_level();

        try {
            // Find a recent order for variable substitution (read-only, no writes).
            $order_id = 0;
            if ( function_exists( 'wc_get_orders' ) ) {
                $orders   = wc_get_orders( array(
                    'limit'  => 1,
                    'status' => array( 'processing', 'completed', 'on-hold', 'pending' ),
                    'return' => 'ids',
                ) );
                $order_id = ! empty( $orders ) ? (int) $orders[0] : 0;
            }

            // Replace {variables} with real order data when possible.
            $rendered_body = $order_id
                ? self::replace_variables( $body_raw, $order_id )
                : $body_raw;

            // Resolve WC email object for heading + WC template wrapping.
            $wc_id     = self::get_wc_id_for_legacy_key( $email_type );
            $email_obj = ( $wc_id && class_exists( 'Securehold_Email_Manager' ) )
                ? Securehold_Email_Manager::get_wc_email( $wc_id )
                : null;

            if ( $email_obj ) {
                if ( $order_id ) {
                    $email_obj->object = wc_get_order( $order_id );
                }
                // Apply WC format_string() for any remaining {site_title} etc. tokens.
                $rendered_body = $email_obj->format_string( $rendered_body );

                // Resolve per-email footer: prefer the live (unsaved) POSTed value so
                // the preview reflects what the user has typed, not the saved DB value.
                $saved_settings = self::get_email_settings( $email_type );
                $preview_footer = ( $footer_raw !== null )
                    ? $footer_raw
                    : ( ! empty( $saved_settings['footer'] ) ? $saved_settings['footer'] : '' );
                if ( $preview_footer ) {
                    // Variable + WC token substitution, same as get_content_html().
                    if ( $order_id ) {
                        $preview_footer = self::replace_variables( $preview_footer, $order_id );
                    }
                    $preview_footer = $email_obj->format_string( $preview_footer );
                }

                // Set render context so the woocommerce_email_footer_text filter
                // (registered in register_ajax_handlers) knows this is a SecureHold
                // email preview and can suppress footer text when the toggle is on.
                Securehold_Email_Manager::set_current_email_id( $wc_id );

                ob_start();
                wc_get_template( 'emails/email-header.php', array(
                    'email_heading' => $email_obj->get_heading(),
                    'email'         => $email_obj,
                ) );
                echo wp_kses_post( self::sanitize_email_content( wpautop( $rendered_body ) ) );

                // Per-email footer block — mirrors the output from get_content_html().
                if ( $preview_footer ) {
                    echo wp_kses_post( '<div style="margin-top:20px;padding-top:10px;border-top:1px solid #e0e0e0;">'
                        . self::sanitize_email_content( wpautop( $preview_footer ) )
                        . '</div>' );
                }

                // Always call email-footer.php to close the table structure that
                // email-header.php opened. The woocommerce_email_footer_text filter
                // handles text suppression when the "Hide WC global footer" toggle is on.
                wc_get_template( 'emails/email-footer.php', array( 'email' => $email_obj ) );

                $html = ob_get_clean();
                Securehold_Email_Manager::set_current_email_id( null );

                // Task 3: Inline all WC email CSS so preview typography matches
                // real email clients. WC_Email::style_inline() runs Emogrifier.
                if ( method_exists( $email_obj, 'style_inline' ) ) {
                    $html = $email_obj->style_inline( $html );
                }

                // Defense-in-depth: ensure the 3-column outer_wrapper centering
                // is present even when an active theme overrides our template
                // (locate_email_template() defers to theme overrides, so the
                // woocommerce_locate_template filter alone may not be enough).
                $html = self::fix_outer_wrapper_centering( $html );
            } else {
                // Fallback: custom wrapper (no real WC template available).
                $settings = self::get_email_settings( $email_type );
                $heading  = $settings ? $settings['heading'] : '';
                // Prefer the submitted (live, possibly unsaved) footer over the saved one.
                $footer   = ( $footer_raw !== null ) ? $footer_raw : ( $settings ? $settings['footer'] : '' );
                $html     = self::build_email_html( $heading, $rendered_body, $footer );
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'securehold_log' ) ) {
                securehold_log( 'ajax_preview_email: rendered', array(
                    'email_type' => $email_type,
                    'order_id'   => $order_id,
                    'html_len'   => strlen( $html ),
                ), 'debug' );
            }

            // Discard any stray buffered output (PHP notices, WC debug) before JSON.
            while ( ob_get_level() > $ob_level ) { ob_end_clean(); }
            if ( ob_get_length() ) { ob_clean(); }

            wp_send_json_success( array( 'html' => $html ) );

        } catch ( \Throwable $e ) {
            // Restore output buffer to pre-call level so the JSON response is clean.
            while ( ob_get_level() > $ob_level ) { ob_end_clean(); }
            if ( ob_get_length() ) { ob_clean(); }

            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'ajax_preview_email: exception', array(
                    'email_type' => $email_type,
                    'error'      => $e->getMessage(),
                    'file'       => $e->getFile(),
                    'line'       => $e->getLine(),
                ), 'error' );
            }

            wp_send_json_error( array(
                'message' => __( 'Preview generation failed. Check server logs for details.', 'securehold-security-deposit-holds' ),
            ) );
        }
    }

    /**
     * AJAX: Save a master notification toggle (all / client / admin).
     *
     * POST fields:
     *   nonce   – wp_create_nonce( 'securehold_emails_nonce' )
     *   setting – one of: securehold_notifications_enabled,
     *                     securehold_notifications_client_enabled,
     *                     securehold_notifications_admin_enabled
     *   value   – 'yes' | 'no'
     *
     * Returns wp_send_json_success ONLY when a DB read-back confirms the value
     * was actually persisted.  Returns wp_send_json_error on any mismatch.
     */
    public static function ajax_save_notification_master() {
        check_ajax_referer( 'securehold_emails_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        // ── Allowlist validation ──────────────────────────────────────
        $allowed = array(
            'securehold_notifications_enabled',
            'securehold_notifications_client_enabled',
            'securehold_notifications_admin_enabled',
            // Controls whether WC global email-footer.php is suppressed for
            // SecureHold emails ('yes' = suppress; 'no' = keep WC footer).
            'securehold_disable_wc_footer_for_emails',
        );

        $setting = isset( $_POST['setting'] ) ? sanitize_key( $_POST['setting'] ) : '';

        if ( ! in_array( $setting, $allowed, true ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid setting key.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        // ── Normalize value to canonical 'yes' / 'no' ────────────────
        $value = ( isset( $_POST['value'] ) && 'yes' === $_POST['value'] ) ? 'yes' : 'no';

        // ── Capture state before write (for diagnostics) ─────────────
        $before = get_option( $setting );   // false if option doesn't exist yet

        // ── Write ─────────────────────────────────────────────────────
        // update_option returns false on no-op (value unchanged) AND on error.
        // Do NOT rely on the return value — use a read-back instead.
        update_option( $setting, $value );

        // ── Read-back: the only reliable confirmation ─────────────────
        // We pass no default so we can distinguish "not set" (false) from 'no'.
        $after = get_option( $setting );

        // ── Diagnostic log ────────────────────────────────────────────
        // Always log on mismatch (error level), debug-only on success.
        $verified  = ( $after === $value );
        $log_level = $verified ? 'debug' : 'error';

        if ( ( 'error' === $log_level || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) )
            && function_exists( 'securehold_log' ) ) {
            securehold_log( 'ajax_save_notification_master', array(
                'setting'       => $setting,
                'posted_value'  => $value,
                'before'        => ( false === $before ) ? '(not set)' : $before,
                'after'         => ( false === $after  ) ? '(not set)' : $after,
                'verified'      => $verified ? 'PASS' : 'FAIL — DB did not persist the value',
            ), $log_level );
        }

        // ── Return verified result ────────────────────────────────────
        if ( ! $verified ) {
            wp_send_json_error( array(
                'message' => sprintf(
                    /* translators: 1: option key 2: expected value 3: actual value */
                    __( 'Toggle "%1$s" could not be saved (expected "%2$s", read back "%3$s"). Check server logs.', 'securehold-security-deposit-holds' ),
                    $setting,
                    $value,
                    ( false === $after ) ? '(not set)' : (string) $after
                ),
            ) );
            return;
        }

        // Build effective-state map for all active emails so the JS can update
        // every sidebar indicator without a page reload.
        $all_types     = self::get_email_types();
        $effective_map = array();

        foreach ( $all_types as $et => $cfg ) {
            if ( ! empty( $cfg['coming_soon'] ) || empty( $cfg['wc_id'] ) ) {
                continue;
            }
            $wc_opt = get_option( 'woocommerce_' . $cfg['wc_id'] . '_settings', array() );
            $is_on  = isset( $wc_opt['enabled'] )
                ? ( 'yes' === $wc_opt['enabled'] )
                : $cfg['default_enabled'];

            if ( $is_on ) {
                if ( get_option( 'securehold_notifications_enabled', 'yes' ) !== 'yes' ) {
                    $is_on = false;
                } elseif ( 'customer' === $cfg['recipient']
                           && get_option( 'securehold_notifications_client_enabled', 'yes' ) !== 'yes' ) {
                    $is_on = false;
                } elseif ( 'admin' === $cfg['recipient']
                           && get_option( 'securehold_notifications_admin_enabled', 'yes' ) !== 'yes' ) {
                    $is_on = false;
                }
            }

            $effective_map[ $et ] = $is_on;
        }

        wp_send_json_success( array(
            'message'       => __( 'Toggle saved.', 'securehold-security-deposit-holds' ),
            'setting'       => $setting,
            'value'         => $after,
            'effective_map' => $effective_map,
        ) );
    }

    /**
     * AJAX: Send test email via the WooCommerce mailer.
     *
     * Resolves the WC_Email object via WC()->mailer()->get_emails(), temporarily
     * bypasses the enabled flag, anti-double-send guard (SENT_META, customer
     * emails only), and overrides the recipient + subject — all without touching
     * the database.  All state is restored after trigger() returns.
     *
     * Safe recipient logic:
     *   Customer-type emails → site admin_email  (never a real customer inbox)
     *   Admin-type emails    → WC configured recipient, or admin_email fallback
     *
     * Accepts POST fields:
     *   email_id   – WC class ID (preferred), e.g. 'securehold_deposit_authorized'
     *   email_type – legacy key fallback, e.g. 'customer_hold_created'
     *   nonce      – securehold_emails_nonce
     */
    public static function ajax_send_test_email() {
        // ── Nonce: manual check so a failure always returns JSON, never wp_die(-1) ──
        if ( ! wp_verify_nonce(
            isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '',
            'securehold_emails_nonce'
        ) ) {
            if ( ob_get_length() ) { ob_clean(); }
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'securehold-security-deposit-holds' ) ), 403 );
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            if ( ob_get_length() ) { ob_clean(); }
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'securehold-security-deposit-holds' ) ), 403 );
            return;
        }

        // Snapshot OB level so we can clean up any buffers opened during trigger().
        $ob_level = ob_get_level();

        // Declare variables used in finally-like cleanup so they are in scope.
        $email_obj           = null;
        $orig_enabled        = null;
        $sent_meta_key       = '';
        $orig_sent_meta      = null;
        $order_id            = 0;
        $mail_attempted      = false;
        $mail_capture_fn     = null;
        $subject_filter_fn   = null;
        $recipient_filter_fn = null;
        $email_id            = '';
        $test_email          = '';

        try {
            // ── 1. Resolve WC email ID ─────────────────────────────────────────
            // Prefer email_id (WC class ID) sent directly from the JS hidden field.
            // Fall back to deriving the WC ID from the legacy email_type key.
            $email_id = isset( $_POST['email_id'] ) ? sanitize_text_field( wp_unslash( $_POST['email_id'] ) ) : '';

            if ( empty( $email_id ) ) {
                $email_type = isset( $_POST['email_type'] ) ? sanitize_text_field( wp_unslash( $_POST['email_type'] ) ) : '';
                $types      = self::get_email_types();
                if ( isset( $types[ $email_type ]['wc_id'] ) ) {
                    $email_id = $types[ $email_type ]['wc_id'];
                }
            }

            if ( empty( $email_id ) ) {
                if ( ob_get_length() ) { ob_clean(); }
                wp_send_json_error( array( 'message' => __( 'Email type not specified.', 'securehold-security-deposit-holds' ) ) );
                return;
            }

            // ── 2. Resolve WC_Email object via WooCommerce mailer ──────────────
            if ( ! class_exists( 'Securehold_Email_Manager' ) ) {
                if ( ob_get_length() ) { ob_clean(); }
                wp_send_json_error( array( 'message' => __( 'Email manager not available.', 'securehold-security-deposit-holds' ) ) );
                return;
            }

            $email_obj = Securehold_Email_Manager::get_wc_email( $email_id );

            if ( ! ( $email_obj instanceof WC_Email ) ) {
                if ( ob_get_length() ) { ob_clean(); }
                wp_send_json_error( array(
                    'message' => sprintf(
                        /* translators: %s: WC email class ID */
                        __( 'WooCommerce email object not found for "%s". Ensure the email class is registered via woocommerce_email_classes.', 'securehold-security-deposit-holds' ),
                        $email_id
                    ),
                ) );
                return;
            }

            // ── 3. Determine safe recipient ────────────────────────────────────
            $admin_email = get_option( 'admin_email' );
            $legacy_key  = Securehold_Email_Manager::get_legacy_key( $email_id );
            $types       = self::get_email_types();
            $is_admin    = ( $legacy_key
                             && isset( $types[ $legacy_key ]['recipient'] )
                             && 'admin' === $types[ $legacy_key ]['recipient'] );

            if ( $is_admin ) {
                // Admin emails: use the WC-configured recipient if valid, else site admin.
                $wc_settings = get_option( 'woocommerce_' . $email_id . '_settings', array() );
                $test_email  = ( ! empty( $wc_settings['recipient'] ) && is_email( $wc_settings['recipient'] ) )
                    ? $wc_settings['recipient']
                    : $admin_email;
            } else {
                // Customer emails: always route to admin inbox — never to a real customer.
                $test_email = $admin_email;
            }

            // ── 4. Find most recent non-cancelled order ────────────────────────
            // Cancelled orders trigger an early return inside trigger(); exclude them.
            if ( function_exists( 'wc_get_orders' ) ) {
                $orders = wc_get_orders( array(
                    'limit'  => 1,
                    'status' => array( 'processing', 'completed', 'on-hold', 'pending', 'refunded' ),
                    'return' => 'ids',
                ) );
                $order_id = ! empty( $orders ) ? (int) $orders[0] : 0;
            }

            if ( ! $order_id ) {
                if ( ob_get_length() ) { ob_clean(); }
                wp_send_json_error( array(
                    'message' => __( 'No suitable order found for the test email. Please create at least one non-cancelled order first.', 'securehold-security-deposit-holds' ),
                ) );
                return;
            }

            // ── 5. Bypass anti-double-send guard (customer emails only) ────────
            // Customer email classes define a SENT_META constant.  trigger() returns
            // early if the meta is set on the order.  Clear it before the test and
            // restore the original value afterward so the order is left unchanged.
            $class_name = get_class( $email_obj );

            if ( defined( $class_name . '::SENT_META' ) ) {
                $sent_meta_key = constant( $class_name . '::SENT_META' );
                $order         = wc_get_order( $order_id );
                if ( $order ) {
                    $orig_sent_meta = $order->get_meta( $sent_meta_key, true );
                    if ( $orig_sent_meta ) {
                        $order->delete_meta_data( $sent_meta_key );
                        $order->save();
                    }
                }
            }

            // ── 6. Save original email enabled state ──────────────────────────
            $orig_enabled = $email_obj->enabled;

            // ── 7. Force enabled for this call only ────────────────────────────
            // Prevents is_enabled() from short-circuiting inside send().
            $email_obj->enabled = 'yes';

            // ── 8. Override recipient via WC filter (PHP_INT_MAX = last to run) ─
            // Runs after trigger() sets $this->recipient, ensuring our safe address wins.
            $test_email_cap      = $test_email; // capture value in closure scope
            $recipient_filter_fn = static function() use ( $test_email_cap ) {
                return $test_email_cap;
            };
            add_filter( 'woocommerce_email_recipient_' . $email_id, $recipient_filter_fn, PHP_INT_MAX );

            // ── 9. Prefix "[TEST]" to the subject via WC filter ───────────────
            $subject_filter_fn = static function( $subject ) {
                return '[TEST] ' . $subject;
            };
            add_filter( 'woocommerce_email_subject_' . $email_id, $subject_filter_fn, PHP_INT_MAX );

            // ── 10. Capture whether wp_mail() was actually invoked ─────────────
            // trigger() returns void; this filter is the only way to confirm that
            // WC called wp_mail() without modifying the email classes.
            $mail_capture_fn = static function( $args ) use ( &$mail_attempted ) {
                $mail_attempted = true;
                return $args; // must pass args through unchanged
            };
            add_filter( 'wp_mail', $mail_capture_fn, PHP_INT_MAX );

            // ── 11. Fire trigger() — WC mailer, real templates, real wrappers ──
            // Bypasses Securehold_Email_Manager gates (master toggle / category /
            // per-email enabled).  All WC placeholder logic runs normally.
            $email_obj->trigger( $order_id, null );

        } catch ( \Throwable $e ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'ajax_send_test_email: exception', array(
                    'email_id' => $email_id,
                    'order_id' => $order_id,
                    'error'    => $e->getMessage(),
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                ), 'error' );
            }
            // Fall through — cleanup below still runs, then we report the error.
        }

        // ── 12. Always remove transient filters (runs even after exception) ──
        if ( $mail_capture_fn ) {
            remove_filter( 'wp_mail', $mail_capture_fn, PHP_INT_MAX );
        }
        if ( $subject_filter_fn && $email_id ) {
            remove_filter( 'woocommerce_email_subject_'   . $email_id, $subject_filter_fn,   PHP_INT_MAX );
        }
        if ( $recipient_filter_fn && $email_id ) {
            remove_filter( 'woocommerce_email_recipient_' . $email_id, $recipient_filter_fn, PHP_INT_MAX );
        }

        // ── 13. Restore email enabled state ───────────────────────────────
        if ( $email_obj && $orig_enabled !== null ) {
            $email_obj->enabled = $orig_enabled;
        }

        // ── 14. Restore anti-double-send meta to original value ───────────
        if ( $sent_meta_key && $order_id ) {
            $order_fresh = wc_get_order( $order_id );
            if ( $order_fresh ) {
                if ( $orig_sent_meta ) {
                    // Restore the original timestamp — trigger() may have overwritten it.
                    $order_fresh->update_meta_data( $sent_meta_key, $orig_sent_meta );
                } else {
                    // Meta did not exist before — remove whatever trigger() wrote.
                    $order_fresh->delete_meta_data( $sent_meta_key );
                }
                $order_fresh->save();
            }
        }

        // ── 15. Discard any stray buffered output before sending JSON ──────
        while ( ob_get_level() > $ob_level ) { ob_end_clean(); }
        if ( ob_get_length() ) { ob_clean(); }

        // ── 16. Report result ──────────────────────────────────────────────
        if ( ! $mail_attempted ) {
            wp_send_json_error( array(
                'message' => __( 'Email trigger ran but wp_mail() was not called. The order may have been skipped (no recipient resolved or unexpected early return in trigger).', 'securehold-security-deposit-holds' ),
            ) );
            return;
        }

        wp_send_json_success( array(
            'message'   => sprintf(
                /* translators: %s: recipient email address */
                __( 'Test email sent to %s.', 'securehold-security-deposit-holds' ),
                $test_email
            ),
            'recipient' => $test_email,
            'email_id'  => $email_id,
            'order_id'  => $order_id,
        ) );
    }

    /**
     * AJAX: Instant-save the per-email enabled state (Task B).
     *
     * POST fields:
     *   nonce      – securehold_emails_nonce
     *   email_type – legacy key e.g. 'customer_hold_created'
     *   value      – any truthy/falsy: 'yes'|'no'|'true'|'false'|'1'|'0'
     */
    public static function ajax_save_email_enabled() {
        check_ajax_referer( 'securehold_emails_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        $email_type = isset( $_POST['email_type'] ) ? sanitize_text_field( wp_unslash( $_POST['email_type'] ) ) : '';
        $types      = self::get_email_types();

        if ( ! isset( $types[ $email_type ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email type.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        $wc_id = self::get_wc_id_for_legacy_key( $email_type );
        if ( ! $wc_id ) {
            wp_send_json_error( array( 'message' => __( 'This email type has no active WooCommerce class yet.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        $value          = self::normalize_enabled( isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : 'no' );
        $wc_option_name = 'woocommerce_' . $wc_id . '_settings';
        $current        = get_option( $wc_option_name, array() );
        $current['enabled'] = $value;
        update_option( $wc_option_name, $current );

        // Read-back verification.
        $after    = get_option( $wc_option_name, array() );
        $verified = isset( $after['enabled'] ) && $after['enabled'] === $value;

        if ( ! $verified ) {
            if ( function_exists( 'securehold_log' ) ) {
                securehold_log( 'ajax_save_email_enabled: DB mismatch', array(
                    'email_type' => $email_type,
                    'wanted'     => $value,
                    'got'        => $after['enabled'] ?? '(missing)',
                ), 'error' );
            }
            wp_send_json_error( array( 'message' => __( 'Could not save enabled state. Check server logs.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        // Compute effective state (WC enabled AND all master gates).
        $config    = $types[ $email_type ];
        $effective = ( 'yes' === $value );
        if ( $effective ) {
            if ( get_option( 'securehold_notifications_enabled', 'yes' ) !== 'yes' ) {
                $effective = false;
            } elseif ( 'customer' === $config['recipient']
                       && get_option( 'securehold_notifications_client_enabled', 'yes' ) !== 'yes' ) {
                $effective = false;
            } elseif ( 'admin' === $config['recipient']
                       && get_option( 'securehold_notifications_admin_enabled', 'yes' ) !== 'yes' ) {
                $effective = false;
            }
        }

        wp_send_json_success( array(
            'message'           => __( 'Saved.', 'securehold-security-deposit-holds' ),
            'enabled'           => $value,
            'enabled_effective' => $effective,
        ) );
    }

    /**
     * AJAX: Reset email template to plugin defaults (Task D).
     *
     * Clears subject/heading overrides from the WC option and deletes the
     * SecureHold body/footer option so defaults are shown on next load.
     * Returns the new settings so the JS can update the form without a reload.
     *
     * POST fields:
     *   nonce      – securehold_emails_nonce
     *   email_type – legacy key e.g. 'customer_hold_created'
     */
    public static function ajax_reset_email_to_default() {
        check_ajax_referer( 'securehold_emails_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        $email_type = isset( $_POST['email_type'] ) ? sanitize_text_field( wp_unslash( $_POST['email_type'] ) ) : '';
        $types      = self::get_email_types();

        if ( ! isset( $types[ $email_type ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email type.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        $config = $types[ $email_type ];
        $wc_id  = self::get_wc_id_for_legacy_key( $email_type );

        // ── Reset WC option: clear subject/heading, reset enabled to default ──
        if ( $wc_id ) {
            $wc_option_name = 'woocommerce_' . $wc_id . '_settings';
            $current        = get_option( $wc_option_name, array() );

            // Remove overrides so WC email class falls back to get_default_subject().
            unset( $current['subject'] );
            unset( $current['heading'] );
            $current['enabled'] = $config['default_enabled'] ? 'yes' : 'no';

            // For admin emails, reset recipient to site admin email.
            if ( 'admin' === $config['recipient'] ) {
                $current['recipient'] = get_option( 'admin_email' );
            }

            update_option( $wc_option_name, $current );
        }

        // ── Delete SecureHold body/footer option ──────────────────────────────
        delete_option( 'securehold_email_' . $email_type );

        // ── Read back the now-active settings to return to the client ─────────
        $new_settings = self::get_email_settings( $email_type );

        wp_send_json_success( array(
            'message'  => __( 'Template reset to defaults.', 'securehold-security-deposit-holds' ),
            'settings' => $new_settings ? array(
                'subject' => $new_settings['subject'],
                'heading' => $new_settings['heading'],
                'body'    => $new_settings['body'],
                'footer'  => $new_settings['footer'],
                'enabled' => $new_settings['enabled'],
            ) : null,
        ) );
    }
    
    /**
     * AJAX: Save email settings.
     *
     * Writes enabled/subject/heading to woocommerce_{wc_id}_settings (WC is
     * the single source of truth) and body/footer to securehold_email_{type}.
     *
     * Returns wp_send_json_success ONLY after a read-back confirms the WC
     * option was actually updated.
     */
    public static function ajax_save_email_settings() {
        check_ajax_referer( 'securehold_emails_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        // ── Validate email type ───────────────────────────────────────
        // Accept either:
        //   email_type  – the legacy SecureHold key  (e.g. 'customer_hold_created')
        //   email_id    – the WC email-class ID       (e.g. 'securehold_deposit_authorized')
        // The JS sends both; we prefer email_type and fall back to resolving email_id.
        $email_type = isset( $_POST['email_type'] ) ? sanitize_text_field( wp_unslash( $_POST['email_type'] ) ) : '';
        $types      = self::get_email_types();

        if ( ! isset( $types[ $email_type ] ) && ! empty( $_POST['email_id'] ) ) {
            // Resolve WC class ID → legacy key by scanning email types for matching wc_id.
            $wc_id_from_post = sanitize_text_field( wp_unslash( $_POST['email_id'] ) );
            foreach ( $types as $legacy_key => $cfg ) {
                if ( isset( $cfg['wc_id'] ) && $cfg['wc_id'] === $wc_id_from_post ) {
                    $email_type = $legacy_key;
                    break;
                }
            }
        }

        if ( ! isset( $types[ $email_type ] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid email type.', 'securehold-security-deposit-holds' ) ) );
            return;
        }

        // ── Sanitize & build settings array ──────────────────────────
        // JS may send enabled as "true"/"false", "yes"/"no", "1"/"0", or a bool.
        // normalize_enabled() accepts all these forms.
        $enabled_raw = isset( $_POST['enabled'] ) ? wp_unslash( $_POST['enabled'] ) : 'false';
        $enabled     = ( 'yes' === self::normalize_enabled( $enabled_raw ) );

        $settings = array(
            'enabled' => $enabled,
            'subject' => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
            'heading' => isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : '',
            'body'    => isset( $_POST['body'] )   ? wp_kses_post( wp_unslash( $_POST['body'] ) )   : '',
            'footer'  => isset( $_POST['footer'] ) ? wp_kses_post( wp_unslash( $_POST['footer'] ) ) : '',
        );

        // Recipient (admin emails): optional POST field.
        if ( isset( $_POST['recipient'] ) ) {
            $settings['recipient'] = sanitize_text_field( wp_unslash( $_POST['recipient'] ) );
        }

        // ── Instrument: log state before save ─────────────────────────
        $wc_id  = self::get_wc_id_for_legacy_key( $email_type );
        $before = $wc_id ? get_option( 'woocommerce_' . $wc_id . '_settings', array() ) : array();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'securehold_log' ) ) {
            securehold_log( 'ajax_save_email_settings: incoming', array(
                'email_type'      => $email_type,
                'wc_id'           => $wc_id ?: '(none — coming-soon type)',
                'wc_option'       => $wc_id ? 'woocommerce_' . $wc_id . '_settings' : 'n/a',
                'before_enabled'  => $before['enabled'] ?? '(missing)',
                'before_subject'  => $before['subject'] ?? '(missing)',
                'posting_enabled' => $enabled ? 'true' : 'false',
                'posting_subject' => $settings['subject'],
                'posting_heading' => $settings['heading'],
            ), 'debug' );
        }

        // ── Save ──────────────────────────────────────────────────────
        // save_email_settings() returns true when the WC option read-back matches.
        $ok = self::save_email_settings( $email_type, $settings );

        // ── Verify ───────────────────────────────────────────────────
        // For coming-soon types there is no WC_Email class, so $wc_id is null
        // and $ok is always true — body/footer were saved to SH option only.
        if ( ! $ok ) {
            wp_send_json_error( array(
                'message' => __( 'Email settings could not be saved to WooCommerce options. Check server logs for details.', 'securehold-security-deposit-holds' ),
            ) );
            return;
        }

        // Return verified settings so the JS can refresh form fields from the confirmed DB state.
        $verified = self::get_email_settings( $email_type );
        wp_send_json_success( array(
            'message'  => __( 'Email settings saved successfully!', 'securehold-security-deposit-holds' ),
            'settings' => $verified ? array(
                'subject' => $verified['subject'],
                'heading' => $verified['heading'],
                'enabled' => $verified['enabled'],
            ) : null,
        ) );
    }
    
    /**
     * AJAX: Get email preview
     */
    public static function ajax_get_email_preview() {
        check_ajax_referer('securehold_emails_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $heading = isset( $_POST['heading'] ) ? sanitize_text_field( wp_unslash( $_POST['heading'] ) ) : '';
        $body    = isset( $_POST['body'] )    ? wp_kses_post( wp_unslash( $_POST['body'] ) )            : '';
        $footer  = isset( $_POST['footer'] )  ? wp_kses_post( wp_unslash( $_POST['footer'] ) )          : '';
        
        $html = self::build_email_html($heading, $body, $footer);
        
        wp_send_json_success(array('html' => $html));
    }
}

// Initialize AJAX handlers
Securehold_Emails::register_ajax_handlers();
