<?php
/**
 * Setup Wizard - Guides users through initial configuration
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Setup_Wizard {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_wizard_page'));
        add_action('admin_init', array($this, 'handle_wizard_actions'));
        add_action('admin_notices', array($this, 'show_wizard_notice'));
        add_action('wp_ajax_securehold_test_config', array($this, 'test_stripe_configuration'));
    }    
    /**
     * Add wizard page
     */
    public function add_wizard_page() {
        add_submenu_page(
            null, // Hidden from menu
            __('SecureHold WP Setup Wizard', 'securehold-security-deposit-holds'),
            __('Setup Wizard', 'securehold-security-deposit-holds'),
            'manage_woocommerce',
            'securehold-setup-wizard',
            array($this, 'render_wizard')
        );
    }
    
    /**
     * Show notice to complete setup
     */
    public function show_wizard_notice() {
        $setup_completed = get_option('securehold_setup_completed', false);
        
        if (!$setup_completed && current_user_can('manage_woocommerce')) {
            $screen = get_current_screen();
            if ($screen && $screen->id !== 'admin_page_securehold-setup-wizard') {
                ?>
                <div class="notice notice-info is-dismissible">
                    <p>
                        <strong><?php esc_html_e('SecureHold WP Setup Required', 'securehold-security-deposit-holds'); ?></strong><br>
                        <?php esc_html_e('Complete the setup wizard to start using security deposits.', 'securehold-security-deposit-holds'); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-setup-wizard' ) ); ?>" class="button button-primary" style="margin-left:10px;">
                            <?php esc_html_e('Start Setup', 'securehold-security-deposit-holds'); ?>
                        </a>
                    </p>
                </div>
                <?php
            }
        }
    }
    
    /**
     * Handle wizard actions
     */
    public function handle_wizard_actions() {
        if (!isset($_POST['securehold_wizard_action'])) {
            return;
        }
        
        check_admin_referer('securehold_wizard_nonce');

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die(
                esc_html__( 'You do not have permission to perform this action.', 'securehold-security-deposit-holds' ),
                403
            );
        }

        $action = sanitize_key( wp_unslash( $_POST['securehold_wizard_action'] ) );
        
        switch ($action) {
            case 'install_plugin':
                $this->handle_plugin_installation();
                break;
                
            case 'install_stripe':
                $this->install_stripe_sdk();
                break;
                
            case 'save_stripe_keys':
                $this->save_stripe_keys();
                break;
                
            case 'auto_configure_webhook':
                $this->auto_configure_webhook();
                break;
            case 'save_webhook':
                $this->save_webhook_secret();
                break;
    
            case 'complete_setup':
                update_option('securehold_setup_completed', true);
                update_option('securehold_setup_wizard_completed', true);
                wp_safe_redirect(admin_url('admin.php?page=securehold&setup=completed'));
                exit;
                break;
        }
    }
    
    /**
     * Handle plugin installation
     */
    private function handle_plugin_installation() {
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-plugin-installer.php';
        
        $plugin_slug = sanitize_text_field( wp_unslash( $_POST['plugin_slug'] ) );
        $result = Securehold_Plugin_Installer::install_and_activate($plugin_slug);
        
        if (is_wp_error($result)) {
            add_settings_error('securehold_wizard', 'install_failed', $result->get_error_message(), 'error');
        } else {
            add_settings_error('securehold_wizard', 'install_success', $result['message'], 'success');
            
            // Refresh page to update status
            wp_safe_redirect(admin_url('admin.php?page=securehold-setup-wizard&step=2'));
            exit;
        }
    }
    
    /**
     * Install Stripe SDK
     */
    private function install_stripe_sdk() {
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-stripe-installer.php';
        
        $result = Securehold_Stripe_Installer::install();
        
        if (is_wp_error($result)) {
            add_settings_error('securehold_wizard', 'install_failed', $result->get_error_message(), 'error');
        } else {
            add_settings_error('securehold_wizard', 'install_success', __('Stripe SDK installed successfully!', 'securehold-security-deposit-holds'), 'success');
        }
    }
    
    /**
     * Save Stripe API keys
     */
    private function save_stripe_keys() {

        $post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_wizard_actions()

        $mode = ( isset( $post['stripe_mode'] ) && sanitize_text_field( $post['stripe_mode'] ) === 'live' ) ? 'live' : 'test';

        $submitted = array(
            'securehold_stripe_test_publishable_key' => isset( $post['test_publishable_key'] ) ? trim( sanitize_text_field( $post['test_publishable_key'] ) ) : '',
            'securehold_stripe_test_secret_key'      => isset( $post['test_secret_key'] ) ? trim( sanitize_text_field( $post['test_secret_key'] ) ) : '',
            'securehold_stripe_live_publishable_key' => isset( $post['live_publishable_key'] ) ? trim( sanitize_text_field( $post['live_publishable_key'] ) ) : '',
            'securehold_stripe_live_secret_key'      => isset( $post['live_secret_key'] ) ? trim( sanitize_text_field( $post['live_secret_key'] ) ) : '',
        );

        $test_prefixes = securehold_stripe_key_prefixes( 'test' );
        $live_prefixes = securehold_stripe_key_prefixes( 'live' );

        $rules = array(
            'securehold_stripe_test_publishable_key' => array( __( 'Test Publishable Key', 'securehold-security-deposit-holds' ), $test_prefixes['publishable'], 'test' ),
            'securehold_stripe_test_secret_key'      => array( __( 'Test Secret Key', 'securehold-security-deposit-holds' ),      $test_prefixes['secret'],      'test' ),
            'securehold_stripe_live_publishable_key' => array( __( 'Live Publishable Key', 'securehold-security-deposit-holds' ), $live_prefixes['publishable'], 'live' ),
            'securehold_stripe_live_secret_key'      => array( __( 'Live Secret Key', 'securehold-security-deposit-holds' ),      $live_prefixes['secret'],      'live' ),
        );

        // ── Format check ──
        // An empty field keeps whatever is stored, so the form never has to echo a
        // secret back into the page just to survive a re-save.
        $errors  = array();
        $to_save = array();

        foreach ( $rules as $option => $rule ) {
            list( $label, $prefixes, $key_mode ) = $rule;
            $value = $submitted[ $option ];

            if ( $value === '' ) {
                if ( $key_mode === $mode && ! get_option( $option, '' ) ) {
                    /* translators: %s is a field label such as "Test Secret Key" */
                    $errors[] = sprintf( __( '%s is required for the selected mode.', 'securehold-security-deposit-holds' ), $label );
                }
                continue;
            }

            if ( ! securehold_validate_stripe_key( $value, $prefixes ) ) {
                // The rejected value is never echoed back — only the expected shape.
                $errors[] = sprintf(
                    /* translators: 1: field label, 2: comma-separated valid key prefixes */
                    __( '%1$s: value rejected — must start with %2$s.', 'securehold-security-deposit-holds' ),
                    $label,
                    implode( ' ' . __( 'or', 'securehold-security-deposit-holds' ) . ' ', $prefixes )
                );
                continue;
            }

            $to_save[ $option ] = $value;
        }

        if ( ! empty( $errors ) ) {
            self::set_wizard_notice( 'error', __( 'Stripe keys were not saved.', 'securehold-security-deposit-holds' ), $errors );
            wp_safe_redirect( admin_url( 'admin.php?page=securehold-setup-wizard&step=5' ) );
            exit;
        }

        // ── Persist ──
        update_option( 'securehold_stripe_mode', $mode );

        foreach ( $to_save as $option => $value ) {
            update_option( $option, $value );
        }

        // SecureHold stores its own Stripe credentials independently.
        // The WooCommerce Stripe gateway (woocommerce_stripe_settings) is configured separately
        // by the merchant in WooCommerce > Settings > Payments. SecureHold does not overwrite it
        // to prevent credential divergence after key rotation and to avoid breaking the WC Stripe
        // checkout gateway if merchants use it alongside SecureHold.

        // ── Verify against Stripe, and against what WooCommerce actually uses ──
        // Saving proves nothing on its own: this step used to report success for
        // any string a merchant pasted, including a key belonging to a different
        // Stripe account or test environment.
        $notice = self::diagnose_saved_credentials();

        self::set_wizard_notice( $notice['type'], $notice['message'], $notice['details'] );

        // Rejected credentials keep the merchant on this step — there is nothing
        // useful to configure further until they are fixed. Every other verdict,
        // including "cannot verify yet", is informational and lets setup proceed.
        $next_step = ( $notice['blocking'] ) ? 5 : 6;

        wp_safe_redirect( admin_url( 'admin.php?page=securehold-setup-wizard&step=' . $next_step ) );
        exit;
    }

    /**
     * Run the Stripe context diagnosis and phrase it for the wizard.
     *
     * @since 3.4.4
     *
     * @return array type, message, details, blocking.
     */
    private static function diagnose_saved_credentials() {

        if ( ! class_exists( 'Securehold_Stripe_Context' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe-context.php';
        }

        if ( ! class_exists( 'Securehold_Stripe_Context' ) ) {
            return array(
                'type'     => 'warning',
                'message'  => __( 'Stripe keys saved. SecureHold could not run its verification on this site.', 'securehold-security-deposit-holds' ),
                'details'  => array(),
                'blocking' => false,
            );
        }

        // The credentials just changed; never report a verdict computed for the
        // old ones. The webhook diagnosis is keyed to the credentials too, and a
        // rotation is exactly when its previous answer becomes misleading.
        Securehold_Stripe_Context::flush();

        if ( ! class_exists( 'Securehold_Webhook_Configurator' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-webhook-configurator.php';
        }
        if ( class_exists( 'Securehold_Webhook_Configurator' ) ) {
            Securehold_Webhook_Configurator::flush_status();
        }
        $context = Securehold_Stripe_Context::get( true );

        switch ( $context['status'] ) {

            case Securehold_Stripe_Context::STATUS_INVALID_CREDENTIALS:
                return array(
                    'type'     => 'error',
                    'message'  => __( 'Stripe authentication failed: the secret key you entered was rejected by Stripe. Check that you copied the whole key, from the Stripe environment you intend to use.', 'securehold-security-deposit-holds' ),
                    'details'  => array(),
                    'blocking' => true,
                );

            case Securehold_Stripe_Context::STATUS_MODE_MISMATCH:
                return array(
                    'type'    => 'error',
                    'message' => sprintf(
                        /* translators: 1: SecureHold mode, 2: WooCommerce Stripe mode */
                        __( 'Stripe keys saved, but SecureHold is in %1$s mode while the WooCommerce Stripe Gateway is in %2$s mode. Security deposits cannot be created until both use the same mode.', 'securehold-security-deposit-holds' ),
                        ucfirst( (string) $context['mode']['securehold'] ),
                        ucfirst( (string) $context['mode']['woocommerce'] )
                    ),
                    'details'  => array(),
                    'blocking' => false,
                );

            case Securehold_Stripe_Context::STATUS_ACCOUNT_MISMATCH:
                return array(
                    'type'    => 'error',
                    'message' => __( 'Your SecureHold Stripe credentials are valid, but they do not match the Stripe context used by WooCommerce Stripe.', 'securehold-security-deposit-holds' ),
                    'details' => array(
                        sprintf(
                            /* translators: 1: SecureHold Stripe account id, 2: WooCommerce Stripe account id */
                            __( 'SecureHold is connected to %1$s while WooCommerce is connected to %2$s. Security deposits will fail, because the payment does not exist in SecureHold\'s account.', 'securehold-security-deposit-holds' ),
                            $context['accounts']['securehold'],
                            $context['accounts']['woocommerce']
                        ),
                    ),
                    'blocking' => false,
                );

            case Securehold_Stripe_Context::STATUS_CONTEXT_INCOMPATIBLE:
                return array(
                    'type'    => 'error',
                    'message' => __( 'Your SecureHold Stripe credentials are valid, but they do not appear to match the Stripe context used by WooCommerce Stripe.', 'securehold-security-deposit-holds' ),
                    'details' => array(
                        sprintf(
                            /* translators: %s is a WooCommerce order number */
                            __( 'The payment on order #%s is not visible with these keys. This usually means they come from a different Stripe test environment or sandbox than the one WooCommerce is connected to.', 'securehold-security-deposit-holds' ),
                            $context['probe']['order_id']
                        ),
                        __( 'Open the Stripe environment where that payment appears, and copy the API keys from there.', 'securehold-security-deposit-holds' ),
                    ),
                    'blocking' => false,
                );

            case Securehold_Stripe_Context::STATUS_COMPATIBLE:
                return array(
                    'type'    => 'success',
                    'message' => sprintf(
                        /* translators: %s is a WooCommerce order number */
                        __( 'Stripe credentials are valid and match the Stripe context used by WooCommerce Stripe (verified against order #%s).', 'securehold-security-deposit-holds' ),
                        $context['probe']['order_id']
                    ),
                    'details'  => array(),
                    'blocking' => false,
                );

            default:
                // Valid credentials, verdict pending. Deliberately not dressed up as
                // a clean bill of health, and deliberately not an error either.
                $message = in_array( 'no_stripe_orders', $context['notes'], true )
                    ? __( 'Stripe credentials are valid. SecureHold could not yet verify compatibility with WooCommerce Stripe because no recent WooCommerce Stripe payment is available. No problem has been detected — this check will complete on its own after the first Stripe payment.', 'securehold-security-deposit-holds' )
                    : __( 'Stripe credentials are valid. SecureHold could not complete the compatibility check with WooCommerce Stripe right now. No problem has been detected — you can re-run the check from SecureHold → Health Check.', 'securehold-security-deposit-holds' );

                return array(
                    'type'     => 'warning',
                    'message'  => $message,
                    'details'  => array(),
                    'blocking' => false,
                );
        }
    }

    /**
     * Stash a notice that survives the post-redirect-get cycle.
     *
     * add_settings_error() cannot: this handler redirects, which discarded the
     * message the wizard used to raise here. Mirrors the transient pattern the
     * settings page already uses.
     *
     * @since 3.4.4
     *
     * @param string $type    success|warning|error.
     * @param string $message Main sentence.
     * @param array  $details Optional supporting lines.
     * @return void
     */
    private static function set_wizard_notice( $type, $message, $details = array() ) {
        set_transient(
            'securehold_wizard_stripe_notice_' . get_current_user_id(),
            array(
                'type'    => (string) $type,
                'message' => (string) $message,
                'details' => array_values( (array) $details ),
            ),
            120
        );
    }

    /**
     * Auto-configure webhook
     */
    private function auto_configure_webhook() {
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-webhook-configurator.php';
    
        $configurator = new Securehold_Webhook_Configurator();
        $result = $configurator->auto_configure();
    
        if (is_wp_error($result)) {
    
            add_settings_error(
                'securehold_wizard',
                'webhook_failed',
                $result->get_error_message(),
                'error'
            );
    
            return;
        }
    
        // Always store the Stripe webhook endpoint ID if provided
        if (!empty($result['endpoint_id'])) {
            update_option('securehold_webhook_endpoint_id', sanitize_text_field($result['endpoint_id']));
        }
    
        // Stripe only returns the signing secret when creating a brand new endpoint.
        // If we reused/updated an existing endpoint, secret may be empty: keep the existing stored secret.
        if (!empty($result['secret'])) {
            update_option('securehold_webhook_secret', sanitize_text_field($result['secret']));
        } else {
            $existing_secret = get_option('securehold_webhook_secret', '');
            if (empty($existing_secret)) {
                add_settings_error(
                    'securehold_wizard',
                    'webhook_secret_missing',
                    __(
                        'A webhook endpoint already exists in Stripe for this URL, but Stripe does not return the signing secret again. Please copy the signing secret (whsec_) from Stripe and paste it in the Webhook Secret field.',
                        'securehold-security-deposit-holds'
                    ),
                    'error'
                );
                return;
            }
        }
    
        // Success messaging (optional: differentiate reused vs created)
        if (!empty($result['reused'])) {
            add_settings_error(
                'securehold_wizard',
                'webhook_updated',
                __('Webhook endpoint updated successfully.', 'securehold-security-deposit-holds'),
                'success'
            );
        } else {
            add_settings_error(
                'securehold_wizard',
                'webhook_success',
                __('Webhook configured automatically!', 'securehold-security-deposit-holds'),
                'success'
            );
        }
    }
    
    private function save_webhook_secret() {
        $secret = isset($_POST['webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['webhook_secret'])) : '';
    
        if (empty($secret)) {
            add_settings_error('securehold_wizard', 'webhook_empty', __('Webhook secret is required.', 'securehold-security-deposit-holds'), 'error');
            return;
        }
    
        if (strpos($secret, 'whsec_') !== 0) {
            add_settings_error('securehold_wizard', 'webhook_invalid', __('Invalid webhook secret format. It should start with whsec_.', 'securehold-security-deposit-holds'), 'error');
            return;
        }
    
        update_option('securehold_webhook_secret', $secret);
    
        add_settings_error('securehold_wizard', 'webhook_saved', __('Webhook secret saved successfully.', 'securehold-security-deposit-holds'), 'success');
    }

    /**
     * Render wizard interface
     */
    public function render_wizard() {
        $current_step = isset($_GET['step']) ? intval($_GET['step']) : 1;
        
        // Enqueue step-bar centering script for all steps (mobile only — JS guards < 783px)
        wp_enqueue_script(
            'securehold-wizard-step-center',
            SECUREHOLD_PLUGIN_URL . 'assets/js/wizard-step-center.js',
            array(),
            SECUREHOLD_VERSION,
            true
        );

        // Enqueue test assets on complete step
        if ($current_step === 7) {
            // Suppress the Welcome modal once the user reaches the final wizard step.
            // Uses the modal-specific dismissal flag only — full setup completion is still
            // gated on the "Complete Setup" form submission at the bottom of step 7.
            if ( ! get_option( 'securehold_setup_modal_dismissed' ) ) {
                update_option( 'securehold_setup_modal_dismissed', true );
            }

            wp_enqueue_style(
                'securehold-wizard-test',
                SECUREHOLD_PLUGIN_URL . 'assets/css/wizard-test.css',
                array(),
                SECUREHOLD_VERSION
            );
            
            wp_enqueue_script(
                'securehold-wizard-test',
                SECUREHOLD_PLUGIN_URL . 'assets/js/wizard-test.js',
                array('jquery'),
                SECUREHOLD_VERSION,
                true
            );
            
            wp_localize_script('securehold-wizard-test', 'secureholdWizardTest', array(
                'nonce' => wp_create_nonce('securehold_test_config'),
                'testingText' => __('Testing...', 'securehold-security-deposit-holds'),
                'successText' => __('✅ Test Passed!', 'securehold-security-deposit-holds'),
                'errorText' => __('❌ Test Failed', 'securehold-security-deposit-holds'),
                'errorLabel' => __('Error', 'securehold-security-deposit-holds'),
                'ajaxErrorText' => __('Unable to run test. Please check your configuration.', 'securehold-security-deposit-holds')
            ));
        }
        
        require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/setup-wizard.php';
    }    
    /**
     * Get wizard steps
     */
    public static function get_steps() {
        return array(
            1 => array(
                'title' => __('Welcome', 'securehold-security-deposit-holds'),
                'desc' => __('Get started with SecureHold WP', 'securehold-security-deposit-holds')
            ),
            2 => array(
                'title' => __('Dependencies', 'securehold-security-deposit-holds'),
                'desc' => __('Install required plugins', 'securehold-security-deposit-holds')
            ),
            3 => array(
                'title' => __('WooCommerce', 'securehold-security-deposit-holds'),
                'desc' => __('Configure account settings', 'securehold-security-deposit-holds')
            ),
            4 => array(
                'title' => __('Stripe SDK', 'securehold-security-deposit-holds'),
                'desc' => __('Automatic installation', 'securehold-security-deposit-holds')
            ),
            5 => array(
                'title' => __('API Keys', 'securehold-security-deposit-holds'),
                'desc' => __('Connect your Stripe account', 'securehold-security-deposit-holds')
            ),
            6 => array(
                'title' => __('Webhook', 'securehold-security-deposit-holds'),
                'desc' => __('Automatic configuration', 'securehold-security-deposit-holds')
            ),
            7 => array(
                'title' => __('Complete', 'securehold-security-deposit-holds'),
                'desc' => __('You\'re all set!', 'securehold-security-deposit-holds')
            )
        );
    }
    
    /**
     * Complete SecureHold flow test (AJAX manager) - SECURE & FULL FLOW
     */
    public function test_stripe_configuration() {
        check_ajax_referer('securehold_test_config', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json(array(
                'success' => false,
                'steps'   => array(array(
                    'name'    => 'Permissions',
                    'status'  => 'error',
                    'message' => 'You do not have permission to run this test.',
                )),
            ));
        }

        // Load error handler
        require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-error-handler.php';
        
        $results = array(
            'success' => true,
            'steps' => array()
        );
        
        // Step 0: Pre-validation
        $validation_errors = Securehold_Error_Handler::validate_configuration();
        if (!empty($validation_errors)) {
            $results['success'] = false;
            $results['steps'] = $validation_errors;
            wp_send_json($results);
            return;
        }
        
        $test_order_id = null;
        $test_customer_id = null;
        $test_payment_intent_id = null;
        $test_hold_id = null;
        
        try {
            // Step 1: Check dependencies
            if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_VERSION' ) ) {
                throw new Exception( 'WooCommerce not active' );
            }
            if (!class_exists('\Stripe\Stripe')) {
                throw new Exception('Stripe SDK not loaded');
            }
            
            $results['steps'][] = array(
                'name' => 'Dependencies',
                'status' => 'success',
                'message' => 'WooCommerce and Stripe SDK loaded'
            );
            
            // Step 2: Load Stripe credentials
            $mode = get_option('securehold_stripe_mode', 'test');
            $secret_key = $mode === 'test' 
                ? get_option('securehold_stripe_test_secret_key')
                : get_option('securehold_stripe_live_secret_key');
            
            if (empty($secret_key)) {
                throw new Exception('❌ Stripe API keys are not configured. Please go to SecureHold WP → Settings and enter your ' . ucfirst($mode) . ' Secret Key.');
            }
            
            \Stripe\Stripe::setApiKey($secret_key);
            
            $results['steps'][] = array(
                'name' => 'Stripe API',
                'status' => 'success',
                'message' => 'Connected to Stripe (' . $mode . ' mode)'
            );
            
            // Step 3: Create Stripe customer
            $stripe_customer = \Stripe\Customer::create(array(
                'email' => 'test-' . time() . '@securehold-test.com',
                'name' => 'SecureHold WP Test User',
                'description' => 'SecureHold WP Setup Test',
                'metadata' => array(
                    'test' => 'true',
                    'source' => 'setup_wizard'
                )
            ));
            
            $test_customer_id = $stripe_customer->id;
            
            $results['steps'][] = array(
                'name' => 'Stripe Customer',
                'status' => 'success',
                'message' => 'Created: ' . $stripe_customer->id
            );
            
            // Step 4: Use Stripe TEST Payment Method (CORRECTIF SÉCURITÉ)
            // Au lieu d'utiliser un numéro de carte brute, on utilise le token de test officiel
            $payment_method_id = 'pm_card_visa';
            
            // On attache ce moyen de paiement au client pour simuler un "Client enregistré"
            $payment_method = \Stripe\PaymentMethod::retrieve($payment_method_id);
            $payment_method->attach(['customer' => $stripe_customer->id]);
            
            $results['steps'][] = array(
                'name' => 'Payment Method',
                'status' => 'success',
                'message' => 'Test Card Attached (Secure Token)'
            );
            
            // Step 5: Create WooCommerce test order
            $order = wc_create_order();
            $product_id = $this->get_or_create_test_product();
            $order->add_product(wc_get_product($product_id), 1);
            $order->set_billing_email('test-' . time() . '@securehold-test.com');
            $order->set_billing_first_name('SecureHold');
            $order->set_billing_last_name('Test');
            $order->calculate_totals();
            
            $test_order_id = $order->get_id();
            
            $results['steps'][] = array(
                'name' => 'WooCommerce Order',
                'status' => 'success',
                'message' => 'Order #' . $test_order_id . ' created ($' . $order->get_total() . ')'
            );
            
            // Step 6: Create Security Deposit (Hold) - CORRECTIF
            // On crée une intention avec capture_method = manual pour simuler une caution
            $payment_intent = \Stripe\PaymentIntent::create(array(
                'amount' => intval($order->get_total() * 100),
                'currency' => strtolower($order->get_currency()),
                'customer' => $stripe_customer->id,
                'payment_method' => $payment_method_id, // Utilisation du token ID
                'confirm' => true,
                'off_session' => true, // Simule une transaction automatique
                'capture_method' => 'manual', // <--- C'EST LA CLÉ : Crée une caution, pas un débit
                'description' => 'Order #' . $test_order_id . ' - SecureHold WP Test',
                'metadata' => array(
                    'order_id' => $test_order_id,
                    'securehold_test' => 'true'
                )
            ));
            
            $test_payment_intent_id = $payment_intent->id;
            
            // VERIFICATION 1 : L'empreinte est-elle créée (statut requires_capture) ?
            if ($payment_intent->status === 'requires_capture') {
                $results['steps'][] = array(
                    'name' => 'Create Hold',
                    'status' => 'success',
                    'message' => '✅ Funds successfully held (Authorized only)'
                );
                
                // Mettre à jour la commande comme si l'empreinte était faite
                $order->update_meta_data('_stripe_customer_id', $stripe_customer->id);
                $order->update_meta_data('_stripe_source_id', $payment_method->id);
                $order->update_meta_data('_stripe_intent_id', $payment_intent->id);
                $order->update_meta_data('_stripe_charge_captured', 'no'); // Important : non capturé
                $order->update_meta_data('_stripe_payment_method_id', $payment_method->id);
                $order->save();

                // Step 7: Test Capture (Transform Hold into Charge)
                // Cela prouve que vous pouvez récupérer l'argent si besoin
                $payment_intent->capture();
                
                // On rafraîchit l'objet pour avoir le nouveau statut
                $payment_intent = \Stripe\PaymentIntent::retrieve($test_payment_intent_id);
                
                if ($payment_intent->status === 'succeeded') {
                    $results['steps'][] = array(
                        'name' => 'Test Capture',
                        'status' => 'success',
                        'message' => '✅ Hold successfully captured (Money charged)'
                    );
                    
                    // Mise à jour finale de la commande
                    $order->payment_complete($payment_intent->id);
                    $order->update_meta_data('_stripe_charge_captured', 'yes');
                    $order->save();
                    
                } else {
                    throw new Exception('Capture failed. Status: ' . $payment_intent->status);
                }

            } else {
                // Si le statut est 'succeeded' direct, c'est que c'était un débit immédiat, pas une caution
                throw new Exception('Hold failed. Status was "' . $payment_intent->status . '" instead of "requires_capture". Check Stripe settings.');
            }
            
            // Step 8: Verify order metadata
            $metadata_check = array(
                '_stripe_customer_id' => $order->get_meta('_stripe_customer_id'),
                '_stripe_intent_id' => $order->get_meta('_stripe_intent_id'),
                '_stripe_payment_method_id' => $order->get_meta('_stripe_payment_method_id')
            );
            
            $metadata_complete = !empty($metadata_check['_stripe_customer_id']) && 
                                !empty($metadata_check['_stripe_intent_id']) && 
                                !empty($metadata_check['_stripe_payment_method_id']);
            
            if ($metadata_complete) {
                $results['steps'][] = array(
                    'name' => 'Order Metadata',
                    'status' => 'success',
                    'message' => '✅ All required Stripe metadata present'
                );
            } else {
                throw new Exception('Missing required Stripe metadata on order');
            }
            
            // Step 9: Check if SecureHold database works
            global $wpdb;
            $hold_table = $wpdb->prefix . 'securehold_holds';
            if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $hold_table ) ) ) === $hold_table ) {
                $results['steps'][] = array(
                    'name' => 'SecureHold WP Database',
                    'status' => 'success',
                    'message' => 'Database table ready for deposits'
                );
            } else {
                $results['steps'][] = array(
                    'name' => 'SecureHold WP Database',
                    'status' => 'warning',
                    'message' => 'Database table not found. Activate SecureHold WP to create it.'
                );
            }
            
            // Step 10: Test webhook configuration
            $webhook_secret = get_option('securehold_webhook_secret');
            if (!empty($webhook_secret)) {
                $results['steps'][] = array(
                    'name' => 'Webhook',
                    'status' => 'success',
                    'message' => 'Webhook configured and ready'
                );
            } else {
                $results['steps'][] = array(
                    'name' => 'Webhook',
                    'status' => 'warning',
                    'message' => 'Webhook not configured (recommended for production)'
                );
            }
            
            // Step 11: Refund the payment (Cleanup)
            if ($test_payment_intent_id) {
                $refund = \Stripe\Refund::create(array(
                    'payment_intent' => $test_payment_intent_id
                ));
                
                $results['steps'][] = array(
                    'name' => 'Cleanup (Payment)',
                    'status' => 'success',
                    'message' => 'Test payment fully refunded'
                );
            }
            
            // Step 12: Delete test customer
            if ($test_customer_id) {
                $stripe_customer->delete();
                $results['steps'][] = array(
                    'name' => 'Cleanup (Customer)',
                    'status' => 'success',
                    'message' => 'Test customer deleted'
                );
            }
            
            // Step 13: Delete test order
            if ($test_order_id) {
                wp_delete_post($test_order_id, true);
                $results['steps'][] = array(
                    'name' => 'Cleanup (Order)',
                    'status' => 'success',
                    'message' => 'Test order deleted'
                );
            }
            
            // Final message
            $results['steps'][] = array(
                'name' => 'Test Complete',
                'status' => 'success',
                'message' => '🎉 All tests passed! SecureHold WP is ready for production.'
            );
            
        } catch (\Stripe\Exception\AuthenticationException $e) {
            // Invalid API key
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - Authentication', $e->getMessage(), array(
                'api_key_prefix' => substr(get_option('securehold_stripe_test_secret_key'), 0, 15)
            ));
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (\Stripe\Exception\PermissionException $e) {
            // Insufficient permissions
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - Permission', $e->getMessage());
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (\Stripe\Exception\RateLimitException $e) {
            // Rate limit exceeded
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - Rate Limit', $e->getMessage());
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            // Invalid parameters
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - Invalid Request', $e->getMessage(), array(
                'test_customer_id' => $test_customer_id,
                'test_order_id' => $test_order_id
            ));
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (\Stripe\Exception\CardException $e) {
            // Card declined or invalid
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - Card Error', $e->getMessage(), array(
                'decline_code' => method_exists($e, 'getDeclineCode') ? $e->getDeclineCode() : 'unknown'
            ));
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            // Network connection error
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - Connection', $e->getMessage());
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Stripe API error
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;
            
            Securehold_Error_Handler::log_error('Test Configuration - API Error', $e->getMessage());
            
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
            
        } catch (Exception $e) {
            // Generic exception
            $results['success'] = false;
            $error_info = Securehold_Error_Handler::get_stripe_error_message($e);
            $results['steps'][] = $error_info;

            Securehold_Error_Handler::log_error('Test Configuration - Generic', $e->getMessage(), array(
                'exception_class' => get_class($e)
            ));

            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);

        } catch (\Throwable $e) {
            // PHP 7+ fatal-style errors (Error, TypeError, ParseError, etc.) are not
            // caught by catch(Exception). Without this block they escape to a PHP error
            // page, producing non-JSON output that triggers the JS generic fallback.
            $results['success'] = false;
            $results['steps'][] = array(
                'name'    => 'Internal Error',
                'status'  => 'error',
                'message' => esc_html( $e->getMessage() ),
            );
            Securehold_Error_Handler::log_error('Test Configuration - Fatal', $e->getMessage(), array(
                'exception_class' => get_class($e),
            ));
            $this->cleanup_test_resources($test_payment_intent_id, $test_customer_id, $test_order_id);
        }

        wp_send_json($results);
    }
    
    /**
     * Cleanup test resources on error
     */
    private function cleanup_test_resources($payment_intent_id, $customer_id, $order_id) {
        try {
            if ($payment_intent_id) {
                \Stripe\Refund::create(array('payment_intent' => $payment_intent_id));
            }
            
            if ($customer_id) {
                \Stripe\Customer::retrieve($customer_id)->delete();
            }
            
            if ($order_id) {
                wp_delete_post($order_id, true);
            }
        } catch (Exception $cleanup_error) {
            // Ignore cleanup errors silently
        }
    }
    
    /**
     * Get or create a test product for testing
     */
    private function get_or_create_test_product() {
        // Check if test product exists
        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                array(
                    'key' => '_securehold_test_product',
                    'value' => 'yes'
                )
            ),
            'posts_per_page' => 1
        );
        
        $products = get_posts($args);
        
        if (!empty($products)) {
            return $products[0]->ID;
        }
        
        // Create test product
        $product = new WC_Product_Simple();
        $product->set_name('SecureHold WP Test Product');
        $product->set_regular_price(100);
        $product->set_virtual(true);
        $product->set_catalog_visibility('hidden');
        $product->save();
        
        update_post_meta($product->get_id(), '_securehold_test_product', 'yes');
        
        return $product->get_id();
    }
}