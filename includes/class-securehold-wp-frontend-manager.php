<?php
/**
 * SecureHold Frontend Manager
 * Handles checkout messages, My Account page, and shortcodes
 * Version: 3.8.0
 */

if (!defined('ABSPATH')) exit;

class Securehold_Frontend_Manager {
    
    public function __construct() {
        // Checkout message hook — position honours the stored option.
        $position = get_option( 'securehold_checkout_message_position', 'before' );
        $hook     = ( 'after' === $position )
            ? 'woocommerce_review_order_after_payment'
            : 'woocommerce_review_order_before_payment';
        add_action( $hook, array( $this, 'display_checkout_message' ) );

        // Cart & Checkout Blocks compatibility: woocommerce_review_order_before/after_payment
        // are classic-template hooks and are never fired by the block-based checkout, so the
        // notice above never appeared there. Confirmed wc_add_notice() does NOT surface on the
        // block checkout either (known WooCommerce core limitation, see
        // https://github.com/woocommerce/woocommerce/issues/50153) — the only documented,
        // working extension point is a JS slot registered through ExperimentalOrderMeta, so we
        // enqueue a small script that renders the same message via that slot, only when the
        // Checkout page content actually uses the block (classic keeps its inline notice as-is).
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_checkout_notice' ) );

        // Correct Stripe Elements locale for logged-in users on English checkouts.
        // See inline doc on force_stripe_elements_locale() for full explanation.
        add_filter( 'wc_stripe_upe_params', array( $this, 'force_stripe_elements_locale' ), 5 );

        // My Account endpoint + content registration — respects the admin toggle.
        if ( get_option( 'securehold_enable_my_account_tab', true ) ) {
            add_action( 'init', array( $this, 'add_my_account_endpoint' ) );
            add_filter( 'woocommerce_account_menu_items', array( $this, 'add_my_account_menu_item' ) );
            add_action( 'woocommerce_account_securehold-deposits_endpoint', array( $this, 'my_account_deposits_content' ) );
        }

        // Shortcodes.
        add_shortcode( 'securehold_my_deposits',      array( $this, 'shortcode_my_deposits' ) );
        add_shortcode( 'securehold_deposit_status',   array( $this, 'shortcode_deposit_status' ) );
        add_shortcode( 'securehold_checkout_message', array( $this, 'shortcode_checkout_message' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_styles' ) );
    }

    public function enqueue_frontend_styles() {
        wp_enqueue_style(
            'securehold-frontend',
            SECUREHOLD_PLUGIN_URL . 'assets/css/securehold-wp-frontend.css',
            array(),
            SECUREHOLD_VERSION,
            'all'
        );
    }
    
    /**
     * Display customizable message at checkout
     */
    public function display_checkout_message() {
        // Static guard: prevent double-render even if the hook fires twice
        // (e.g. some themes or block checkout call the hook more than once).
        static $rendered = false;
        if ( $rendered ) {
            return;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return;
        }

        // Check if message is enabled
        if (!get_option('securehold_enable_checkout_message', true)) {
            return;
        }

        // Check if any product in cart requires a hold
        $cart_data = $this->get_cart_hold_data();

        if (!$cart_data['has_hold']) {
            return;
        }

        // Get custom message or use default
        $message = get_option('securehold_checkout_message', $this->get_default_checkout_message());

        if (empty($message)) {
            return;
        }

        // Variable replacement: full set of variables.
        $message = $this->replace_message_variables( $message, $cart_data );
        // Per-item aggregated: append discrete mention.
        if ( ! empty( $cart_data['aggregation_mode'] ) && 'per_item_aggregated' === $cart_data['aggregation_mode'] ) {
            $message .= '<br><small>' . esc_html__( 'Calculated from items in your cart.', 'securehold-security-deposit-holds' ) . '</small>';
        }

        // Style: honour the stored option (info, warning, success).
        $style = get_option( 'securehold_checkout_message_style', 'info' );

        $rendered = true;
        $this->render_checkout_notice($message, $style);

        // Debug HTML comment — visible to admin users when WP_DEBUG is on.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_woocommerce' ) ) {
            $dbg = isset( $cart_data['_debug'] ) ? $cart_data['_debug'] : array();
            $dbg_source = isset( $dbg['source'] ) ? $dbg['source'] : 'n/a';
            $dbg_label  = isset( $dbg['source_label'] ) ? $dbg['source_label'] : '';
            $dbg_timing = isset( $dbg['timing'] ) ? $dbg['timing'] : 'n/a';
            $dbg_agg    = isset( $dbg['aggregation'] ) ? $dbg['aggregation'] : 'per_order';
            $dbg_engine = class_exists( 'Securehold_Config_Resolver' ) ? Securehold_Config_Resolver::get_engine_version() : 'n/a';
            $dbg_policy = class_exists( 'Securehold_Config_Resolver' ) ? Securehold_Config_Resolver::get_active_policy() : 'n/a';
            $dbg_raw    = isset( $dbg['compute_for_cart_amount'] ) ? $dbg['compute_for_cart_amount'] : 'n/a';

            echo "\n<!-- SecureHold Debug"
                . ' | preview_amount=' . esc_html( $cart_data['total_amount'] )
                . ' | compute_for_cart_amount=' . esc_html( $dbg_raw )
                . ' | source=' . esc_html( $dbg_source )
                . ( $dbg_label ? '(' . esc_html( $dbg_label ) . ')' : '' )
                . ' | timing=' . esc_html( $dbg_timing )
                . ' | aggregation=' . esc_html( $dbg_agg )
                . ' | engine=' . esc_html( $dbg_engine )
                . ' | policy=' . esc_html( $dbg_policy )
                . " -->\n";
        }
    }

    /**
     * Show the same checkout deposit notice on the Cart & Checkout Blocks
     * checkout, via the official ExperimentalOrderMeta slot.
     *
     * display_checkout_message() is hooked on woocommerce_review_order_before/
     * after_payment, both classic-template hooks the block-based checkout
     * never fires, so the notice never appeared there. wc_add_notice() was
     * tried too and confirmed NOT to surface on the block checkout either —
     * this is a known WooCommerce core limitation
     * (https://github.com/woocommerce/woocommerce/issues/50153). The only
     * working extension point is the JS-based ExperimentalOrderMeta slot, so
     * this enqueues a small script (assets/js/checkout-blocks-notice.js) that
     * renders the same message through it — only when the Checkout page
     * content actually uses the block, so a store still on the classic
     * shortcode is completely unaffected and keeps its inline notice as-is.
     *
     * @since 3.4.3
     */
    public function enqueue_blocks_checkout_notice() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
            return;
        }

        $checkout_page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
        if ( ! $checkout_page_id || ! function_exists( 'has_block' ) || ! has_block( 'woocommerce/checkout', $checkout_page_id ) ) {
            return; // Classic checkout already shows the notice inline — nothing to do here.
        }

        if ( ! get_option( 'securehold_enable_checkout_message', true ) ) {
            return;
        }

        $cart_data = $this->get_cart_hold_data();
        if ( ! $cart_data['has_hold'] ) {
            return;
        }

        $message = get_option( 'securehold_checkout_message', $this->get_default_checkout_message() );
        if ( empty( $message ) ) {
            return;
        }

        $message = $this->replace_message_variables( $message, $cart_data );
        if ( ! empty( $cart_data['aggregation_mode'] ) && 'per_item_aggregated' === $cart_data['aggregation_mode'] ) {
            $message .= ' ' . esc_html__( 'Calculated from items in your cart.', 'securehold-security-deposit-holds' );
        }
        $message = wp_kses_post( $message );

        // Same 3-way palette as the classic notice (render_checkout_notice()) — kept in
        // sync intentionally so the two checkout types read as the same message, not a
        // different feature.
        $colors = array(
            'info'    => array( 'bg' => '#e3f2fd', 'border' => '#2196f3', 'text' => '#1565c0' ),
            'warning' => array( 'bg' => '#fff3e0', 'border' => '#ff9800', 'text' => '#e65100' ),
            'success' => array( 'bg' => '#e8f5e9', 'border' => '#4caf50', 'text' => '#2e7d32' ),
        );
        $style = get_option( 'securehold_checkout_message_style', 'info' );
        $color = isset( $colors[ $style ] ) ? $colors[ $style ] : $colors['info'];

        wp_enqueue_script(
            'securehold-checkout-blocks-notice',
            SECUREHOLD_PLUGIN_URL . 'assets/js/checkout-blocks-notice.js',
            array( 'wp-element', 'wp-plugins', 'wc-blocks-checkout' ),
            SECUREHOLD_VERSION,
            true
        );
        wp_add_inline_script(
            'securehold-checkout-blocks-notice',
            'window.secureholdCheckoutNotice = ' . wp_json_encode( array(
                'message' => $message,
                'bg'      => $color['bg'],
                'border'  => $color['border'],
                'text'    => $color['text'],
            ) ) . ';',
            'before'
        );
    }

    /**
     * Get cart hold data — delegates to the Computation Service (single source of truth).
     *
     * The Computation Service handles all aggregation modes, resolution policies,
     * and engine versions. This method simply translates the result into the
     * display-oriented structure expected by the checkout message renderer.
     *
     * @since 4.3.0 Original implementation.
     * @since 5.3.0 Rewritten: delegates entirely to Securehold_Deposit_Computation_Service::compute_for_cart().
     * @return array {
     *   has_hold:          bool
     *   total_amount:      float
     *   product_names:     array
     *   products_count:    int
     *   aggregation_mode:  string (optional, set for per_item_aggregated)
     *   _debug:            array  (source, timing — for debug comment, not displayed)
     * }
     */
    private function get_cart_hold_data() {
        if ( ! WC()->cart ) {
            return array(
                'has_hold'       => false,
                'total_amount'   => 0,
                'product_names'  => array(),
                'products_count' => 0,
            );
        }

        $cart_items = WC()->cart->get_cart();
        if ( empty( $cart_items ) ) {
            return array(
                'has_hold'       => false,
                'total_amount'   => 0,
                'product_names'  => array(),
                'products_count' => 0,
            );
        }

        // Load the Computation Service (loads Config Resolver internally).
        if ( ! class_exists( 'Securehold_Deposit_Computation_Service' ) ) {
            $service_path = defined( 'SECUREHOLD_PLUGIN_DIR' )
                ? SECUREHOLD_PLUGIN_DIR . 'includes/services/class-securehold-wp-computation-service.php'
                : plugin_dir_path( dirname( __FILE__ ) ) . 'includes/services/class-securehold-wp-computation-service.php';
            if ( file_exists( $service_path ) ) {
                require_once $service_path;
            }
        }

        // Collect all product names for message variables regardless of winner.
        $all_product_names = array();
        foreach ( $cart_items as $cart_item ) {
            if ( ! empty( $cart_item['data'] ) ) {
                $all_product_names[] = $cart_item['data']->get_name();
            }
        }

        // ── Fallback: service unavailable — use global setting ──
        if ( ! class_exists( 'Securehold_Deposit_Computation_Service' ) ) {
            $default_amount = floatval( get_option( 'securehold_default_hold_amount', '300' ) );
            if ( $default_amount <= 0 ) {
                return array(
                    'has_hold'       => false,
                    'total_amount'   => 0,
                    'product_names'  => array(),
                    'products_count' => 0,
                );
            }
            return array(
                'has_hold'       => true,
                'total_amount'   => $default_amount,
                'product_names'  => array_unique( $all_product_names ),
                'products_count' => count( $all_product_names ),
            );
        }

        // ── Delegate to Computation Service (single source of truth) ──
        $result = Securehold_Deposit_Computation_Service::compute_for_cart( $cart_items );

        if ( ! $result['has_hold'] || $result['total_amount'] <= 0 ) {
            return array(
                'has_hold'       => false,
                'total_amount'   => 0,
                'product_names'  => array(),
                'products_count' => 0,
            );
        }

        $data = array(
            'has_hold'       => true,
            'total_amount'   => $result['total_amount'],
            'product_names'  => array_unique( $all_product_names ),
            'products_count' => count( $all_product_names ),
        );

        if ( 'per_item_aggregated' === $result['aggregation_mode'] ) {
            $data['aggregation_mode'] = 'per_item_aggregated';
        }

        // Attach debug metadata (used by debug HTML comment, never displayed directly).
        $data['_debug'] = array(
            'source'                  => isset( $result['source'] ) ? $result['source'] : '',
            'source_label'            => isset( $result['source_label'] ) ? $result['source_label'] : '',
            'timing'                  => isset( $result['timing'] ) ? $result['timing'] : '',
            'aggregation'             => $result['aggregation_mode'],
            'compute_for_cart_amount' => $result['total_amount'],
        );

        return $data;
    }
    
    /**
     * Replace message variables
     */
    private function replace_message_variables($message, $cart_data = null) {
        if (!$cart_data) {
            $cart_data = $this->get_cart_hold_data();
        }
        
        $currency = get_woocommerce_currency();
        $auto_release_days = get_option('securehold_auto_release_days', '7');
        $release_date = gmdate('F j, Y', strtotime('+' . $auto_release_days . ' days'));
        
        $replacements = array(
            '{amount}' => wc_price($cart_data['total_amount']),
            '{amount_raw}' => number_format($cart_data['total_amount'], 2),
            '{currency}' => $currency,
            '{currency_symbol}' => get_woocommerce_currency_symbol($currency),
            '{product_name}' => implode(', ', $cart_data['product_names']),
            '{product_names}' => implode(', ', $cart_data['product_names']),
            '{products_count}' => $cart_data['products_count'],
            '{order_total}' => WC()->cart ? WC()->cart->get_total() : '',
            '{release_date}' => $release_date,
            '{release_days}' => $auto_release_days,
            '{site_name}' => get_bloginfo('name'),
        );
        
        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }
    
    /**
     * Render checkout notice
     */
    private function render_checkout_notice($message, $style = 'info') {
        $colors = array(
            'info' => array('bg' => '#e3f2fd', 'border' => '#2196f3', 'text' => '#1565c0', 'icon' => 'dashicons-info'),
            'warning' => array('bg' => '#fff3e0', 'border' => '#ff9800', 'text' => '#e65100', 'icon' => 'dashicons-warning'),
            'success' => array('bg' => '#e8f5e9', 'border' => '#4caf50', 'text' => '#2e7d32', 'icon' => 'dashicons-yes-alt'),
        );
        
        $color = isset($colors[$style]) ? $colors[$style] : $colors['info'];
        ?>
        <div class="securehold-checkout-notice" style="
            background: <?php echo esc_attr($color['bg']); ?>;
            border-left: 4px solid <?php echo esc_attr($color['border']); ?>;
            padding: 1.25rem 1.5rem;
            margin: 1.5rem 0;
            border-radius: 6px;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            font-size: 0.9375rem;
            line-height: 1.6;
        ">
            <span class="dashicons <?php echo esc_attr($color['icon']); ?>" style="
                color: <?php echo esc_attr($color['border']); ?>;
                font-size: 24px;
                margin-top: 2px;
                flex-shrink: 0;
            "></span>
            <div style="flex: 1; color: <?php echo esc_attr($color['text']); ?>;">
                <?php echo wp_kses_post( wpautop( wp_kses_post( $message ) ) ); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get default checkout message.
     *
     * The wording is intentionally automation-strategy-agnostic:
     *   — "may be authorized" (not "will be placed") — true for all strategies.
     *   — No release delay mentioned — correct for Immediate, Delayed, Scheduled,
     *     By Status, and Manual, since the timing is operator-controlled and not
     *     known at checkout time for most strategies.
     *   — "not an additional charge" — EU 2011/83/EU and US contract standard.
     *   — No mention of saved cards or tokenisation.
     *
     * Operators may override this via Settings > Appearance > Checkout Message.
     * The {amount} variable is still replaced via replace_message_variables().
     */
    private function get_default_checkout_message() {
        /* translators: %s = formatted deposit amount, e.g. "$300.00" */
        return sprintf(
            /* translators: %s = formatted hold amount with HTML (e.g. <strong>$300.00</strong>) */
            __( 'A security deposit of %s may be authorized on your payment method in accordance with the store\'s deposit policy. This is an authorization only and not an additional charge.', 'securehold-security-deposit-holds' ),
            '<strong>{amount}</strong>'
        );
    }
    
    /**
     * Add My Account endpoint
     */
    public function add_my_account_endpoint() {
        add_rewrite_endpoint('securehold-deposits', EP_ROOT | EP_PAGES);
    }
    
    /**
     * Add menu item to My Account
     */
    public function add_my_account_menu_item($items) {
        $menu_label = get_option('securehold_my_account_menu_label', __('My Deposits', 'securehold-security-deposit-holds'));
        
        // Insert before logout
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);
        
        $items['securehold-deposits'] = $menu_label;
        $items['customer-logout'] = $logout;
        
        return $items;
    }
    
    /**
     * My Account deposits page content.
     *
     * Queries the wp_securehold_holds custom table (single source of truth)
     * via the customer's WooCommerce order IDs.  HPOS-compatible.
     *
     * @since 5.4.0 Rewritten: queries custom table instead of order post-meta.
     */
    public function my_account_deposits_content() {
        $user_id = get_current_user_id();

        if ( ! $user_id ) {
            return;
        }

        // ── Load DB class ──
        if ( ! class_exists( 'SecureHold_DB' ) ) {
            $db_path = defined( 'SECUREHOLD_PLUGIN_DIR' )
                ? SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php'
                : plugin_dir_path( dirname( __FILE__ ) ) . 'includes/database/class-securehold-wp-db.php';
            if ( file_exists( $db_path ) ) {
                require_once $db_path;
            }
        }

        // ── Get customer's order IDs (HPOS-compatible, no post-meta dependency) ──
        $order_ids = wc_get_orders( array(
            'customer_id' => $user_id,
            'limit'       => -1,
            'return'      => 'ids',
            'status'      => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' ),
        ) );

        // ── Query deposits from the custom table ──
        $deposits = array();
        if ( ! empty( $order_ids ) && class_exists( 'SecureHold_DB' ) ) {
            $deposits = SecureHold_DB::get_deposits_by_order_ids( $order_ids );
        }

        // ── Pagination ──
        $per_page     = 10;
        $total        = count( $deposits );
        $current_page = max( 1, absint( isset( $_GET['deposit-page'] ) ? $_GET['deposit-page'] : 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
        $total_pages  = (int) ceil( $total / $per_page );
        $offset       = ( $current_page - 1 ) * $per_page;
        $paged        = array_slice( $deposits, $offset, $per_page );

        // ── Admin-customisable labels ──
        $page_title       = get_option( 'securehold_my_account_page_title', __( 'My Security Deposits', 'securehold-security-deposit-holds' ) );
        $page_description = get_option( 'securehold_my_account_page_description', __( 'View and manage your security deposits for active reservations.', 'securehold-security-deposit-holds' ) );

        // ── Status colour map (matches DB statuses from wp_securehold_holds) ──
        $status_map = array(
            'pending'        => array( 'label' => __( 'Pending', 'securehold-security-deposit-holds' ),        'color' => '#6b7280' ),
            'authorized'     => array( 'label' => __( 'Active', 'securehold-security-deposit-holds' ),         'color' => '#10b981' ),
            'captured'       => array( 'label' => __( 'Captured', 'securehold-security-deposit-holds' ),       'color' => '#ef4444' ),
            'released'       => array( 'label' => __( 'Released', 'securehold-security-deposit-holds' ),       'color' => '#6b7280' ),
            'expired'        => array( 'label' => __( 'Expired', 'securehold-security-deposit-holds' ),        'color' => '#f59e0b' ),
            'scheduled'      => array( 'label' => __( 'Scheduled', 'securehold-security-deposit-holds' ),      'color' => '#3b82f6' ),
            'pending_manual' => array( 'label' => __( 'Pending Manual', 'securehold-security-deposit-holds' ), 'color' => '#8b5cf6' ),
            'failed'         => array( 'label' => __( 'Failed', 'securehold-security-deposit-holds' ),         'color' => '#dc2626' ),
        );

        ?>
        <div class="securehold-my-account-deposits">
            <h2><?php echo esc_html( $page_title ); ?></h2>
            <?php if ( $page_description ) : ?>
                <p class="securehold-page-description"><?php echo esc_html( $page_description ); ?></p>
            <?php endif; ?>

            <?php if ( empty( $paged ) ) : ?>
                <div class="woocommerce-message woocommerce-message--info woocommerce-info">
                    <?php echo esc_html( get_option( 'securehold_my_account_empty_message', __( 'You have no security deposits at this time.', 'securehold-security-deposit-holds' ) ) ); ?>
                </div>

            <?php else : ?>
                <table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
                    <thead>
                        <tr>
                            <th class="woocommerce-orders-table__header"><?php esc_html_e( 'Order', 'securehold-security-deposit-holds' ); ?></th>
                            <th class="woocommerce-orders-table__header"><?php esc_html_e( 'Date', 'securehold-security-deposit-holds' ); ?></th>
                            <th class="woocommerce-orders-table__header"><?php esc_html_e( 'Deposit Amount', 'securehold-security-deposit-holds' ); ?></th>
                            <th class="woocommerce-orders-table__header"><?php esc_html_e( 'Status', 'securehold-security-deposit-holds' ); ?></th>
                            <th class="woocommerce-orders-table__header"><?php esc_html_e( 'Details', 'securehold-security-deposit-holds' ); ?></th>
                            <th class="woocommerce-orders-table__header"><?php esc_html_e( 'Actions', 'securehold-security-deposit-holds' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $paged as $deposit ) :
                            $order  = wc_get_order( $deposit->order_id );
                            $status = isset( $status_map[ $deposit->status ] )
                                ? $status_map[ $deposit->status ]
                                : array( 'label' => ucfirst( $deposit->status ), 'color' => '#6b7280' );
                        ?>
                        <tr class="woocommerce-orders-table__row order">
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Order', 'securehold-security-deposit-holds' ); ?>">
                                <?php if ( $order ) : ?>
                                    <a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
                                <?php else : ?>
                                    #<?php echo esc_html( $deposit->order_id ); ?>
                                <?php endif; ?>
                            </td>
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Date', 'securehold-security-deposit-holds' ); ?>">
                                <time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $deposit->created_at ) ) ); ?>">
                                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deposit->created_at ) ) ); ?>
                                </time>
                            </td>
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Deposit Amount', 'securehold-security-deposit-holds' ); ?>">
                                <strong><?php echo wp_kses_post( wc_price( $deposit->amount, array( 'currency' => strtoupper( $deposit->currency ) ) ) ); ?></strong>
                                <?php if ( $deposit->captured_amount > 0 && 'captured' === $deposit->status ) : ?>
                                    <br><small><?php
                                        /* translators: %s = formatted captured amount */
                                        printf( esc_html__( 'Captured: %s', 'securehold-security-deposit-holds' ), wp_kses_post( wc_price( $deposit->captured_amount, array( 'currency' => strtoupper( $deposit->currency ) ) ) ) );
                                    ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Status', 'securehold-security-deposit-holds' ); ?>">
                                <span class="securehold-status-badge" style="display:inline-block;padding:0.25rem 0.75rem;border-radius:12px;background:<?php echo esc_attr( $status['color'] ); ?>20;color:<?php echo esc_attr( $status['color'] ); ?>;font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.025em;">
                                    <?php echo esc_html( $status['label'] ); ?>
                                </span>
                            </td>
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Details', 'securehold-security-deposit-holds' ); ?>">
                                <?php if ( ! empty( $deposit->expires_at ) && 'authorized' === $deposit->status ) : ?>
                                    <small><?php
                                        /* translators: %s = formatted expiry date */
                                        printf( esc_html__( 'Expires %s', 'securehold-security-deposit-holds' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deposit->expires_at ) ) ) );
                                    ?></small>
                                <?php elseif ( ! empty( $deposit->released_at ) && 'released' === $deposit->status ) : ?>
                                    <small><?php
                                        /* translators: %s = formatted release date */
                                        printf( esc_html__( 'Released %s', 'securehold-security-deposit-holds' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deposit->released_at ) ) ) );
                                    ?></small>
                                <?php elseif ( ! empty( $deposit->captured_at ) && 'captured' === $deposit->status ) : ?>
                                    <small><?php
                                        /* translators: %s = formatted capture date */
                                        printf( esc_html__( 'Captured %s', 'securehold-security-deposit-holds' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deposit->captured_at ) ) ) );
                                    ?></small>
                                <?php else : ?>
                                    <span style="color:#9ca3af;">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Actions', 'securehold-security-deposit-holds' ); ?>">
                                <?php if ( $order ) : ?>
                                    <a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" class="woocommerce-button wp-element-button button view">
                                        <?php esc_html_e( 'View Order', 'securehold-security-deposit-holds' ); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <nav class="woocommerce-pagination securehold-pagination">
                        <?php
                        $base_url = wc_get_endpoint_url( 'securehold-deposits', '', wc_get_page_permalink( 'myaccount' ) );
                        echo wp_kses_post( paginate_links( array(
                            'base'      => add_query_arg( 'deposit-page', '%#%', $base_url ),
                            'format'    => '',
                            'current'   => $current_page,
                            'total'     => $total_pages,
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'type'      => 'list',
                        ) ) );
                        ?>
                    </nav>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <?php
    }
    
    /**
     * SHORTCODE: [securehold_my_deposits]
     * Display user's deposits anywhere
     */
    public function shortcode_my_deposits($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your deposits.', 'securehold-security-deposit-holds') . '</p>';
        }

        ob_start();
        $this->my_account_deposits_content();
        return ob_get_clean();
    }
    
    /**
     * SHORTCODE: [securehold_deposit_status order_id="123"]
     * Display status of a specific deposit.
     *
     * Reads from the wp_securehold_holds table (single source of truth).
     * Includes ownership check: only the order owner or a shop manager
     * may view the deposit.
     *
     * @since 5.4.0 Rewritten: reads from custom table, adds ownership check.
     */
    public function shortcode_deposit_status( $atts ) {
        $atts = shortcode_atts( array(
            'order_id' => 0,
        ), $atts );

        $order_id = absint( $atts['order_id'] );
        if ( ! $order_id ) {
            return '';
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return '';
        }

        // Guest: never expose deposit information.
        if ( ! is_user_logged_in() ) {
            return '';
        }

        // Ownership check: logged-in user must own the order (or be admin).
        if ( (int) $order->get_customer_id() !== get_current_user_id()
            && ! current_user_can( 'manage_woocommerce' ) ) {
            return '';
        }

        // Load DB class.
        if ( ! class_exists( 'SecureHold_DB' ) ) {
            $db_path = defined( 'SECUREHOLD_PLUGIN_DIR' )
                ? SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php'
                : plugin_dir_path( dirname( __FILE__ ) ) . 'includes/database/class-securehold-wp-db.php';
            if ( file_exists( $db_path ) ) {
                require_once $db_path;
            }
        }

        if ( ! class_exists( 'SecureHold_DB' ) ) {
            return '';
        }

        $deposit = SecureHold_DB::get_deposit( $order_id );
        if ( ! $deposit || ! $deposit->amount ) {
            return '';
        }

        $status_labels = array(
            'pending'    => __( 'Pending', 'securehold-security-deposit-holds' ),
            'authorized' => __( 'Active Hold', 'securehold-security-deposit-holds' ),
            'captured'   => __( 'Captured', 'securehold-security-deposit-holds' ),
            'released'   => __( 'Released', 'securehold-security-deposit-holds' ),
            'expired'    => __( 'Expired', 'securehold-security-deposit-holds' ),
            'scheduled'  => __( 'Scheduled', 'securehold-security-deposit-holds' ),
            'failed'     => __( 'Failed', 'securehold-security-deposit-holds' ),
        );

        $status = isset( $status_labels[ $deposit->status ] )
            ? $status_labels[ $deposit->status ]
            : ucfirst( $deposit->status );

        return sprintf(
            '<div class="securehold-deposit-status"><strong>%s:</strong> %s (%s)</div>',
            esc_html__( 'Security Deposit', 'securehold-security-deposit-holds' ),
            wp_kses_post( wc_price( $deposit->amount, array( 'currency' => strtoupper( $deposit->currency ) ) ) ),
            esc_html( $status )
        );
    }
    
    /**
     * SHORTCODE: [securehold_checkout_message]
     * Display checkout message anywhere
     */
    public function shortcode_checkout_message($atts) {
        if (!WC()->cart) {
            return '';
        }
        
        $cart_data = $this->get_cart_hold_data();
        
        if (!$cart_data['has_hold']) {
            return '';
        }
        
        $message = get_option('securehold_checkout_message', $this->get_default_checkout_message());
        $message = $this->replace_message_variables($message, $cart_data);
        $style = get_option('securehold_checkout_message_style', 'info');
        
        ob_start();
        $this->render_checkout_notice($message, $style);
        return ob_get_clean();
    }
    
    /**
     * Ensure Stripe Elements uses 'auto' locale on checkout.
     *
     * WHY THIS EXISTS:
     *   WooCommerce Stripe Gateway renders a mandate disclosure text ("By providing
     *   your payment information…") via Stripe Elements when setup_future_usage is
     *   set on the PaymentIntent. SecureHold forces setup_future_usage=off_session
     *   (required for reusable PaymentMethods and off-session holds).
     *
     *   For logged-in users, the gateway creates a Stripe Customer object and sends
     *   the Customer's WP locale to Stripe Elements. If the user's WP account is
     *   set to French but the checkout page is in English, this text appears in French.
     *   Guest checkouts are unaffected: no Customer object = no mandate text shown.
     *
     *   Setting locale='auto' instructs Stripe Elements to detect the language from
     *   the page's <html lang=""> attribute instead of the user account locale.
     *   This is a non-breaking change: 'auto' is functionally the Stripe Elements
     *   default, so this filter is a safe no-op on flows where locale is already set.
     *
     *   This filter targets wc_stripe_upe_params (WC Stripe Gateway v6+).
     *   Priority 5 keeps it well below the Checkout Engine's priority-9999 SFU hooks.
     *
     * @param array $params Stripe UPE JS params passed by WC Stripe Gateway.
     * @return array
     */
    public function force_stripe_elements_locale( $params ) {
        if ( ! isset( $params['locale'] ) || $params['locale'] === '' ) {
            $params['locale'] = 'auto';
        }
        return $params;
    }

    /**
     * Get available variables for messages
     */
    public static function get_available_variables() {
        return array(
            'checkout' => array(
                'label' => __('Checkout & Cart', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{amount}' => __('Formatted hold amount (e.g., $300.00)', 'securehold-security-deposit-holds'),
                    '{amount_raw}' => __('Raw hold amount (e.g., 300.00)', 'securehold-security-deposit-holds'),
                    '{currency}' => __('Currency code (e.g., USD)', 'securehold-security-deposit-holds'),
                    '{currency_symbol}' => __('Currency symbol (e.g., $)', 'securehold-security-deposit-holds'),
                    '{product_name}' => __('First product name', 'securehold-security-deposit-holds'),
                    '{product_names}' => __('All product names (comma-separated)', 'securehold-security-deposit-holds'),
                    '{products_count}' => __('Number of products with holds', 'securehold-security-deposit-holds'),
                    '{order_total}' => __('Total order amount', 'securehold-security-deposit-holds'),
                )
            ),
            'timing' => array(
                'label' => __('Dates & Timing', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{release_date}' => __('Automatic release date', 'securehold-security-deposit-holds'),
                    '{release_days}' => __('Days until automatic release', 'securehold-security-deposit-holds'),
                )
            ),
            'site' => array(
                'label' => __('Site Information', 'securehold-security-deposit-holds'),
                'variables' => array(
                    '{site_name}' => __('Your site name', 'securehold-security-deposit-holds'),
                )
            ),
        );
    }
}
