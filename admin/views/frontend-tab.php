<?php
/**
 * SecureHold Frontend & Appearance Tab
 * Customization options for checkout and My Account
 */

if (!defined('ABSPATH')) exit;

// Load frontend manager to get available variables
require_once plugin_dir_path(dirname(__FILE__)) . '../includes/class-securehold-wp-frontend-manager.php';

// Get current settings
$enable_checkout_message = get_option('securehold_enable_checkout_message', true);
$checkout_message = get_option('securehold_checkout_message', '');
$checkout_message_style = get_option('securehold_checkout_message_style', 'info');
$checkout_message_position = get_option('securehold_checkout_message_position', 'before');

$enable_my_account_tab = get_option('securehold_enable_my_account_tab', true);
$my_account_menu_label = get_option('securehold_my_account_menu_label', __('My Deposits', 'securehold-security-deposit-holds'));
$my_account_page_title = get_option('securehold_my_account_page_title', __('My Security Deposits', 'securehold-security-deposit-holds'));
$my_account_page_description = get_option('securehold_my_account_page_description', __('View and manage your security deposits for active reservations.', 'securehold-security-deposit-holds'));
$my_account_empty_message = get_option('securehold_my_account_empty_message', __('You have no security deposits at this time.', 'securehold-security-deposit-holds'));

// Get available variables
$variables = Securehold_Frontend_Manager::get_available_variables();

?>

<div class="sh-frontend-wrapper">
    
    <!-- ========================================== -->
    <!-- SECTION 1: CHECKOUT MESSAGE -->
    <!-- ========================================== -->
    <div class="sh-card">
        <div class="sh-card-header">
            <h2 class="sh-card-title">
                <span class="dashicons dashicons-cart"></span>
                <?php esc_html_e('Checkout Message', 'securehold-security-deposit-holds'); ?>
            </h2>
            <p class="sh-card-description">
                <?php esc_html_e('Customize the security deposit message displayed during checkout. Use variables to make it dynamic.', 'securehold-security-deposit-holds'); ?>
            </p>
        </div>
        
        <div class="sh-card-body">
            
            <!-- Enable/Disable -->
            <div class="sh-form-group">
                <label class="sh-toggle-wrapper">
                    <input type="checkbox" 
                           name="securehold_enable_checkout_message" 
                           value="1" 
                           <?php checked($enable_checkout_message, true); ?>>
                    <span class="sh-toggle-slider"></span>
                    <span class="sh-toggle-label">
                        <strong><?php esc_html_e('Display checkout message', 'securehold-security-deposit-holds'); ?></strong>
                        <span class="sh-help-text"><?php esc_html_e('Show security deposit information at checkout', 'securehold-security-deposit-holds'); ?></span>
                    </span>
                </label>
            </div>
            
            <div id="checkout-message-settings" style="<?php echo !$enable_checkout_message ? 'display:none;' : ''; ?>">
                
                <!-- Message Editor -->
                <div class="sh-form-group">
                    <label class="sh-label">
                        <?php esc_html_e('Checkout Message', 'securehold-security-deposit-holds'); ?>
                        <span class="sh-required">*</span>
                    </label>
                    <?php
                    /*
                     * Default must match Securehold_Frontend_Manager::get_default_checkout_message()
                     * so the admin editor shows the exact same text used on the live checkout.
                     */
                    $default_message = sprintf(
                        /* translators: %s is replaced with the deposit amount wrapped in <strong> tags */
                        __( 'A security deposit of %s may be authorized on your payment method in accordance with the store\'s deposit policy. This is an authorization only and not an additional charge.', 'securehold-security-deposit-holds' ),
                        '<strong>{amount}</strong>'
                    );

                    wp_editor(
                        $checkout_message ?: $default_message,
                        'securehold_checkout_message',
                        array(
                            'textarea_name' => 'securehold_checkout_message',
                            'textarea_rows' => 6,
                            'media_buttons' => false,
                            'teeny' => true,
                            'quicktags' => true,
                        )
                    );
                    ?>
                    <p class="sh-help-text">
                        <?php esc_html_e('This message will be displayed on the checkout page. Use the variables from the sidebar to make it dynamic.', 'securehold-security-deposit-holds'); ?>
                    </p>
                </div>
                
                <!-- Message Style -->
                <div class="sh-form-row">
                    <div class="sh-form-group">
                        <label class="sh-label"><?php esc_html_e('Message Style', 'securehold-security-deposit-holds'); ?></label>
                        <select name="securehold_checkout_message_style" class="sh-select">
                            <option value="info" <?php selected($checkout_message_style, 'info'); ?>>
                                <?php esc_html_e('Info (Blue)', 'securehold-security-deposit-holds'); ?>
                            </option>
                            <option value="warning" <?php selected($checkout_message_style, 'warning'); ?>>
                                <?php esc_html_e('Warning (Orange)', 'securehold-security-deposit-holds'); ?>
                            </option>
                            <option value="success" <?php selected($checkout_message_style, 'success'); ?>>
                                <?php esc_html_e('Success (Green)', 'securehold-security-deposit-holds'); ?>
                            </option>
                        </select>
                    </div>

                    <div class="sh-form-group">
                        <label class="sh-label"><?php esc_html_e('Display Position', 'securehold-security-deposit-holds'); ?></label>
                        <select name="securehold_checkout_message_position" class="sh-select">
                            <option value="before" <?php selected($checkout_message_position, 'before'); ?>>
                                <?php esc_html_e('Before payment methods', 'securehold-security-deposit-holds'); ?>
                            </option>
                            <option value="after" <?php selected($checkout_message_position, 'after'); ?>>
                                <?php esc_html_e('After payment methods', 'securehold-security-deposit-holds'); ?>
                            </option>
                        </select>
                    </div>
                </div>
                
                <!-- Preview -->
                <div class="sh-form-group">
                    <label class="sh-label"><?php esc_html_e('Preview', 'securehold-security-deposit-holds'); ?></label>
                    <div id="checkout-message-preview" class="sh-message-preview">
                        <div class="sh-preview-note">
                            <?php esc_html_e('Preview will show here with example values', 'securehold-security-deposit-holds'); ?>
                        </div>
                    </div>
                    <button type="button" id="preview-checkout-message" class="sh-button sh-button-secondary">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e('Update Preview', 'securehold-security-deposit-holds'); ?>
                    </button>
                </div>
                
            </div>
        </div>
    </div>
    
    <!-- ========================================== -->
    <!-- SECTION 2: MY ACCOUNT PAGE -->
    <!-- ========================================== -->
    <div class="sh-card" style="margin-top: 2rem;">
        <div class="sh-card-header">
            <h2 class="sh-card-title">
                <span class="dashicons dashicons-admin-users"></span>
                <?php esc_html_e('My Account Page', 'securehold-security-deposit-holds'); ?>
            </h2>
            <p class="sh-card-description">
                <?php esc_html_e('Add a "My Deposits" page in WooCommerce My Account where customers can view their security deposits.', 'securehold-security-deposit-holds'); ?>
            </p>
        </div>

        <div class="sh-card-body">

            <!-- Enable/Disable -->
            <div class="sh-form-group">
                <label class="sh-toggle-wrapper">
                    <input type="checkbox"
                           name="securehold_enable_my_account_tab"
                           value="1"
                           <?php checked($enable_my_account_tab, true); ?>>
                    <span class="sh-toggle-slider"></span>
                    <span class="sh-toggle-label">
                        <strong><?php esc_html_e('Enable My Account tab', 'securehold-security-deposit-holds'); ?></strong>
                        <span class="sh-help-text"><?php esc_html_e('Add "My Deposits" section to customer My Account page', 'securehold-security-deposit-holds'); ?></span>
                    </span>
                </label>
            </div>

            <div id="my-account-settings" style="<?php echo !$enable_my_account_tab ? 'display:none;' : ''; ?>">

                <!-- Menu Label -->
                <div class="sh-form-group">
                    <label class="sh-label">
                        <?php esc_html_e('Menu Label', 'securehold-security-deposit-holds'); ?>
                        <span class="sh-required">*</span>
                    </label>
                    <input type="text"
                           name="securehold_my_account_menu_label"
                           value="<?php echo esc_attr($my_account_menu_label); ?>"
                           class="sh-input"
                           placeholder="<?php esc_html_e('My Deposits', 'securehold-security-deposit-holds'); ?>">
                    <p class="sh-help-text">
                        <?php esc_html_e('The text shown in the My Account menu', 'securehold-security-deposit-holds'); ?>
                    </p>
                </div>

                <!-- Page Title -->
                <div class="sh-form-group">
                    <label class="sh-label">
                        <?php esc_html_e('Page Title', 'securehold-security-deposit-holds'); ?>
                        <span class="sh-required">*</span>
                    </label>
                    <input type="text"
                           name="securehold_my_account_page_title"
                           value="<?php echo esc_attr($my_account_page_title); ?>"
                           class="sh-input"
                           placeholder="<?php esc_html_e('My Security Deposits', 'securehold-security-deposit-holds'); ?>">
                </div>

                <!-- Page Description -->
                <div class="sh-form-group">
                    <label class="sh-label"><?php esc_html_e('Page Description', 'securehold-security-deposit-holds'); ?></label>
                    <textarea name="securehold_my_account_page_description"
                              rows="2"
                              class="sh-textarea"
                              placeholder="<?php esc_html_e('View and manage your security deposits...', 'securehold-security-deposit-holds'); ?>"><?php echo esc_textarea($my_account_page_description); ?></textarea>
                    <p class="sh-help-text">
                        <?php esc_html_e('Optional description shown at the top of the page', 'securehold-security-deposit-holds'); ?>
                    </p>
                </div>

                <!-- Empty State Message -->
                <div class="sh-form-group">
                    <label class="sh-label"><?php esc_html_e('Empty State Message', 'securehold-security-deposit-holds'); ?></label>
                    <input type="text"
                           name="securehold_my_account_empty_message"
                           value="<?php echo esc_attr($my_account_empty_message); ?>"
                           class="sh-input"
                           placeholder="<?php esc_html_e('You have no security deposits at this time.', 'securehold-security-deposit-holds'); ?>">
                    <p class="sh-help-text">
                        <?php esc_html_e('Message displayed when customer has no deposits', 'securehold-security-deposit-holds'); ?>
                    </p>
                </div>

                <!-- Info Box -->
                <div class="sh-alert sh-alert-info">
                    <span class="dashicons dashicons-info"></span>
                    <div>
                        <strong><?php esc_html_e('Flush Rewrite Rules', 'securehold-security-deposit-holds'); ?></strong>
                        <p><?php esc_html_e('After enabling/disabling this feature, you may need to go to Settings > Permalinks and click "Save Changes" to flush the rewrite rules.', 'securehold-security-deposit-holds'); ?></p>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Shortcodes documentation moved to sidebar (settings-page.php) -->

</div>
