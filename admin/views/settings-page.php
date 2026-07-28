<?php
/**
 * SecureHold Settings Page with Tabs
 */

if (!defined('ABSPATH')) exit;

// Load Product Settings class if not already available.
if (!class_exists('Securehold_Product_Settings')) {
    if (defined('SECUREHOLD_PLUGIN_DIR')) {
        require_once SECUREHOLD_PLUGIN_DIR . 'admin/class-securehold-wp-product-settings.php';
    }
}

// Helper: returns true when $value starts with one of the allowed $prefixes.
function securehold_validate_stripe_key( $value, array $prefixes ) {
    foreach ( $prefixes as $prefix ) {
        if ( strncmp( $value, $prefix, strlen( $prefix ) ) === 0 ) {
            return true;
        }
    }
    return false;
}

// SAVE SETTINGS HANDLER
if (isset($_POST['securehold_settings_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['securehold_settings_nonce'])), 'securehold_save_settings')) {
    // Defense-in-depth capability check: the settings page menu is already restricted to
    // manage_options, but we verify here in the save handler as an additional server-side guard.
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to change SecureHold settings.', 'securehold-security-deposit-holds'), 403);
    }
    // ... [Saving logic remains identical] ...
    if (isset($_POST['securehold_stripe_api_key'])) update_option('securehold_stripe_api_key', sanitize_text_field(wp_unslash($_POST['securehold_stripe_api_key'])));
    if (isset($_POST['securehold_stripe_secret_key'])) update_option('securehold_stripe_secret_key', sanitize_text_field(wp_unslash($_POST['securehold_stripe_secret_key'])));

    // Validate and save structured Stripe credential fields.
    // Rules per field:
    //   empty submitted value  → preserve existing stored value (masked field not edited)
    //   non-empty valid prefix → update stored value
    //   non-empty invalid      → reject; emit admin notice; do NOT update stored value
    $credential_validation_errors = array();

    $stripe_credentials = array(
        'securehold_stripe_test_publishable_key' => array(
            'label'    => __( 'Test Publishable Key', 'securehold-security-deposit-holds' ),
            'prefixes' => array( 'pk_test_' ),
        ),
        'securehold_stripe_test_secret_key'      => array(
            'label'    => __( 'Test Secret Key', 'securehold-security-deposit-holds' ),
            'prefixes' => array( 'sk_test_', 'rk_test_' ),
        ),
        'securehold_stripe_live_publishable_key' => array(
            'label'    => __( 'Live Publishable Key', 'securehold-security-deposit-holds' ),
            'prefixes' => array( 'pk_live_' ),
        ),
        'securehold_stripe_live_secret_key'      => array(
            'label'    => __( 'Live Secret Key', 'securehold-security-deposit-holds' ),
            'prefixes' => array( 'sk_live_', 'rk_live_' ),
        ),
        'securehold_webhook_secret'              => array(
            'label'    => __( 'Webhook Secret', 'securehold-security-deposit-holds' ),
            'prefixes' => array( 'whsec_' ),
        ),
    );

    foreach ( $stripe_credentials as $option_name => $meta ) {
        if ( ! isset( $_POST[ $option_name ] ) ) {
            continue;
        }
        $submitted = trim( sanitize_text_field( wp_unslash( $_POST[ $option_name ] ) ) );
        if ( $submitted === '' ) {
            // Empty → preserve existing value (masked field not edited).
            continue;
        }
        if ( securehold_validate_stripe_key( $submitted, $meta['prefixes'] ) ) {
            update_option( $option_name, $submitted );
        } else {
            $prefix_parts  = array_map( 'esc_html', $meta['prefixes'] );
            $prefixes_html = '<code>' . implode(
                '</code> ' . esc_html__( 'or', 'securehold-security-deposit-holds' ) . ' <code>',
                $prefix_parts
            ) . '</code>';

            $credential_validation_errors[] = '<strong>' . sprintf(
                /* translators: 1: field label, 2: comma-separated valid key prefixes */
                esc_html__( '%1$s: value rejected — must start with %2$s.', 'securehold-security-deposit-holds' ),
                esc_html( $meta['label'] ),
                $prefixes_html
            ) . '</strong>';
        }
    }

    if ( ! empty( $credential_validation_errors ) ) {
        // Store errors in transient so they survive the post-redirect-get cycle.
        set_transient( 'securehold_credential_errors', $credential_validation_errors, 30 );
    }
    if (isset($_POST['securehold_stripe_mode'])) update_option('securehold_stripe_mode', sanitize_text_field(wp_unslash($_POST['securehold_stripe_mode'])));
    if (isset($_POST['securehold_stripe_currency'])) update_option('securehold_stripe_currency', sanitize_text_field(wp_unslash($_POST['securehold_stripe_currency'])));
    if (isset($_POST['securehold_default_hold_amount'])) update_option('securehold_default_hold_amount', sanitize_text_field(wp_unslash($_POST['securehold_default_hold_amount'])));
    if (isset($_POST['securehold_auto_release_days'])) {
        // Honour user input for all tiers, clamped to Stripe's 1-7 day window.
        $release_days = absint( wp_unslash( $_POST['securehold_auto_release_days'] ) );
        if ($release_days < 1) $release_days = 1;
        if ($release_days > 7) $release_days = 7;
        update_option('securehold_auto_release_days', $release_days);
        // Enable auto-release and reschedule cron when days are configured
        update_option('securehold_auto_release', 'yes');
        if (class_exists('Securehold_Scheduler')) {
            Securehold_Scheduler::schedule_auto_release_cron();
        }
    }
    // Save Resolution Policy
    if ( isset( $_POST['securehold_resolution_policy'] ) ) {
        $policy = sanitize_text_field( wp_unslash( $_POST['securehold_resolution_policy'] ) );
        if ( in_array( $policy, array( 'priority_chain', 'highest_deposit_wins' ), true ) ) {
            update_option( 'securehold_resolution_policy', $policy );
        }
    }

    // Save Aggregation Mode
    if ( isset( $_POST['securehold_aggregation_mode'] ) ) {
        $agg_mode = sanitize_text_field( wp_unslash( $_POST['securehold_aggregation_mode'] ) );
        if ( in_array( $agg_mode, array( 'per_order', 'per_item_aggregated' ), true ) ) {
            update_option( 'securehold_aggregation_mode', $agg_mode );
        }
    }

    // Save Engine Version
    if ( isset( $_POST['securehold_engine_version'] ) ) {
        $engine_ver = sanitize_text_field( wp_unslash( $_POST['securehold_engine_version'] ) );
        if ( in_array( $engine_ver, array( 'legacy', 'v2' ), true ) ) {
            update_option( 'securehold_engine_version', $engine_ver );
        }
    }

    // Save Automation strategy. FREE supports two strategies: Immediate and Manual.
    // Any other value is normalized to Immediate.
    if (isset($_POST['securehold_capture_timing'])) {
        $timing = sanitize_text_field(wp_unslash($_POST['securehold_capture_timing']));
        // FREE accepts immediate/manual; PRO extends this whitelist with delayed/scheduled/status.
        $sh_allowed_timings = apply_filters( 'securehold_capture_timing_whitelist', array( 'immediate', 'manual' ) );
        if ( ! in_array( $timing, $sh_allowed_timings, true ) ) {
            $timing = 'immediate';
        }
        update_option('securehold_capture_timing', $timing);
        // Allow PRO to persist advanced timing config fields (delay_days, date_field_key, scheduled_*, trigger_status).
        do_action( 'securehold_save_timing_settings' );
    }

    // ── Deposit Rules tab: new options ──
    // Guard: securehold_resolution_policy is a <select> always present on the
    // Global Configuration sub-tab. This prevents checkbox/array reset when
    // saving from a different tab.
    if ( isset( $_POST['securehold_resolution_policy'] ) ) {

        // Minimum Cart Amount (empty or 0 = no threshold).
        if ( isset( $_POST['securehold_min_cart_amount'] ) ) {
            $min_cart = sanitize_text_field( wp_unslash( $_POST['securehold_min_cart_amount'] ) );
            update_option( 'securehold_min_cart_amount', $min_cart );
        }

        // Product & Category Exclusions (arrays — empty means none excluded).
        $excluded_products = isset( $_POST['securehold_excluded_products'] )
            ? array_map( 'absint', (array) wp_unslash( $_POST['securehold_excluded_products'] ) )
            : array();
        update_option( 'securehold_excluded_products', array_filter( $excluded_products ) );

        $excluded_categories = isset( $_POST['securehold_excluded_categories'] )
            ? array_map( 'absint', (array) wp_unslash( $_POST['securehold_excluded_categories'] ) )
            : array();
        update_option( 'securehold_excluded_categories', array_filter( $excluded_categories ) );

        // Deposit Failure Handling (checkbox — default: enabled).
        update_option(
            'securehold_require_deposit_auth',
            isset( $_POST['securehold_require_deposit_auth'] ) ? '1' : ''
        );
    }

    // Debug logging toggle (checkbox — only update when Connection tab is submitted;
    // guarded by securehold_stripe_mode which is only present in that tab's form).
    // Without this guard, saving from another tab (Automation) would reset the checkbox
    // to 'no' because an absent checkbox is indistinguishable from an unchecked one.
    if ( isset( $_POST['securehold_stripe_mode'] ) ) {
        $logging_value = isset( $_POST['securehold_enable_logging'] ) ? 'yes' : 'no';
        update_option( 'securehold_enable_logging', $logging_value );
        securehold_log(
            'Debug logging option updated',
            array( 'value' => $logging_value ),
            'debug'
        );
    }

    // ── Appearance & Frontend tab ──
    // Guard: securehold_checkout_message_style is a <select> always present on
    // this tab. When saving from another tab it is absent, so the block is skipped
    // and checkbox values are not accidentally reset to unchecked.
    if ( isset( $_POST['securehold_checkout_message_style'] ) ) {
        // ── FREE settings — always save ──
        // Checkboxes (absent ⇒ unchecked).
        update_option( 'securehold_enable_checkout_message', isset( $_POST['securehold_enable_checkout_message'] ) ? '1' : '' );

        // Checkout message text (rich text from wp_editor).
        if ( isset( $_POST['securehold_checkout_message'] ) ) {
            update_option( 'securehold_checkout_message', wp_kses_post( wp_unslash( $_POST['securehold_checkout_message'] ) ) );
        }

        // Checkout message style — clamp to allowed enum.
        if ( isset( $_POST['securehold_checkout_message_style'] ) ) {
            $style_raw = sanitize_text_field( wp_unslash( $_POST['securehold_checkout_message_style'] ) );
            if ( ! in_array( $style_raw, array( 'info', 'warning', 'success' ), true ) ) {
                $style_raw = 'info';
            }
            update_option( 'securehold_checkout_message_style', $style_raw );
        }

        // Checkout message position — clamp to allowed enum.
        if ( isset( $_POST['securehold_checkout_message_position'] ) ) {
            $position_raw = sanitize_text_field( wp_unslash( $_POST['securehold_checkout_message_position'] ) );
            if ( ! in_array( $position_raw, array( 'before', 'after' ), true ) ) {
                $position_raw = 'before';
            }
            update_option( 'securehold_checkout_message_position', $position_raw );
        }

        // My Account toggle and text fields.
        update_option( 'securehold_enable_my_account_tab', isset( $_POST['securehold_enable_my_account_tab'] ) ? '1' : '' );
        if ( isset( $_POST['securehold_my_account_menu_label'] ) ) {
            update_option( 'securehold_my_account_menu_label', sanitize_text_field( wp_unslash( $_POST['securehold_my_account_menu_label'] ) ) );
        }
        if ( isset( $_POST['securehold_my_account_page_title'] ) ) {
            update_option( 'securehold_my_account_page_title', sanitize_text_field( wp_unslash( $_POST['securehold_my_account_page_title'] ) ) );
        }
        if ( isset( $_POST['securehold_my_account_page_description'] ) ) {
            update_option( 'securehold_my_account_page_description', sanitize_textarea_field( wp_unslash( $_POST['securehold_my_account_page_description'] ) ) );
        }
        if ( isset( $_POST['securehold_my_account_empty_message'] ) ) {
            update_option( 'securehold_my_account_empty_message', sanitize_text_field( wp_unslash( $_POST['securehold_my_account_empty_message'] ) ) );
        }
    }

    $redirect_url = add_query_arg('settings-updated', 'true', wp_get_referer() ?: admin_url('admin.php?page=securehold-settings'));
    wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Return a masked display string for a Stripe credential.
 * Shows the key prefix (up to 8 chars) + bullet placeholders + last 4 chars.
 * The real key value is never placed in an HTML attribute or input value.
 *
 * Returns trusted HTML (the dynamic parts are already escaped internally via
 * esc_html()/esc_html__()). Callers must echo the result through wp_kses()
 * with the matching whitelist below — never wrap it in esc_html(), which
 * would escape the markup itself and print the raw tags as text.
 *
 * @param  string $key The raw credential value.
 * @return string      Safe HTML snippet (uses esc_html internally).
 */
function securehold_mask_stripe_key( $key ) {
    if ( empty( $key ) ) {
        return '<em class="securehold-not-configured">' . esc_html__( 'Not configured', 'securehold-security-deposit-holds' ) . '</em>';
    }
    $prefix_len = min( 8, strlen( $key ) );
    $prefix     = substr( $key, 0, $prefix_len );
    $last4      = ( strlen( $key ) > $prefix_len + 4 ) ? substr( $key, -4 ) : '';
    $dot_count  = max( 0, strlen( $key ) - $prefix_len - strlen( $last4 ) );
    $dots       = str_repeat( "\xE2\x80\xA2", $dot_count ); // UTF-8 bullet •
    return '<code class="securehold-masked-secret">' . esc_html( $prefix . $dots . $last4 ) . '</code>';
}

/**
 * Strict wp_kses() whitelist for securehold_mask_stripe_key() output.
 * Only <code class="securehold-masked-secret"> and <em class="securehold-not-configured">
 * are allowed — no other tags or attributes pass through.
 *
 * @param string $html Output of securehold_mask_stripe_key().
 * @return string
 */
function securehold_kses_masked_key( $html ) {
    return wp_kses( $html, array(
        'code' => array( 'class' => array() ),
        'em'   => array( 'class' => array() ),
    ) );
}

// Get current settings
$stripe_mode = get_option('securehold_stripe_mode', 'test');
$mode_status = function_exists( 'securehold_get_stripe_mode_status' ) ? securehold_get_stripe_mode_status() : null;
$test_publishable = get_option('securehold_stripe_test_publishable_key', '');
$test_secret = get_option('securehold_stripe_test_secret_key', '');
$live_publishable = get_option('securehold_stripe_live_publishable_key', '');
$live_secret = get_option('securehold_stripe_live_secret_key', '');
$webhook_secret = get_option('securehold_webhook_secret', ''); 
$currency = get_option('securehold_stripe_currency', 'USD');
$deposit_amount = get_option('securehold_default_hold_amount', '300'); 
$auto_release_days = get_option('securehold_auto_release_days', '7');

// Timing strategy. FREE supports two strategies: Immediate and Manual.
$capture_timing = get_option('securehold_capture_timing', 'immediate');
$sh_allowed_timings = apply_filters( 'securehold_capture_timing_whitelist', array( 'immediate', 'manual' ) );
if ( ! in_array( $capture_timing, $sh_allowed_timings, true ) ) {
    $capture_timing = 'immediate';
}

$stripe_status = 'incomplete';
if ($stripe_mode === 'test' && !empty($test_publishable) && !empty($test_secret)) $stripe_status = 'test';
elseif ($stripe_mode === 'live' && !empty($live_publishable) && !empty($live_secret)) $stripe_status = 'live';

$webhook_url = get_rest_url(null, 'securehold/v1/webhook');
$active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'connection';

// Backward compatibility: redirect old tabs to rule engine
if ( $active_tab === 'product-rules' ) {
    $active_tab = 'rule-engine-products';
}
if ( in_array( $active_tab, array( 'automation', 'deposits' ), true ) ) {
    $active_tab = 'rule-engine-global';
}
// FREE fallback: Product/Category Rules sub-tabs are PRO-only. When PRO is not
// active, redirect those sub-tab keys to the Global Configuration sub-tab so a
// direct URL never lands on an empty page. PRO keeps its own routing.
if ( in_array( $active_tab, array( 'rule-engine-products', 'rule-engine-categories' ), true )
     && ! securehold_rule_engine_enabled() ) {
    $active_tab = 'rule-engine-global';
}

/**
 * Base tab definitions for the Settings page.
 * PRO extends this array via the securehold_settings_tabs filter.
 *
 * Array structure per entry:
 *   'label'       => string  (translated display label)
 *   'icon'        => string  (dashicons class, e.g. 'dashicons-admin-network')
 *   'active_keys' => array   (tab slug values that should mark this tab as active)
 *
 * @since 4.5.0
 */
$base_tabs = array(
    'connection'    => array(
        'label'       => __( 'Connection', 'securehold-security-deposit-holds' ),
        'icon'        => 'dashicons-admin-network',
        'active_keys' => array( 'connection' ),
    ),
    'rule-engine'   => array(
        'label'       => __( 'Deposit Rules', 'securehold-security-deposit-holds' ),
        'icon'        => 'dashicons-networking',
        'active_keys' => array( 'rule-engine', 'rule-engine-global', 'rule-engine-products', 'rule-engine-categories' ),
    ),
    'notifications' => array(
        'label'       => __( 'Notifications', 'securehold-security-deposit-holds' ),
        'icon'        => 'dashicons-email',
        'active_keys' => array( 'notifications' ),
    ),
    'frontend'      => array(
        'label'       => __( 'Appearance & Frontend', 'securehold-security-deposit-holds' ),
        'icon'        => 'dashicons-admin-appearance',
        'active_keys' => array( 'frontend' ),
    ),
);

/**
 * Filter the Settings page tab definitions.
 *
 * PRO uses this to inject additional tabs (Rules, Automation, etc.)
 * into the nav before it is rendered. Each entry must follow the
 * same structure as the base tabs above.
 *
 * @since 4.5.0
 * @param array  $tabs        Associative array of tab definitions keyed by tab slug.
 * @param string $active_tab  Currently active tab key.
 */
$tabs = apply_filters( 'securehold_settings_tabs', $base_tabs, $active_tab );
?>

<div class="wrap securehold-wrapper securehold-tab-<?php echo esc_attr($active_tab); ?>">
    
    <?php 
    Securehold_Admin::render_page_header(
        __('Settings', 'securehold-security-deposit-holds'),
        __('Configure your SecureHold WP security deposits and Stripe integration', 'securehold-security-deposit-holds'),
        'dashicons-admin-settings'
    ); 
    ?>

    <?php if ( isset( $_GET['settings-updated'] ) && $_GET['settings-updated'] === 'true' && $active_tab !== 'notifications' && ! get_transient( 'securehold_credential_errors' ) ) : ?>
    <div class="sh-alert sh-alert-success" style="animation: slideInDown 0.3s ease; margin-top: 2rem;">
        <span class="dashicons dashicons-yes-alt" style="color: var(--sh-success); font-size: 24px;"></span>
        <div>
            <strong><?php esc_html_e('Settings Saved!', 'securehold-security-deposit-holds'); ?></strong>
            <p><?php esc_html_e('Your SecureHold WP configuration has been updated successfully.', 'securehold-security-deposit-holds'); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Display a settings validation error, if one was set during the save handler.
    $settings_error_msg = get_transient('securehold_settings_error');
    if ($settings_error_msg) :
        delete_transient('securehold_settings_error');
    ?>
    <div class="sh-alert sh-alert-danger" style="animation: slideInDown 0.3s ease; margin-top: 2rem; border-left: 4px solid #dc2626; background: #fef2f2;">
        <span class="dashicons dashicons-warning" style="color: #dc2626; font-size: 24px;"></span>
        <div>
            <strong><?php esc_html_e('Settings NOT saved', 'securehold-security-deposit-holds'); ?></strong>
            <p><?php echo esc_html($settings_error_msg); ?></p>
        </div>
    </div>
    <?php endif; ?>

    <?php
    // Display Stripe credential validation errors (fields rejected due to invalid format).
    $credential_errors = get_transient( 'securehold_credential_errors' );
    if ( ! empty( $credential_errors ) ) :
        delete_transient( 'securehold_credential_errors' );
    ?>
    <div class="sh-alert sh-alert-danger" style="animation: slideInDown 0.3s ease; margin-top: 2rem; border-left: 4px solid #dc2626; background: #fef2f2;">
        <span class="dashicons dashicons-warning" style="color: #dc2626; font-size: 24px;"></span>
        <div>
            <strong><?php esc_html_e( 'Invalid Stripe credentials — the following fields were not saved:', 'securehold-security-deposit-holds' ); ?></strong>
            <ul style="margin: 0.5rem 0 0; padding-left: 1.2rem;">
            <?php foreach ( $credential_errors as $err ) : ?>
                <li><?php echo wp_kses( $err, array( 'strong' => array(), 'code' => array() ) ); ?></li>
            <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="sh-tabs-wrapper">
        <nav class="sh-tabs-nav">
            <?php foreach ( $tabs as $tab_slug => $tab ) :
                $is_active = in_array( $active_tab, $tab['active_keys'], true );
            ?>
            <a href="<?php echo esc_url( add_query_arg( array( 'page' => 'securehold-settings', 'tab' => $tab_slug ), admin_url( 'admin.php' ) ) ); ?>"
               class="sh-tab-link <?php echo $is_active ? 'active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr( $tab['icon'] ); ?>"></span>
                <?php echo esc_html( $tab['label'] ); ?>
            </a>
            <?php endforeach; ?>
            <?php
            /**
             * Allow PRO to inject additional tab navigation links not covered
             * by the securehold_settings_tabs filter (e.g. tabs with complex
             * active detection or non-standard URL structures).
             *
             * @since 4.5.0
             * @param string $active_tab  Currently active tab key.
             */
            do_action( 'securehold_settings_tabs_nav', $active_tab );
            ?>
        </nav>
    </div>

    <form method="post" action="" id="securehold-settings-form">
        <?php wp_nonce_field('securehold_save_settings', 'securehold_settings_nonce'); ?>
        
        <div class="sh-settings-grid">
            
            <div class="sh-settings-main">
                
                <?php if ($active_tab === 'connection') : ?>
                   <div class="sh-card sh-card-animated">
                        <div class="sh-card-header" style="background: linear-gradient(to right, var(--sh-gray-50), white); border-bottom: 2px solid var(--sh-gray-100);">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--sh-primary), #1e40af); border-radius: var(--sh-radius-lg); display: flex; align-items: center; justify-content: center; box-shadow: var(--sh-shadow-md);">
                                    <span class="dashicons dashicons-admin-network" style="color: white; font-size: 24px; width: 24px; height: 24px;"></span>
                                </div>
                                <div style="flex: 1;">
                                    <h2 class="sh-card-title" style="margin: 0;"><?php esc_html_e('Stripe Configuration', 'securehold-security-deposit-holds'); ?></h2>
                                    <p style="margin: 0.25rem 0 0 0; color: var(--sh-gray-600); font-size: 0.875rem;"><?php esc_html_e('Connect and configure your Stripe account', 'securehold-security-deposit-holds'); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div style="padding: 2rem;">
                            <div class="sh-setting-row-enhanced">
                                <div class="sh-setting-label-enhanced">
                                    <label><strong style="font-size: 1rem;"><?php esc_html_e('Stripe Mode', 'securehold-security-deposit-holds'); ?></strong></label>
                                    <p class="description"><?php esc_html_e('Use Test mode for development, Live mode for production', 'securehold-security-deposit-holds'); ?></p>
                                </div>
                                <div class="sh-setting-input-enhanced">
                                    <div class="sh-toggle-group">
                                        <input type="radio" name="securehold_stripe_mode" id="mode_test" value="test" <?php checked($stripe_mode, 'test'); ?>>
                                        <label for="mode_test" class="sh-toggle-option"><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('Test', 'securehold-security-deposit-holds'); ?></label>
                                        <input type="radio" name="securehold_stripe_mode" id="mode_live" value="live" <?php checked($stripe_mode, 'live'); ?>>
                                        <label for="mode_live" class="sh-toggle-option"><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Live', 'securehold-security-deposit-holds'); ?></label>
                                    </div>
                                </div>
                            </div>

                            <?php if ( $mode_status ) : ?>
                                <?php if ( ! $mode_status['gateway_active'] ) : ?>
                                    <div class="notice notice-warning inline">
                                        <p><?php esc_html_e( 'WooCommerce Stripe Gateway not detected — mode alignment cannot be verified.', 'securehold-security-deposit-holds' ); ?></p>
                                    </div>
                                <?php elseif ( ! $mode_status['aligned'] ) : ?>
                                    <div class="notice notice-warning inline">
                                        <p><?php
                                            printf(
                                                /* translators: 1: SecureHold mode (e.g. Live), 2: WooCommerce Stripe mode (e.g. Test) */
                                                esc_html__( 'Mode mismatch: SecureHold is set to %1$s but the WooCommerce Stripe Gateway is set to %2$s. Security deposits will be blocked until modes match.', 'securehold-security-deposit-holds' ),
                                                esc_html( ucfirst( $mode_status['securehold'] ) ),
                                                esc_html( ucfirst( $mode_status['woocommerce'] ) )
                                            );
                                        ?></p>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>

                            <div class="sh-divider-gradient"></div>

                            <h3 style="margin-top:0;"><?php esc_html_e( 'API Keys', 'securehold-security-deposit-holds' ); ?></h3>

                            <!-- Test mode fields -->
                            <div id="sh-api-fields-test" <?php echo ( $stripe_mode !== 'test' ) ? 'style="display:none;"' : ''; ?>>
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e( 'Test Publishable Key', 'securehold-security-deposit-holds' ); ?></label>
                                    <div class="sh-key-display" id="sh-display-test-publishable">
                                        <?php echo securehold_kses_masked_key( securehold_mask_stripe_key( $test_publishable ) ); ?>
                                        <button type="button" class="sh-key-update-btn" data-display="sh-display-test-publishable" data-edit="sh-edit-test-publishable"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Update', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                    <div class="sh-input-wrapper sh-key-edit" id="sh-edit-test-publishable" style="display:none;">
                                        <input type="text" name="securehold_stripe_test_publishable_key" value="" placeholder="<?php esc_attr_e( 'Paste new key to replace', 'securehold-security-deposit-holds' ); ?>" class="sh-input-modern" autocomplete="off">
                                        <button type="button" class="sh-key-cancel-btn" data-display="sh-display-test-publishable" data-edit="sh-edit-test-publishable"><?php esc_html_e( 'Cancel', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                </div>
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e( 'Test Secret Key', 'securehold-security-deposit-holds' ); ?></label>
                                    <div class="sh-key-display" id="sh-display-test-secret">
                                        <?php echo securehold_kses_masked_key( securehold_mask_stripe_key( $test_secret ) ); ?>
                                        <button type="button" class="sh-key-update-btn" data-display="sh-display-test-secret" data-edit="sh-edit-test-secret"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Update', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                    <div class="sh-input-wrapper sh-key-edit" id="sh-edit-test-secret" style="display:none;">
                                        <input type="text" name="securehold_stripe_test_secret_key" value="" placeholder="<?php esc_attr_e( 'Paste new key to replace', 'securehold-security-deposit-holds' ); ?>" class="sh-input-modern" autocomplete="off">
                                        <button type="button" class="sh-key-cancel-btn" data-display="sh-display-test-secret" data-edit="sh-edit-test-secret"><?php esc_html_e( 'Cancel', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                </div>
                            </div>

                            <!-- Live mode fields -->
                            <div id="sh-api-fields-live" <?php echo ( $stripe_mode !== 'live' ) ? 'style="display:none;"' : ''; ?>>
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e( 'Live Publishable Key', 'securehold-security-deposit-holds' ); ?></label>
                                    <div class="sh-key-display" id="sh-display-live-publishable">
                                        <?php echo securehold_kses_masked_key( securehold_mask_stripe_key( $live_publishable ) ); ?>
                                        <button type="button" class="sh-key-update-btn" data-display="sh-display-live-publishable" data-edit="sh-edit-live-publishable"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Update', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                    <div class="sh-input-wrapper sh-key-edit" id="sh-edit-live-publishable" style="display:none;">
                                        <input type="text" name="securehold_stripe_live_publishable_key" value="" placeholder="<?php esc_attr_e( 'Paste new key to replace', 'securehold-security-deposit-holds' ); ?>" class="sh-input-modern" autocomplete="off">
                                        <button type="button" class="sh-key-cancel-btn" data-display="sh-display-live-publishable" data-edit="sh-edit-live-publishable"><?php esc_html_e( 'Cancel', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                </div>
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e( 'Live Secret Key', 'securehold-security-deposit-holds' ); ?></label>
                                    <div class="sh-key-display" id="sh-display-live-secret">
                                        <?php echo securehold_kses_masked_key( securehold_mask_stripe_key( $live_secret ) ); ?>
                                        <button type="button" class="sh-key-update-btn" data-display="sh-display-live-secret" data-edit="sh-edit-live-secret"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Update', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                    <div class="sh-input-wrapper sh-key-edit" id="sh-edit-live-secret" style="display:none;">
                                        <input type="text" name="securehold_stripe_live_secret_key" value="" placeholder="<?php esc_attr_e( 'Paste new key to replace', 'securehold-security-deposit-holds' ); ?>" class="sh-input-modern" autocomplete="off">
                                        <button type="button" class="sh-key-cancel-btn" data-display="sh-display-live-secret" data-edit="sh-edit-live-secret"><?php esc_html_e( 'Cancel', 'securehold-security-deposit-holds' ); ?></button>
                                    </div>
                                </div>
                            </div>

                            <div class="sh-divider-gradient"></div>
                            <h3 style="margin-top:0;"><?php esc_html_e( 'Webhook', 'securehold-security-deposit-holds' ); ?></h3>
                            <div class="sh-input-field">
                                <label class="sh-label-modern"><?php esc_html_e( 'Webhook Secret', 'securehold-security-deposit-holds' ); ?></label>
                                <div class="sh-key-display" id="sh-display-webhook-secret">
                                    <?php echo securehold_kses_masked_key( securehold_mask_stripe_key( $webhook_secret ) ); ?>
                                    <button type="button" class="sh-key-update-btn" data-display="sh-display-webhook-secret" data-edit="sh-edit-webhook-secret"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Update', 'securehold-security-deposit-holds' ); ?></button>
                                </div>
                                <div class="sh-input-wrapper sh-key-edit" id="sh-edit-webhook-secret" style="display:none;">
                                    <input type="text" name="securehold_webhook_secret" value="" placeholder="<?php esc_attr_e( 'Paste new webhook secret to replace', 'securehold-security-deposit-holds' ); ?>" class="sh-input-modern" autocomplete="off">
                                    <button type="button" class="sh-key-cancel-btn" data-display="sh-display-webhook-secret" data-edit="sh-edit-webhook-secret"><?php esc_html_e( 'Cancel', 'securehold-security-deposit-holds' ); ?></button>
                                </div>
                                <p class="description"><?php esc_html_e( 'URL:', 'securehold-security-deposit-holds' ); ?> <code><?php echo esc_url( $webhook_url ); ?></code></p>
                            </div>

                            <div class="sh-divider-gradient"></div>
                            <h3 style="margin-top:0;"><?php esc_html_e('Diagnostics', 'securehold-security-deposit-holds'); ?></h3>
                            <div class="sh-input-field">
                                <label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
                                    <input type="checkbox" name="securehold_enable_logging" value="yes" <?php checked(get_option('securehold_enable_logging', 'no'), 'yes'); ?>>
                                    <strong><?php esc_html_e('Enable Debug Logging', 'securehold-security-deposit-holds'); ?></strong>
                                </label>
                                <p class="description" style="margin-top:0.5rem;">
                                    <?php esc_html_e('When enabled, SecureHold logs detailed technical information (Layer 1/2/3 injection details, Stripe request args, hook names). When disabled, only essential events (hold created, hold failed, warnings, errors) are logged. Errors and warnings are always logged regardless of this setting.', 'securehold-security-deposit-holds'); ?>
                                </p>
                            </div>
                        </div>
                   </div>
                <?php endif; ?>
                
                
                <?php if ($active_tab === 'deposits') : ?>
                
                    <div class="sh-card sh-card-animated">
                        <div class="sh-card-header" style="background: linear-gradient(to right, var(--sh-gray-50), white); border-bottom: 2px solid var(--sh-gray-100);">
                            <div style="display: flex; align-items: center; gap: 1rem;">
                                <div style="width: 48px; height: 48px; background: linear-gradient(135deg, #F59E0B, #D97706); border-radius: var(--sh-radius-lg); display: flex; align-items: center; justify-content: center; box-shadow: var(--sh-shadow-md);">
                                    <span class="dashicons dashicons-money-alt" style="color: white; font-size: 24px; width: 24px; height: 24px;"></span>
                                </div>
                                <div style="flex: 1;">
                                    <h2 class="sh-card-title" style="margin: 0;"><?php esc_html_e('Deposit Settings', 'securehold-security-deposit-holds'); ?></h2>
                                    <p style="margin: 0.25rem 0 0 0; color: var(--sh-gray-600); font-size: 0.875rem;"><?php esc_html_e('Configure default deposit amounts and behavior', 'securehold-security-deposit-holds'); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div style="padding: 2rem;">
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem;">
    
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e('Currency', 'securehold-security-deposit-holds'); ?></label>
                                    <div class="sh-select-wrapper">
                                        <select name="securehold_stripe_currency" class="sh-select-modern">
                                            <option value="USD" <?php selected($currency, 'USD'); ?>>USD - United States Dollar</option>
                                            <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR - Euro</option>
                                            <option value="GBP" <?php selected($currency, 'GBP'); ?>>GBP - British Pound</option>
                                            <option value="CAD" <?php selected($currency, 'CAD'); ?>>CAD - Canadian Dollar</option>
                                            <option value="AUD" <?php selected($currency, 'AUD'); ?>>AUD - Australian Dollar</option>
                                        </select>
                                        <span class="sh-select-icon"><span class="dashicons dashicons-arrow-down-alt2"></span></span>
                                    </div>
                                </div>
                                
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e('Default Hold Amount', 'securehold-security-deposit-holds'); ?></label>
                                    <div class="sh-input-wrapper">
                                        <input type="text" name="securehold_default_hold_amount" value="<?php echo esc_attr($deposit_amount); ?>" placeholder="e.g. 300 or 20%" class="sh-input-modern">
                                    </div>
                                </div>
                                
                                <div class="sh-input-field">
                                    <label class="sh-label-modern"><?php esc_html_e('Auto-Release After (Days)', 'securehold-security-deposit-holds'); ?></label>
                                    <div class="sh-input-wrapper">
                                        <input type="number" name="securehold_auto_release_days" value="<?php echo esc_attr($auto_release_days); ?>" min="1" max="7" class="sh-input-modern">
                                    </div>
                                    <p class="description"><?php esc_html_e('Automatically release the blocked amount after X days (1 to 7, default: 7).', 'securehold-security-deposit-holds'); ?></p>
                                </div>
                                
                            </div>
                        </div>
                    </div>
                
                <?php endif; ?>
                
                <?php if ( in_array( $active_tab, array( 'rule-engine', 'rule-engine-global', 'rule-engine-products', 'rule-engine-categories' ), true ) ) : ?>

                    <?php
                    // Default sub-tab
                    $rule_sub = $active_tab;
                    if ( $rule_sub === 'rule-engine' ) {
                        $rule_sub = 'rule-engine-global';
                    }
                    ?>

                    <!-- Sub-tabs navigation -->
                    <div class="sh-subtabs-nav">
                        <a href="?page=securehold-settings&tab=rule-engine-global" class="sh-subtab-link <?php echo $rule_sub === 'rule-engine-global' ? 'active' : ''; ?>">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php esc_html_e( 'Global Configuration', 'securehold-security-deposit-holds' ); ?>
                        </a>
                        <?php if ( securehold_rule_engine_enabled() ) : ?>
                        <a href="?page=securehold-settings&tab=rule-engine-products" class="sh-subtab-link <?php echo $rule_sub === 'rule-engine-products' ? 'active' : ''; ?>">
                            <span class="dashicons dashicons-products"></span>
                            <?php esc_html_e( 'Product Rules', 'securehold-security-deposit-holds' ); ?>
                        </a>
                        <a href="?page=securehold-settings&tab=rule-engine-categories" class="sh-subtab-link <?php echo $rule_sub === 'rule-engine-categories' ? 'active' : ''; ?>">
                            <span class="dashicons dashicons-category"></span>
                            <?php esc_html_e( 'Category Rules', 'securehold-security-deposit-holds' ); ?>
                        </a>
                        <?php endif; ?>
                        <button type="button" class="sh-subtab-link sh-clause-modal-trigger"
                                aria-haspopup="dialog" aria-controls="sh-clause-modal"
                                style="margin-left:auto;">
                            <span class="dashicons dashicons-media-document"></span>
                            <?php esc_html_e( 'Deposit Clause', 'securehold-security-deposit-holds' ); ?>
                        </button>
                    </div>

                    <!-- ── Deposit Clause Modal ── -->
                    <div id="sh-clause-modal" class="sh-clause-modal" role="dialog"
                         aria-modal="true" aria-labelledby="sh-clause-modal-title" hidden>
                        <div class="sh-clause-modal-backdrop"></div>
                        <div class="sh-clause-modal-dialog">
                            <div class="sh-clause-modal-header">
                                <h2 id="sh-clause-modal-title" class="sh-clause-modal-title">
                                    <span class="dashicons dashicons-media-document"></span>
                                    <?php esc_html_e( 'Deposit Clause (Terms)', 'securehold-security-deposit-holds' ); ?>
                                </h2>
                                <button type="button" class="sh-clause-modal-close" aria-label="<?php esc_attr_e( 'Close', 'securehold-security-deposit-holds' ); ?>">
                                    <span class="dashicons dashicons-no-alt"></span>
                                </button>
                            </div>
                            <div class="sh-clause-modal-body">
                                <p class="sh-re-caption">
                                    <?php esc_html_e( 'Copy this clause into your Terms &amp; Conditions. Compatible with EU (2011/83/EU) and US contract disclosure standards.', 'securehold-security-deposit-holds' ); ?>
                                </p>
                                <div class="sh-re-clause-box" id="sh-cgv-clause-text">
                                    <p><strong><?php esc_html_e( 'Security Deposit Authorization', 'securehold-security-deposit-holds' ); ?></strong></p>
                                    <p><?php esc_html_e( 'By placing this order, you acknowledge that a security deposit may be authorized on your payment method to secure this transaction.', 'securehold-security-deposit-holds' ); ?></p>
                                    <p><?php esc_html_e( 'This authorization is not an additional charge. It is a temporary hold and may be captured only in accordance with the terms of service (e.g., damages, unpaid fees, or contractual breaches).', 'securehold-security-deposit-holds' ); ?></p>
                                    <p><?php esc_html_e( 'The deposit authorization is strictly linked to this order and will not be used for unrelated future purchases.', 'securehold-security-deposit-holds' ); ?></p>
                                    <p><?php esc_html_e( 'If no claim is made, the authorization will expire automatically according to your bank\'s release timeframe.', 'securehold-security-deposit-holds' ); ?></p>
                                </div>
                            </div>
                            <div class="sh-clause-modal-footer">
                                <button type="button" id="sh-copy-clause-btn" class="sh-btn sh-btn-primary sh-re-clause-copy-btn">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <?php esc_html_e( 'Copy Clause', 'securehold-security-deposit-holds' ); ?>
                                </button>
                                <button type="button" class="sh-btn sh-btn-secondary sh-clause-modal-close">
                                    <?php esc_html_e( 'Close', 'securehold-security-deposit-holds' ); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <?php if ( $rule_sub === 'rule-engine-global' ) : ?>
                        <!-- Global Configuration sub-tab -->
                        <?php include plugin_dir_path( __FILE__ ) . 'rule-engine-global-tab.php'; ?>
                    <?php elseif ( $rule_sub === 'rule-engine-products' && securehold_rule_engine_enabled() ) : ?>
                        <!-- Product Rules: rendered by PRO via securehold_settings_tab_content. -->
                    <?php elseif ( $rule_sub === 'rule-engine-categories' && securehold_rule_engine_enabled() ) : ?>
                        <!-- Category Rules: rendered by PRO via securehold_settings_tab_content. -->
                    <?php endif; ?>

                <?php endif; ?>
                
                <?php if ($active_tab === 'notifications') : ?>
                    <?php include plugin_dir_path(__FILE__) . 'notifications-tab.php'; ?>
                <?php endif; ?>
                
                <?php if ($active_tab === 'frontend') : ?>
                    <?php include plugin_dir_path(__FILE__) . 'frontend-tab.php'; ?>
                <?php endif; ?>

                <?php
                /**
                 * Allow PRO to render additional settings tab content panels.
                 *
                 * PRO hooks here to output content for tabs injected via
                 * securehold_settings_tabs or securehold_settings_tabs_nav.
                 *
                 * @since 4.5.0
                 * @param string $active_tab  Currently active tab key.
                 */
                do_action( 'securehold_settings_tab_content', $active_tab );
                ?>


                <?php if ( $active_tab !== 'notifications' ) : ?>
                <div class="sh-card" style="margin-top: 2rem; background: transparent; box-shadow: none; border: none; padding: 0; display: flex; justify-content: flex-end;">
                    <button type="submit" id="sh-save-settings-btn" class="sh-btn sh-btn-primary sh-btn-lg" style="padding: 0.75rem 2rem; font-size: 1rem;">
                        <span class="dashicons dashicons-saved" style="margin-right: 8px;"></span>
                        <?php esc_html_e('Save Settings', 'securehold-security-deposit-holds'); ?>
                    </button>
                </div>
                <?php endif; ?>

            </div>
            
            <div class="sh-settings-sidebar">
                <?php if ($active_tab === 'frontend') : ?>
                    <!-- Available Variables -->
                    <div class="sh-card">
                        <div style="padding: 1.5rem;">
                            <h4><?php esc_html_e('Available Variables', 'securehold-security-deposit-holds'); ?></h4>
                             <?php
                            require_once plugin_dir_path(dirname(__FILE__)) . '../includes/class-securehold-wp-frontend-manager.php';
                            $variables = Securehold_Frontend_Manager::get_available_variables();
                            foreach ($variables as $category_key => $category) : ?>
                                <div class="sh-variables-category-sidebar">
                                    <h5><?php echo esc_html($category['label']); ?></h5>
                                    <ul>
                                        <?php foreach ($category['variables'] as $var => $description) : ?>
                                            <li><code class="sh-variable-sidebar"><?php echo esc_html($var); ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Shortcodes Reference -->
                    <div class="sh-card">
                        <div style="padding: 1.5rem;">
                            <h4 style="display:flex;align-items:center;gap:0.5rem;">
                                <span class="dashicons dashicons-editor-code" style="color:var(--sh-primary);"></span>
                                <?php esc_html_e( 'Shortcodes', 'securehold-security-deposit-holds' ); ?>
                            </h4>
                            <p style="margin:0.25rem 0 1rem;font-size:0.8125rem;color:#6b7280;">
                                <?php esc_html_e( 'Display deposit information anywhere on your site.', 'securehold-security-deposit-holds' ); ?>
                            </p>

                            <div class="sh-shortcode-item" style="margin-bottom:1rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                                    <code class="sh-variable-sidebar">[securehold_my_deposits]</code>
                                    <button type="button" class="sh-copy-shortcode" data-shortcode="[securehold_my_deposits]" title="<?php esc_attr_e( 'Copy', 'securehold-security-deposit-holds' ); ?>" style="background:none;border:none;cursor:pointer;padding:2px;">
                                        <span class="dashicons dashicons-admin-page" style="font-size:14px;width:14px;height:14px;color:#6b7280;"></span>
                                    </button>
                                </div>
                                <p style="margin:0.25rem 0 0;font-size:0.75rem;color:#6b7280;">
                                    <?php esc_html_e( 'Customer deposits list. Login required.', 'securehold-security-deposit-holds' ); ?>
                                </p>
                            </div>

                            <div class="sh-shortcode-item" style="margin-bottom:1rem;">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                                    <code class="sh-variable-sidebar">[securehold_deposit_status]</code>
                                    <button type="button" class="sh-copy-shortcode" data-shortcode='[securehold_deposit_status order_id=""]' title="<?php esc_attr_e( 'Copy', 'securehold-security-deposit-holds' ); ?>" style="background:none;border:none;cursor:pointer;padding:2px;">
                                        <span class="dashicons dashicons-admin-page" style="font-size:14px;width:14px;height:14px;color:#6b7280;"></span>
                                    </button>
                                </div>
                                <p style="margin:0.25rem 0 0;font-size:0.75rem;color:#6b7280;">
                                    <?php esc_html_e( 'Single deposit status.', 'securehold-security-deposit-holds' ); ?>
                                    <code style="font-size:0.7rem;">order_id</code> <?php esc_html_e( 'required.', 'securehold-security-deposit-holds' ); ?>
                                </p>
                            </div>

                            <div class="sh-shortcode-item">
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                                    <code class="sh-variable-sidebar">[securehold_checkout_message]</code>
                                    <button type="button" class="sh-copy-shortcode" data-shortcode="[securehold_checkout_message]" title="<?php esc_attr_e( 'Copy', 'securehold-security-deposit-holds' ); ?>" style="background:none;border:none;cursor:pointer;padding:2px;">
                                        <span class="dashicons dashicons-admin-page" style="font-size:14px;width:14px;height:14px;color:#6b7280;"></span>
                                    </button>
                                </div>
                                <p style="margin:0.25rem 0 0;font-size:0.75rem;color:#6b7280;">
                                    <?php esc_html_e( 'Checkout message on any page. Requires active cart.', 'securehold-security-deposit-holds' ); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php elseif ( in_array( $active_tab, array( 'rule-engine', 'rule-engine-global', 'rule-engine-products', 'rule-engine-categories' ), true ) ) : ?>

                    <!-- ══════════════════════════════════════════
                         DEPOSIT PREVIEW
                         Add products to preview the deposit amount for any cart.
                         Reflects the global configuration, including unsaved values.
                         ══════════════════════════════════════════ -->
                    <div class="sh-card sh-sim-panel" id="sh-rule-sim-panel">
                        <div class="sh-card-header" style="background:linear-gradient(to right,var(--sh-gray-50),white);border-bottom:2px solid var(--sh-gray-100);">
                            <h2 class="sh-card-title" style="font-size:1rem;">
                                <span class="dashicons dashicons-visibility"></span>
                                <?php esc_html_e( 'Deposit Preview', 'securehold-security-deposit-holds' ); ?>
                            </h2>
                        </div>
                        <div class="sh-card-body" style="padding:1.25rem;">

                            <!-- Product Search (Add to Cart) -->
                            <div class="sh-sim-section">
                                <label class="sh-sim-heading" for="sh-sim-product-search"><?php esc_html_e( 'Add Products', 'securehold-security-deposit-holds' ); ?></label>
                                <select id="sh-sim-product-search"
                                        class="sh-select-modern"
                                        data-placeholder="<?php esc_attr_e( 'Search and add a product…', 'securehold-security-deposit-holds' ); ?>"
                                        style="width:100%;">
                                </select>
                            </div>

                            <!-- Simulated Cart -->
                            <div id="sh-sim-cart" class="sh-sim-cart" style="display:none;">
                                <div class="sh-sim-cart-header">
                                    <span class="sh-sim-heading" style="margin-bottom:0;">
                                        <span class="dashicons dashicons-cart" style="font-size:14px;width:14px;height:14px;"></span>
                                        <?php esc_html_e( 'Cart', 'securehold-security-deposit-holds' ); ?>
                                        <span id="sh-sim-cart-count" class="sh-sim-cart-count">0</span>
                                    </span>
                                    <button type="button" id="sh-sim-cart-clear" class="sh-sim-cart-clear" title="<?php esc_attr_e( 'Clear cart', 'securehold-security-deposit-holds' ); ?>">
                                        <span class="dashicons dashicons-dismiss"></span>
                                    </button>
                                </div>
                                <div id="sh-sim-cart-items" class="sh-sim-cart-items">
                                    <!-- Rendered by JS -->
                                </div>
                            </div>

                            <!-- Loading state -->
                            <div id="sh-sim-loading" style="display:none;text-align:center;padding:1.5rem 0;">
                                <span class="dashicons dashicons-update" style="animation:spin 1s infinite linear;font-size:24px;width:24px;height:24px;color:var(--sh-primary);"></span>
                                <p style="margin:0.5rem 0 0;color:var(--sh-gray-500);font-size:0.8125rem;"><?php esc_html_e( 'Simulating…', 'securehold-security-deposit-holds' ); ?></p>
                            </div>

                            <!-- Empty state (default) -->
                            <div id="sh-sim-empty" class="sh-sim-empty-state">
                                <span class="dashicons dashicons-products" style="font-size:32px;width:32px;height:32px;color:var(--sh-gray-300);"></span>
                                <p><?php esc_html_e( 'Add products above to preview the deposit amount for a cart.', 'securehold-security-deposit-holds' ); ?></p>
                            </div>

                            <!-- Results container (hidden until simulation runs) -->
                            <div id="sh-sim-results" style="display:none;">

                                <div class="sh-sim-divider"></div>

                                <!-- Block 1 — Simulation Context -->
                                <div class="sh-sim-section">
                                    <h4 class="sh-sim-heading">
                                        <span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;"></span>
                                        <?php esc_html_e( 'Simulation Context', 'securehold-security-deposit-holds' ); ?>
                                    </h4>
                                    <div class="sh-sim-context-grid" id="sh-sim-context">
                                        <!-- Rendered by JS -->
                                    </div>
                                </div>

                                <div class="sh-sim-divider"></div>

                                <!-- Block 2 — Evaluation (mode-dependent: per-item cards or order-level flow) -->
                                <div id="sh-sim-evaluation">
                                    <!-- Rendered by JS -->
                                </div>

                                <div class="sh-sim-divider"></div>

                                <!-- Block 3 — Final Resolution / Aggregate -->
                                <div class="sh-sim-section">
                                    <h4 class="sh-sim-heading">
                                        <span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px;color:var(--sh-success);"></span>
                                        <span id="sh-sim-resolution-title"><?php esc_html_e( 'Final Resolution', 'securehold-security-deposit-holds' ); ?></span>
                                    </h4>
                                    <div id="sh-sim-resolution" class="sh-sim-resolution-grid">
                                        <!-- Rendered by JS -->
                                    </div>
                                </div>

                            </div>


                        </div>
                    </div>


                <?php else : ?>
                    <div class="sh-card">
                        <div class="sh-card-header">
                            <h2 class="sh-card-title">
                                <span class="dashicons dashicons-admin-tools"></span>
                                <?php esc_html_e('Quick Actions', 'securehold-security-deposit-holds'); ?>
                            </h2>
                        </div>

                        <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-deposits' ) ); ?>" class="sh-btn sh-btn-primary" style="justify-content: center;">
                                <span class="dashicons dashicons-list-view"></span>
                                View All Deposits
                            </a>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-settings' ) ); ?>" class="sh-btn sh-btn-secondary" style="justify-content: center;">
                                <span class="dashicons dashicons-admin-settings"></span>
                                Settings
                            </a>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-health' ) ); ?>" class="sh-btn sh-btn-secondary" style="justify-content: center;">
                                <span class="dashicons dashicons-yes-alt"></span>
                                Health Check
                            </a>
                        </div>
                    </div>

                    <div class="sh-card" style="margin-top: 1.5rem;">
                        <div style="padding: 1.5rem;">
                             <h4><?php esc_html_e('Configuration Status', 'securehold-security-deposit-holds'); ?></h4>

                             <div class="sh-status-item-modern">
                                <span>Mode</span>
                                <span class="sh-badge sh-badge-<?php echo esc_attr( $stripe_status === 'live' ? 'success' : 'warning' ); ?>"><?php echo esc_html( ucfirst( $stripe_status ) ); ?></span>
                             </div>

                             <div class="sh-status-item-modern">
                                <span>API Keys</span>
                                <?php if ($stripe_status !== 'incomplete') : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #10b981; font-size: 20px;"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-warning" style="color: #ef4444; font-size: 20px;"></span>
                                <?php endif; ?>
                             </div>

                             <div class="sh-status-item-modern">
                                <span>Webhook</span>
                                <?php if (!empty($webhook_secret)) : ?>
                                    <span class="dashicons dashicons-yes-alt" style="color: #10b981; font-size: 20px;"></span>
                                <?php else : ?>
                                    <span class="dashicons dashicons-warning" style="color: #ef4444; font-size: 20px;"></span>
                                <?php endif; ?>
                             </div>

                             <div class="sh-status-item-modern">
                                <span>Trigger</span>
                                <span class="sh-badge sh-badge-info"><?php echo esc_html($capture_timing); ?></span>
                             </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<?php /* Settings page styles: assets/css/admin-settings.css (handle: securehold-admin-settings) */ ?>

