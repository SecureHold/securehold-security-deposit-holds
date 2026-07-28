<?php
/**
 * SecureHold Notifications Tab
 * Email management interface
 */

if (!defined('ABSPATH')) exit;

// Load email manager
require_once plugin_dir_path(dirname(__FILE__)) . 'class-securehold-wp-emails.php';

$email_types = Securehold_Emails::get_email_types();
$variables = Securehold_Emails::get_available_variables();

// Active email type (from URL or first one)
$active_email = isset($_GET['email_type']) ? sanitize_text_field(wp_unslash($_GET['email_type'])) : 'customer_hold_created';

// Determine if the active email is "coming soon" (no WC class / no trigger yet)
$active_email_config  = isset( $email_types[ $active_email ] ) ? $email_types[ $active_email ] : array();
$is_active_coming_soon = ! empty( $active_email_config['coming_soon'] );

// Get current settings (only needed for non-coming-soon emails)
$current_settings = $is_active_coming_soon ? array() : Securehold_Emails::get_email_settings( $active_email );
?>

<div class="sh-notifications-wrapper">

    <!-- Two-column row: sidebar + editor -->
    <div class="sh-notifications-layout">

    <!-- Emails List Sidebar -->
    <div class="sh-emails-sidebar">
        <div class="sh-sidebar-header">
            <h3><?php esc_html_e('Email Notifications', 'securehold-security-deposit-holds'); ?></h3>
            <p><?php esc_html_e('Customize email templates sent to customers and admins', 'securehold-security-deposit-holds'); ?></p>
        </div>
        
        <!-- ── Master Notification Toggles ─────────────────────── -->
        <div class="sh-master-settings">
            <h4><?php esc_html_e( 'Global Settings', 'securehold-security-deposit-holds' ); ?></h4>
            <?php
            $sh_master_enabled    = get_option( 'securehold_notifications_enabled', 'yes' ) === 'yes';
            $sh_client_enabled    = get_option( 'securehold_notifications_client_enabled', 'yes' ) === 'yes';
            $sh_admin_enabled     = get_option( 'securehold_notifications_admin_enabled', 'yes' ) === 'yes';
            $sh_disable_wc_footer = get_option( 'securehold_disable_wc_footer_for_emails', 'no' ) === 'yes';
            ?>
            <div class="sh-master-toggle-row">
                <span class="sh-master-toggle-label"><?php esc_html_e( 'All Notifications', 'securehold-security-deposit-holds' ); ?></span>
                <label class="sh-toggle-switch">
                    <input type="checkbox"
                           class="sh-master-toggle-input"
                           data-setting="securehold_notifications_enabled"
                           <?php checked( $sh_master_enabled ); ?>>
                    <span class="sh-toggle-slider"></span>
                </label>
            </div>
            <div class="sh-master-toggle-row">
                <span class="sh-master-toggle-label"><?php esc_html_e( 'Customer Emails', 'securehold-security-deposit-holds' ); ?></span>
                <label class="sh-toggle-switch">
                    <input type="checkbox"
                           class="sh-master-toggle-input"
                           data-setting="securehold_notifications_client_enabled"
                           <?php checked( $sh_client_enabled ); ?>>
                    <span class="sh-toggle-slider"></span>
                </label>
            </div>
            <div class="sh-master-toggle-row">
                <span class="sh-master-toggle-label"><?php esc_html_e( 'Admin Emails', 'securehold-security-deposit-holds' ); ?></span>
                <label class="sh-toggle-switch">
                    <input type="checkbox"
                           class="sh-master-toggle-input"
                           data-setting="securehold_notifications_admin_enabled"
                           <?php checked( $sh_admin_enabled ); ?>>
                    <span class="sh-toggle-slider"></span>
                </label>
            </div>
            <!-- Separator row -->
            <div class="sh-master-toggle-row" style="margin-top:8px;padding-top:8px;border-top:1px solid #e5e7eb;">
                <span class="sh-master-toggle-label"
                      style="font-size:12px;color:#6b7280;"
                      title="<?php esc_attr_e( 'When enabled, the WooCommerce global email footer is hidden for SecureHold emails — use this to prevent a duplicate footer when a custom footer is already set per-email above.', 'securehold-security-deposit-holds' ); ?>">
                    <?php esc_html_e( 'Hide WC global footer', 'securehold-security-deposit-holds' ); ?>
                </span>
                <label class="sh-toggle-switch">
                    <input type="checkbox"
                           class="sh-master-toggle-input"
                           data-setting="securehold_disable_wc_footer_for_emails"
                           <?php checked( $sh_disable_wc_footer ); ?>>
                    <span class="sh-toggle-slider"></span>
                </label>
            </div>
        </div><!-- /.sh-master-settings -->

        <div class="sh-emails-list">
            <!-- Customer Emails -->
            <div class="sh-email-category">
                <div class="sh-category-header">
                    <span class="dashicons dashicons-admin-users"></span>
                    <?php esc_html_e('Customer Emails', 'securehold-security-deposit-holds'); ?>
                </div>
                <?php foreach ($email_types as $type => $config) : ?>
                    <?php if ($config['recipient'] === 'customer') : ?>
                        <?php
                        $item_is_active      = ( $active_email === $type );
                        $item_is_coming_soon = ! empty( $config['coming_soon'] );
                        $item_is_enabled     = false;
                        if ( ! $item_is_coming_soon ) {
                            $item_settings   = Securehold_Emails::get_email_settings( $type );
                            $item_is_enabled = $item_settings['enabled'];
                        }
                        ?>
                        <a href="?page=securehold-settings&tab=notifications&email_type=<?php echo esc_attr($type); ?>"
                           class="sh-email-item <?php echo $item_is_active ? 'active' : ''; ?>"
                           data-email-type="<?php echo esc_attr($type); ?>">
                            <div class="sh-email-item-header">
                                <span class="sh-email-name"><?php echo esc_html($config['name']); ?></span>
                                <?php if ( $item_is_coming_soon ) : ?>
                                <span class="sh-email-status coming-soon" title="<?php esc_attr_e( 'Coming Soon', 'securehold-security-deposit-holds' ); ?>">
                                    <span class="dashicons dashicons-clock"></span>
                                </span>
                                <?php else : ?>
                                <span class="sh-email-status <?php echo $item_is_enabled ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons dashicons-<?php echo $item_is_enabled ? 'yes-alt' : 'dismiss'; ?>"></span>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="sh-email-description"><?php echo esc_html($config['description']); ?></p>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Admin Emails -->
            <div class="sh-email-category">
                <div class="sh-category-header">
                    <span class="dashicons dashicons-admin-settings"></span>
                    <?php esc_html_e('Admin Emails', 'securehold-security-deposit-holds'); ?>
                </div>
                <?php foreach ($email_types as $type => $config) : ?>
                    <?php if ($config['recipient'] === 'admin') : ?>
                        <?php
                        $item_is_active      = ( $active_email === $type );
                        $item_is_coming_soon = ! empty( $config['coming_soon'] );
                        $item_is_enabled     = false;
                        if ( ! $item_is_coming_soon ) {
                            $item_settings   = Securehold_Emails::get_email_settings( $type );
                            $item_is_enabled = $item_settings['enabled'];
                        }
                        ?>
                        <a href="?page=securehold-settings&tab=notifications&email_type=<?php echo esc_attr($type); ?>"
                           class="sh-email-item <?php echo $item_is_active ? 'active' : ''; ?>"
                           data-email-type="<?php echo esc_attr($type); ?>">
                            <div class="sh-email-item-header">
                                <span class="sh-email-name"><?php echo esc_html($config['name']); ?></span>
                                <?php if ( $item_is_coming_soon ) : ?>
                                <span class="sh-email-status coming-soon" title="<?php esc_attr_e( 'Coming Soon', 'securehold-security-deposit-holds' ); ?>">
                                    <span class="dashicons dashicons-clock"></span>
                                </span>
                                <?php else : ?>
                                <span class="sh-email-status <?php echo $item_is_enabled ? 'enabled' : 'disabled'; ?>">
                                    <span class="dashicons dashicons-<?php echo $item_is_enabled ? 'yes-alt' : 'dismiss'; ?>"></span>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="sh-email-description"><?php echo esc_html($config['description']); ?></p>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Email Editor -->
    <div class="sh-email-editor">

        <!-- Editor Header -->
        <div class="sh-editor-header">
            <div>
                <h2><?php echo esc_html( $active_email_config['name'] ); ?></h2>
                <p><?php echo esc_html( $active_email_config['description'] ); ?></p>
            </div>
            <div class="sh-editor-actions">
                <?php if ( ! $is_active_coming_soon ) : ?>
                <label class="sh-toggle-switch">
                    <input type="checkbox"
                           id="sh-email-enabled"
                           <?php checked( $current_settings['enabled'], true ); ?>>
                    <span class="sh-toggle-slider"></span>
                    <span class="sh-toggle-label"><?php esc_html_e( 'Enabled', 'securehold-security-deposit-holds' ); ?></span>
                </label>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $is_active_coming_soon ) : ?>

        <!-- Coming Soon Panel -->
        <div class="sh-coming-soon-panel">
            <div class="sh-coming-soon-icon">
                <span class="dashicons dashicons-clock"></span>
            </div>
            <h3><?php esc_html_e( 'Coming Soon', 'securehold-security-deposit-holds' ); ?></h3>
            <p><?php esc_html_e( 'This email notification is planned for a future release of SecureHold WP. Stay tuned for updates!', 'securehold-security-deposit-holds' ); ?></p>
        </div>

        <?php else : ?>

        <!-- Editor: scroll container + padded inner wrapper.
             Using <div> not <form> — the outer settings page wraps all tab
             content in a <form> and HTML disallows nested forms. The browser
             silently drops any nested <form> tag, so .sh-editor-form never
             appears in the DOM. All saves use type="button" + JS AJAX handlers,
             so no <form> element is needed here. -->
        <div class="sh-editor-scroll">
        <div class="sh-editor-inner">
            <!-- Legacy-key identifier (used internally by PHP save handler) -->
            <input type="hidden" id="sh-email-type" value="<?php echo esc_attr( $active_email ); ?>">
            <!-- WooCommerce email class ID — stable identifier sent with every save request -->
            <input type="hidden" id="sh-email-id"   value="<?php echo esc_attr( Securehold_Emails::get_wc_id_for_legacy_key( $active_email ) ); ?>">
            
            <!-- Subject Line -->
            <div class="sh-form-group">
                <label class="sh-form-label">
                    <span class="dashicons dashicons-email"></span>
                    <?php esc_html_e('Email Subject', 'securehold-security-deposit-holds'); ?>
                </label>
                <input type="text" 
                       id="sh-email-subject" 
                       class="sh-form-input" 
                       value="<?php echo esc_attr($current_settings['subject']); ?>" 
                       placeholder="<?php esc_html_e('Enter email subject...', 'securehold-security-deposit-holds'); ?>">
                <p class="sh-form-hint"><?php esc_html_e('Use variables like {order_number}, {customer_name}, etc.', 'securehold-security-deposit-holds'); ?></p>
            </div>
            
            <!-- Email Heading -->
            <div class="sh-form-group">
                <label class="sh-form-label">
                    <span class="dashicons dashicons-editor-alignleft"></span>
                    <?php esc_html_e('Email Heading', 'securehold-security-deposit-holds'); ?>
                </label>
                <input type="text" 
                       id="sh-email-heading" 
                       class="sh-form-input" 
                       value="<?php echo esc_attr($current_settings['heading']); ?>" 
                       placeholder="<?php esc_html_e('Optional heading displayed at the top', 'securehold-security-deposit-holds'); ?>">
            </div>
            
            <!-- Email Body Editor -->
            <div class="sh-form-group">
                <div class="sh-body-label-row">
                    <label class="sh-form-label" style="margin-bottom:0;">
                        <span class="dashicons dashicons-edit"></span>
                        <?php esc_html_e('Email Body', 'securehold-security-deposit-holds'); ?>
                    </label>
                    <!-- Editor Mode Toggle (Task 3) -->
                    <div class="sh-editor-mode-toggle" role="group" aria-label="<?php esc_attr_e('Editor mode', 'securehold-security-deposit-holds'); ?>">
                        <button type="button" class="sh-mode-btn active" data-mode="simple">
                            <span class="dashicons dashicons-editor-alignleft"></span>
                            <?php esc_html_e('Simple', 'securehold-security-deposit-holds'); ?>
                        </button>
                        <button type="button" class="sh-mode-btn" data-mode="code">
                            <span class="dashicons dashicons-editor-code"></span>
                            <?php esc_html_e('HTML', 'securehold-security-deposit-holds'); ?>
                        </button>
                    </div>
                </div>

                <!-- Editor Toolbar (Simple mode only) -->
                <div class="sh-editor-toolbar" id="sh-simple-toolbar">
                    <button type="button" class="sh-editor-btn" data-action="bold" title="Bold">
                        <span class="dashicons dashicons-editor-bold"></span>
                    </button>
                    <button type="button" class="sh-editor-btn" data-action="italic" title="Italic">
                        <span class="dashicons dashicons-editor-italic"></span>
                    </button>
                    <button type="button" class="sh-editor-btn" data-action="insertUnorderedList" title="Bullet List">
                        <span class="dashicons dashicons-editor-ul"></span>
                    </button>
                    <button type="button" class="sh-editor-btn" data-action="insertOrderedList" title="Numbered List">
                        <span class="dashicons dashicons-editor-ol"></span>
                    </button>
                    <button type="button" class="sh-editor-btn" data-action="createLink" title="Insert Link">
                        <span class="dashicons dashicons-admin-links"></span>
                    </button>
                    <div class="sh-block-format-group" role="group"
                         aria-label="<?php esc_attr_e( 'Text style', 'securehold-security-deposit-holds' ); ?>">
                        <button type="button" class="sh-block-btn" data-block="p"
                                title="<?php esc_attr_e( 'Paragraph', 'securehold-security-deposit-holds' ); ?>">
                            <?php esc_html_e( 'P', 'securehold-security-deposit-holds' ); ?>
                        </button>
                        <button type="button" class="sh-block-btn" data-block="h2"
                                title="<?php esc_attr_e( 'Heading 2', 'securehold-security-deposit-holds' ); ?>">
                            <?php esc_html_e( 'H2', 'securehold-security-deposit-holds' ); ?>
                        </button>

                    </div>
                    <div class="sh-toolbar-separator"></div>
                    <button type="button" class="sh-editor-btn" id="sh-show-variables" title="Insert Variable">
                        <span class="dashicons dashicons-plus-alt"></span>
                        <?php esc_html_e('Variables', 'securehold-security-deposit-holds'); ?>
                    </button>
                    <button type="button" class="sh-editor-btn" id="sh-preview-email" title="Preview">
                        <span class="dashicons dashicons-visibility"></span>
                        <?php esc_html_e('Preview', 'securehold-security-deposit-holds'); ?>
                    </button>
                </div>

                <!-- Simple (contenteditable) Editor -->
                <div id="sh-email-body"
                     class="sh-email-editor-content"
                     contenteditable="true"><?php echo wp_kses_post($current_settings['body']); ?></div>

                <!-- Code (CodeMirror) Editor (Task 3) -->
                <div class="sh-code-editor-wrap" style="display:none;">
                    <!-- Toolbar row shared with code mode -->
                    <div class="sh-editor-toolbar sh-code-toolbar">
                        <button type="button" class="sh-editor-btn" id="sh-show-variables-code" title="Insert Variable">
                            <span class="dashicons dashicons-plus-alt"></span>
                            <?php esc_html_e('Variables', 'securehold-security-deposit-holds'); ?>
                        </button>
                        <button type="button" class="sh-editor-btn" id="sh-preview-email-code" title="Preview">
                            <span class="dashicons dashicons-visibility"></span>
                            <?php esc_html_e('Preview', 'securehold-security-deposit-holds'); ?>
                        </button>
                    </div>
                    <textarea id="sh-email-body-code"><?php echo esc_textarea($current_settings['body']); ?></textarea>
                </div>

                <textarea id="sh-email-body-hidden" style="display:none;"><?php echo esc_textarea($current_settings['body']); ?></textarea>
            </div>
            
            <!-- Email Footer -->
            <div class="sh-form-group">
                <label class="sh-form-label">
                    <span class="dashicons dashicons-editor-aligncenter"></span>
                    <?php esc_html_e('Email Footer', 'securehold-security-deposit-holds'); ?>
                </label>
                <textarea id="sh-email-footer" 
                          class="sh-form-textarea" 
                          rows="3" 
                          placeholder="<?php esc_html_e('Optional footer text (e.g., unsubscribe link, contact info)', 'securehold-security-deposit-holds'); ?>"><?php echo esc_textarea($current_settings['footer']); ?></textarea>
            </div>
            
        </div><!-- /.sh-editor-inner -->
        </div><!-- /.sh-editor-scroll -->

        <!-- Actions — outside .sh-editor-scroll so buttons are always visible
             at the card bottom regardless of scroll position.
             padding: 1rem 2rem 1.5rem matches .sh-editor-inner left/right
             spacing for visual alignment with the fields above. -->
        <div class="sh-form-actions">
            <button type="button" class="sh-btn sh-btn-secondary" id="sh-send-test-email">
                <span class="dashicons dashicons-email-alt"></span>
                <?php esc_html_e('Send Test Email', 'securehold-security-deposit-holds'); ?>
            </button>
            <button type="button" class="sh-btn sh-btn-secondary" id="sh-reset-template">
                <span class="dashicons dashicons-image-rotate"></span>
                <?php esc_html_e('Reset to Default', 'securehold-security-deposit-holds'); ?>
            </button>
            <!-- type="button" — never triggers the outer settings-page <form>.
                 The JS click handler on #sh-save-email-settings fires the
                 AJAX action securehold_save_email_settings instead. -->
            <button type="button" id="sh-save-email-settings" class="sh-btn sh-btn-primary">
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e('Save Changes', 'securehold-security-deposit-holds'); ?>
            </button>
        </div>

        <?php endif; // is_active_coming_soon ?>

    </div>

    </div><!-- /.sh-notifications-layout -->

</div><!-- /.sh-notifications-wrapper -->

<!-- Variables Sidebar -->
<div id="sh-variables-sidebar" class="sh-sidebar-overlay" style="display: none;">
    <div class="sh-sidebar-panel">
        <div class="sh-sidebar-header">
            <h3><?php esc_html_e('Available Variables', 'securehold-security-deposit-holds'); ?></h3>
            <button type="button" class="sh-sidebar-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="sh-sidebar-content">
            <p class="sh-sidebar-description">
                <?php esc_html_e('Click any variable to insert it at the cursor position', 'securehold-security-deposit-holds'); ?>
            </p>
            
            <?php foreach ($variables as $category_key => $category) : ?>
            <div class="sh-variable-category">
                <h4><?php echo esc_html($category['label']); ?></h4>
                <div class="sh-variables-list">
                    <?php foreach ($category['variables'] as $var => $description) : ?>
                    <button type="button" class="sh-variable-item" data-variable="<?php echo esc_attr($var); ?>">
                        <code><?php echo esc_html($var); ?></code>
                        <span><?php echo esc_html($description); ?></span>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Preview Modal (Task 3 — iframe so WC email styles are isolated) -->
<div id="sh-preview-modal" class="sh-modal-overlay" style="display: none;">
    <div class="sh-modal-container sh-preview-modal-container">
        <div class="sh-modal-header">
            <h3><?php esc_html_e('Email Preview', 'securehold-security-deposit-holds'); ?></h3>
            <button type="button" class="sh-modal-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="sh-preview-modal-body">
            <iframe id="sh-preview-frame"
                    class="sh-preview-frame"
                    sandbox="allow-same-origin"
                    title="<?php esc_attr_e('Email preview', 'securehold-security-deposit-holds'); ?>"></iframe>
        </div>
        <div class="sh-modal-footer">
            <button type="button" class="sh-btn sh-btn-secondary sh-modal-close">
                <?php esc_html_e('Close', 'securehold-security-deposit-holds'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Test Email Modal -->
<div id="sh-test-email-modal" class="sh-modal-overlay" style="display: none;">
    <div class="sh-modal-container sh-modal-small">
        <div class="sh-modal-header">
            <h3><?php esc_html_e('Send Test Email', 'securehold-security-deposit-holds'); ?></h3>
            <button type="button" class="sh-modal-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="sh-modal-body">
            <p><?php esc_html_e('Enter an email address to receive a test email with the current template:', 'securehold-security-deposit-holds'); ?></p>
            <input type="email" 
                   id="sh-test-email-address" 
                   class="sh-form-input" 
                   value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" 
                   placeholder="<?php esc_html_e('your@email.com', 'securehold-security-deposit-holds'); ?>">
        </div>
        <div class="sh-modal-footer">
            <button type="button" class="sh-btn sh-btn-secondary sh-modal-close">
                <?php esc_html_e('Cancel', 'securehold-security-deposit-holds'); ?>
            </button>
            <button type="button" class="sh-btn sh-btn-primary" id="sh-confirm-test-email">
                <span class="dashicons dashicons-email-alt"></span>
                <?php esc_html_e('Send Test', 'securehold-security-deposit-holds'); ?>
            </button>
        </div>
    </div>
</div>
