<?php
/**
 * SecureHold Error Handler
 * Centralized error handling with clear, actionable messages
 */

if (!defined('ABSPATH')) {
    exit;
}

class Securehold_Error_Handler {
    
    /**
     * Get error message and solution for Stripe exceptions
     */
    public static function get_stripe_error_message($exception) {
        $error_data = array(
            'name' => 'Stripe Error',
            'status' => 'error',
            'message' => '',
            'solution' => '',
            'error_code' => '',
            'documentation' => 'https://stripe.com/docs/error-codes'
        );
        
        // Authentication Errors (Invalid API Key)
        if ($exception instanceof \Stripe\Exception\AuthenticationException) {
            $error_data['name'] = 'Authentication Error';
            $error_data['message'] = '❌ Invalid Stripe API Key';
            $error_data['solution'] = 'Please check your Secret Key in SecureHold WP → Settings. Make sure you\'re using the correct key for your mode (Test or Live).';
            $error_data['error_code'] = 'authentication_error';
        }
        
        // Permission Errors (API key doesn't have required permissions)
        else if ($exception instanceof \Stripe\Exception\PermissionException) {
            $error_data['name'] = 'Permission Error';
            $error_data['message'] = '❌ API Key lacks required permissions';
            $error_data['solution'] = 'Your Stripe API key doesn\'t have the necessary permissions. Generate a new key with full permissions in your Stripe Dashboard.';
            $error_data['error_code'] = 'permission_error';
        }
        
        // Rate Limit Errors (Too many requests)
        else if ($exception instanceof \Stripe\Exception\RateLimitException) {
            $error_data['name'] = 'Rate Limit Error';
            $error_data['message'] = '⏱️ Too many requests to Stripe';
            $error_data['solution'] = 'You\'ve exceeded Stripe\'s rate limit. Please wait a few minutes and try again. Consider reducing the frequency of API calls.';
            $error_data['error_code'] = 'rate_limit_error';
        }
        
        // Invalid Request Errors (Bad parameters)
        else if ($exception instanceof \Stripe\Exception\InvalidRequestException) {
            $error_data['name'] = 'Invalid Request';
            $error_data['message'] = '❌ Invalid request to Stripe API';
            
            $error_message = $exception->getMessage();
            
            // Specific invalid request scenarios
            if ( stripos( $error_message, 'No such payment_method' ) !== false ) {
                $error_data['solution'] = 'The payment method ID does not exist in Stripe or belongs to a different mode (Test vs Live). Ensure SecureHold and the WooCommerce Stripe Gateway are set to the same mode in SecureHold → Settings.';
            } elseif ( stripos( $error_message, 'payment_method' ) !== false ) {
                $error_data['solution'] = 'The payment method is invalid or doesn\'t exist. Please ensure the customer has a valid payment method attached.';
            } elseif ( stripos( $error_message, 'No such payment_intent' ) !== false ) {
                $error_data['solution'] = 'The PaymentIntent ID does not exist in Stripe or belongs to a different mode (Test vs Live). Ensure SecureHold and the WooCommerce Stripe Gateway are set to the same mode in SecureHold → Settings.';
            } else if (stripos($error_message, 'customer') !== false) {
                $error_data['solution'] = 'The customer ID is invalid. Please ensure the customer exists in Stripe.';
            } else if (stripos($error_message, 'amount') !== false) {
                $error_data['solution'] = 'The amount is invalid. Stripe requires positive integers (in cents). Example: $10.00 = 1000.';
            } else if (stripos($error_message, 'currency') !== false) {
                $error_data['solution'] = 'The currency code is invalid. Use 3-letter ISO codes (usd, eur, gbp, etc.) in lowercase.';
            } else if (stripos($error_message, 'test mode') !== false || stripos($error_message, 'live mode') !== false) {
                $error_data['solution'] = 'You\'re mixing Test and Live mode resources. Ensure your API keys match the mode of the resources you\'re accessing.';
            } else {
                $error_data['solution'] = 'The request contains invalid parameters. Error: ' . $error_message;
            }
            
            $error_data['error_code'] = 'invalid_request_error';
        }
        
        // Card Errors (Payment declined, insufficient funds, etc.)
        else if ($exception instanceof \Stripe\Exception\CardException) {
            $error_data['name'] = 'Card Error';
            $error_message = $exception->getMessage();
            $decline_code = method_exists($exception, 'getDeclineCode') ? $exception->getDeclineCode() : null;
            
            // Specific card error scenarios
            if ($decline_code === 'insufficient_funds') {
                $error_data['message'] = '💳 Insufficient funds';
                $error_data['solution'] = 'The card has insufficient funds. Ask the customer to use a different payment method or contact their bank.';
            } else if ($decline_code === 'card_declined') {
                $error_data['message'] = '💳 Card declined';
                $error_data['solution'] = 'The card was declined by the bank. Ask the customer to contact their bank or use a different card.';
            } else if ($decline_code === 'expired_card') {
                $error_data['message'] = '💳 Card expired';
                $error_data['solution'] = 'The card has expired. Ask the customer to update their payment method with a valid card.';
            } else if ($decline_code === 'incorrect_cvc') {
                $error_data['message'] = '💳 Incorrect CVC';
                $error_data['solution'] = 'The card\'s security code (CVC) is incorrect. Ask the customer to verify and re-enter their card details.';
            } else if ($decline_code === 'processing_error') {
                $error_data['message'] = '💳 Processing error';
                $error_data['solution'] = 'A temporary error occurred while processing the card. Please try again in a few moments.';
            } else if (stripos($error_message, 'card number') !== false) {
                $error_data['message'] = '💳 Invalid card number';
                $error_data['solution'] = 'The card number is invalid. Ask the customer to verify their card details.';
            } else {
                $error_data['message'] = '💳 Card error: ' . $error_message;
                $error_data['solution'] = 'There was an issue with the card. Ask the customer to use a different payment method.';
            }
            
            $error_data['error_code'] = 'card_error';
        }
        
        // API Connection Errors (Network issues)
        else if ($exception instanceof \Stripe\Exception\ApiConnectionException) {
            $error_data['name'] = 'Connection Error';
            $error_data['message'] = '🌐 Cannot connect to Stripe';
            $error_data['solution'] = 'There\'s a network issue preventing connection to Stripe. Check your internet connection and firewall settings. Try again in a few moments.';
            $error_data['error_code'] = 'api_connection_error';
        }
        
        // General API Errors (Stripe server issues)
        else if ($exception instanceof \Stripe\Exception\ApiErrorException) {
            $error_data['name'] = 'Stripe API Error';
            $error_data['message'] = '⚠️ Stripe server error';
            $error_data['solution'] = 'Stripe is experiencing issues. This is temporary. Check https://status.stripe.com and try again later.';
            $error_data['error_code'] = 'api_error';
        }
        
        // Unknown Stripe Exception
        else if ($exception instanceof \Stripe\Exception\ExceptionInterface) {
            $error_data['name'] = 'Unknown Stripe Error';
            $error_data['message'] = '❓ Unexpected Stripe error';
            $error_data['solution'] = 'An unexpected error occurred: ' . $exception->getMessage() . '. Contact SecureHold WP support if this persists.';
            $error_data['error_code'] = 'unknown_stripe_error';
        }
        
        // Generic Exception
        else {
            $error_data['name'] = 'Error';
            $error_data['message'] = '❌ ' . $exception->getMessage();
            $error_data['solution'] = 'An unexpected error occurred. Please contact support if this persists.';
            $error_data['error_code'] = 'generic_error';
        }
        
        // Add original error message as technical details
        $error_data['technical_details'] = $exception->getMessage();
        
        // Format final message
        $final_message = $error_data['message'];
        if (!empty($error_data['solution'])) {
            $final_message .= "\n\n" . $error_data['solution'];
        }
        
        return array(
            'name' => $error_data['name'],
            'status' => 'error',
            'message' => $final_message
        );
    }
    
    /**
     * Validate configuration before running tests
     */
    public static function validate_configuration() {
        $errors = array();
        
        // Check if WooCommerce is active
        if ( ! class_exists( 'WooCommerce' ) && ! defined( 'WC_VERSION' ) ) {
            $errors[] = array(
                'name' => 'WooCommerce Missing',
                'status' => 'error',
                'message' => '❌ WooCommerce is not installed or activated. Please install and activate WooCommerce before using SecureHold WP.'
            );
        }
        
        // Check if Stripe SDK is loaded (works in both scoped build and dev environment)
        if ( ! Securehold_Stripe_Installer::get_stripe_class() ) {
            $errors[] = array(
                'name' => 'Stripe SDK Missing',
                'status' => 'error',
                'message' => '❌ Stripe PHP library is not loaded. Please ensure the plugin is properly installed with all dependencies.'
            );
        }
        
        // Check API keys
        $mode = get_option('securehold_stripe_mode', 'test');
        $secret_key = $mode === 'test' 
            ? get_option('securehold_stripe_test_secret_key')
            : get_option('securehold_stripe_live_secret_key');
        $publishable_key = $mode === 'test'
            ? get_option('securehold_stripe_test_publishable_key')
            : get_option('securehold_stripe_live_publishable_key');
        
        if (empty($secret_key)) {
            $errors[] = array(
                'name' => 'Secret Key Missing',
                'status' => 'error',
                'message' => '❌ Stripe Secret Key is not configured. Please go to SecureHold WP → Settings and enter your ' . ucfirst($mode) . ' Secret Key (starts with sk_' . ($mode === 'test' ? 'test' : 'live') . '_).'
            );
        }
        
        if (empty($publishable_key)) {
            $errors[] = array(
                'name' => 'Publishable Key Missing',
                'status' => 'warning',
                'message' => '⚠️ Stripe Publishable Key is not configured. This is needed for frontend payment collection. Add it in SecureHold WP → Settings (starts with pk_' . ($mode === 'test' ? 'test' : 'live') . '_).'
            );
        }
        
        // Validate key format
        if (!empty($secret_key)) {
            $expected_prefix = $mode === 'test' ? 'sk_test_' : 'sk_live_';
            if (strpos($secret_key, $expected_prefix) !== 0) {
                $errors[] = array(
                    'name' => 'Wrong API Key Mode',
                    'status' => 'error',
                    'message' => '❌ Your Secret Key doesn\'t match the selected mode (' . ucfirst($mode) . '). Secret keys should start with "' . $expected_prefix . '". Check SecureHold WP → Settings.'
                );
            }
        }
        
        // Check database tables
        global $wpdb;
        $hold_table = $wpdb->prefix . 'securehold_holds';
        $log_table = $wpdb->prefix . 'securehold_logs';
        
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $hold_table ) ) ) !== $hold_table ) {
            $errors[] = array(
                'name' => 'Database Table Missing',
                'status' => 'error',
                'message' => '❌ SecureHold WP database tables are missing. Try deactivating and reactivating the plugin to recreate them.'
            );
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            $errors[] = array(
                'name' => 'PHP Version',
                'status' => 'error',
                'message' => '❌ SecureHold WP requires PHP 7.4 or higher. Your server is running PHP ' . PHP_VERSION . '. Contact your hosting provider to upgrade.'
            );
        }
        
        // Check WordPress version
        global $wp_version;
        if (version_compare($wp_version, '5.8', '<')) {
            $errors[] = array(
                'name' => 'WordPress Version',
                'status' => 'warning',
                'message' => '⚠️ SecureHold WP works best with WordPress 5.8 or higher. You\'re running ' . $wp_version . '. Consider updating WordPress.'
            );
        }
        
        // Check WooCommerce version if active
        if (class_exists('WooCommerce')) {
            if (defined('WC_VERSION') && version_compare(WC_VERSION, '5.0', '<')) {
                $errors[] = array(
                    'name' => 'WooCommerce Version',
                    'status' => 'warning',
                    'message' => '⚠️ SecureHold WP works best with WooCommerce 5.0 or higher. You\'re running ' . WC_VERSION . '. Consider updating WooCommerce.'
                );
            }
        }
        
        // Check if cURL is available (needed for Stripe API)
        if (!function_exists('curl_init')) {
            $errors[] = array(
                'name' => 'cURL Missing',
                'status' => 'error',
                'message' => '❌ cURL extension is not installed. Stripe API requires cURL. Contact your hosting provider to install it.'
            );
        }
        
        // Check file permissions
        $upload_dir = wp_upload_dir();
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- WP < 6.3 fallback
        if (!(function_exists( 'wp_is_writable' ) ? wp_is_writable( $upload_dir['basedir'] ) : is_writable($upload_dir['basedir']))) {
            $errors[] = array(
                'name' => 'File Permissions',
                'status' => 'warning',
                'message' => '⚠️ WordPress uploads directory is not writable. This may cause issues with logs. Check file permissions.'
            );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
        
        return $errors;
    }
    
    /**
     * Validate order before creating hold
     */
    public static function validate_order($order) {
        $errors = array();
        
        if (!$order || !is_a($order, 'WC_Order')) {
            $errors[] = 'Invalid order object';
            return $errors;
        }
        
        // Check order status
        $valid_statuses = array('processing', 'completed', 'on-hold', 'pending');
        if (!in_array($order->get_status(), $valid_statuses)) {
            $errors[] = 'Order status "' . $order->get_status() . '" is not valid for creating holds. Expected: ' . implode(', ', $valid_statuses) . '.';
        }
        
        // Check order total
        if ($order->get_total() <= 0) {
            $errors[] = 'Order total must be greater than 0. Current total: ' . $order->get_total();
        }
        
        // Check currency
        $currency = $order->get_currency();
        $supported_currencies = array('USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'SEK', 'NOK', 'DKK');
        if (!in_array(strtoupper($currency), $supported_currencies)) {
            $errors[] = 'Currency "' . $currency . '" may not be supported for Stripe pre-authorizations. Supported: ' . implode(', ', $supported_currencies) . '.';
        }
        
        // Check customer — guest orders (customer_id = 0) are fully supported
        // SecureHold WP creates a Stripe customer from billing data for guests
        $customer_id = $order->get_customer_id();
        $billing_email_check = $order->get_billing_email();
        if (empty($customer_id) && empty($billing_email_check)) {
            $errors[] = 'Guest order has no billing email. SecureHold WP needs at least a billing email to create a Stripe customer.';
        }
        
        // Check billing email
        $billing_email = $order->get_billing_email();
        if (empty($billing_email) || !is_email($billing_email)) {
            $errors[] = 'Order has invalid or missing billing email: ' . $billing_email;
        }
        
        return $errors;
    }
    
    /**
     * Log error for debugging
     */
    public static function log_error($context, $error_message, $error_data = array()) {
        if (function_exists('securehold_log')) {
            securehold_log('ERROR - ' . $context, array(
                'message' => $error_message,
                'data' => $error_data,
                'timestamp' => current_time('mysql')
            ));
        }
    }
}
