<?php
/**
 * Email Header — SecureHold WP override.
 *
 * Dual-path template:
 *
 *  ① SecureHold email (get_current_email_id() is set)
 *      → Branded layout driven by the securehold_email_branding option.
 *        Falls back to sensible purple-gradient defaults if no settings saved.
 *        Logo, all colours, border-radius and width are customisable.
 *
 *  ② Every other WC email (get_current_email_id() is null)
 *      → Original WC-compatible 3-column centering wrapper (unchanged).
 *        Colour options from WooCommerce › Settings › Emails are honoured.
 *
 * The 3-column centering pattern ( left-spacer <td> + content <td> +
 * right-spacer <td> ) is used in BOTH paths so Gmail and Outlook 2007-19
 * render the content column visually centred without relying on CSS alignment.
 *
 * Template loaded via woocommerce_locate_template (priority 5) registered in
 * Securehold_Emails::register_ajax_handlers(). Theme overrides in
 * woocommerce/emails/ take precedence over this file.
 *
 * @see    wc_get_template()
 * @package SecureHold
 */

defined( 'ABSPATH' ) || exit;

// ── WC email styles (Emogrifier source) ──────────────────────────────────────
$email_styles = apply_filters(
    'woocommerce_email_styles',
    function_exists( 'wc_get_template_html' )
        ? wc_get_template_html( 'emails/email-styles.php', array( 'email' => isset( $email ) ? $email : null ) )
        : ''
);

// ── WC email colour options (used by the WC-compatible fallback path) ─────────
$bg         = get_option( 'woocommerce_email_background_color',      '#f7f7f7' );
$body_bg    = get_option( 'woocommerce_email_body_background_color', '#ffffff' );
$base       = get_option( 'woocommerce_email_base_color',            '#7f54b3' );
$text       = get_option( 'woocommerce_email_text_color',            '#3c3c3c' );

$bg_darker_10    = function_exists( 'wc_hex_darker' )  ? wc_hex_darker( $bg, 10 )     : '#e5e5e5';
$base_lighter_20 = function_exists( 'wc_hex_lighter' ) ? wc_hex_lighter( $base, 20 )  : '#9b78c2';
$header_text_color = ( function_exists( 'wc_hex_is_light' ) && wc_hex_is_light( $base ) )
    ? '#202020'
    : '#ffffff';

// ── Context: are we rendering a SecureHold email? ─────────────────────────────
$is_securehold_email = class_exists( 'Securehold_Email_Manager' )
    && null !== Securehold_Email_Manager::get_current_email_id();

// ── use_woo_block: when true, skip SecureHold branded path and use WC native ──
// The toggle is stored in securehold_email_branding['use_woo_block'].
// We read it here (before $b is merged) using a temporary get_option so we can
// make the Path ① vs Path ② decision without resolving the full branding array first.
$use_woo_block_raw = false;
if ( $is_securehold_email ) {
    $raw = (array) get_option( 'securehold_email_branding', array() );
    $use_woo_block_raw = ! empty( $raw['use_woo_block'] ) && '1' === (string) $raw['use_woo_block'];
}

// $is_securehold = true  → Path ① (SecureHold branded)
// $is_securehold = false → Path ② (WC native / use_woo_block)
$is_securehold = $is_securehold_email && ! $use_woo_block_raw;

// ── SecureHold branding settings (only resolved when needed) ──────────────────
if ( $is_securehold ) {
    $b = array_merge(
        array(
            'brand_name'            => '',
            'logo_url'              => '',
            'card_bg_color'         => '#ffffff',
            'header_gradient_start' => '#7c3aed',
            'header_gradient_end'   => '#9333ea',
            'header_text_color'     => '#ffffff',
            'body_text_color'       => '#374151',
            'accent_color'          => '#7c3aed',
            'link_color'            => '#7c3aed',
            'footer_text_color'     => '#9ca3af',
            'border_radius'         => '12px',
            'content_max_width'     => '600px',
            'use_plain_layout'      => '',
        ),
        (array) get_option( 'securehold_email_branding', array() )
    );

    // Numeric card width for HTML width="" attributes (strip 'px').
    $card_width = absint( $b['content_max_width'] ) ?: 600;

    // Border-radius for the card and the header top corners.
    $br     = esc_attr( $b['border_radius'] );                    // e.g. "12px"
    $br_top = esc_attr( $b['border_radius'] ) . ' ' . esc_attr( $b['border_radius'] ) . ' 0 0';

    // Plain layout flag: no gradient header, no card border — clean text email.
    $use_plain = ! empty( $b['use_plain_layout'] ) && '1' === (string) $b['use_plain_layout'];

    // Pre-build style strings so the HTML block stays readable.
    $container_style = 'background-color:' . esc_attr( $b['card_bg_color'] ) . ';';
    if ( ! $use_plain ) {
        $container_style .= ' border:1px solid #e5e7eb; border-radius:' . $br . ' !important; overflow:hidden !important;';
    }

    if ( $use_plain ) {
        $header_td_style = 'padding:24px 32px 20px; background:transparent; border-bottom:2px solid #e5e7eb;';
        $h1_font_size    = '22px';
    } else {
        $header_td_style = 'background-color:' . esc_attr( $b['header_gradient_start'] ) . ';'
            . ' background-image:linear-gradient(135deg,' . esc_attr( $b['header_gradient_start'] ) . ' 0%,' . esc_attr( $b['header_gradient_end'] ) . ' 100%);'
            . ' border-radius:' . $br_top . ' !important;'
            . ' padding:32px;';
        $h1_font_size    = '28px';
    }
}

// =============================================================================
// PATH ①  SecureHold branded design
// =============================================================================
if ( $is_securehold ) :
?>
<!DOCTYPE html>
<html <?php echo is_rtl() ? 'dir="rtl"' : ''; ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title><?php echo esc_html( ! empty( $b['brand_name'] ) ? $b['brand_name'] : get_bloginfo( 'name', 'display' ) ); ?></title>
    <?php if ( $email_styles ) : ?>
    <style type="text/css">
        <?php echo esc_html( wp_strip_all_tags( $email_styles ) ); ?>
    </style>
    <?php endif; ?>
    <style type="text/css">
        /*
         * SecureHold email content styles.
         * WC_Email::style_inline() (Emogrifier) inlines these onto matching
         * elements before the email is sent, so every rule here affects
         * real email output and the admin preview iframe identically.
         */

        /* Base paragraph spacing */
        #body_content_inner p              { margin: 0 0 16px 0; }

        /* Headings */
        #body_content_inner h2,
        #body_content_inner h3             { color: #111827; font-size: 15px; font-weight: 700;
                                             line-height: 140%; margin: 0 0 10px 0; padding: 0; }

        /* Order-details / styled list block */
        #body_content_inner ul,
        #body_content_inner ol             { background-color: #f8fafc; border: 1px solid #e5e7eb;
                                             border-radius: 6px; margin: 0 0 16px 0;
                                             padding: 16px 16px 16px 36px; }
        #body_content_inner li             { margin-bottom: 8px; }
        #body_content_inner li:last-child  { margin-bottom: 0; }

        /* Primary CTA link — uses branding link_color.
         * !important beats WC email-styles.php "a { color: $base !important; }"
         * which Emogrifier would otherwise inline with higher precedence. */
        #body_content_inner a              { color: <?php echo esc_attr( $b['link_color'] ); ?> !important;
                                             font-weight: 600; text-decoration: none; }

        /* Important / notice block (.sh-notice on any block element) */
        #body_content_inner .sh-notice     { background-color: #fff7ed; border-left: 4px solid #f97316;
                                             padding: 16px; margin-top: 20px; margin-bottom: 16px;
                                             border-radius: 0 4px 4px 0; }

        /* ── Override WC Emogrifier rules that use !important ──
         * WC email-styles.php sets:
         *   #template_container { border-radius: 3px !important; }
         *   h1 { color: $base; } (WC purple)
         * Our rules below appear AFTER the WC <style> block (later in document →
         * higher cascade priority at equal specificity) and use !important so
         * they survive style_inline() (Emogrifier) in both preview and real sends.
         */
        #template_container              { border-radius: <?php echo esc_html( $br ); ?> !important; overflow: hidden !important; }
        #template_header                 { border-radius: <?php echo esc_html( $br_top ); ?> !important; }
        #template_header h1              { color: <?php echo esc_attr( $b['header_text_color'] ); ?> !important;
                                           background: none !important;
                                           background-color: transparent !important; }
    </style>
</head>
<body <?php echo is_rtl() ? 'class="rtl"' : ''; ?>>
<div id="wrapper" dir="ltr"
     style="background-color:transparent; margin:0; padding:70px 0; -webkit-text-size-adjust:none !important; width:100%;">

    <?php
    /**
     * 3-column centering: left spacer + card + right spacer.
     * The right spacer <td> is emitted in email-footer.php.
     */
    ?>
    <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="outer_wrapper">
        <tr>
            <td>&nbsp;</td><!-- left spacer -->

            <td width="<?php echo esc_attr( $card_width ); ?>" valign="top">

                <?php if ( ! empty( $b['logo_url'] ) || ! empty( $b['brand_name'] ) ) : ?>
                <div id="template_header_logo" style="text-align:center; padding-bottom:16px;">
                    <?php if ( ! empty( $b['logo_url'] ) ) : ?>
                    <img src="<?php echo esc_url( $b['logo_url'] ); ?>"
                         alt="<?php echo esc_attr( ! empty( $b['brand_name'] ) ? $b['brand_name'] : get_bloginfo( 'name', 'display' ) ); ?>"
                         style="max-height:60px; max-width:200px; display:block; margin:0 auto;" />
                    <?php endif; ?>
                    <?php if ( ! empty( $b['brand_name'] ) ) : ?>
                    <p style="margin:<?php echo ! empty( $b['logo_url'] ) ? '6px' : '0'; ?> 0 0;
                               font-size:13px;
                               font-weight:600;
                               color:#374151;
                               font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
                               line-height:1.4;">
                        <?php echo esc_html( $b['brand_name'] ); ?>
                    </p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <table border="0" cellpadding="0" cellspacing="0" width="<?php echo esc_attr( $card_width ); ?>"
                       id="template_container"
                       style="<?php echo esc_attr( $container_style ); ?>">

                    <!-- ── Header (gradient branded OR plain minimal) ── -->
                    <tr>
                        <td align="left" valign="top" id="template_header"
                            style="<?php echo esc_attr( $header_td_style ); ?>">
                            <h1 style="color:<?php echo esc_attr( $b['header_text_color'] ); ?>;
                                       font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
                                       font-size:<?php echo esc_attr( $h1_font_size ); ?>;
                                       font-weight:700;
                                       line-height:130%;
                                       margin:0;
                                       text-align:left;">
                                <?php echo wp_kses_post( $email_heading ); ?>
                            </h1>
                        </td>
                    </tr>
                    <!-- /Header -->

                    <!-- ── Body row ── -->
                    <tr>
                        <td align="left" valign="top" id="template_body">
                            <table border="0" cellpadding="0" cellspacing="0" width="<?php echo esc_attr( $card_width ); ?>"
                                   id="template_bodytable">
                                <tr>
                                    <td valign="top" id="body_content"
                                        style="background-color:<?php echo esc_attr( $b['card_bg_color'] ); ?>;">
                                        <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                            <tr>
                                                <td valign="top" style="padding:32px;">
                                                    <div id="body_content_inner"
                                                         style="color:<?php echo esc_attr( $b['body_text_color'] ); ?>;
                                                                font-family:'Helvetica Neue',Helvetica,Arial,sans-serif;
                                                                font-size:14px;
                                                                line-height:150%;
                                                                text-align:left;">
<?php
// =============================================================================
// PATH ②  WC-compatible fallback (non-SecureHold emails) — original design
// =============================================================================
else :
?>
<!DOCTYPE html>
<html <?php echo is_rtl() ? 'dir="rtl"' : ''; ?>>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <title><?php echo esc_html( get_bloginfo( 'name', 'display' ) ); ?></title>
    <?php if ( $email_styles ) : ?>
    <style type="text/css">
        <?php echo esc_html( wp_strip_all_tags( $email_styles ) ); ?>
    </style>
    <?php endif; ?>
</head>
<body <?php echo is_rtl() ? 'class="rtl"' : ''; ?>>
<div id="wrapper" dir="ltr"
     style="background-color: <?php echo esc_attr( $bg ); ?>; margin: 0; padding: 70px 0 70px 0; -webkit-text-size-adjust: none !important; width: 100%;">

    <?php
    /**
     * 3-column centering pattern: left spacer + 600 px content + right spacer.
     * The right spacer <td> is emitted in email-footer.php.
     */
    ?>
    <table border="0" cellpadding="0" cellspacing="0" height="100%" width="100%" id="outer_wrapper">
        <tr>
            <!-- Left spacer — expands to fill available width on the left -->
            <td>&nbsp;</td>

            <!-- 600 px centred content column -->
            <td width="600" valign="top">

                <?php if ( $img = get_option( 'woocommerce_email_header_image' ) ) : ?>
                <div id="template_header_image" style="text-align:center;">
                    <p style="margin-top:0;">
                        <img src="<?php echo esc_url( $img ); ?>"
                             alt="<?php echo esc_attr( get_bloginfo( 'name', 'display' ) ); ?>"
                             style="max-width:100%;" />
                    </p>
                </div>
                <?php endif; ?>

                <table border="0" cellpadding="0" cellspacing="0" width="600"
                       id="template_container"
                       style="background-color: <?php echo esc_attr( $body_bg ); ?>;
                              border: 1px solid <?php echo esc_attr( $bg_darker_10 ); ?>;
                              box-shadow: 0 1px 4px rgba(0,0,0,.1) !important;
                              border-radius: 3px !important;">

                    <!-- Email heading row -->
                    <tr>
                        <td align="center" valign="top">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%"
                                   id="template_header"
                                   style="background-color: <?php echo esc_attr( $base ); ?>;
                                          border-bottom: 0;
                                          font-weight: bold;
                                          line-height: 100%;
                                          vertical-align: middle;
                                          word-wrap: break-word;">
                                <tr>
                                    <td id="template_header_image_cell"
                                        style="padding: 36px 48px; display: block;">
                                        <h1 style="color: <?php echo esc_attr( $header_text_color ); ?>;
                                                   font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;
                                                   font-size: 30px;
                                                   font-weight: 300;
                                                   line-height: 150%;
                                                   margin: 0;
                                                   text-align: left;
                                                   text-shadow: 0 1px 0 <?php echo esc_attr( $base_lighter_20 ); ?>;
                                                   -webkit-font-smoothing: antialiased;">
                                            <?php echo wp_kses_post( $email_heading ); ?>
                                        </h1>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- /Email heading row -->

                    <!-- Body row -->
                    <tr>
                        <td align="center" valign="top" id="template_body">
                            <table border="0" cellpadding="0" cellspacing="0" width="600"
                                   id="template_bodytable">
                                <tr>
                                    <td valign="top" id="body_content"
                                        style="background-color: <?php echo esc_attr( $body_bg ); ?>;">
                                        <table border="0" cellpadding="20" cellspacing="0" width="100%">
                                            <tr>
                                                <td valign="top">
                                                    <div id="body_content_inner"
                                                         style="color: <?php echo esc_attr( $text ); ?>;
                                                                font-family: 'Helvetica Neue', Helvetica, Roboto, Arial, sans-serif;
                                                                font-size: 14px;
                                                                line-height: 150%;
                                                                text-align: left;">
<?php
endif;
