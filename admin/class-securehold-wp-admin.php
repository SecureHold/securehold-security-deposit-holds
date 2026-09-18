<?php
/**
 * Admin functionality
 * Version: 3.10.0 - Tools Control Center
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Securehold_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_footer', array($this, 'render_setup_modal'));
        add_action('admin_footer', array($this, 'render_deactivation_modal'));
        add_action('admin_footer', array($this, 'render_capture_modal_for_order_screen'));

        // AJAX Handlers
        add_action('wp_ajax_securehold_modal_action', array($this, 'handle_modal_action'));
        add_action('wp_ajax_securehold_deactivation_feedback', array($this, 'handle_deactivation_feedback'));
        add_action('wp_ajax_securehold_capture_hold', array($this, 'handle_capture_hold'));
        add_action('wp_ajax_securehold_release_hold', array($this, 'handle_release_hold'));
        add_action('wp_ajax_securehold_diagnose_deposit', array($this, 'handle_diagnose_deposit'));
        add_action('wp_ajax_securehold_diagnose_order_stripe', array($this, 'ajax_diagnose_order_stripe'));

        add_action('wp_ajax_securehold_create_hold_manual', array($this, 'ajax_create_hold_manual'));
        add_action('wp_ajax_securehold_inspect_order_meta', array($this, 'ajax_inspect_order_meta'));
        // Product/category rule AJAX is handled by Securehold_Pro_Rule_Engine::init() when PRO is active.

        // Simulator FREE path — global-only; self-bails at execution time when PRO is active.
        add_action( 'wp_ajax_securehold_simulate_cart', array( $this, 'ajax_simulate_cart_free' ) );

        // Search AJAX (categories + products with initial list)
        add_action('wp_ajax_securehold_search_categories', array($this, 'ajax_search_categories'));
        add_action('wp_ajax_securehold_search_products', array($this, 'ajax_search_products'));

        add_action('admin_init', array($this, 'handle_tool_actions'));
        add_action('admin_notices', array($this, 'show_configuration_notice'));
        add_action('admin_notices', array($this, 'show_transient_notices'));
        add_action('wp_ajax_securehold_dismiss_config_notice', array($this, 'dismiss_configuration_notice'));
        add_action('woocommerce_admin_order_totals_after_total', array($this, 'display_admin_deposit_totals'), 10, 1);
        add_action('current_screen', array($this, 'ensure_metabox_visible'));
        add_action('add_meta_boxes', array($this, 'add_order_metabox'));
        add_action('add_meta_boxes_woocommerce_page_wc-orders', array($this, 'add_order_metabox'));
        add_action('save_post_shop_order', array($this, 'handle_order_metabox_actions'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'handle_order_metabox_actions'));

    }
    
    /**
     * Get status badge class based on deposit/hold status
     * Centralized mapping for consistent UI across plugin
     * 
     * @param string $status The deposit status
     * @return string CSS class for badge
     */

    public static function get_status_badge_class($status) {
        $status_map = array(
            'authorized'     => 'sh-badge-success',      // Active hold - Green
            'captured'       => 'sh-badge-captured',     // Money captured - Dark green
            'released'       => 'sh-badge-info',         // Cancelled - Blue
            'failed'         => 'sh-badge-danger',       // Error - Red
            'pending_manual' => 'sh-badge-pending',      // Action required - Orange
            'pending'        => 'sh-badge-secondary',    // Inactive - Gray
            'scheduled'      => 'sh-badge-scheduled',    // Awaiting cron execution - Purple
        );

        return isset($status_map[$status]) ? $status_map[$status] : 'sh-badge-secondary';
    }
    
    /**
     * Render status badge HTML
     *
     * For deposits with DB status 'scheduled', the actual timing strategy is stored
     * in the order meta '_securehold_timing_strategy' (values: 'scheduled' or 'delayed').
     * Passing $order_id (and optionally $deposit_notes for legacy records) allows the badge
     * to display the correct label ("Delayed" vs "Scheduled").
     *
     * @param string   $status        The deposit status from the DB.
     * @param bool     $echo          Whether to echo or return.
     * @param int|null $order_id      Order ID used to resolve timing strategy for 'scheduled' status.
     * @param string   $deposit_notes Deposit notes for legacy fallback (optional).
     * @return string|void Badge HTML
     */
    public static function render_status_badge($status, $echo = true, $order_id = null, $deposit_notes = '') {
        $class = self::get_status_badge_class($status);
        $label = self::get_status_label($status, $order_id, $deposit_notes);
        $html = sprintf('<span class="sh-badge %s">%s</span>', esc_attr($class), esc_html($label));

        if ($echo) {
            echo wp_kses_post( $html );
        } else {
            return $html;
        }
    }

    /**
     * Centralized mapping of strategy internal values → UI labels.
     *
     * Used for filter dropdowns, display, and any future UI that needs to
     * translate internal strategy keys ('immediate', 'manual', etc.) into
     * human-readable labels.
     *
     * @return array Associative array: internal_value => translated_label.
     */
    public static function get_strategy_labels() {
        return array(
            'immediate' => __( 'Immediate', 'securehold-security-deposit-holds' ),
            'manual'    => __( 'Manual', 'securehold-security-deposit-holds' ),
            'delayed'   => __( 'Delayed', 'securehold-security-deposit-holds' ),
            'scheduled' => __( 'Scheduled', 'securehold-security-deposit-holds' ),
            'status'    => __( 'By Status', 'securehold-security-deposit-holds' ),
        );
    }

    /**
     * Resolve the human-readable label for a deposit status.
     *
     * For DB status 'scheduled', the actual timing strategy is determined by
     * order meta '_securehold_timing_strategy'. Legacy deposits without the meta
     * display the raw status label; use the migration tool to backfill.
     *
     * @param string   $status        DB status value.
     * @param int|null $order_id      Order ID for meta lookup (only needed for 'scheduled' status).
     * @param string   $deposit_notes Deprecated. No longer used (legacy notes fallback removed).
     * @return string Translated label.
     */
    public static function get_status_label($status, $order_id = null, $deposit_notes = '') {
        if ($status === 'scheduled' && $order_id) {
            $order = wc_get_order($order_id);
            if ($order) {
                $timing_strategy = $order->get_meta('_securehold_timing_strategy', true);
                if ($timing_strategy === 'delayed') {
                    return __('Delayed', 'securehold-security-deposit-holds');
                }
                if ($timing_strategy === 'scheduled') {
                    return __('Scheduled', 'securehold-security-deposit-holds');
                }
            }
        }
        return ucfirst(str_replace('_', ' ', $status));
    }
    
    /**
     * Get available actions for a deposit based on its status and data.
     *
     * Returns a structured array of actions grouped by role (primary, secondary, danger).
     * Used by both the deposits list kebab menu and the deposit details header.
     *
     * @param object $deposit       Full deposit DB row.
     * @param float  $total         Total authorized amount.
     * @param float  $captured      Already captured amount.
     * @param string $currency      Currency code (e.g. 'USD').
     * @param string $currency_symbol Currency symbol (e.g. '$').
     * @return array  Flat list of action descriptors, each with keys:
     *   'key'       – unique action id (view_details, view_order, capture, release, create_hold, diagnose, view_stripe)
     *   'label'     – translated display label
     *   'icon'      – dashicons class (without 'dashicons-' prefix)
     *   'icon_color'– hex color for inline icon tint (or empty)
     *   'role'      – 'primary' | 'secondary' | 'danger' | 'link'
     *   'type'      – 'link' | 'button'
     *   'url'       – href for links (empty for buttons)
     *   'target'    – '_blank' or '' for links
     *   'classes'   – space-separated CSS classes for the element
     *   'data'      – associative array of data-* attributes
     */
    public static function get_deposit_actions( $deposit, $total = 0, $captured = 0, $currency = 'USD', $currency_symbol = '$' ) {
        $actions   = array();
        $remaining = $total - $captured;
        $intent_id = ! empty( $deposit->intent_id ) ? $deposit->intent_id : '';
        $is_real_intent = ( strpos( $intent_id, 'pi_' ) === 0 );
        $stripe_mode = get_option( 'securehold_stripe_mode', 'test' );

        // ── Always: View Details ───────────────────────────────
        $actions[] = array(
            'key'        => 'view_details',
            'label'      => __( 'View Details', 'securehold-security-deposit-holds' ),
            'icon'       => 'visibility',
            'icon_color' => '',
            'role'       => 'link',
            'type'       => 'link',
            'url'        => admin_url( 'admin.php?page=securehold-deposit-details&deposit_id=' . $deposit->id ),
            'target'     => '',
            'classes'    => '',
            'data'       => array(),
        );

        // ── Always: View Order ─────────────────────────────────
        $actions[] = array(
            'key'        => 'view_order',
            'label'      => __( 'View Order', 'securehold-security-deposit-holds' ),
            'icon'       => 'cart',
            'icon_color' => '',
            'role'       => 'link',
            'type'       => 'link',
            'url'        => admin_url( 'post.php?post=' . $deposit->order_id . '&action=edit' ),
            'target'     => '',
            'classes'    => '',
            'data'       => array(),
        );

        // ── SCHEDULED → Info only, cron will handle it ─────────
        if ( $deposit->status === 'scheduled' ) {
            $next_run = 0;
            $sched_order = wc_get_order( $deposit->order_id );
            if ( $sched_order ) {
                $next_run = $sched_order->get_meta( '_securehold_deposit_next_run', true );
            }
            // No action buttons — just informational. View Details + View Order are already added above.
        }

        // ── PENDING_MANUAL → Create Hold ───────────────────────
        if ( $deposit->status === 'pending_manual' ) {
            $actions[] = array(
                'key'        => 'create_hold',
                'label'      => __( 'Create Hold', 'securehold-security-deposit-holds' ),
                'icon'       => 'shield',
                'icon_color' => '#10b981',
                'role'       => 'primary',
                'type'       => 'button',
                'url'        => '',
                'target'     => '',
                'classes'    => 'sh-btn-create-hold',
                'data'       => array(
                    'order-id'   => $deposit->order_id,
                    'deposit-id' => $deposit->id,
                ),
            );
        }

        // ── AUTHORIZED (never captured) → Capture ───────────
        // Stripe rule: after ANY capture (full or partial), the remaining
        // authorization is automatically released. No second capture is possible.
        $has_been_captured = ( $deposit->status === 'captured' || $captured > 0 || ! empty( $deposit->captured_at ) );
        if ( $deposit->status === 'authorized' && ! $has_been_captured && ! empty( $intent_id ) ) {
            $actions[] = array(
                'key'        => 'capture',
                'label'      => __( 'Capture', 'securehold-security-deposit-holds' ),
                'icon'       => 'money',
                'icon_color' => '#d97706',
                'role'       => 'primary',
                'type'       => 'button',
                'url'        => '',
                'target'     => '',
                'classes'    => 'sh-btn-capture',
                'data'       => array(
                    'id'       => $deposit->id,
                    'amount'   => $total,
                    'captured' => $captured,
                    'currency' => $deposit->currency,
                ),
            );
        }

        // ── AUTHORIZED → Release Hold ──────────────────────────
        if ( $deposit->status === 'authorized' && ! empty( $intent_id ) ) {
            $actions[] = array(
                'key'        => 'release',
                'label'      => __( 'Release Hold', 'securehold-security-deposit-holds' ),
                'icon'       => 'dismiss',
                'icon_color' => '#dc2626',
                'role'       => 'danger',
                'type'       => 'button',
                'url'        => '',
                'target'     => '',
                'classes'    => 'sh-btn-release',
                'data'       => array(
                    'id'       => $deposit->id,
                    'order-id' => $deposit->order_id,
                    'amount'   => wp_strip_all_tags( wc_price( $total, array( 'currency' => $currency ) ) ),
                ),
            );
        }

        // ── FAILED → Retry + Diagnose ────────────────────────────
        if ( $deposit->status === 'failed' ) {
            $actions[] = array(
                'key'        => 'retry_hold',
                'label'      => __( 'Retry Create Hold', 'securehold-security-deposit-holds' ),
                'icon'       => 'update',
                'icon_color' => '#10b981',
                'role'       => 'primary',
                'type'       => 'button',
                'url'        => '',
                'target'     => '',
                'classes'    => 'sh-btn-create-hold',
                'data'       => array(
                    'order-id'   => $deposit->order_id,
                    'deposit-id' => $deposit->id,
                ),
            );
            $actions[] = array(
                'key'        => 'diagnose',
                'label'      => __( 'Diagnose', 'securehold-security-deposit-holds' ),
                'icon'       => 'search',
                'icon_color' => '#dc2626',
                'role'       => 'primary',
                'type'       => 'button',
                'url'        => '',
                'target'     => '',
                'classes'    => 'sh-btn-diagnose',
                'data'       => array(
                    'deposit-id' => $deposit->id,
                    'order-id'   => $deposit->order_id,
                ),
            );
        }

        // ── Valid pi_* intent → View in Stripe ─────────────────
        if ( $is_real_intent ) {
            $stripe_url = 'https://dashboard.stripe.com/'
                . ( $stripe_mode === 'test' ? 'test/' : '' )
                . 'payments/' . $intent_id;
            $actions[] = array(
                'key'        => 'view_stripe',
                'label'      => __( 'View in Stripe', 'securehold-security-deposit-holds' ),
                'icon'       => 'external',
                'icon_color' => '',
                'role'       => 'secondary',
                'type'       => 'link',
                'url'        => $stripe_url,
                'target'     => '_blank',
                'classes'    => '',
                'data'       => array(),
            );
        }

        return $actions;
    }

    /**
     * Ensure metabox is shown by default in Screen Options
     */
    public function ensure_metabox_visible() {
        $screen = get_current_screen();
        
        if (!$screen) return;
        
        // Check if we're on an order edit screen
        $is_order_screen = (
            $screen->id === 'shop_order' || 
            $screen->id === 'woocommerce_page_wc-orders' ||
            $screen->id === 'post-shop_order'
        );
        
        if (!$is_order_screen) return;
        
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) return;
        
        // Check if metabox is hidden
        $hidden = get_user_meta($user_id, 'metaboxhidden_' . $screen->id, true);
        
        if (is_array($hidden) && in_array('securehold_order_data', $hidden)) {
            // Remove our metabox from hidden list
            $hidden = array_diff($hidden, ['securehold_order_data']);
            update_user_meta($user_id, 'metaboxhidden_' . $screen->id, $hidden);
        }
    }

    /**
     * FREE Simulator — global-only simulation path.
     *
     * Handles wp_ajax_securehold_simulate_cart in FREE mode.
     * When PRO is active, this method bails immediately and PRO's
     * SecureHold_Simulator::ajax_simulate_cart() handles the request instead.
     *
     * Returns per_order mode using the global configuration only.
     * Product and category rules are PRO features — not evaluated here.
     *
     * @since 4.6.0
     */
    public function ajax_simulate_cart_free() {
        // Execution-time gate: bail when PRO is active so PRO's handler responds instead.
        if ( securehold_rule_engine_enabled() ) {
            return;
        }

        check_ajax_referer( 'securehold_simulate_cart', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'securehold-security-deposit-holds' ) ), 403 );
        }

        // Parse and validate cart products.
        $raw_products = isset( $_POST['products'] ) ? (array) wp_unslash( $_POST['products'] ) : array();
        $cart_items   = array();
        $total_qty    = 0;

        foreach ( $raw_products as $item ) {
            $product_id = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : 0;
            $qty        = isset( $item['qty'] ) ? max( 1, absint( $item['qty'] ) ) : 1;

            if ( ! $product_id ) {
                continue;
            }
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $cart_items[] = array(
                'product_id'   => $product_id,
                'product_name' => $product->get_name(),
                'qty'          => $qty,
                'categories'   => array(), // per-product category lookup requires PRO
            );
            $total_qty += $qty;
        }

        // Empty cart is allowed — returns global config preview without product resolution.

        // Load Config Resolver (always available in FREE).
        if ( ! class_exists( 'Securehold_Config_Resolver' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-config-resolver.php';
        }

        // ── Read draft or saved values for the Deposit Preview ───────────────
        // The JS sends draft_config.global with the current form values (not yet
        // saved). We read draft values directly — no pre_option_* filters needed,
        // no resolver involvement — so object-level caches cannot interfere.
        // Nothing is persisted to the database.
        $cfg = $this->read_deposit_preview_config();

        $deposit  = $cfg['deposit_amount'];
        $strategy = $cfg['capture_timing'];
        $policy   = $cfg['resolution_policy'];
        $agg_mode = $cfg['aggregation_mode'];

        $response = array(
            // Rendering mode stays 'per_order': FREE has no product/category rules,
            // so a real per-item breakdown would just repeat the same global line
            // for every item. The selected aggregation mode is still reflected in
            // the labels below (context/resolution), only the JS layout differs.
            'mode'       => 'per_order',
            'context'    => array(
                'products'        => $cart_items,
                'aggregation'     => $agg_mode,
                'policy'          => $policy,
                'engine_version'  => 'free',
                'total_qty'       => $total_qty,
                'min_cart_amount' => $cfg['min_cart_amount'],
                'auto_release'    => $cfg['auto_release_days'],
            ),
            'candidates' => array(
                array(
                    'type'       => 'global',
                    'label'      => __( 'Global Config', 'securehold-security-deposit-holds' ),
                    'amount_raw' => $deposit,
                    'timing'     => $strategy,
                ),
            ),
            'steps'      => array(
                array(
                    'label'     => __( 'Product Rules', 'securehold-security-deposit-holds' ),
                    'status'    => 'skipped',
                    'detail'    => __( 'Global configuration applies.', 'securehold-security-deposit-holds' ),
                    'source_id' => 0,
                ),
                array(
                    'label'     => __( 'Category Rules', 'securehold-security-deposit-holds' ),
                    'status'    => 'skipped',
                    'detail'    => __( 'Global configuration applies.', 'securehold-security-deposit-holds' ),
                    'source_id' => 0,
                ),
                array(
                    'label'     => __( 'Global Config', 'securehold-security-deposit-holds' ),
                    'status'    => 'winner',
                    'detail'    => __( 'Global configuration is applied to all products.', 'securehold-security-deposit-holds' ),
                    'source_id' => 0,
                ),
            ),
            'resolution' => array(
                'source'              => 'global',
                'source_id'           => 0,
                'source_label'        => __( 'Global Config', 'securehold-security-deposit-holds' ),
                'deposit'             => $deposit,
                'strategy'            => $strategy,
                'fallbacks'           => array(),
                'policy'              => $policy,
                'aggregation'         => $agg_mode,
                'policy_effective'    => $policy,
                'winner_reason'       => __( 'Global configuration applies. Add products to preview the deposit amount for this cart.', 'securehold-security-deposit-holds' ),
                'min_cart_amount'     => $cfg['min_cart_amount'],
                'auto_release_days'   => $cfg['auto_release_days'],
                'delay_days'          => '',
                'date_field_key'      => '',
                'scheduled_days'      => '',
                'scheduled_direction' => '',
                'trigger_status'      => '',
            ),
        );

        wp_send_json_success( $response );
    }

    /**
     * Read Deposit Preview configuration from draft POST values or saved DB options.
     *
     * Reads $_POST['draft_config']['global'] when present (JS sends unsaved form
     * values), falls back to get_option() for any key not in the draft.
     * Nothing is persisted — this is read-only.
     *
     * Returns a flat array of the FREE config values used by the preview:
     *   deposit_amount    string   e.g. "300" or "20%"
     *   capture_timing    string   "immediate"|"manual"
     *   min_cart_amount   string   numeric string or "" (no minimum)
     *   auto_release_days string   integer string "1".."7"
     *   resolution_policy string   "priority_chain"|"highest_deposit_wins"
     *   aggregation_mode  string   "per_order"|"per_item_aggregated"
     *
     * @since 5.8.1
     * @since 5.8.2 Added resolution_policy and aggregation_mode (FREE Rule Engine preview).
     * @return array
     */
    private function read_deposit_preview_config() {
        $draft = array();

        // Read draft values from POST if present and well-formed.
        if ( isset( $_POST['draft_config'] )
            && is_array( $_POST['draft_config'] )
            && isset( $_POST['draft_config']['global'] )
            && is_array( $_POST['draft_config']['global'] )
        ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw = (array) wp_unslash( $_POST['draft_config']['global'] );

            // deposit_amount — free text (amount or percentage string).
            if ( isset( $raw['deposit_amount'] ) ) {
                $draft['deposit_amount'] = sanitize_text_field( $raw['deposit_amount'] );
            }

            // capture_timing — whitelist immediate/manual only in FREE.
            if ( isset( $raw['capture_timing'] ) ) {
                $t = sanitize_key( $raw['capture_timing'] );
                if ( in_array( $t, array( 'immediate', 'manual' ), true ) ) {
                    $draft['capture_timing'] = $t;
                }
            }

            // min_cart_amount — numeric string or empty.
            if ( isset( $raw['min_cart_amount'] ) ) {
                $draft['min_cart_amount'] = sanitize_text_field( $raw['min_cart_amount'] );
            }

            // auto_release_days — integer clamped 1-7.
            if ( isset( $raw['auto_release_days'] ) ) {
                $d = absint( $raw['auto_release_days'] );
                if ( $d < 1 ) { $d = 1; }
                if ( $d > 7 ) { $d = 7; }
                $draft['auto_release_days'] = (string) $d;
            }

            // resolution_policy — whitelist priority_chain/highest_deposit_wins.
            if ( isset( $raw['resolution_policy'] ) ) {
                $p = sanitize_key( $raw['resolution_policy'] );
                if ( in_array( $p, array( 'priority_chain', 'highest_deposit_wins' ), true ) ) {
                    $draft['resolution_policy'] = $p;
                }
            }

            // aggregation_mode — whitelist per_order/per_item_aggregated.
            if ( isset( $raw['aggregation_mode'] ) ) {
                $a = sanitize_key( $raw['aggregation_mode'] );
                if ( in_array( $a, array( 'per_order', 'per_item_aggregated' ), true ) ) {
                    $draft['aggregation_mode'] = $a;
                }
            }
        }

        // Fall back to saved DB values for any field not in the draft.
        return array(
            'deposit_amount'     => isset( $draft['deposit_amount'] )
                ? $draft['deposit_amount']
                : (string) get_option( 'securehold_default_hold_amount', '300' ),
            'capture_timing'     => isset( $draft['capture_timing'] )
                ? $draft['capture_timing']
                : (string) get_option( 'securehold_capture_timing', 'immediate' ),
            'min_cart_amount'    => isset( $draft['min_cart_amount'] )
                ? $draft['min_cart_amount']
                : (string) get_option( 'securehold_min_cart_amount', '' ),
            'auto_release_days'  => isset( $draft['auto_release_days'] )
                ? $draft['auto_release_days']
                : (string) (int) get_option( 'securehold_auto_release_days', 7 ),
            'resolution_policy'  => isset( $draft['resolution_policy'] )
                ? $draft['resolution_policy']
                : (string) get_option( 'securehold_resolution_policy', 'priority_chain' ),
            'aggregation_mode'   => isset( $draft['aggregation_mode'] )
                ? $draft['aggregation_mode']
                : (string) get_option( 'securehold_aggregation_mode', 'per_order' ),
        );
    }

    /**
     * AJAX: Search WooCommerce product categories for Select2.
     * Returns initial list (term empty) or filtered results (term provided).
     * Supports name, slug, and ID matching.
     */
    public function ajax_search_categories() {
        check_ajax_referer('securehold_search_categories', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json(array());
        }

        // phpcs:ignore WordPress.Security.NonceVerification -- nonce verified above
        $term  = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash($_REQUEST['term'])) : '';
        $limit = 50;

        $args = array(
            'taxonomy'   => 'product_cat',
            'orderby'    => 'name',
            'order'      => 'ASC',
            'hide_empty' => false,
            'number'     => $limit,
        );

        // If a search term is provided, use it
        if (!empty($term)) {
            // Check if term is numeric (search by ID)
            if (is_numeric($term)) {
                $args['include'] = array(absint($term));
            } else {
                $args['search'] = $term;
            }
        }

        $categories = get_terms($args);

        // If numeric search returned nothing, fall back to name search
        if (is_numeric($term) && (is_wp_error($categories) || empty($categories))) {
            unset($args['include']);
            $args['search'] = $term;
            $categories = get_terms($args);
        }

        $results = array();

        if (!is_wp_error($categories) && !empty($categories)) {
            foreach ($categories as $cat) {
                // Build hierarchical label: "Parent > Child"
                $label = $cat->name;
                if ($cat->parent > 0) {
                    $ancestors = get_ancestors($cat->term_id, 'product_cat', 'taxonomy');
                    $parts = array();
                    foreach (array_reverse($ancestors) as $ancestor_id) {
                        $ancestor = get_term($ancestor_id, 'product_cat');
                        if ($ancestor && !is_wp_error($ancestor)) {
                            $parts[] = $ancestor->name;
                        }
                    }
                    $parts[] = $cat->name;
                    $label = implode(' > ', $parts);
                }

                $count_text = sprintf(
                    /* translators: %d = number of products in category */
                    _n('%d product', '%d products', $cat->count, 'securehold-security-deposit-holds'),
                    $cat->count
                );

                $results[] = array(
                    'id'   => $cat->term_id,
                    'text' => $label . ' (' . $count_text . ')',
                );
            }
        }

        wp_send_json(array('results' => $results));
    }

    /**
     * AJAX: Search WooCommerce products for Select2.
     * Returns initial list (term empty) or filtered results (term provided).
     * Replaces the default woocommerce_json_search_products_and_variations
     * to support initial list loading (minimumInputLength: 0).
     */
    public function ajax_search_products() {
        check_ajax_referer('securehold_search_products', 'security');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json(array());
        }

        // phpcs:ignore WordPress.Security.NonceVerification -- nonce verified above
        $term  = isset($_REQUEST['term']) ? sanitize_text_field(wp_unslash($_REQUEST['term'])) : '';
        $limit = 50;

        $args = array(
            'post_type'      => array('product', 'product_variation'),
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        );

        if (!empty($term)) {
            // Check if term is numeric (search by ID)
            if (is_numeric($term)) {
                $args['post__in'] = array(absint($term));
                $args['orderby']  = 'post__in';
            } else {
                $args['s'] = $term;
                $args['orderby'] = 'relevance';
            }
        }

        $product_ids = get_posts($args);

        // If numeric search returned nothing, fall back to text search
        if (is_numeric($term) && empty($product_ids)) {
            unset($args['post__in']);
            $args['s'] = $term;
            $args['orderby'] = 'relevance';
            $product_ids = get_posts($args);
        }

        $results = array();

        foreach ($product_ids as $pid) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }

            $text = $product->get_formatted_name();
            if (!$text) {
                $text = $product->get_name() . ' (#' . $pid . ')';
            }

            $results[] = array(
                'id'   => $pid,
                'text' => $text,
            );
        }

        wp_send_json(array('results' => $results));
    }

    /**
     * AJAX: Create hold manually from Deposits table
     *
     * IMPORTANT: We never delete the existing deposit row before confirming success.
     * On Stripe failure, the row is preserved and updated to 'failed' status.
     */
    public function ajax_create_hold_manual() {
        // Nonce verification
        check_ajax_referer('securehold_product_settings', 'nonce');

        // Capability check
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permission denied', 'securehold-security-deposit-holds')]);
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $deposit_id = isset($_POST['deposit_id']) ? intval($_POST['deposit_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('Invalid order ID', 'securehold-security-deposit-holds')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Order not found', 'securehold-security-deposit-holds')]);
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';

        // DO NOT delete the pending_manual entry yet — wait for Stripe result first

        // Force create the hold
        if (!class_exists('Securehold_Scheduler')) {
            wp_send_json_error(['message' => __('Scheduler class not found', 'securehold-security-deposit-holds')]);
        }

        $scheduler = new Securehold_Scheduler();
        $result = $scheduler->create_hold_for_order($order_id, true); // Force execution

        // ── Handle result ──
        // WP_Error is truthy in PHP, so we must check is_wp_error() explicitly
        if (is_wp_error($result)) {
            $error_message = $result->get_error_message();

            // Update the existing row to 'failed' status (preserve it!)
            if ($deposit_id) {
                SecureHold_DB::update_hold($deposit_id, array(
                    'status' => 'failed',
                    'notes'  => sprintf(
                        /* translators: %s is the error message */
                        __('Create Hold failed: %s', 'securehold-security-deposit-holds'),
                        $error_message
                    ),
                ));
            } else {
                // No existing row — insert a failed entry so it remains visible
                SecureHold_DB::insert_deposit(array(
                    'order_id'          => $order_id,
                    'customer_id'       => $order->get_meta('_stripe_customer_id', true) ?: 'N/A',
                    'intent_id'         => 'failed_manual_' . $order_id . '_' . time(),
                    'payment_method_id' => $order->get_meta('_stripe_payment_method', true) ?: 'N/A',
                    'amount'            => 0,
                    'captured_amount'   => 0,
                    'currency'          => $order->get_currency(),
                    'status'            => 'failed',
                    'notes'             => sprintf('Create Hold failed: %s', $error_message),
                    'created_at'        => current_time('mysql'),
                ));
            }

            // Add order note with error details
            $order->add_order_note(sprintf(
                /* translators: %s is the error message */
                __('[SecureHold] Hold creation failed: %s', 'securehold-security-deposit-holds'),
                $error_message
            ));

            if (function_exists('securehold_log')) {
                securehold_log('Manual Create Hold failed', array(
                    'order_id'   => $order_id,
                    'deposit_id' => $deposit_id,
                    'error'      => $error_message,
                ));
            }

            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s is the error message */
                    __('Hold creation failed: %s', 'securehold-security-deposit-holds'),
                    $error_message
                ),
            ));
            return;
        }

        if ($result === false) {
            // Scheduler returned false (non-Stripe failure, e.g. missing data)
            if ($deposit_id) {
                SecureHold_DB::update_hold($deposit_id, array(
                    'status' => 'failed',
                    'notes'  => __('Create Hold failed: scheduler returned false. Check system logs.', 'securehold-security-deposit-holds'),
                ));
            }

            $order->add_order_note(
                __('[SecureHold] Hold creation failed: scheduler returned false. Check system logs.', 'securehold-security-deposit-holds')
            );

            wp_send_json_error(array(
                'message' => __('Failed to create hold. Check order notes and system logs for details.', 'securehold-security-deposit-holds'),
            ));
            return;
        }

        // ── Success: Stripe hold was created ──
        // Now safe to delete the old pending_manual entry (the scheduler already inserted a new 'authorized' row)
        if ($deposit_id) {
            // Check if a new authorized row exists for this order (created by scheduler)
            $new_deposit = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE order_id = %d AND status = 'authorized' ORDER BY id DESC LIMIT 1",
                $order_id
            ));

            if ($new_deposit && (int) $new_deposit->id !== $deposit_id) {
                // New authorized row exists and is different from the old pending row — safe to delete old one
                $wpdb->delete($table_name, ['id' => $deposit_id], ['%d']);
            }
        }

        // Look up the final deposit for the response
        $final_deposit = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $order_id
        ));

        wp_send_json_success(array(
            'message'    => __('Hold created successfully', 'securehold-security-deposit-holds'),
            'order_id'   => $order_id,
            'deposit_id' => $final_deposit ? (int) $final_deposit->id : 0,
        ));
    }

    public function add_order_metabox() {
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') 
            && wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop_order')
            : 'shop_order';

        add_meta_box(
            'securehold_order_data',
            __('SecureHold WP Security Deposit', 'securehold-security-deposit-holds'),
            array($this, 'render_order_metabox'),
            $screen,
            'side',
            'high'
        );
    }

    public function render_order_metabox($post_or_order_object) {
        $order = ($post_or_order_object instanceof WP_Post) ? wc_get_order($post_or_order_object->ID) : $post_or_order_object;
        if (!$order) return;
        $order_id = $order->get_id();

        if (!class_exists('SecureHold_DB')) {
            if (defined('SECUREHOLD_PLUGIN_DIR')) require_once SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php';
        }
        
        if (!class_exists('SecureHold_DB')) {
            echo '<p>' . esc_html__('Error: DB Class not loaded.', 'securehold-security-deposit-holds') . '</p>';
            return;
        }

        $deposit = SecureHold_DB::get_deposit($order_id);

        // Show "Create Hold Now" button if: no deposit, pending_manual, or failed (retry)
        $should_show_create_button = (!$deposit) || ($deposit && in_array($deposit->status, array('pending_manual', 'failed'), true));
        
        if ($should_show_create_button) {
            // Check if order is eligible for manual hold creation
            $payment_method = $order->get_payment_method();
            $automation_mode = get_option('securehold_capture_timing', 'immediate');
            
            if (strpos($payment_method, 'stripe') !== false && $automation_mode === 'manual') {
                // Show "Create Hold Now" button for manual mode
                ?>
                <div class="securehold-metabox-manual" style="padding: 15px 0;">
                    <?php if ($deposit && $deposit->status === 'failed') : ?>
                        <div style="background:#fef2f2; border-left:4px solid #dc2626; padding:12px; margin-bottom:12px;">
                            <p style="margin:0; color:#991b1b; font-size:13px;">
                                <span class="dashicons dashicons-warning" style="vertical-align:middle;"></span>
                                <strong><?php esc_html_e('Previous Attempt Failed', 'securehold-security-deposit-holds'); ?></strong>
                            </p>
                            <?php if (!empty($deposit->notes)) : ?>
                            <p style="margin:6px 0 0 0; color:#7f1d1d; font-size:12px;">
                                <?php echo esc_html($deposit->notes); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($deposit && $deposit->status === 'pending_manual') : ?>
                        <div style="background:#fef3c7; border-left:4px solid #f59e0b; padding:12px; margin-bottom:12px;">
                            <p style="margin:0; color:#92400e; font-size:13px;">
                                <span class="dashicons dashicons-info" style="vertical-align:middle;"></span>
                                <strong><?php esc_html_e('Awaiting Manual Action', 'securehold-security-deposit-holds'); ?></strong>
                            </p>
                        </div>
                    <?php else : ?>
                        <p style="color:#777; margin-top:0; margin-bottom:12px;">
                            <?php esc_html_e('No security deposit created yet.', 'securehold-security-deposit-holds'); ?>
                        </p>
                    <?php endif; ?>
                    
                    <form method="post" style="margin:0;">
                        <?php wp_nonce_field('securehold_metabox_action', 'securehold_metabox_nonce'); ?>
                        <button type="submit" class="button button-primary" name="securehold_create_now" value="1" style="width:100%; display:inline-flex; align-items:center; justify-content:center; gap:6px; text-align:center; font-size:14px; padding:8px 12px; height:auto;">
                            <span class="dashicons dashicons-shield" style="display:block; flex:0 0 20px; width:20px; height:20px; line-height:20px;"></span>
                            <?php echo ($deposit && $deposit->status === 'failed')
                                ? esc_html__('Retry Create Hold', 'securehold-security-deposit-holds')
                                : esc_html__('Create Hold Now', 'securehold-security-deposit-holds'); ?>
                        </button>
                    </form>
                    <p style="margin-top:10px; font-size:11px; color:#999; text-align:center;">
                        <?php esc_html_e('Manual mode: Click to create security deposit', 'securehold-security-deposit-holds'); ?>
                    </p>
                </div>
                <?php
                return;
            } else {
                echo '<p style="color:#777; margin-top:0;">' . esc_html__('No SecureHold WP deposit found for this order.', 'securehold-security-deposit-holds') . '</p>';
                return;
            }
        }
        
        // If we reach here, deposit exists and is not pending_manual
        if (!$deposit) {
            echo '<p style="color:#777; margin-top:0;">' . esc_html__('No SecureHold WP deposit found for this order.', 'securehold-security-deposit-holds') . '</p>';
            return;
        }

        $currency_symbol = get_woocommerce_currency_symbol($deposit->currency);
        $metabox_currency = ! empty( $deposit->currency ) ? strtoupper( $deposit->currency ) : 'USD';
        $wc_price_args = array( 'currency' => $metabox_currency );

        ?>
        <div class="securehold-order-box">
            <div style="margin-bottom: 12px; display:flex; justify-content:space-between; align-items:center;">
                <strong><?php esc_html_e('Status:', 'securehold-security-deposit-holds'); ?></strong>
                <?php self::render_status_badge($deposit->status, true, $deposit->order_id, isset($deposit->notes) ? $deposit->notes : ''); ?>
            </div>

            <div style="margin-bottom: 8px;">
                <strong><?php esc_html_e('Amount:', 'securehold-security-deposit-holds'); ?></strong>
                <span><?php echo wp_kses_post( wc_price( $deposit->amount, $wc_price_args ) ); ?></span>
            </div>

            <?php if ($deposit->captured_amount > 0): ?>
            <div style="margin-bottom: 8px; color: #d63638;">
                <strong><?php esc_html_e('Captured:', 'securehold-security-deposit-holds'); ?></strong>
                <span><?php echo wp_kses_post( wc_price( $deposit->captured_amount, $wc_price_args ) ); ?></span>
            </div>
            <?php endif; ?>

            <div style="margin-bottom: 15px; font-size: 11px; color: #999;">
                <strong><?php esc_html_e('ID:', 'securehold-security-deposit-holds'); ?></strong> <?php echo esc_html($deposit->intent_id); ?>
            </div>

            <?php if (!empty($deposit->expires_at)) : ?>
            <div style="margin-bottom: 12px; font-size: 12px; color: #6b7280;">
                <strong><?php esc_html_e('Expires:', 'securehold-security-deposit-holds'); ?></strong>
                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($deposit->expires_at))); ?>
            </div>
            <?php endif; ?>

            <?php if ($deposit->status === 'authorized') : ?>
            <div style="border-top: 1px solid #eee; padding-top: 10px; margin-bottom: 10px; display:flex; gap:6px;">
                <button type="button" class="button button-small sh-btn-capture"
                        data-id="<?php echo esc_attr($deposit->id); ?>"
                        data-amount="<?php echo esc_attr($deposit->amount); ?>"
                        data-captured="<?php echo esc_attr($deposit->captured_amount); ?>"
                        data-currency="<?php echo esc_attr($deposit->currency); ?>"
                        style="flex:1; background:#d97706; border-color:#d97706; color:white; text-align:center;">
                    <?php esc_html_e('Capture', 'securehold-security-deposit-holds'); ?>
                </button>
                <button type="button" class="button button-small sh-btn-release"
                        data-id="<?php echo esc_attr($deposit->id); ?>"
                        data-order-id="<?php echo esc_attr($deposit->order_id); ?>"
                        data-amount="<?php echo esc_attr( wp_strip_all_tags( wc_price( $deposit->amount, $wc_price_args ) ) ); ?>"
                        style="flex:1; background:#dc2626; border-color:#dc2626; color:white; text-align:center;">
                    <?php esc_html_e('Release', 'securehold-security-deposit-holds'); ?>
                </button>
            </div>
            <?php endif; ?>

            <div style="text-align: center; border-top: 1px solid #eee; padding-top: 10px;">
                <a href="<?php echo esc_url( admin_url('admin.php?page=securehold-deposits') ); ?>" class="button" style="width:100%; text-align:center;">
                    <?php esc_html_e('View All Deposits', 'securehold-security-deposit-holds'); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render the shared "Capture Security Deposit" modal markup.
     *
     * The .sh-btn-capture click handler (assets/js/admin.js) targets
     * #sh-capture-modal-overlay by ID. That markup used to live only in
     * admin/views/deposits-page.php, so the Capture button rendered by
     * render_order_metabox() on the order edit screen had no modal to open —
     * clicking it silently did nothing. Extracted here so both screens can
     * render the same modal once.
     *
     * @since 3.4.3
     */
    public static function render_capture_modal() {
        ?>
        <div id="sh-capture-modal-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; justify-content:center; align-items:center;">
            <div style="background:white; padding:2rem; border-radius:8px; width:400px; max-width:90%; box-shadow:0 10px 25px rgba(0,0,0,0.2);">
                <h2 style="margin-top:0; color:#d97706;"><?php esc_html_e( 'Capture Security Deposit', 'securehold-security-deposit-holds' ); ?></h2>

                <form id="sh-capture-form">
                    <input type="hidden" id="sh-capture-id" name="deposit_id">

                    <div style="margin-bottom:1rem; background:#f9fafb; padding:10px; border-radius:4px;">
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                            <span><?php esc_html_e( 'Total Authorized:', 'securehold-security-deposit-holds' ); ?></span>
                            <strong id="sh-capture-total-display"></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:5px;">
                            <span><?php esc_html_e( 'Already Captured:', 'securehold-security-deposit-holds' ); ?></span>
                            <strong id="sh-capture-captured-display"></strong>
                        </div>
                        <div style="display:flex; justify-content:space-between; color:#d97706;">
                            <span><?php esc_html_e( 'Remaining:', 'securehold-security-deposit-holds' ); ?></span>
                            <strong id="sh-capture-remaining-display"></strong>
                        </div>
                    </div>

                    <div style="margin-bottom:1.5rem;">
                        <label for="sh-capture-amount" style="display:block; font-weight:600; margin-bottom:0.5rem; color:#111;"><?php esc_html_e( 'Amount to Capture', 'securehold-security-deposit-holds' ); ?></label>
                        <div style="display:flex; align-items:center;">
                            <span id="sh-capture-currency-symbol" style="background:#eee; padding:0.5rem 1rem; border:1px solid #ddd; border-right:none; border-radius:4px 0 0 4px; line-height: 1.5;">$</span>
                            <input type="number" id="sh-capture-amount" step="0.01" min="0.50" required style="width:100%; border-radius:0 4px 4px 0;">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; gap:10px; border-top:1px solid #eee; padding-top:1rem;">
                        <button type="button" class="button" id="sh-capture-cancel"><?php esc_html_e( 'Cancel', 'securehold-security-deposit-holds' ); ?></button>
                        <button type="submit" class="button button-primary" id="sh-capture-submit" style="background:#d97706; border-color:#d97706;">
                            <span class="dashicons dashicons-money" style="vertical-align:middle; margin-right:4px;"></span>
                            <?php esc_html_e( 'Capture Now', 'securehold-security-deposit-holds' ); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Print the shared capture modal in the footer of order-edit screens.
     *
     * The deposits list page (admin/views/deposits-page.php) already prints
     * its own copy inline, so this is scoped to order screens only —
     * reuses the exact screen check from ensure_metabox_visible() to avoid
     * printing the modal twice on any screen.
     *
     * @since 3.4.3
     */
    public function render_capture_modal_for_order_screen() {
        $screen = get_current_screen();
        if ( ! $screen ) {
            return;
        }

        $is_order_screen = (
            $screen->id === 'shop_order' ||
            $screen->id === 'woocommerce_page_wc-orders' ||
            $screen->id === 'post-shop_order'
        );

        if ( ! $is_order_screen ) {
            return;
        }

        self::render_capture_modal();
    }

    /**
     * Handle metabox actions (Create Hold Now button)
     */
    public function handle_order_metabox_actions($order_id) {
        // Security check
        if (!isset($_POST['securehold_metabox_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['securehold_metabox_nonce'])), 'securehold_metabox_action')) {
            return;
        }

        // Capability check
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Handle "Create Hold Now" button
        if (isset($_POST['securehold_create_now']) && $_POST['securehold_create_now'] === '1') {

            // ── One submission, one interpretation ──
            // This handler is registered on both save_post_shop_order and
            // woocommerce_process_shop_order_meta, and on legacy (non-HPOS)
            // orders WordPress fires both within a single save request. The
            // scheduler already refuses the duplicate creation, but it reports
            // that refusal as "nothing to do" (true) — which this handler would
            // read as success and use to overwrite the genuine error notice with
            // "Security deposit created successfully". Stopping here keeps the
            // first run's outcome, its deposit row, its order note and its
            // admin notice intact.
            static $handled = array();
            $order_id = (int) $order_id;

            if ( isset( $handled[ $order_id ] ) ) {
                return;
            }
            $handled[ $order_id ] = true;

            $order = wc_get_order($order_id);
            if (!$order) return;
            
            // Check if it's a Stripe payment
            $payment_method = $order->get_payment_method();
            if (strpos($payment_method, 'stripe') === false) {
                return;
            }
            
            // Get existing entry — could be pending_manual or failed (retry)
            // DO NOT delete it yet — wait for Stripe result first
            $existing = null;
            $existing_id = 0;
            if (class_exists('SecureHold_DB')) {
                $existing = SecureHold_DB::get_deposit($order_id);
                if ($existing && in_array($existing->status, array('pending_manual', 'failed'), true)) {
                    $existing_id = (int) $existing->id;
                }
            }

            // Force execution of hold creation
            if (class_exists('Securehold_Scheduler')) {
                $scheduler = new Securehold_Scheduler();
                $result = $scheduler->create_hold_for_order($order_id, true); // Force = true bypasses strategy checks

                // WP_Error is truthy in PHP — must check explicitly
                if (is_wp_error($result)) {
                    $error_message = $result->get_error_message();

                    // Preserve row as 'failed' instead of deleting
                    if ($existing_id) {
                        SecureHold_DB::update_hold($existing_id, array(
                            'status' => 'failed',
                            'notes'  => sprintf(
                                /* translators: %s is the error message */
                                __('Create Hold failed (metabox): %s', 'securehold-security-deposit-holds'),
                                $error_message
                            ),
                        ));
                    } else {
                        // No existing row — insert a failed entry
                        SecureHold_DB::insert_deposit(array(
                            'order_id'          => $order_id,
                            'customer_id'       => $order->get_meta('_stripe_customer_id', true) ?: 'N/A',
                            'intent_id'         => 'failed_metabox_' . $order_id . '_' . time(),
                            'payment_method_id' => $order->get_meta('_stripe_payment_method', true) ?: 'N/A',
                            'amount'            => 0,
                            'captured_amount'   => 0,
                            'currency'          => $order->get_currency(),
                            'status'            => 'failed',
                            'notes'             => sprintf('Create Hold failed (metabox): %s', $error_message),
                            'created_at'        => current_time('mysql'),
                        ));
                    }

                    // Add order note
                    $order->add_order_note(sprintf(
                        /* translators: %s is the error message */
                        __('[SecureHold] Hold creation failed: %s', 'securehold-security-deposit-holds'),
                        $error_message
                    ));

                    if (function_exists('securehold_log')) {
                        securehold_log('Metabox Create Hold failed', array(
                            'order_id'   => $order_id,
                            'deposit_id' => $existing_id,
                            'error'      => $error_message,
                        ));
                    }

                    set_transient('securehold_admin_notice_' . get_current_user_id(), [
                        'type' => 'error',
                        'message' => sprintf(
                            /* translators: %s is the error message */
                            __('Security deposit creation failed: %s', 'securehold-security-deposit-holds'),
                            $error_message
                        )
                    ], 45);

                } elseif ($result === false) {
                    // Scheduler returned false (missing data, not a Stripe error)
                    if ($existing_id) {
                        SecureHold_DB::update_hold($existing_id, array(
                            'status' => 'failed',
                            'notes'  => __('Create Hold failed (metabox): scheduler returned false. Check system logs.', 'securehold-security-deposit-holds'),
                        ));
                    }

                    set_transient('securehold_admin_notice_' . get_current_user_id(), [
                        'type' => 'error',
                        'message' => __('Failed to create security deposit. Check order notes and system logs.', 'securehold-security-deposit-holds')
                    ], 45);

                } else {
                    // Success — NOW safe to delete old pending_manual row
                    if ($existing_id) {
                        global $wpdb;
                        $table_name = $wpdb->prefix . 'securehold_holds';
                        // Only delete if a new authorized row exists
                        $new_row = $wpdb->get_var($wpdb->prepare(
                            "SELECT id FROM {$table_name} WHERE order_id = %d AND status = 'authorized' AND id != %d ORDER BY id DESC LIMIT 1",
                            $order_id,
                            $existing_id
                        ));
                        if ($new_row) {
                            $wpdb->delete($table_name, ['id' => $existing_id], ['%d']);
                            if (function_exists('securehold_log')) {
                                securehold_log('Deleted old pending_manual entry after successful hold', [
                                    'order_id'       => $order_id,
                                    'old_deposit_id' => $existing_id,
                                    'new_deposit_id' => $new_row,
                                ]);
                            }
                        }
                    }

                    set_transient('securehold_admin_notice_' . get_current_user_id(), [
                        'type' => 'success',
                        'message' => __('Security deposit created successfully.', 'securehold-security-deposit-holds')
                    ], 45);
                }
            }
        }
    }

    public function handle_capture_hold() {
        check_ajax_referer('securehold_capture_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'securehold-security-deposit-holds')]);
        }

        $deposit_id = isset($_POST['deposit_id']) ? intval($_POST['deposit_id']) : 0;
        $amount_capture_float = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;

        // The status check below reads, then Stripe is called, then the row is
        // written. Two simultaneous requests both cleared that check on staging
        // and both reached Stripe; only Stripe's own refusal stopped a second
        // charge. The same atomic lock the creation path uses closes that
        // window here, before any money can move.
        $terminal_token = null;

        if ($deposit_id <= 0 || $amount_capture_float <= 0) {
            wp_send_json_error(['message' => __('Invalid data provided.', 'securehold-security-deposit-holds')]);
        }

        if (!class_exists('SecureHold_DB')) { if (defined('SECUREHOLD_PLUGIN_DIR')) require_once SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php'; }
        if (!class_exists('SecureHold_Stripe')) { if (defined('SECUREHOLD_PLUGIN_DIR')) require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe.php'; }

        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';
        $deposit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $deposit_id));

        if (!$deposit) {
            wp_send_json_error(['message' => __('Deposit not found in database.', 'securehold-security-deposit-holds')]);
        }
        
        if (empty($deposit->intent_id)) {
            securehold_log('Manual Capture Failed: Missing Intent ID', ['deposit_id' => $deposit_id], 'error');
            wp_send_json_error(['message' => __('Error: No Stripe PaymentIntent ID found for this deposit.', 'securehold-security-deposit-holds')]);
        }

        if ( class_exists( 'Securehold_Scheduler' ) ) {
            $terminal_token = Securehold_Scheduler::acquire_terminal_lock( $deposit->order_id );

            if ( $terminal_token === false ) {
                // A collision, not a failure: another request is mid-flight on
                // this same deposit. Debug, because nothing is broken.
                securehold_log( 'Terminal operation already in progress', array(
                    'deposit_id' => $deposit_id,
                    'operation'  => 'capture',
                ), 'debug' );
                wp_send_json_error( array( 'message' => __( 'Another capture or release is already running for this deposit.', 'securehold-security-deposit-holds' ) ) );
            }

            // wp_send_json_* ends the request, so there is no point at which a
            // finally block would run. Shutdown is the only hook that fires on
            // every exit path, and without it a refused capture would hold the
            // lock for its full TTL and block the merchant's next attempt.
            $sh_order_id = $deposit->order_id;
            register_shutdown_function( function () use ( $sh_order_id, $terminal_token ) {
                Securehold_Scheduler::release_terminal_lock( $sh_order_id, $terminal_token );
            } );

            // Re-read now that we hold the lock: the request that just finished
            // may have captured this deposit a moment ago.
            $deposit = SecureHold_DB::get_deposit( $deposit->order_id ) ?: $deposit;
        }

        // ── Server-side guard: block any second capture ──
        // After ANY capture (full or partial) Stripe automatically releases the
        // remaining authorization, so a second capture is impossible.
        $already_captured = floatval($deposit->captured_amount);
        $has_been_captured = ( $deposit->status === 'captured' || $already_captured > 0 || ! empty( $deposit->captured_at ) );
        if ( $has_been_captured ) {
            securehold_log('Second capture blocked', ['deposit_id' => $deposit_id, 'status' => $deposit->status, 'captured_amount' => $already_captured]);
            wp_send_json_error(['message' => __('This deposit has already been captured. Stripe releases the remaining authorization after any capture (including partial). A second capture is not possible.', 'securehold-security-deposit-holds')]);
        }

        if ( $deposit->status !== 'authorized' ) {
            /* translators: %s is the deposit status */
            wp_send_json_error(['message' => sprintf(__('Cannot capture a deposit with status "%s". Only authorized deposits can be captured.', 'securehold-security-deposit-holds'), $deposit->status)]);
        }

        $authorized_amount = floatval($deposit->amount);

        if ($amount_capture_float > ($authorized_amount + 0.01)) {
            wp_send_json_error(['message' => __('Capture amount cannot exceed authorized amount.', 'securehold-security-deposit-holds')]);
        }

        $amount_cents = (int) round($amount_capture_float * 100);
        if ($amount_cents <= 0) {
             wp_send_json_error(['message' => __('Amount too small.', 'securehold-security-deposit-holds')]);
        }

        $result = SecureHold_Stripe::capture_payment_intent($deposit->intent_id, $amount_cents);

        if (is_wp_error($result)) {
            // A refusal from Stripe is a real failure, not a note in passing.
            securehold_log('Manual Capture Failed', ['order_id' => $deposit->order_id, 'error' => $result->get_error_message()], 'error');
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $final_captured_cents = $result->amount_received;
        $final_captured_float = $final_captured_cents / 100;

                // One place decides the state, writes the table and mirrors the order
        // meta. The meta used to be left behind entirely here, which is why a
        // captured deposit still read as authorized on the order screen.
        // No occurred_at here on purpose. The captured PaymentIntent carries no
        // capture timestamp: ->created is when the intent was opened, which for a
        // deposit is the authorization, days earlier. Passing it wrote a
        // captured_at that predated the capture by the whole hold period. The
        // capture moment does exist on Stripe, on the charge's balance
        // transaction, but reading it costs another API call for a value the
        // local clock already approximates within a second. So transition()
        // falls back to current_time( 'mysql' ), taken just after Stripe
        // confirmed: slightly less precise than Stripe's own moment, and
        // actually the moment the money moved.
        $sh_state = Securehold_Hold_State::transition( $deposit, 'captured', array(
            'captured_amount' => $final_captured_float,
            'source'          => 'admin',
        ) );

        // Side effects belong to the caller, and only when the state actually
        // moved. A transition that was already applied elsewhere must not add a
        // second note or send the customer a second email.
        $order = $sh_state['applied'] ? wc_get_order($deposit->order_id) : false;
        if ($order) {
            $formatted_amount = wc_price($amount_capture_float, array('currency' => $deposit->currency));
            $note = sprintf(
                /* translators: %1$s is the formatted currency amount, %2$s is the Stripe reference ID */
                __('SecureHold WP: Security deposit captured manually: %1$s. Reference: %2$s', 'securehold-security-deposit-holds'),
                $formatted_amount,
                $result->id
            );
            $order->add_order_note($note);
        }

        // Fire captured email notifications via centralized manager.
        $this->ensure_email_manager_loaded();
        if ( $sh_state['applied'] && class_exists( 'Securehold_Email_Manager' ) ) {
            $capture_payload = (object) array(
                'amount'          => floatval( $deposit->amount ),
                'captured_amount' => $final_captured_float,
                'currency'        => $deposit->currency,
            );
            Securehold_Email_Manager::fire_email( 'securehold_deposit_captured', $deposit->order_id, $capture_payload );
            Securehold_Email_Manager::fire_email( 'securehold_admin_hold_captured', $deposit->order_id, $capture_payload );
        }
        securehold_log('Manual Capture Success', ['order_id' => $deposit->order_id, 'intent' => $deposit->intent_id, 'amount' => $amount_capture_float]);
        wp_send_json_success(['message' => __('Capture successful!', 'securehold-security-deposit-holds')]);
    }

    /**
     * AJAX: Release (cancel) a hold on Stripe
     */
    public function handle_release_hold() {
        check_ajax_referer('securehold_release_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied.', 'securehold-security-deposit-holds')]);
        }

        $deposit_id = isset($_POST['deposit_id']) ? intval($_POST['deposit_id']) : 0;
        if ($deposit_id <= 0) {
            wp_send_json_error(['message' => __('Invalid deposit ID.', 'securehold-security-deposit-holds')]);
        }

        if (!class_exists('SecureHold_DB')) {
            if (defined('SECUREHOLD_PLUGIN_DIR')) require_once SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php';
        }
        if (!class_exists('SecureHold_Stripe')) {
            if (defined('SECUREHOLD_PLUGIN_DIR')) require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe.php';
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'securehold_holds';
        $deposit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $deposit_id));

        if (!$deposit) {
            wp_send_json_error(['message' => __('Deposit not found.', 'securehold-security-deposit-holds')]);
        }

        if ($deposit->status !== 'authorized') {
            /* translators: %s is the deposit status */
            wp_send_json_error(['message' => sprintf(__('Cannot release a deposit with status "%s". Only authorized deposits can be released.', 'securehold-security-deposit-holds'), $deposit->status)]);
        }

        if (empty($deposit->intent_id)) {
            wp_send_json_error(['message' => __('No Stripe PaymentIntent ID found for this deposit.', 'securehold-security-deposit-holds')]);
        }

        // Same lock as capture, deliberately: a capture and a release fired at
        // the same moment on one deposit must contend, not both reach Stripe.
        $terminal_token = null;
        if ( class_exists( 'Securehold_Scheduler' ) ) {
            $terminal_token = Securehold_Scheduler::acquire_terminal_lock( $deposit->order_id );

            if ( $terminal_token === false ) {
                securehold_log( 'Terminal operation already in progress', array(
                    'deposit_id' => $deposit_id,
                    'operation'  => 'release',
                ), 'debug' );
                wp_send_json_error( array( 'message' => __( 'Another capture or release is already running for this deposit.', 'securehold-security-deposit-holds' ) ) );
            }

            $sh_order_id = $deposit->order_id;
            register_shutdown_function( function () use ( $sh_order_id, $terminal_token ) {
                Securehold_Scheduler::release_terminal_lock( $sh_order_id, $terminal_token );
            } );

            $deposit = SecureHold_DB::get_deposit( $deposit->order_id ) ?: $deposit;

            if ( in_array( $deposit->status, array( 'captured', 'released' ), true ) ) {
                securehold_log( 'Skipped (already ' . $deposit->status . ')', array(
                    'deposit_id' => $deposit_id,
                    'operation'  => 'release',
                ), 'debug' );
                wp_send_json_error( array( 'message' => __( 'This deposit is no longer authorized.', 'securehold-security-deposit-holds' ) ) );
            }
        }

        // Cancel on Stripe
        $result = SecureHold_Stripe::cancel_payment_intent($deposit->intent_id);

        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();

            // If already canceled on Stripe, update our DB anyway
            if ($error_code === 'already_canceled') {
                $sh_state = Securehold_Hold_State::transition( $deposit, 'released', array(
                    // Stripe's cancellation moment when it gives one, so a
                    // delayed round trip cannot become the business time.
                    'occurred_at' => isset( $result->canceled_at ) ? (int) $result->canceled_at : null,
                    'source'      => 'admin',
                ) );

                wp_send_json_success(['message' => __('Hold released (was already canceled on Stripe).', 'securehold-security-deposit-holds')]);
                return;
            }

            securehold_log('Manual Release Failed', ['deposit_id' => $deposit_id, 'error' => $result->get_error_message()], 'error');
            wp_send_json_error(['message' => $result->get_error_message()]);
            return;
        }

        // Update DB
        $sh_state = Securehold_Hold_State::transition( $deposit, 'released', array(
                    // Stripe's cancellation moment when it gives one, so a
                    // delayed round trip cannot become the business time.
                    'occurred_at' => isset( $result->canceled_at ) ? (int) $result->canceled_at : null,
                    'source'      => 'admin',
                ) );

        // Order note
        // Only when this request is the one that moved the state.
        $order = $sh_state['applied'] ? wc_get_order($deposit->order_id) : false;
        if ($order) {
            $formatted_amount = wc_price($deposit->amount, array('currency' => $deposit->currency));
            $order->add_order_note(sprintf(
                /* translators: %s is the formatted currency amount */
                __('SecureHold WP: Security deposit of %s released manually via admin.', 'securehold-security-deposit-holds'),
                wp_strip_all_tags($formatted_amount)
            ));
        }

        // ── Email notifications (via centralized manager) ────────────
        $this->ensure_email_manager_loaded();
        $release_payload = (object) array(
            'amount'   => floatval( $deposit->amount ),
            'currency' => $deposit->currency,
        );

        if ( $sh_state['applied'] && class_exists( 'Securehold_Email_Manager' ) ) {
            Securehold_Email_Manager::fire_email( 'securehold_deposit_released', $deposit->order_id, $release_payload );
            Securehold_Email_Manager::fire_email( 'securehold_admin_hold_released', $deposit->order_id, $release_payload );
        }

        securehold_log('Manual Release Success', ['order_id' => $deposit->order_id, 'intent' => $deposit->intent_id]);
        wp_send_json_success(['message' => __('Hold released successfully!', 'securehold-security-deposit-holds')]);
    }

    /**
     * Ensure the email manager and all email class files are loaded.
     *
     * In AJAX requests the normal plugin bootstrap may skip the email file
     * includes (they are loaded on plugins_loaded via the main plugin file,
     * but the WP AJAX handler can reach this class before that path fires).
     * This method is a safe, idempotent guard: it returns immediately when
     * Securehold_Email_Manager is already defined.
     *
     * Called by handle_capture_hold() and handle_release_hold() before any
     * fire_email() call.
     */
    private function ensure_email_manager_loaded() {
        // CORE FIX: initialize WC mailer to trigger woocommerce_email_classes filter.
        // In AJAX context WC()->mailer() is never called automatically.
        // Without it: woocommerce_email_classes never fires, add_email_classes() never
        // runs, SecureHold email instances are never registered, get_wc_email() returns null.
        if ( function_exists( 'WC' ) && is_callable( array( WC(), 'mailer' ) ) ) {
            WC()->mailer();
        }

        // Fallback: if Securehold_Email_Manager is still not defined after mailer init.
        if ( ! class_exists( 'Securehold_Email_Manager' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/emails/class-securehold-email-manager.php';
        }
    }

    /**
     * AJAX: Diagnose a failed deposit.
     *
     * DATA-DRIVEN ONLY — this method NEVER invents or guesses a cause.
     * It reads real data from 3 sources, in priority order:
     *   1. deposit.notes  (written at the exact failure point)
     *   2. securehold_logs  (chronological trace via indexed order_id)
     *   3. deposit record (intent_id pattern, missing IDs)
     * Then build_failure_cascade() identifies root cause + secondary effects.
     */
    public function handle_diagnose_deposit() {
        check_ajax_referer('securehold_diagnose_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'securehold-security-deposit-holds')));
        }

        $deposit_id = isset($_POST['deposit_id']) ? intval($_POST['deposit_id']) : 0;
        if ($deposit_id <= 0) {
            wp_send_json_error(array('message' => __('Invalid deposit ID.', 'securehold-security-deposit-holds')));
        }

        global $wpdb;
        $table_holds = $wpdb->prefix . 'securehold_holds';
        $deposit = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_holds WHERE id = %d", $deposit_id));

        if (!$deposit) {
            wp_send_json_error(array('message' => __('Deposit not found.', 'securehold-security-deposit-holds')));
        }

        $order_id = intval($deposit->order_id);
        $order    = wc_get_order($order_id);

        // ========================================
        // 1. COLLECT RAW FACTS (no interpretation)
        // ========================================
        $facts = array();
        $facts['deposit_id']        = intval($deposit->id);
        $facts['order_id']          = $order_id;
        $facts['order_exists']      = !empty($order);
        $facts['order_url']         = admin_url('post.php?post=' . $order_id . '&action=edit');
        $facts['order_status']      = $order ? $order->get_status() : null;
        $facts['wc_payment_method'] = $order ? $order->get_payment_method() : null;
        $facts['created_at']        = $deposit->created_at;
        $facts['deposit_notes']     = !empty($deposit->notes) ? $deposit->notes : null;
        $facts['intent_id']         = !empty($deposit->intent_id) ? $deposit->intent_id : null;
        $facts['customer_id']       = (!empty($deposit->customer_id) && $deposit->customer_id !== 'N/A') ? $deposit->customer_id : null;
        $facts['payment_method_id'] = (!empty($deposit->payment_method_id) && $deposit->payment_method_id !== 'N/A') ? $deposit->payment_method_id : null;
        $facts['amount']            = floatval($deposit->amount);
        $facts['currency']          = $deposit->currency;

        // Stripe config booleans (NEVER expose actual keys)
        $keys = function_exists('securehold_get_woocommerce_stripe_keys') ? securehold_get_woocommerce_stripe_keys() : array();
        $facts['stripe_mode']         = !empty($keys['testmode']) ? 'test' : 'live';
        $facts['has_secret_key']      = !empty($keys['secret']);
        $facts['has_publishable_key'] = !empty($keys['publishable']);

        // Order meta Stripe IDs (fallback)
        if ($order) {
            if (empty($facts['customer_id'])) {
                $meta_cus = $order->get_meta('_stripe_customer_id', true);
                $facts['customer_id'] = !empty($meta_cus) ? $meta_cus : null;
                $facts['customer_id_source'] = !empty($meta_cus) ? 'order_meta' : null;
            } else {
                $facts['customer_id_source'] = 'deposit_record';
            }
            if (empty($facts['payment_method_id'])) {
                $meta_pm = $order->get_meta('_stripe_payment_method', true);
                if (empty($meta_pm)) {
                    $meta_pm = $order->get_meta('_stripe_source_id', true);
                }
                $facts['payment_method_id'] = !empty($meta_pm) ? $meta_pm : null;
            }
        }

        // Plugin settings context
        $facts['capture_timing'] = get_option('securehold_capture_timing', 'immediate');
        if ($facts['capture_timing'] === 'scheduled') {
            $facts['date_field_key']   = get_option('securehold_date_field_key', '_checkout_date');
            $facts['days_before_date'] = intval(get_option('securehold_days_before_date', 1));
        }

        // ========================================
        // 2. RETRIEVE RELATED LOGS (indexed query)
        // ========================================
        $table_logs = $wpdb->prefix . 'securehold_logs';
        $facts['logs'] = array();

        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_logs ) ) ) === $table_logs ) {
            // Primary: indexed order_id column (populated by fixed securehold_log)
            $raw_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT message, data, severity, created_at FROM $table_logs
                 WHERE order_id = %d
                 ORDER BY created_at ASC LIMIT 30",
                $order_id
            ));

            // Fallback: text search if indexed column was not populated (old logs)
            if (empty($raw_logs)) {
                $search_id = $wpdb->esc_like(strval($order_id));
                $raw_logs = $wpdb->get_results($wpdb->prepare(
                    "SELECT message, data, severity, created_at FROM $table_logs
                     WHERE message LIKE %s OR data LIKE %s
                     ORDER BY created_at ASC LIMIT 30",
                    '%' . $search_id . '%',
                    '%' . $search_id . '%'
                ));
            }

            foreach ($raw_logs as $log) {
                $log_data_raw = $log->data;
                // Normalize: unserialize old PHP format, or parse JSON
                if (!empty($log_data_raw)) {
                    $unserialized = @unserialize($log_data_raw);
                    if ($unserialized !== false || $log_data_raw === 'b:0;') {
                        $log_data_raw = wp_json_encode($unserialized);
                    }
                    // If already valid JSON, leave as-is
                }
                $facts['logs'][] = array(
                    'message'    => $log->message,
                    'severity'   => $log->severity,
                    'created_at' => $log->created_at,
                    'data'       => $log_data_raw,
                );
            }
        }

        // ========================================
        // 3. DETERMINE FAILURE CASCADE (data-driven)
        // ========================================
        $cascade = $this->build_failure_cascade($facts);
        $facts['primary_failure']   = $cascade['primary'];
        $facts['secondary_effects'] = $cascade['secondary'];

        wp_send_json_success($facts);
    }

    /**
     * Build failure cascade from FACTS ONLY.
     *
     * Priority order (first match = root cause):
     *   1. deposit.notes  → most reliable, written at exact failure point
     *   2. Log entries     → chronological, first error = root cause
     *   3. Record pattern  → intent_id prefix, missing IDs (last resort)
     *
     * NEVER guesses. Returns 'unknown' with honest message if no data available.
     *
     * @param array $facts Collected raw facts from handle_diagnose_deposit()
     * @return array { 'primary' => [...], 'secondary' => [...] }
     */
    private function build_failure_cascade($facts) {
        $primary   = null;
        $secondary = array();

        // -----------------------------------------------
        // SOURCE 1: deposit.notes (authoritative)
        // Written by the exact code path that failed.
        // -----------------------------------------------
        if (!empty($facts['deposit_notes'])) {
            $notes = $facts['deposit_notes'];

            $primary = array(
                'source'  => 'deposit_record',
                'message' => $notes,
            );

            // Classify based on actual notes text (not guessing)
            if (stripos($notes, 'Missing required date field') !== false) {
                $primary['step']   = 'scheduled_missing_date';
                $primary['label']  = __('Scheduled Strategy: Missing Date Field', 'securehold-security-deposit-holds');
                $primary['action'] = 'settings';
            } elseif (stripos($notes, 'Invalid date format') !== false) {
                $primary['step']   = 'scheduled_invalid_date';
                $primary['label']  = __('Scheduled Strategy: Invalid Date Format', 'securehold-security-deposit-holds');
                $primary['action'] = 'settings';
            } elseif (stripos($notes, 'single-use') !== false || stripos($notes, 'setup_future_usage') !== false) {
                $primary['step']   = 'pm_single_use';
                $primary['label']  = __('Payment Method Single-Use (Gateway Configuration)', 'securehold-security-deposit-holds');
                $primary['action'] = 'gateway_settings';
            } elseif (stripos($notes, 'Stripe error') !== false) {
                $primary['step']   = 'stripe_api_error';
                $primary['label']  = __('Stripe API Error', 'securehold-security-deposit-holds');
                $primary['action'] = 'stripe_dashboard';
            } elseif (stripos($notes, 'API keys') !== false) {
                $primary['step']   = 'missing_api_keys';
                $primary['label']  = __('Missing Stripe API Keys', 'securehold-security-deposit-holds');
                $primary['action'] = 'settings';
            } else {
                $primary['step']   = 'recorded_error';
                $primary['label']  = __('Recorded Error', 'securehold-security-deposit-holds');
                $primary['action'] = 'logs';
            }

            // Determine secondary effects from deposit record state
            if (!empty($facts['intent_id']) && strpos($facts['intent_id'], 'failed_') === 0) {
                $secondary[] = __('Internal placeholder intent ID generated (no real Stripe PaymentIntent was created).', 'securehold-security-deposit-holds');
            }
            if (empty($facts['customer_id'])) {
                $secondary[] = __('No Stripe Customer ID — deposit failed before Stripe customer lookup could occur.', 'securehold-security-deposit-holds');
            }
            if (empty($facts['payment_method_id'])) {
                $secondary[] = __('No payment method on record — deposit failed before card attachment.', 'securehold-security-deposit-holds');
            }
            if ($facts['amount'] == 0) {
                $secondary[] = __('Deposit amount is $0 — the hold amount was never calculated.', 'securehold-security-deposit-holds');
            }

            // Scan logs for additional cascade errors AFTER the primary
            if (!empty($facts['logs'])) {
                foreach ($facts['logs'] as $log) {
                    if ($this->is_error_log($log) && stripos($log['message'], 'Capture Webhook') !== false) {
                        $secondary[] = sprintf(
                            /* translators: %s is the error message */
                            __('Webhook error: %s', 'securehold-security-deposit-holds'),
                            $log['message']
                        );
                    }
                }
            }

            return array('primary' => $primary, 'secondary' => $secondary);
        }

        // -----------------------------------------------
        // SOURCE 2: Log entries (chronological analysis)
        // First error log = root cause, rest = cascade.
        // -----------------------------------------------
        if (!empty($facts['logs'])) {
            $first_error = null;

            foreach ($facts['logs'] as $log) {
                if ($this->is_error_log($log)) {
                    $first_error = $log;
                    break;
                }
            }

            if ($first_error) {
                $detail = $first_error['message'];

                // Enrich from log data JSON if available
                if (!empty($first_error['data'])) {
                    $decoded = json_decode($first_error['data'], true);
                    if (is_array($decoded)) {
                        if (!empty($decoded['error'])) {
                            $detail .= ' — ' . $decoded['error'];
                        }
                        if (!empty($decoded['meta_key'])) {
                            $detail .= ' (meta_key: ' . $decoded['meta_key'] . ')';
                        }
                    }
                }

                $primary = array(
                    'source'   => 'log_entry',
                    'message'  => $detail,
                    'log_time' => $first_error['created_at'],
                );

                // Classify from log message text
                $msg = $first_error['message'];
                if (stripos($msg, 'Scheduled strategy') !== false) {
                    $primary['step']   = 'scheduled_strategy_error';
                    $primary['label']  = __('Scheduled Strategy Error', 'securehold-security-deposit-holds');
                    $primary['action'] = 'settings';
                } elseif (stripos($msg, 'Missing Stripe data') !== false) {
                    $primary['step']   = 'missing_stripe_data';
                    $primary['label']  = __('Missing Stripe Data on Order', 'securehold-security-deposit-holds');
                    $primary['action'] = 'logs';
                } elseif (stripos($msg, 'Stripe') !== false && stripos($msg, 'error') !== false) {
                    $primary['step']   = 'stripe_error';
                    $primary['label']  = __('Stripe Error', 'securehold-security-deposit-holds');
                    $primary['action'] = 'stripe_dashboard';
                } elseif (stripos($msg, 'secret key not found') !== false) {
                    $primary['step']   = 'missing_secret_key';
                    $primary['label']  = __('Missing Stripe Secret Key', 'securehold-security-deposit-holds');
                    $primary['action'] = 'settings';
                } else {
                    $primary['step']   = 'log_error';
                    $primary['label']  = __('Error Found in Logs', 'securehold-security-deposit-holds');
                    $primary['action'] = 'logs';
                }

                // Collect subsequent error logs as secondary effects
                $found_primary = false;
                foreach ($facts['logs'] as $log) {
                    if (!$found_primary) {
                        if ($log['created_at'] === $first_error['created_at'] && $log['message'] === $first_error['message']) {
                            $found_primary = true;
                        }
                        continue;
                    }
                    if ($this->is_error_log($log) && $log['message'] !== $first_error['message']) {
                        $secondary[] = $log['message'];
                    }
                }
                $secondary = array_values(array_unique($secondary));

                return array('primary' => $primary, 'secondary' => $secondary);
            }
        }

        // -----------------------------------------------
        // SOURCE 3: Record pattern analysis (last resort)
        // Only when notes AND logs are both empty.
        // -----------------------------------------------
        if (!$facts['has_secret_key']) {
            $primary = array(
                'source'  => 'config_check',
                'step'    => 'missing_api_keys',
                'label'   => __('Stripe Secret Key Not Configured', 'securehold-security-deposit-holds'),
                'message' => __('The Stripe secret key is not set in WooCommerce Stripe Gateway settings.', 'securehold-security-deposit-holds'),
                'action'  => 'settings',
            );
            return array('primary' => $primary, 'secondary' => $secondary);
        }

        if (!$facts['order_exists']) {
            $primary = array(
                'source'  => 'record_check',
                'step'    => 'order_deleted',
                'label'   => __('WooCommerce Order Deleted', 'securehold-security-deposit-holds'),
                /* translators: %d is the order ID */
                'message' => sprintf(__('Order #%d no longer exists.', 'securehold-security-deposit-holds'), $facts['order_id']),
                'action'  => 'none',
            );
            return array('primary' => $primary, 'secondary' => $secondary);
        }

        if (!empty($facts['intent_id']) && strpos($facts['intent_id'], 'failed_') === 0) {
            $primary = array(
                'source'  => 'record_check',
                'step'    => 'pre_stripe_failure',
                'label'   => __('Failed Before Stripe API Call', 'securehold-security-deposit-holds'),
                'message' => __('Deposit was marked failed before any Stripe PaymentIntent was created. No notes or logs were recorded. Enable WooCommerce logging and retry.', 'securehold-security-deposit-holds'),
                'action'  => 'health_check',
            );
            return array('primary' => $primary, 'secondary' => $secondary);
        }

        if (!empty($facts['intent_id']) && strpos($facts['intent_id'], 'pi_') === 0) {
            $primary = array(
                'source'  => 'record_check',
                'step'    => 'stripe_pi_failed',
                'label'   => __('Stripe PaymentIntent Failed', 'securehold-security-deposit-holds'),
                'message' => __('A Stripe PaymentIntent was created but failed. No error details were recorded. Check the Stripe Dashboard for the actual failure reason.', 'securehold-security-deposit-holds'),
                'action'  => 'stripe_dashboard',
            );
            return array('primary' => $primary, 'secondary' => $secondary);
        }

        // -----------------------------------------------
        // UNKNOWN: No data available anywhere
        // -----------------------------------------------
        $primary = array(
            'source'  => 'none',
            'step'    => 'unknown',
            'label'   => __('Unknown — No Diagnostic Data Available', 'securehold-security-deposit-holds'),
            'message' => __('No error notes, no related logs, and no identifiable pattern. Enable WooCommerce logging, run a Health Check, and retry the operation.', 'securehold-security-deposit-holds'),
            'action'  => 'health_check',
        );
        return array('primary' => $primary, 'secondary' => $secondary);
    }

    /**
     * Check if a log entry represents an error.
     * Uses only the data present in the log — no guessing.
     */
    private function is_error_log($log) {
        if ($log['severity'] === 'error' || $log['severity'] === 'warning') {
            return true;
        }
        $error_markers = array('❌', 'Error', 'error', 'Failed', 'failed', 'Missing');
        foreach ($error_markers as $marker) {
            if (strpos($log['message'], $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * AJAX: Diagnose an order's Stripe state (Health Check tool).
     *
     * Queries Stripe API live and returns structured diagnosis data.
     * Used by the Health Check page "Order Stripe Diagnostic" tool.
     *
     * @since 4.1.0
     */
    public function ajax_diagnose_order_stripe() {
        check_ajax_referer('securehold_diagnose_order_stripe', 'nonce');

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'securehold-security-deposit-holds')));
        }

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        if ($order_id <= 0) {
            wp_send_json_error(array('message' => __('Please enter a valid Order ID.', 'securehold-security-deposit-holds')));
        }

        if (!class_exists('SecureHold_Stripe')) {
            wp_send_json_error(array('message' => __('SecureHold_Stripe class not available.', 'securehold-security-deposit-holds')));
        }

        $result = SecureHold_Stripe::diagnose_order_stripe_state($order_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }

        if (function_exists('securehold_log')) {
            securehold_log('Health Check: Order Stripe diagnostic run', array(
                'order_id' => $order_id,
                'diagnosis' => $result['diagnosis'],
                'can_create_hold' => $result['can_create_hold'],
            ));
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX: Inspect meta keys available on an order.
     *
     * Returns a deduplicated, sorted list of order-level and item-level meta keys
     * with truncated sample values and origin labels. Used by the Settings > Automation
     * "Inspect Order" helper to help users identify the correct date meta key.
     *
     * Security: nonce + manage_woocommerce capability.
     * Privacy: values are truncated to 80 chars, emails/phones are masked.
     *
     * @since 4.3.0
     */
    public function ajax_inspect_order_meta() {
        check_ajax_referer('securehold_inspect_order_meta', 'nonce');

        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'securehold-security-deposit-holds')));
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ($order_id <= 0) {
            wp_send_json_error(array('message' => __('Please enter a valid Order ID.', 'securehold-security-deposit-holds')));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => sprintf(
                /* translators: %d = order ID */
                __('Order #%d not found.', 'securehold-security-deposit-holds'),
                $order_id
            )));
        }

        $meta_list = array();

        // ── 1. Order-level meta ──
        $order_meta = $order->get_meta_data();
        $order_meta_count = 0;
        $max_order_meta = 200; // Safety limit

        foreach ($order_meta as $meta_obj) {
            if ($order_meta_count >= $max_order_meta) break;
            $order_meta_count++;

            $key = $meta_obj->key;
            $value = $meta_obj->value;

            // Skip internal WC keys that are never useful for date resolution
            if (in_array($key, array('_edit_lock', '_edit_last'), true)) continue;

            $meta_list[] = array(
                'key'    => $key,
                'value'  => $this->sanitize_meta_value_for_display($value),
                'origin' => 'order_meta',
            );
        }

        // ── 2. Order item meta (line items only, first 5 items max) ──
        $items = $order->get_items();
        $item_count = 0;
        $max_items = 5;
        $max_item_meta = 50; // Per item

        foreach ($items as $item_id => $item) {
            if ($item_count >= $max_items) break;
            $item_count++;

            $item_meta = $item->get_meta_data();
            $item_meta_count = 0;

            $product_name = $item->get_name();

            foreach ($item_meta as $meta_obj) {
                if ($item_meta_count >= $max_item_meta) break;
                $item_meta_count++;

                $key = $meta_obj->key;
                $value = $meta_obj->value;

                $meta_list[] = array(
                    'key'    => $key,
                    'value'  => $this->sanitize_meta_value_for_display($value),
                    'origin' => 'item_meta',
                    'item'   => $this->truncate_string($product_name, 40),
                );
            }
        }

        // ── 3. Deduplicate by key+origin, sort alphabetically ──
        $seen = array();
        $deduplicated = array();
        foreach ($meta_list as $entry) {
            $dedup_key = $entry['origin'] . '::' . $entry['key'];
            if (!isset($seen[$dedup_key])) {
                $seen[$dedup_key] = true;
                $deduplicated[] = $entry;
            }
        }

        usort($deduplicated, function($a, $b) {
            // Sort by origin first (order_meta < item_meta), then by key
            $origin_cmp = strcmp($a['origin'], $b['origin']);
            if ($origin_cmp !== 0) return $origin_cmp;
            return strcasecmp($a['key'], $b['key']);
        });

        wp_send_json_success(array(
            'order_id' => $order_id,
            'count'    => count($deduplicated),
            'meta'     => $deduplicated,
        ));
    }

    /**
     * Sanitize a meta value for safe display in the inspector.
     * Truncates, masks emails/phones, handles arrays/objects.
     *
     * @param mixed $value Raw meta value.
     * @return string Safe display string.
     */
    private function sanitize_meta_value_for_display($value) {
        if (is_array($value) || is_object($value)) {
            $json = wp_json_encode($value);
            return $this->truncate_string($json, 80) . ' [' . __('array/object', 'securehold-security-deposit-holds') . ']';
        }

        $value = (string) $value;

        // Mask email addresses
        if (preg_match('/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/', $value)) {
            $parts = explode('@', $value);
            return substr($parts[0], 0, 2) . '***@' . $parts[1];
        }

        // Mask phone-like values
        if (preg_match('/^\+?\d[\d\s\-\(\)]{7,}$/', $value)) {
            return substr($value, 0, 4) . '****' . substr($value, -2);
        }

        // Mask Stripe secret keys
        if (preg_match('/^(sk_|rk_|whsec_)/', $value)) {
            return substr($value, 0, 7) . '****';
        }

        return $this->truncate_string($value, 80);
    }

    /**
     * Truncate a string with ellipsis.
     *
     * @param string $str
     * @param int $max_length
     * @return string
     */
    private function truncate_string($str, $max_length = 80) {
        if (mb_strlen($str) <= $max_length) {
            return $str;
        }
        return mb_substr($str, 0, $max_length) . '...';
    }

    public function display_admin_deposit_totals($order_id) {
        if (empty($order_id)) { global $post; if (isset($post->ID)) $order_id = $post->ID; }
        if (!class_exists('SecureHold_DB')) { if (defined('SECUREHOLD_PLUGIN_DIR')) require_once SECUREHOLD_PLUGIN_DIR . 'includes/database/class-securehold-wp-db.php'; }
        if (!class_exists('SecureHold_DB')) return;
        $deposit = SecureHold_DB::get_deposit($order_id);
        if (!$deposit) return;
        $currency = $deposit->currency ? $deposit->currency : get_woocommerce_currency();
        $auth_amount = floatval($deposit->amount);
        $captured_amount = isset($deposit->captured_amount) ? floatval($deposit->captured_amount) : 0;
        $remaining_amount = $auth_amount - $captured_amount;
        $label_style = 'text-align:right; width:50%;';
        $value_style = 'text-align:right;';
        ?>
        <tr><td class="label" colspan="2" style="border-bottom: 1px solid #eee; padding-top:10px;"></td></tr>
        <tr><td class="label" style="<?php echo esc_attr( $label_style ); ?>"><strong><?php esc_html_e('Authorized Deposit:', 'securehold-security-deposit-holds'); ?></strong><?php if ($deposit->status === 'released'): ?><br><small style="color: #999; font-weight: normal;"><?php esc_html_e('(Released)', 'securehold-security-deposit-holds'); ?></small><?php endif; ?></td><td width="1%" style="<?php echo esc_attr( $value_style ); ?>"><?php echo wp_kses_post( wc_price($auth_amount, array('currency' => $currency)) ); ?></td></tr>
        <?php if ($captured_amount > 0): ?><tr><td class="label" style="<?php echo esc_attr( $label_style ); ?>"><strong><?php esc_html_e('Captured Deposit:', 'securehold-security-deposit-holds'); ?></strong></td><td width="1%" style="<?php echo esc_attr( $value_style ); ?>"><span style="color: #d63638; font-weight:bold;"><?php echo wp_kses_post( wc_price($captured_amount, array('currency' => $currency)) ); ?></span></td></tr><?php endif; ?>
        <?php if ($remaining_amount > 0 && ($deposit->status === 'authorized' || $deposit->status === 'captured')): ?><tr><td class="label" style="<?php echo esc_attr( $label_style ); ?>">
        <?php esc_html_e('Remaining (Released):', 'securehold-security-deposit-holds'); ?>
        </td><td width="1%" style="<?php echo esc_attr( $value_style ); ?>"><span style="color: #7ad03a;"><?php echo wp_kses_post( wc_price($remaining_amount, array('currency' => $currency)) ); ?></span></td></tr><?php endif; ?>
        <?php
    }

    public function show_configuration_notice() {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'dashboard') return;
        if (get_option('securehold_setup_completed')) return;
        if (get_option('securehold_config_notice_dismissed')) return;
        ?>
        <div class="notice notice-warning is-dismissible securehold-config-notice" id="securehold-config-notice">
            <div style="display: flex; align-items: center; gap: 15px; padding: 10px 0;">
                <div style="flex-shrink: 0;">
                    <span class="dashicons dashicons-shield-alt" style="font-size: 32px; color: #f59e0b; width: 32px; height: 32px;"></span>
                </div>
                <div style="flex-grow: 1;">
                    <h3 style="margin: 0 0 5px 0; font-size: 16px; font-weight: 600;">
                        <?php esc_html_e('SecureHold WP Security Deposits Needs Configuration', 'securehold-security-deposit-holds'); ?>
                    </h3>
                    <p style="margin: 0; font-size: 14px; line-height: 1.5;">
                        <?php esc_html_e('Complete the setup wizard to start managing security deposits with Stripe.', 'securehold-security-deposit-holds'); ?>
                    </p>
                    <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="<?php echo esc_url( admin_url('admin.php?page=securehold-setup-wizard') ); ?>" class="button button-primary">
                            <span class="dashicons dashicons-admin-tools" style="vertical-align: middle; margin-right: 5px;"></span>
                            <?php esc_html_e('Start Configuration Wizard', 'securehold-security-deposit-holds'); ?>
                        </a>
                        <button type="button" class="button button-secondary securehold-dismiss-notice-forever" data-nonce="<?php echo esc_attr( wp_create_nonce( 'securehold_dismiss_config_notice' ) ); ?>">
                            <?php esc_html_e('Don\'t show this again', 'securehold-security-deposit-holds'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        // Dismiss handler is enqueued as assets/js/admin-config-notice.js on the
        // dashboard (see enqueue_admin_assets) — no inline script here.
    }
    
    /**
     * Show transient admin notices (for manual hold creation feedback)
     */
    public function show_transient_notices() {
        $user_id = get_current_user_id();
        $notice = get_transient('securehold_admin_notice_' . $user_id);
        
        if ($notice && is_array($notice)) {
            $type = isset($notice['type']) ? $notice['type'] : 'info';
            $message = isset($notice['message']) ? $notice['message'] : '';
            
            if ($message) {
                $class = 'notice notice-' . esc_attr($type) . ' is-dismissible';
                echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($message) . '</p></div>';
                delete_transient('securehold_admin_notice_' . $user_id);
            }
        }
    }
    
    public function dismiss_configuration_notice() {
        check_ajax_referer('securehold_dismiss_config_notice', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(array('message' => 'Permission denied'));
        update_option('securehold_config_notice_dismissed', true);
        wp_send_json_success(array('message' => __('Configuration notice dismissed permanently', 'securehold-security-deposit-holds')));
    }
    
    public function enqueue_admin_assets($hook) {
        // ── Detect WooCommerce order edit pages (classic + HPOS) ──
        // Used below to load admin.js (the metabox capture/release handlers) on these screens.
        $screen           = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_classic_order = ( $hook === 'post.php' ) && $screen && ( $screen->post_type === 'shop_order' );
        $is_hpos_order    = ( $hook === 'woocommerce_page_wc-orders' );

        // ── Dashboard: setup configuration notice dismiss handler ──
        // show_configuration_notice() renders the prompt on the dashboard only.
        // This static script handles its dismiss button (no inline script).
        if ( $hook === 'index.php' ) {
            wp_enqueue_script(
                'securehold-admin-config-notice',
                SECUREHOLD_PLUGIN_URL . 'assets/js/admin-config-notice.js',
                array( 'jquery' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin-config-notice.js' ) ?: SECUREHOLD_VERSION,
                true
            );
        }

        // ── SecureHold's own admin pages: opt-in telemetry notice handler ──
        // maybe_show_optin_notice() renders via the securehold_after_page_header
        // hook, fired on every SecureHold screen — this loads alongside it.
        if ( strpos( $hook, 'securehold' ) !== false ) {
            wp_enqueue_script(
                'securehold-admin-telemetry-notice',
                SECUREHOLD_PLUGIN_URL . 'assets/js/admin-telemetry-notice.js',
                array( 'jquery' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin-telemetry-notice.js' ) ?: SECUREHOLD_VERSION,
                true
            );
        }

        // Order edit screens (classic + HPOS) need admin.js: it's what actually
        // handles .sh-btn-capture / .sh-btn-release clicks (opens the capture modal,
        // fires the release AJAX call) for the metabox rendered by render_order_metabox().
        // Without this, the metabox's buttons render but do nothing when clicked.
        if ( strpos( $hook, 'securehold' ) === false && $hook !== 'plugins.php' && ! $is_classic_order && ! $is_hpos_order ) {
            return;
        }

        wp_enqueue_script('selectWoo');
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_style('woocommerce_admin_styles');
        
        wp_enqueue_style('securehold-design-system', SECUREHOLD_PLUGIN_URL . 'assets/css/securehold-wp-design-system.css', array(), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/css/securehold-wp-design-system.css' ) ?: SECUREHOLD_VERSION);
        wp_enqueue_style('securehold-admin', SECUREHOLD_PLUGIN_URL . 'assets/css/admin.css', array('securehold-design-system', 'woocommerce_admin_styles'), SECUREHOLD_VERSION);
        wp_enqueue_script('securehold-admin-js', SECUREHOLD_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'selectWoo'), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin.js' ) ?: SECUREHOLD_VERSION, true);
        
        $unified_params = array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'captureTiming' => get_option('securehold_capture_timing', 'immediate'),
            'nonces' => array(
                'testConfig' => wp_create_nonce('securehold_test_config'),
                'emails' => wp_create_nonce('securehold_emails_nonce'),
                'capture' => wp_create_nonce('securehold_capture_nonce'),
                'release' => wp_create_nonce('securehold_release_nonce'),
                'diagnose' => wp_create_nonce('securehold_diagnose_nonce'),
                'productSearch' => wp_create_nonce('securehold_search_products'),
                'productConfig' => wp_create_nonce('securehold_product_settings'),
                'categoryConfig' => wp_create_nonce('securehold_category_settings'),
                'categorySearch' => wp_create_nonce('securehold_search_categories'),
                'simulateProduct' => wp_create_nonce('securehold_simulate_product'),
                'simulateCart' => wp_create_nonce('securehold_simulate_cart')
            ),
            'i18n' => array(
                'copied' => __('Copied!', 'securehold-security-deposit-holds'),
                'testCompleted' => __('Test Completed', 'securehold-security-deposit-holds'),
                'confirmCapture' => __('Are you sure you want to capture this amount?', 'securehold-security-deposit-holds'),
                'captureSuccess' => __('Capture successful!', 'securehold-security-deposit-holds'),
                'selectProduct' => __('Please select a product first', 'securehold-security-deposit-holds'),
                'confirmReset' => __('Are you sure you want to reset this product to global defaults? This will remove all product-specific overrides.', 'securehold-security-deposit-holds'),
                'resetting' => __('Resetting...', 'securehold-security-deposit-holds'),
                'reset' => __('Reset!', 'securehold-security-deposit-holds'),
                'connectionError' => __('Connection error', 'securehold-security-deposit-holds'),
                'confirmRelease' => __('Are you sure you want to release this hold? The customer will no longer be charged.', 'securehold-security-deposit-holds'),
                'releaseSuccess' => __('Hold released successfully!', 'securehold-security-deposit-holds'),
                'confirmCreateHold' => __('Are you sure you want to create the security deposit for this order?', 'securehold-security-deposit-holds'),
                'productScheduledGlobalFallback' => __('Leave empty to use the global scheduled settings.', 'securehold-security-deposit-holds'),
                'scheduledSameDay' => __('The deposit will be captured on the same day as the specified date.', 'securehold-security-deposit-holds'),
                /* translators: %d is the number of days before the specified date */
                'scheduledBefore' => __('The deposit will be captured %d days before the specified date.', 'securehold-security-deposit-holds'),
                /* translators: %d is the number of days after the specified date */
                'scheduledAfter' => __('The deposit will be captured %d days after the specified date.', 'securehold-security-deposit-holds'),
                'validationDateKeyRequired' => __('Date Meta Key is required when using the Scheduled strategy.', 'securehold-security-deposit-holds'),
                'validationDirectionRequired' => __('Please select a valid timing direction.', 'securehold-security-deposit-holds'),
                'validationDaysRequired' => __('Number of days is required (integer >= 0).', 'securehold-security-deposit-holds'),
                'selectCategory' => __('Please select a category first', 'securehold-security-deposit-holds'),
                'confirmResetCategory' => __('Are you sure you want to remove the rule for this category? It will fall back to global defaults.', 'securehold-security-deposit-holds'),
                'installing' => __('Installing...', 'securehold-security-deposit-holds')
            )
        );
        
        wp_localize_script('securehold-admin-js', 'secureholdAdminParams', $unified_params);

        // Admin page URLs for diagnostic action links (no secrets exposed)
        wp_localize_script('securehold-admin-js', 'secureholdDiagUrls', array(
            'settings'    => admin_url('admin.php?page=securehold-settings'),
            'logs'        => admin_url('admin.php?page=securehold-logs'),
            'healthCheck' => admin_url('admin.php?page=securehold-health'),
        ));

        if (strpos($hook, 'securehold') !== false) {
            wp_enqueue_style( 'securehold-admin-wizard', SECUREHOLD_PLUGIN_URL . 'assets/css/admin-wizard.css', array( 'securehold-admin' ), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/css/admin-wizard.css' ) ?: SECUREHOLD_VERSION, 'all' );
            wp_enqueue_script('securehold-modal', SECUREHOLD_PLUGIN_URL . 'assets/js/securehold-wp-modal.js', array('jquery'), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/securehold-wp-modal.js' ) ?: SECUREHOLD_VERSION, true);
            wp_localize_script('securehold-modal', 'secureholdModalData', array(
                'show_modal' => $this->should_show_setup_modal(),
                'wizard_url' => admin_url('admin.php?page=securehold-setup-wizard'),
                'nonce' => wp_create_nonce('securehold_modal_action')
            ));
        }

        if ($hook === 'plugins.php') {
            wp_enqueue_style( 'securehold-admin-deactivation', SECUREHOLD_PLUGIN_URL . 'assets/css/admin-deactivation.css', array(), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/css/admin-deactivation.css' ) ?: SECUREHOLD_VERSION, 'all' );
            wp_enqueue_script('securehold-deactivation', SECUREHOLD_PLUGIN_URL . 'assets/js/deactivation.js', array('jquery'), SECUREHOLD_VERSION, true);
            wp_localize_script('securehold-deactivation', 'SecureHoldAdmin', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'deactivationNonce' => wp_create_nonce('securehold_deactivation_feedback')
            ));
        }

        wp_enqueue_style('securehold-admin-emails', SECUREHOLD_PLUGIN_URL . 'assets/css/admin-emails.css', array(), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/css/admin-emails.css' ) ?: SECUREHOLD_VERSION, 'all');
        wp_enqueue_script('securehold-admin-emails', SECUREHOLD_PLUGIN_URL . 'assets/js/admin-emails.js', array('jquery'), filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin-emails.js' ) ?: SECUREHOLD_VERSION, true);
        // Localize nonce + ajaxurl DIRECTLY on this script handle so it never
        // depends on admin.js load order to have secureholdAdminParams available.
        wp_localize_script( 'securehold-admin-emails', 'secureholdEmailParams', array(
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'securehold_emails_nonce' ),
            'i18n'    => array(
                'saved'        => __( 'Settings saved successfully.', 'securehold-security-deposit-holds' ),
                'saving'       => __( 'Saving...', 'securehold-security-deposit-holds' ),
                'toggleSaved'  => __( 'Toggle saved.', 'securehold-security-deposit-holds' ),
                'saveFailed'   => __( 'Could not save - please reload and try again.', 'securehold-security-deposit-holds' ),
                'serverError'  => __( 'Server error. Please try again.', 'securehold-security-deposit-holds' ),
                // Reset to Default strings (Task D)
                'resetConfirm' => __( 'Reset this template to defaults? This cannot be undone.', 'securehold-security-deposit-holds' ),
                'resetting'    => __( 'Resetting...', 'securehold-security-deposit-holds' ),
                'resetDone'    => __( 'Template reset to defaults.', 'securehold-security-deposit-holds' ),
                'resetFailed'  => __( 'Reset failed. Please try again.', 'securehold-security-deposit-holds' ),
            ),
        ) );

        // ── CodeMirror (Task 3 — HTML code editor) ───────────────────────
        // wp_enqueue_code_editor() registers wp-codemirror + code-editor and
        // returns the settings object that JS passes to wp.codeEditor.initialize().
        // Gate to SecureHold pages only; htmlmixed mode covers HTML + embedded CSS/JS.
        if ( strpos( $hook, 'securehold' ) !== false ) {
            $cm_settings = wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
            if ( false !== $cm_settings ) {
                wp_add_inline_script(
                    'securehold-admin-emails',
                    'window.secureholdCMSettings = ' . wp_json_encode( $cm_settings ) . ';',
                    'before'
                );
            }
        }

        // Email Branding tab assets removed in Phase 2B-DashboardLogs-Branding —
        // the subtab and its handlers were stripped, so the branding bundle
        // (admin-branding.css / admin-branding.js / Securehold_Email_Templates)
        // is no longer enqueued or referenced on any screen.

        // ── Health Check page assets ─────────────────────────────────────────
        // Scoped to the Health Check screen only (securehold-health).
        // Provides the Stripe order diagnosis JS with its own nonce + i18n data.
        $is_health_page = ( strpos( $hook, 'securehold' ) !== false )
            && isset( $_GET['page'] )
            && 'securehold-health' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $is_health_page ) {
            wp_enqueue_script(
                'securehold-admin-health-check',
                SECUREHOLD_PLUGIN_URL . 'assets/js/admin-health-check.js',
                array( 'jquery' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin-health-check.js' ) ?: SECUREHOLD_VERSION,
                true
            );
            wp_localize_script( 'securehold-admin-health-check', 'secureholdHealthCheck', array(
                'nonce' => wp_create_nonce( 'securehold_diagnose_order_stripe' ),
                'i18n'  => array(
                    'invalidOrderId'  => __( 'Please enter a valid Order ID.', 'securehold-security-deposit-holds' ),
                    'diagReusable'    => __( 'Reusable', 'securehold-security-deposit-holds' ),
                    'diagNoSfu'       => __( 'No setup_future_usage', 'securehold-security-deposit-holds' ),
                    'diagPmNotAttached' => __( 'PM Not Attached', 'securehold-security-deposit-holds' ),
                    'diagNoIntentId'  => __( 'No Intent ID', 'securehold-security-deposit-holds' ),
                    'diagApiError'    => __( 'API Error', 'securehold-security-deposit-holds' ),
                    'diagnosisLabel'  => __( 'Diagnosis:', 'securehold-security-deposit-holds' ),
                    'holdShouldSucceed' => __( 'Hold creation should succeed', 'securehold-security-deposit-holds' ),
                    'paymentIntent'   => __( 'PaymentIntent', 'securehold-security-deposit-holds' ),
                    'notSet'          => __( 'not set', 'securehold-security-deposit-holds' ),
                    'piCustomer'      => __( 'PI Customer', 'securehold-security-deposit-holds' ),
                    'none'            => __( 'none', 'securehold-security-deposit-holds' ),
                    'piAmount'        => __( 'PI Amount', 'securehold-security-deposit-holds' ),
                    'paymentMethod'   => __( 'Payment Method', 'securehold-security-deposit-holds' ),
                    'card'            => __( 'Card', 'securehold-security-deposit-holds' ),
                    'pmAttachedTo'    => __( 'PM attached to', 'securehold-security-deposit-holds' ),
                    'notAttached'     => __( 'not attached', 'securehold-security-deposit-holds' ),
                    'errorLabel'      => __( 'Error:', 'securehold-security-deposit-holds' ),
                    'customer'        => __( 'Customer', 'securehold-security-deposit-holds' ),
                    'orderMeta'       => __( 'Order Meta', 'securehold-security-deposit-holds' ),
                    'recommendations' => __( 'Recommendations:', 'securehold-security-deposit-holds' ),
                    'networkError'    => __( 'Network error. Please try again.', 'securehold-security-deposit-holds' ),
                ),
            ) );
        }

        // ── Settings page assets ─────────────────────────────────────────────
        // Scoped to the Settings screen only (securehold-settings).
        // CSS covers timing cards, clause modal, credential key UI, rule engine
        // sidebar blocks, tooltips, and all Settings-specific layout components.
        $is_settings_page = ( strpos( $hook, 'securehold' ) !== false )
            && isset( $_GET['page'] )
            && 'securehold-settings' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $is_settings_page ) {
            wp_enqueue_style(
                'securehold-admin-settings',
                SECUREHOLD_PLUGIN_URL . 'assets/css/admin-settings.css',
                array( 'securehold-admin' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/css/admin-settings.css' ) ?: SECUREHOLD_VERSION,
                'all'
            );
            wp_enqueue_script(
                'securehold-admin-settings-js',
                SECUREHOLD_PLUGIN_URL . 'assets/js/admin-settings.js',
                array( 'jquery', 'securehold-admin-js' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin-settings.js' ) ?: SECUREHOLD_VERSION,
                true
            );
            wp_localize_script( 'securehold-admin-settings-js', 'secureholdSettingsParams', array(
                'nonce' => wp_create_nonce( 'securehold_inspect_order_meta' ),
                'i18n'  => array(
                    'schedSameDay'      => __( 'The deposit will be created on the same day as the specified date.', 'securehold-security-deposit-holds' ),
                    'sched1After'       => __( 'The deposit will be created 1 day after the specified date.', 'securehold-security-deposit-holds' ),
                    /* translators: %d is the number of days after the specified date */
                    'schedNAfter'       => __( 'The deposit will be created %d days after the specified date.', 'securehold-security-deposit-holds' ),
                    'sched1Before'      => __( 'The deposit will be created 1 day before the specified date.', 'securehold-security-deposit-holds' ),
                    /* translators: %d is the number of days before the specified date */
                    'schedNBefore'      => __( 'The deposit will be created %d days before the specified date.', 'securehold-security-deposit-holds' ),
                    'inspectInvalidId'  => __( 'Please enter a valid Order ID.', 'securehold-security-deposit-holds' ),
                    'inspecting'        => __( 'Inspecting order...', 'securehold-security-deposit-holds' ),
                    'found'             => __( 'Found', 'securehold-security-deposit-holds' ),
                    'metaKeysOn'        => __( 'meta keys on order', 'securehold-security-deposit-holds' ),
                    'colKey'            => __( 'Key', 'securehold-security-deposit-holds' ),
                    'colValue'          => __( 'Value (sample)', 'securehold-security-deposit-holds' ),
                    'colSource'         => __( 'Source', 'securehold-security-deposit-holds' ),
                    'colUse'            => __( 'Use', 'securehold-security-deposit-holds' ),
                    'originOrder'       => __( 'Order', 'securehold-security-deposit-holds' ),
                    'originItem'        => __( 'Item', 'securehold-security-deposit-holds' ),
                    'copyToField'       => __( 'Copy to Date Meta Key field', 'securehold-security-deposit-holds' ),
                    'requestFailed'     => __( 'Request failed. Please try again.', 'securehold-security-deposit-holds' ),
                ),
            ) );
        }

        // ── Tools page assets ────────────────────────────────────────────────
        // Scoped to the Tools & Maintenance screen only. The script handle
        // matches the wp_script_is() guard in admin/views/tools-page.php so the
        // inline tab-routing block stands down when this script is enqueued.
        // Reuses secureholdAdminParams.nonces.testConfig (already localized on
        // securehold-admin-js above) — no additional localization needed.
        $is_tools_page = ( strpos( $hook, 'securehold' ) !== false )
            && isset( $_GET['page'] )
            && 'securehold-tools' === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $is_tools_page ) {
            wp_enqueue_style(
                'securehold-admin-tools-css',
                SECUREHOLD_PLUGIN_URL . 'assets/css/admin-tools.css',
                array( 'securehold-admin' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/css/admin-tools.css' ) ?: SECUREHOLD_VERSION,
                'all'
            );
            wp_enqueue_script(
                'securehold-admin-tools',
                SECUREHOLD_PLUGIN_URL . 'assets/js/admin-tools.js',
                array( 'jquery', 'securehold-admin-js' ),
                filemtime( SECUREHOLD_PLUGIN_DIR . 'assets/js/admin-tools.js' ) ?: SECUREHOLD_VERSION,
                true
            );
        }

        wp_enqueue_style('securehold-admin-frontend', SECUREHOLD_PLUGIN_URL . 'assets/css/admin-frontend.css', array(), SECUREHOLD_VERSION, 'all');
        wp_enqueue_script('securehold-admin-frontend', SECUREHOLD_PLUGIN_URL . 'assets/js/admin-frontend.js', array('jquery'), SECUREHOLD_VERSION, true);
        
        wp_localize_script('securehold-admin-frontend', 'secureholdAdminFrontendParams', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('securehold_frontend_nonce')
        ));
    }

    /**
     * Returns a base64-encoded SVG data URI for the admin menu icon.
     * Falls back to a Dashicon string if the SVG file is missing.
     *
     * @return string
     */
    private function get_admin_menu_icon(): string {
        $icon_path = SECUREHOLD_PLUGIN_DIR . 'assets/images/admin-menu-icon.svg';

        if ( ! file_exists( $icon_path ) ) {
            return 'dashicons-shield-alt';
        }

        $svg = file_get_contents( $icon_path );

        if ( empty( $svg ) ) {
            return 'dashicons-shield-alt';
        }

        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    public function add_plugin_admin_menu() {
        // ── Capability constants ──
        // manage_woocommerce : lecture / monitoring (Shop Manager + Admin)
        // manage_options     : actions destructives / export / reset (Admin only)

        // Main menu entry — uses manage_woocommerce so Shop Manager sees it
        add_menu_page(
            __('SecureHold WP', 'securehold-security-deposit-holds'),
            __('SecureHold WP', 'securehold-security-deposit-holds'),
            'manage_woocommerce',
            'securehold',
            array($this, 'display_dashboard_page'),
            $this->get_admin_menu_icon(),
            56
        );

        // ── Visible for Shop Manager (manage_woocommerce) ──
        add_submenu_page('securehold', __('Dashboard',    'securehold-security-deposit-holds'), __('Dashboard',    'securehold-security-deposit-holds'), 'manage_woocommerce', 'securehold',                array($this, 'display_dashboard_page'));
        add_submenu_page('securehold', __('Deposits',     'securehold-security-deposit-holds'), __('Deposits',     'securehold-security-deposit-holds'), 'manage_woocommerce', 'securehold-deposits',       array($this, 'display_deposits_page'));
        add_submenu_page('securehold', __('Tools',        'securehold-security-deposit-holds'), __('Tools',        'securehold-security-deposit-holds'), 'manage_woocommerce', 'securehold-tools',          array($this, 'display_tools_page'));
        add_submenu_page('securehold', __('Logs',         'securehold-security-deposit-holds'), __('Logs',         'securehold-security-deposit-holds'), 'manage_woocommerce', 'securehold-logs',           array($this, 'display_logs_page'));

        // ── Admin only (manage_options) — Settings page contains Stripe credentials ──
        add_submenu_page('securehold', __('Settings',     'securehold-security-deposit-holds'), __('Settings',     'securehold-security-deposit-holds'), 'manage_options',     'securehold-settings',       array($this, 'display_settings_page'));

        add_submenu_page('securehold', __('Health Check', 'securehold-security-deposit-holds'), __('Health Check', 'securehold-security-deposit-holds'), 'manage_woocommerce', 'securehold-health',         array($this, 'display_health_check_page'));

        /**
         * Allow PRO to register additional admin menu pages.
         *
         * PRO hooks here at this position so that License appears between
         * Health Check and Extensions in the menu.
         *
         * @since 4.5.0
         */
        do_action( 'securehold_admin_menu' );

        add_submenu_page('securehold', __('Add-ons &amp; Pro', 'securehold-security-deposit-holds'), __('Add-ons &amp; Pro', 'securehold-security-deposit-holds'), 'manage_woocommerce', 'securehold-extensions', array($this, 'display_extensions_page'));

        // Hidden page (no menu entry) for deposit details — accessible to Shop Manager
        add_submenu_page(null, __('Deposit Details', 'securehold-security-deposit-holds'), '', 'manage_woocommerce', 'securehold-deposit-details', array($this, 'display_deposit_details_page'));

        // ── Admin only (manage_options) — invisible for Shop Manager ──
        add_submenu_page('securehold', __('Setup Wizard', 'securehold-security-deposit-holds'), __('Setup Wizard', 'securehold-security-deposit-holds'), 'manage_options', 'securehold-setup-wizard', array($this, 'display_setup_wizard_page'));
    }
    
    public function display_dashboard_page() { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/dashboard-page.php'; }
    public function display_setup_wizard_page() {

        if (!class_exists('Securehold_Setup_Wizard')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-setup-wizard.php';
        }
    
        $wizard = new Securehold_Setup_Wizard();
    
        // Traite les actions POST (step 5 -> 6 etc.)
        $wizard->handle_wizard_actions();
    
        // Rend la page et enfile les assets nécessaires (dont le JS du test en step 7)
        $wizard->render_wizard();
    }

    public function display_deposits_page() { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/deposits-page.php'; }
    public function display_deposit_details_page() { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/deposit-details-page.php'; }
    public function display_settings_page() { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/settings-page.php'; }
    public function display_health_check_page() { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/health-check-page.php'; }
    public function display_tools_page()       { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/tools-page.php'; }
    public function display_logs_page()        { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/logs-page.php'; }
    public function display_extensions_page()  { require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/extensions-page.php'; }
    
    private function should_show_setup_modal() {
        if (get_option('securehold_setup_completed')) return false;
        if (get_option('securehold_setup_modal_dismissed')) return false;
        if (isset($_GET['page']) && $_GET['page'] === 'securehold-setup-wizard') return false;
        return true;
    }
    
    public function render_setup_modal() {
        $screen = get_current_screen();
    
        if (!$screen || strpos($screen->id, 'securehold') === false) {
            return;
        }
    
        if (!$this->should_show_setup_modal()) {
            return;
        }
    
        require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/modal-setup-wizard.php';
    }

    public function render_deactivation_modal() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'plugins') {
            require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/modal-deactivation.php';
        }
    }
    
    public function handle_modal_action() {
        check_ajax_referer('securehold_modal_action', 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(array('message' => 'Permission denied'));
        $action = isset($_POST['modal_action']) ? sanitize_text_field(wp_unslash($_POST['modal_action'])) : '';
        if ($action === 'dismiss') {
            update_option('securehold_setup_modal_dismissed', true);
            wp_send_json_success();
        }
        wp_send_json_error();
    }

    public function handle_deactivation_feedback() {
        check_ajax_referer( 'securehold_deactivation_feedback', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied' ) );
        }

        // ── Inputs ───────────────────────────────────────────────────────────
        $plugin  = isset( $_POST['plugin'] ) ? sanitize_key( $_POST['plugin'] ) : 'free';
        $plugin  = in_array( $plugin, array( 'free', 'pro' ), true ) ? $plugin : 'free';
        $reason  = isset( $_POST['reason'] )  ? sanitize_text_field( wp_unslash( $_POST['reason'] ) )      : '';
        $details = isset( $_POST['details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['details'] ) ) : '';

        // ── Metadata ─────────────────────────────────────────────────────────
        $plugin_version = ( $plugin === 'pro' && defined( 'SECUREHOLD_PRO_VERSION' ) )
            ? SECUREHOLD_PRO_VERSION
            : SECUREHOLD_VERSION;

        $license_status = 'n/a';
        if ( $plugin === 'pro' ) {
            $license_data   = get_option( 'securehold_pro_license', array() );
            $license_status = isset( $license_data['status'] ) ? sanitize_text_field( $license_data['status'] ) : 'unknown';
        }

        $site_url  = home_url();
        $timestamp = gmdate( 'Y-m-d H:i:s' ); // UTC

        // ── Email ─────────────────────────────────────────────────────────────
        $to      = 'support@secureholdwp.com';
        $subject = sprintf(
            '[SecureHold %s] Deactivation Feedback — %s',
            strtoupper( $plugin ),
            wp_parse_url( $site_url, PHP_URL_HOST )
        );
        $body    = $this->build_deactivation_email( $plugin, $reason, $details, $plugin_version, $license_status, $site_url, $timestamp );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $mail_sent = wp_mail( $to, $subject, $body, $headers );

        // ── Local log (backup) ───────────────────────────────────────────────
        // Includes whether wp_mail() succeeded so delivery failures are surfaced.
        if ( function_exists( 'securehold_log' ) ) {
            securehold_log( 'Deactivation feedback sent', array(
                'plugin'    => $plugin,
                'version'   => $plugin_version,
                'reason'    => $reason,
                'license'   => $license_status,
                'site'      => $site_url,
                'timestamp' => $timestamp,
                'mail_sent' => $mail_sent ? 'yes' : 'no',
            ), 'info' );
        }

        wp_send_json_success( array( 'message' => __( 'Thank you for your feedback!', 'securehold-security-deposit-holds' ) ) );
    }

    /**
     * Build the plain-text body for the deactivation feedback email.
     *
     * @param string $plugin         'free' | 'pro'
     * @param string $reason         Selected reason value.
     * @param string $details        Optional textarea content.
     * @param string $plugin_version Plugin version string.
     * @param string $license_status License status ('n/a' for FREE).
     * @param string $site_url       Site home URL.
     * @param string $timestamp      UTC timestamp (Y-m-d H:i:s).
     * @return string
     */
    private function build_deactivation_email( $plugin, $reason, $details, $plugin_version, $license_status, $site_url, $timestamp ) {
        $plugin_label = ( $plugin === 'pro' ) ? 'SecureHold PRO' : 'SecureHold WP (FREE)';

        $lines = array(
            '----------------------------------------',
            'SecureHold WP — Deactivation Feedback',
            '----------------------------------------',
            '',
            'Plugin:          ' . $plugin_label,
            'Version:         ' . $plugin_version,
            'Reason:          ' . $reason,
            'Site URL:        ' . $site_url,
            'Timestamp (UTC): ' . $timestamp,
        );

        if ( $plugin === 'pro' ) {
            $lines[] = 'License Status:  ' . $license_status;
        }

        if ( ! empty( $details ) ) {
            $lines[] = '';
            $lines[] = 'Additional info:';
            $lines[] = $details;
        }

        $lines[] = '';
        $lines[] = '----------------------------------------';

        return implode( "\n", $lines );
    }
    
    public function handle_tool_actions() {
    
        if (!isset($_GET['page'])) {
            return;
        }
    
        $current_page  = sanitize_key($_GET['page']);
        $allowed_pages = array('securehold-logs', 'securehold-health');
    
        if (!in_array($current_page, $allowed_pages, true)) {
            return;
        }
    
        // Minimum capability: manage_woocommerce (Shop Manager + Admin)
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // ── ADMIN-ONLY actions (destructive / system state changes) ──

        // CLEAR LOGS — Admin only
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'clear_logs'
        ) {
            if (!current_user_can('manage_options')) {
                wp_die( esc_html__( 'You do not have permission to clear logs.', 'securehold-security-deposit-holds' ) );
            }
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));

            if (wp_verify_nonce($nonce, 'securehold_clear_logs')) {
                global $wpdb;

                $table_name = $wpdb->prefix . 'securehold_logs';

                // Use backticks to avoid issues with table name
                $wpdb->query("TRUNCATE TABLE `{$table_name}`");

                wp_safe_redirect(add_query_arg(array(
                    'page'         => $current_page,
                    'logs_cleared' => '1',
                ), admin_url('admin.php')));

                exit;
            }
        }

        // RESET SETTINGS — Admin only
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'reset_settings'
        ) {
            if (!current_user_can('manage_options')) {
                wp_die( esc_html__( 'You do not have permission to reset settings.', 'securehold-security-deposit-holds' ) );
            }
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));

            if (wp_verify_nonce($nonce, 'securehold_reset_settings')) {

                delete_option('securehold_stripe_api_key');
                delete_option('securehold_stripe_secret_key');
                delete_option('securehold_stripe_webhook_secret');
                delete_option('securehold_default_deposit_amount');
                delete_option('securehold_capture_timing');
                delete_option('securehold_setup_wizard_completed');
                delete_option('securehold_setup_modal_dismissed');
                delete_option('securehold_config_notice_dismissed');

                wp_safe_redirect(add_query_arg(array(
                    'page'           => $current_page,
                    'settings_reset' => '1',
                ), admin_url('admin.php')));

                exit;
            }
        }

        // ── Stripe API diagnostic actions (manage_options required — make real Stripe API calls) ──

        // TEST WEBHOOK (Health Check)
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_webhook'
        ) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to run Stripe diagnostics.', 'securehold-security-deposit-holds'), 403);
            }
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));

            if (wp_verify_nonce($nonce, 'securehold_test_webhook')) {

                require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-webhook-configurator.php';

                // Re-test means re-test: drop the cached verdict so the Health
                // Check recomputes against Stripe on the redirect instead of
                // replaying what it already knew.
                Securehold_Webhook_Configurator::flush_status();

                $configurator = new Securehold_Webhook_Configurator();
                $result = $configurator->test_webhook();

                $transient_key = 'securehold_webhook_test_notice_' . get_current_user_id();

                if (is_wp_error($result)) {

                    set_transient(
                        $transient_key,
                        array(
                            'success' => false,
                            'message' => $result->get_error_message(),
                        ),
                        60
                    );

                    wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                    exit;
                }

                $message = '';
                if (is_array($result) && isset($result['message'])) {
                    $message = $result['message'];
                }

                set_transient(
                    $transient_key,
                    array(
                        'success' => true,
                        'message' => $message,
                    ),
                    60
                );

                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }
        // TEST STRIPE API KEYS (Health Check)
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_api_keys'
        ) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to run Stripe API key diagnostics.', 'securehold-security-deposit-holds'), 403);
            }
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));

            if (wp_verify_nonce($nonce, 'securehold_test_api_keys')) {
        
                $success = false;
                $message = __('API keys test failed.', 'securehold-security-deposit-holds');
        
                try {
                    // Load helper if needed
                    if (!function_exists('securehold_get_stripe_keys')) {
                        require_once SECUREHOLD_PLUGIN_DIR . 'includes/helpers.php';
                    }
        
                    $keys = securehold_get_stripe_keys();
        
                    if (empty($keys['secret'])) {
                        throw new Exception(__('Stripe secret key is missing.', 'securehold-security-deposit-holds'));
                    }
        
                    if (empty($keys['publishable'])) {
                        throw new Exception(__('Stripe publishable key is missing.', 'securehold-security-deposit-holds'));
                    }
        
                    // Your plugin already has a proper Stripe bootstrap
                    if (!class_exists('Securehold_Stripe')) {
                        require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe.php';
                    }
        
                    Securehold_Stripe::init_stripe();
        
                    // Minimal real API call (server-side validation)
                    \Stripe\Balance::retrieve();
        
                    $success = true;
                    $message = __('Stripe API keys are valid.', 'securehold-security-deposit-holds');
        
                } catch (Exception $e) {
                    $success = false;
                    $message = $e->getMessage();
                }
        
                // One-time notice (consumed in health-check-page.php)
                set_transient(
                    'securehold_api_keys_test_notice_' . get_current_user_id(),
                    array(
                        'success' => (bool) $success,
                        'message' => (string) $message,
                    ),
                    60
                );
        
                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }

        // RE-TEST STRIPE CONTEXT (Health Check)
        // Drops the cached verdict and lets the Health Check recompute on the
        // redirect. Nothing is evaluated here, so the diagnosis and its display
        // stay in one place.
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_stripe_context'
        ) {
            if (!current_user_can('manage_options')) {
                wp_die(esc_html__('You do not have permission to run Stripe diagnostics.', 'securehold-security-deposit-holds'), 403);
            }

            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));

            if (wp_verify_nonce($nonce, 'securehold_test_stripe_context')) {

                if (!class_exists('Securehold_Stripe_Context') && defined('SECUREHOLD_PLUGIN_DIR')) {
                    require_once SECUREHOLD_PLUGIN_DIR . 'includes/stripe/class-securehold-wp-stripe-context.php';
                }

                if (class_exists('Securehold_Stripe_Context')) {
                    Securehold_Stripe_Context::flush();
                }

                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }

        // TEST CRON (Health Check)
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_cron'
        ) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        
            if (wp_verify_nonce($nonce, 'securehold_test_cron')) {
        
                $details = array();
                $has_errors = false;
        
                $add_detail = function ($label, $ok, $message_ok, $message_bad, $is_error = true) use (&$details, &$has_errors) {
                    $details[] = array(
                        'label'   => $label,
                        'success' => (bool) $ok,
                        'message' => $ok ? $message_ok : $message_bad,
                    );
                    if (!$ok && $is_error) {
                        $has_errors = true;
                    }
                };
        
                // 1) WP Cron enabled (warning only)
                $wp_cron_disabled = (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON);
                $add_detail(
                    __('WP Cron enabled', 'securehold-security-deposit-holds'),
                    !$wp_cron_disabled,
                    __('DISABLE_WP_CRON is not enabled', 'securehold-security-deposit-holds'),
                    __('DISABLE_WP_CRON is enabled. Scheduled events may not run unless a server cron is configured.', 'securehold-security-deposit-holds'),
                    false
                );
        
                // 2) Required SecureHold hook handler exists
                $has_handler = (has_action('securehold_trigger_scheduled_hold') !== false);
                $add_detail(
                    __('securehold_trigger_scheduled_hold handler registered', 'securehold-security-deposit-holds'),
                    $has_handler,
                    __('Callback found for scheduled holds', 'securehold-security-deposit-holds'),
                    __('No callback registered for scheduled holds', 'securehold-security-deposit-holds'),
                    true
                );
        
                // 3) Count scheduled single events
                $count = 0;
                $next = 0;
        
                $cron = _get_cron_array();
                if (is_array($cron)) {
                    foreach ($cron as $timestamp => $hooks) {
                        if (!empty($hooks['securehold_trigger_scheduled_hold'])) {
                            foreach ($hooks['securehold_trigger_scheduled_hold'] as $event) {
                                $count++;
                                if (!$next || $timestamp < $next) {
                                    $next = $timestamp;
                                }
                            }
                        }
                    }
                }
        
                $add_detail(
                    __('Scheduled hold events', 'securehold-security-deposit-holds'),
                    true,
                    /* translators: %d is the number of scheduled events */
                    sprintf(__('%d scheduled event(s) found', 'securehold-security-deposit-holds'), $count),
                    '',
                    false
                );
        
                if ($count > 0) {
                    $add_detail(
                        __('Next scheduled hold', 'securehold-security-deposit-holds'),
                        true,
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $next),
                        '',
                        false
                    );
                }
        
                set_transient(
                    'securehold_cron_test_notice_' . get_current_user_id(),
                    array(
                        'success' => !$has_errors,
                        'message' => !$has_errors
                            ? __('Scheduled tasks are correctly configured.', 'securehold-security-deposit-holds')
                            : __('Some scheduled task checks failed.', 'securehold-security-deposit-holds'),
                        'details' => $details,
                    ),
                    60
                );
        
                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }
        // TEST STRIPE SDK (Health Check)
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_stripe_sdk'
        ) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        
            if (wp_verify_nonce($nonce, 'securehold_test_stripe_sdk')) {
        
                $success = false;
                $message = __('Stripe SDK test failed.', 'securehold-security-deposit-holds');
        
                try {
                    require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-stripe-installer.php';
        
                    if (!Securehold_Stripe_Installer::is_installed()) {
                        throw new Exception(__('Stripe SDK is not installed.', 'securehold-security-deposit-holds'));
                    }
        
                    if ( ! Securehold_Stripe_Installer::load() ) {
                        throw new Exception(__('Stripe SDK (init.php) is missing.', 'securehold-security-deposit-holds'));
                    }
        
                    if (!class_exists('\Stripe\Stripe')) {
                        throw new Exception(__('Stripe SDK loaded but Stripe classes are not available.', 'securehold-security-deposit-holds'));
                    }
        
                    $version = defined('\Stripe\Stripe::VERSION') ? \Stripe\Stripe::VERSION : __('unknown', 'securehold-security-deposit-holds');
        
                    $success = true;
                    /* translators: %s is the Stripe SDK version number */
                    $message = sprintf(__('Stripe SDK is working (version: %s).', 'securehold-security-deposit-holds'), $version);
        
                } catch (Exception $e) {
                    $success = false;
                    $message = $e->getMessage();
                }
        
                $transient_key = 'securehold_stripe_sdk_test_notice_' . get_current_user_id();
        
                set_transient(
                    $transient_key,
                    array(
                        'success' => (bool) $success,
                        'message' => (string) $message,
                    ),
                    60
                );
        
                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }
        // TEST WOOCOMMERCE (Health Check)
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_woocommerce'
        ) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        
            if (wp_verify_nonce($nonce, 'securehold_test_woocommerce')) {
        
                $details = array();
                $has_errors = false;
        
                // Helper: add item
                $add_detail = function($label, $ok, $message_ok, $message_bad) use (&$details, &$has_errors) {
                    $details[] = array(
                        'label'   => (string) $label,
                        'success' => (bool) $ok,
                        'message' => (string) ($ok ? $message_ok : $message_bad),
                    );
                    if (!$ok) {
                        $has_errors = true;
                    }
                };
        
                // Helper: scan hooks for a class method callback
                $has_class_callback = function($hook_name, $class_name, $method_name = null) {
                    global $wp_filter;
        
                    if (!isset($wp_filter[$hook_name])) {
                        return false;
                    }
        
                    $hook_obj = $wp_filter[$hook_name];
        
                    // WP_Hook object
                    if (is_object($hook_obj) && isset($hook_obj->callbacks) && is_array($hook_obj->callbacks)) {
                        foreach ($hook_obj->callbacks as $priority => $callbacks) {
                            if (!is_array($callbacks)) continue;
                            foreach ($callbacks as $cb) {
                                if (!isset($cb['function'])) continue;
        
                                $fn = $cb['function'];
        
                                // method callback: array(object, 'method')
                                if (is_array($fn) && isset($fn[0], $fn[1]) && is_object($fn[0])) {
                                    if ($fn[0] instanceof $class_name) {
                                        if ($method_name === null) {
                                            return true;
                                        }
                                        if ((string) $fn[1] === (string) $method_name) {
                                            return true;
                                        }
                                    }
                                }
        
                                // static callback: array('Class', 'method')
                                if (is_array($fn) && isset($fn[0], $fn[1]) && is_string($fn[0])) {
                                    if ((string) $fn[0] === (string) $class_name) {
                                        if ($method_name === null) {
                                            return true;
                                        }
                                        if ((string) $fn[1] === (string) $method_name) {
                                            return true;
                                        }
                                    }
                                }
                            }
                        }
                    }
        
                    return false;
                };
        
                // 1) WooCommerce active
                $add_detail(
                    __('WooCommerce active', 'securehold-security-deposit-holds'),
                    ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ),
                    __('WooCommerce is active', 'securehold-security-deposit-holds'),
                    __('WooCommerce is not active', 'securehold-security-deposit-holds')
                );
        
                if ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ) {

                    // 2) Guest checkout info — customer account settings are optional.
                    $add_detail(
                        __('Customer accounts', 'securehold-security-deposit-holds'),
                        true,
                        __('Customer accounts are optional. Guest checkout is supported by SecureHold WP.', 'securehold-security-deposit-holds'),
                        ''
                    );

                    // 3) Checkout configuration checks (guest-first approach)
                    // Layer 1 registration is ours, so has_filter() on our own
                    // callback can only ever answer yes — it proved nothing about
                    // whether the gateway still consumes those filters. Real
                    // evidence is the injection layer recorded on orders that
                    // actually went through checkout. No Stripe call is made:
                    // this reads order meta only.
                    $sfu_layers = self::observed_sfu_injection_layers();

                    if ( empty( $sfu_layers ) ) {
                        $add_detail(
                            __('Checkout SFU injection', 'securehold-security-deposit-holds'),
                            true,
                            __('Layer 1 compatibility hooks registered — runtime usage not yet observed. This resolves after the first Stripe order.', 'securehold-security-deposit-holds'),
                            ''
                        );
                    } else {
                        $add_detail(
                            __('Checkout SFU injection', 'securehold-security-deposit-holds'),
                            true,
                            sprintf(
                                /* translators: %s = comma-separated injection layer names observed on recent orders */
                                __('Observed on recent orders: %s', 'securehold-security-deposit-holds'),
                                implode( ', ', $sfu_layers )
                            ),
                            ''
                        );
                    }

                    $add_detail(
                        __('Post-payment Stripe data resolution', 'securehold-security-deposit-holds'),
                        $has_class_callback('woocommerce_payment_complete', 'Securehold_Checkout', 'ensure_stripe_data_on_order'),
                        __('OK — Guest orders are resolved automatically', 'securehold-security-deposit-holds'),
                        __('Securehold_Checkout hook missing: ensure_stripe_data_on_order', 'securehold-security-deposit-holds')
                    );

                    $add_detail(
                        __('Checkout styles loaded', 'securehold-security-deposit-holds'),
                        $has_class_callback('wp_head', 'Securehold_Checkout', 'add_checkout_styles'),
                        __('OK', 'securehold-security-deposit-holds'),
                        __('Securehold_Checkout hook missing: wp_head styles', 'securehold-security-deposit-holds')
                    );
                }
        
                $transient_key = 'securehold_woocommerce_test_notice_' . get_current_user_id();
        
                set_transient(
                    $transient_key,
                    array(
                        'success' => !$has_errors,
                        'message' => !$has_errors
                            ? __('WooCommerce configuration looks good for SecureHold WP.', 'securehold-security-deposit-holds')
                            : __('Some WooCommerce settings are not configured for SecureHold WP.', 'securehold-security-deposit-holds'),
                        'details' => $details,
                    ),
                    60
                );
        
                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }
        // TEST STRIPE GATEWAY (Health Check)
        if (
            isset($_GET['action'], $_GET['_wpnonce']) &&
            $_GET['action'] === 'securehold_test_stripe_gateway'
        ) {
            $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
        
            if (wp_verify_nonce($nonce, 'securehold_test_stripe_gateway')) {
        
                $details = array();
                $has_errors = false;
        
                $add_detail = function($label, $ok, $message_ok, $message_bad, $is_error = true) use (&$details, &$has_errors) {
                    $details[] = array(
                        'label'   => (string) $label,
                        'success' => (bool) $ok,
                        'message' => (string) ($ok ? $message_ok : $message_bad),
                        'is_error'=> (bool) $is_error,
                    );
                    if (!$ok && $is_error) {
                        $has_errors = true;
                    }
                };
        
                // Force WooCommerce to load gateway class files before checking class existence.
                // WooCommerce lazily initializes payment gateways; class_exists( 'WC_Gateway_Stripe' )
                // returns false until payment_gateways() is called. Guards prevent fatal error if WooCommerce is absent.
                if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'payment_gateways' ) ) {
                    WC()->payment_gateways()->payment_gateways();
                }

                // 1) Plugin detected
                $add_detail(
                    __('Stripe gateway plugin detected', 'securehold-security-deposit-holds'),
                    class_exists('WC_Gateway_Stripe'),
                    __('WC_Gateway_Stripe class is available', 'securehold-security-deposit-holds'),
                    __('Stripe gateway plugin not detected (WC_Gateway_Stripe missing)', 'securehold-security-deposit-holds'),
                    true
                );
        
                $stripe_gateway = null;
                $stripe_enabled = false;
        
                // 2) Gateway exists in WooCommerce and is enabled
                if (function_exists('WC') && WC() && method_exists(WC(), 'payment_gateways')) {
                    $pg = WC()->payment_gateways();
                    if ($pg && method_exists($pg, 'payment_gateways')) {
                        $gateways = $pg->payment_gateways();
        
                        foreach ($gateways as $gw) {
                            if (!is_object($gw) || empty($gw->id)) continue;
        
                            // Most common IDs
                            if ((string) $gw->id === 'stripe' || (string) $gw->id === 'woocommerce_stripe') {
                                $stripe_gateway = $gw;
                                $stripe_enabled = (isset($gw->enabled) && $gw->enabled === 'yes');
                                break;
                            }
                        }
        
                        // Fallback: detect any gateway id containing "stripe"
                        if (!$stripe_gateway) {
                            foreach ($gateways as $gw) {
                                if (!is_object($gw) || empty($gw->id)) continue;
                                if (stripos((string) $gw->id, 'stripe') !== false) {
                                    $stripe_gateway = $gw;
                                    $stripe_enabled = (isset($gw->enabled) && $gw->enabled === 'yes');
                                    break;
                                }
                            }
                        }
                    }
                }
        
                $add_detail(
                    __('Stripe gateway found in WooCommerce payment methods', 'securehold-security-deposit-holds'),
                    (bool) $stripe_gateway,
                    __('Stripe gateway is registered in WooCommerce', 'securehold-security-deposit-holds'),
                    __('Stripe gateway not found in WooCommerce payment methods list', 'securehold-security-deposit-holds'),
                    true
                );
        
                $add_detail(
                    __('Stripe gateway is enabled', 'securehold-security-deposit-holds'),
                    (bool) $stripe_enabled,
                    __('Stripe gateway is enabled in WooCommerce', 'securehold-security-deposit-holds'),
                    __('Stripe gateway is installed but disabled in WooCommerce settings', 'securehold-security-deposit-holds'),
                    true
                );
        
                // 3) Supports needed features (important for your deposit flow)
                if ($stripe_gateway && method_exists($stripe_gateway, 'supports')) {
        
                    $supports_tokenization = (bool) $stripe_gateway->supports('tokenization');
                    $supports_add_pm       = (bool) $stripe_gateway->supports('add_payment_method');
        
                    $add_detail(
                        __('Tokenization support', 'securehold-security-deposit-holds'),
                        $supports_tokenization,
                        __('Gateway supports tokenization', 'securehold-security-deposit-holds'),
                        __('Gateway does not support tokenization (required to save payment method)', 'securehold-security-deposit-holds'),
                        true
                    );
        
                    $add_detail(
                        __('Add payment method support', 'securehold-security-deposit-holds'),
                        $supports_add_pm,
                        __('Gateway supports adding payment methods', 'securehold-security-deposit-holds'),
                        __('Gateway does not support adding payment methods', 'securehold-security-deposit-holds'),
                        true
                    );
                } else {
                    $add_detail(
                        __('Supports checks', 'securehold-security-deposit-holds'),
                        false,
                        __('OK', 'securehold-security-deposit-holds'),
                        __('Unable to verify supports() on the Stripe gateway instance', 'securehold-security-deposit-holds'),
                        false
                    );
                }
        
                // 4) Mode consistency (warning only)
                $stripe_settings = get_option('woocommerce_stripe_settings', array());
                $testmode = null;
        
                if (is_array($stripe_settings) && isset($stripe_settings['testmode'])) {
                    $testmode = ($stripe_settings['testmode'] === 'yes');
                }
        
                if ($testmode !== null) {
                    $add_detail(
                        __('Stripe mode configured', 'securehold-security-deposit-holds'),
                        true,
                        $testmode ? __('Stripe is in test mode', 'securehold-security-deposit-holds') : __('Stripe is in live mode', 'securehold-security-deposit-holds'),
                        '',
                        false
                    );
                } else {
                    $add_detail(
                        __('Stripe mode configured', 'securehold-security-deposit-holds'),
                        false,
                        '',
                        __('Unable to read WooCommerce Stripe testmode setting', 'securehold-security-deposit-holds'),
                        false
                    );
                }
        
                // Key hint (warning only)
                if (!function_exists('securehold_get_stripe_keys')) {
                    require_once SECUREHOLD_PLUGIN_DIR . 'includes/helpers.php';
                }
                $keys = securehold_get_stripe_keys();
        
                $secret = isset($keys['secret']) ? (string) $keys['secret'] : '';
                $key_is_test = (stripos($secret, 'sk_test_') === 0);
                $key_is_live = (stripos($secret, 'sk_live_') === 0);
        
                if ($testmode !== null && ($key_is_test || $key_is_live)) {
                    $coherent = ($testmode && $key_is_test) || (!$testmode && $key_is_live);
        
                    $add_detail(
                        __('Mode and API keys consistency', 'securehold-security-deposit-holds'),
                        $coherent,
                        __('Stripe mode and API keys look consistent', 'securehold-security-deposit-holds'),
                        __('Stripe mode and API keys may be mismatched (test versus live)', 'securehold-security-deposit-holds'),
                        false
                    );
                }
        
                // Store notice
                set_transient(
                    'securehold_stripe_gateway_test_notice_' . get_current_user_id(),
                    array(
                        'success' => !$has_errors,
                        'message' => !$has_errors
                            ? __('Stripe Gateway looks correctly configured for SecureHold WP.', 'securehold-security-deposit-holds')
                            : __('Stripe Gateway has configuration issues that may prevent deposits from working.', 'securehold-security-deposit-holds'),
                        'details' => $details,
                    ),
                    60
                );
        
                wp_safe_redirect(admin_url('admin.php?page=securehold-health'));
                exit;
            }
        }

    }
    
    public static function render_page_header($title, $subtitle, $icon, $action_html = '') {
        ?>
        <div class="sh-page-header" style="background: linear-gradient(135deg, var(--sh-primary, #2563eb) 0%, #1e40af 100%); color: white; padding: 1.5rem; border-radius: 0.75rem; margin-bottom: 2rem; position: relative; overflow: hidden; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
            
            <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; z-index: 1; pointer-events: none;"></div>
            <div style="position: absolute; bottom: -30px; left: -30px; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%; z-index: 1; pointer-events: none;"></div>
            
            <div style="display: flex; align-items: center; gap: 1rem; position: relative; z-index: 2;">
                <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; backdrop-filter: blur(5px); flex-shrink: 0;">
                    <span class="dashicons <?php echo esc_attr($icon); ?>" style="font-size: 20px; width: 20px; height: 20px; color: white;"></span>
                </div>
                
                <div>
                    <h1 class="sh-page-title" style="color: white; margin: 0 0 0.25rem 0; font-size: 1.5rem; line-height: 1.2; display: block;">
                        <?php echo esc_html($title); ?>
                    </h1>
                    
                    <p style="color: rgba(255,255,255,0.8); margin: 0; font-size: 0.9rem; line-height: 1.4;">
                        <?php echo esc_html($subtitle); ?>
                    </p>
                </div>
            </div>
            
            <?php if (!empty($action_html)) : ?>
            <div style="position: relative; z-index: 2; padding-left: 1rem;">
                <?php echo wp_kses_post( $action_html ); ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        /**
         * Fires immediately after the SecureHold page header is rendered.
         *
         * Use this hook to output inline notices or banners that should appear
         * directly below the page header — avoiding the admin_notices flash that
         * occurs when content is rendered before the page callback fires.
         *
         * @since 5.7.0
         */
        do_action( 'securehold_after_page_header' );
    }

    public function ajax_test_config() {
        check_ajax_referer('securehold_test_config', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json(array(
                'success' => false,
                'steps' => array(
                    array(
                        'name' => 'Permissions',
                        'status' => 'error',
                        'message' => 'You do not have permission to run this test.'
                    )
                )
            ));
        }
    
        if (!class_exists('Securehold_Setup_Wizard')) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-setup-wizard.php';
        }
    
        $wizard = new Securehold_Setup_Wizard();
        $wizard->test_stripe_configuration();
    }
    
    /**
     * admin_post_securehold_export_support_bundle
     * Generates and streams a sanitized JSON diagnostic bundle.
     */
    public function handle_export_support_bundle() {
        check_admin_referer( 'securehold_export_support_bundle_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'securehold-security-deposit-holds' ) );
        }

        if ( ! class_exists( 'Securehold_Support_Bundle' ) && defined( 'SECUREHOLD_PLUGIN_DIR' ) ) {
            require_once SECUREHOLD_PLUGIN_DIR . 'includes/class-securehold-wp-support-bundle.php';
        }

        if ( ! class_exists( 'Securehold_Support_Bundle' ) ) {
            wp_die( esc_html__( 'The support bundle builder is unavailable.', 'securehold-security-deposit-holds' ) );
        }

        // Assembly, allowlisting and redaction all live in the builder, so what
        // support receives cannot drift from what the builder promises.
        $builder = new Securehold_Support_Bundle();
        $bundle  = $builder->build();

        $json     = wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $filename = 'securehold-support-bundle-' . gmdate( 'Ymd-His' ) . '.json';

        // ── Stream download ───────────────────────────────────────────────────
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $json ) );

        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
    public function handle_reset_wizard() {

        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'securehold-security-deposit-holds' ) );
        }
    
        check_admin_referer('securehold_reset_wizard_nonce');
    
        // Reset wizard completion flags
        delete_option('securehold_setup_completed');
        delete_option('securehold_setup_wizard_completed');
    
        // Reset dismissed notices/modals (so the user can see guidance again)
        delete_option('securehold_setup_dismissed');
        delete_option('securehold_setup_modal_dismissed');
        delete_option('securehold_config_notice_dismissed');
    
        // Optionally clear Stripe credentials + webhook secret
        $clear_keys = !empty($_POST['securehold_clear_keys']);
    
        if ($clear_keys) {
            delete_option('securehold_stripe_test_publishable_key');
            delete_option('securehold_stripe_test_secret_key');
            delete_option('securehold_stripe_live_publishable_key');
            delete_option('securehold_stripe_live_secret_key');
    
            // Webhook secret used in settings page
            delete_option('securehold_webhook_secret');
    
            // If you still store these legacy keys anywhere, clear them too
            delete_option('securehold_stripe_api_key');
            delete_option('securehold_stripe_secret_key');
            delete_option('securehold_stripe_webhook_secret');
    
            // Optional: reset mode to default
            delete_option('securehold_stripe_mode');
        }
    
        // Redirect to wizard step 1
        wp_safe_redirect(admin_url('admin.php?page=securehold-setup-wizard&step=1'));
        exit;
    }


    /**
     * Injection layers actually recorded on recent orders.
     *
     * Layer 1 registration cannot be verified by asking WordPress whether our own
     * filters are attached — they always are. What can be verified is which layer
     * ended up doing the work, which Securehold_Checkout records on every order
     * as _securehold_sfu_injection_layer.
     *
     * Reads order meta through the WooCommerce CRUD, so it serves HPOS and post
     * storage alike, and issues no Stripe call: this runs on a Health Check page
     * the merchant may refresh freely.
     *
     * @since 3.4.4
     * @return string[] Distinct layer names, empty when no order has been processed yet.
     */
    private static function observed_sfu_injection_layers() {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return array();
        }

        $order_ids = wc_get_orders( array(
            'limit'   => 20,
            'orderby' => 'date',
            'order'   => 'DESC',
            'return'  => 'ids',
            'status'  => array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ),
        ) );

        if ( empty( $order_ids ) || ! is_array( $order_ids ) ) {
            return array();
        }

        $layers = array();

        foreach ( $order_ids as $order_id ) {
            $order = wc_get_order( $order_id );

            if ( ! $order ) {
                continue;
            }

            $layer = (string) $order->get_meta( '_securehold_sfu_injection_layer', true );

            if ( $layer !== '' ) {
                $layers[ $layer ] = true;
            }
        }

        return array_keys( $layers );
    }

}
