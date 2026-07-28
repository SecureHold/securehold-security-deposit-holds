<?php
/**
 * Email Footer — SecureHold WP override.
 *
 * Closes the HTML structure opened by templates/emails/email-header.php.
 * Must be kept in exact structural sync with that file.
 *
 * Dual-path template — mirrors email-header.php:
 *
 *  ① SecureHold email (get_current_email_id() is set)
 *      → Closes the branded card structure; emits right spacer <td>;
 *        renders WC additional content and WC global footer text in the
 *        SecureHold style (left-aligned, #9ca3af, 12 px, 20 px side padding).
 *
 *  ② Every other WC email (get_current_email_id() is null)
 *      → Original close structure (unchanged); WC colour options honoured.
 *
 * The Hide WC global footer toggle (securehold_disable_wc_footer_for_emails)
 * is handled entirely by the woocommerce_email_footer_text filter registered
 * in Securehold_Emails::maybe_suppress_email_footer_text() — no logic here.
 *
 * @package SecureHold
 */

defined( 'ABSPATH' ) || exit;

// ── WC colour options (used by the WC-compatible fallback path) ───────────────
$text = get_option( 'woocommerce_email_text_color', '#3c3c3c' );

// ── WC additional content (WC 3.7+) ──────────────────────────────────────────
$additional_content = ( isset( $email ) && is_object( $email ) && method_exists( $email, 'get_additional_content' ) )
    ? apply_filters( 'woocommerce_email_additional_content_' . $email->id, $email->get_additional_content() )
    : '';

// ── WC global footer text ─────────────────────────────────────────────────────
// The maybe_suppress_email_footer_text() filter returns '' when the
// securehold_disable_wc_footer_for_emails toggle is ON for a SecureHold email.
$footer_text = apply_filters(
    'woocommerce_email_footer_text',
    get_option( 'woocommerce_email_footer_text', '{site_title} &mdash; Built with WooCommerce' )
);
if ( isset( $email ) && is_object( $email ) && method_exists( $email, 'format_string' ) ) {
    $footer_text = $email->format_string( $footer_text );
}

// ── Context: are we rendering a SecureHold email? ─────────────────────────────
// set_current_email_id() is still set when this file is included; it is cleared
// in the finally block only after wc_get_template() returns.
$is_securehold_email = class_exists( 'Securehold_Email_Manager' )
    && null !== Securehold_Email_Manager::get_current_email_id();

// ── Respect use_woo_block — must mirror the decision in email-header.php ──────
// When use_woo_block is enabled the header used Path ② (WC native); the footer
// must also use Path ② to keep the HTML structure consistent.
$use_woo_block_footer = false;
if ( $is_securehold_email ) {
    $raw_branding       = (array) get_option( 'securehold_email_branding', array() );
    $use_woo_block_footer = ! empty( $raw_branding['use_woo_block'] ) && '1' === (string) $raw_branding['use_woo_block'];
}

$is_securehold = $is_securehold_email && ! $use_woo_block_footer;

// ── SecureHold branding settings (footer_text_color only needed here) ─────────
$sh_footer_text_color = '#9ca3af'; // default
if ( $is_securehold ) {
    $sh_branding          = (array) get_option( 'securehold_email_branding', array() );
    $sh_footer_text_color = ! empty( $sh_branding['footer_text_color'] ) ? $sh_branding['footer_text_color'] : '#9ca3af';
}

// =============================================================================
// PATH ①  SecureHold branded close structure
// =============================================================================
if ( $is_securehold ) :
?>
                                                    </div><!-- /body_content_inner -->
                                                </td>
                                            </tr>
                                        </table><!-- /inner padding table -->
                                    </td><!-- /body_content -->
                                </tr>
                            </table><!-- /template_bodytable -->
                        </td><!-- /template_body -->
                    </tr><!-- /body row -->

                    <?php if ( $additional_content ) : ?>
                    <tr>
                        <td valign="top" id="template_additional_content"
                            style="padding:0 32px 24px; background-color:#ffffff;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td valign="middle"
                                        style="border-top:1px solid #e5e7eb;
                                               color:#6b7280;
                                               font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
                                               font-size:13px;
                                               line-height:150%;
                                               padding-top:20px;
                                               text-align:left;">
                                        <?php echo wp_kses_post( wpautop( $additional_content ) ); ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>

                </table><!-- /template_container -->

            </td><!-- /600px content column -->

            <!-- Right spacer: balances the left spacer <td> from email-header.php
                 so the 600 px card is truly centred in email clients that ignore CSS. -->
            <td>&nbsp;</td>

        </tr>
    </table><!-- /outer_wrapper -->

    <?php if ( $footer_text ) : ?>
    <table border="0" cellpadding="0" cellspacing="0" width="600"
           style="margin:0 auto;">
        <tr>
            <td align="left" valign="middle" id="template_footer_text"
                style="padding:0 20px; -webkit-border-radius:6px;">
                <p id="footer_copyright"
                   style="color:<?php echo esc_attr( $sh_footer_text_color ); ?>;
                          font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
                          font-size:12px;
                          line-height:150%;
                          margin:0;
                          text-align:left;">
                    <?php echo wp_kses_post( $footer_text ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php endif; ?>

</div><!-- /wrapper -->
</body>
</html>
<?php
// =============================================================================
// PATH ②  WC-compatible fallback close structure (non-SecureHold emails)
// =============================================================================
else :
?>
                                                    </div><!-- /body_content_inner -->
                                                </td>
                                            </tr>
                                        </table><!-- /inner padding table -->
                                    </td><!-- /body_content -->
                                </tr>
                            </table><!-- /template_bodytable -->
                        </td><!-- /template_body -->
                    </tr><!-- /body row -->

                    <?php if ( $additional_content ) : ?>
                    <tr>
                        <td colspan="2" valign="top" id="template_footer"
                            style="padding: 0; -webkit-border-radius: 6px;">
                            <table border="0" cellpadding="10" cellspacing="0" width="600">
                                <tr>
                                    <td colspan="2" valign="middle" id="footer"
                                        style="color: <?php echo esc_attr( $text ); ?>; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif; font-size: 14px; line-height: 150%; text-align: center;">
                                        <?php echo wp_kses_post( wpautop( $additional_content ) ); ?>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <?php endif; ?>

                </table><!-- /template_container -->

            </td><!-- /600px content column -->

            <!-- Right spacer: balances the left empty <td> in email-header.php
                 so the 600 px content column is truly centred in email clients
                 that do not honour CSS text-align or align attributes.          -->
            <td>&nbsp;</td>

        </tr>
    </table><!-- /outer_wrapper -->

    <?php if ( $footer_text ) : ?>
    <table border="0" cellpadding="0" cellspacing="0" width="600"
           style="margin: 0 auto;">
        <tr>
            <td align="left" valign="middle" id="template_footer_text"
                style="padding: 0 20px; -webkit-border-radius: 6px;">
                <p id="footer_copyright"
                   style="color: #8a8a8a; font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;
                          font-size: 12px; line-height: 150%; margin: 0; text-align: left;">
                    <?php echo wp_kses_post( $footer_text ); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php endif; ?>

</div><!-- /wrapper -->
</body>
</html>
<?php
endif;
