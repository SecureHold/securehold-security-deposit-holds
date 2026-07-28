<?php
/**
 * Deposits List Page
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! current_user_can( 'manage_woocommerce' ) ) {
    wp_die( esc_html__( 'You do not have permission to view this page.', 'securehold-security-deposit-holds' ) );
}

global $wpdb;
$table_name = $wpdb->prefix . 'securehold_holds';

if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) !== $table_name ) {
    echo '<div class="notice notice-error"><p>' . esc_html__( 'Database table not found. Please reinstall the plugin.', 'securehold-security-deposit-holds' ) . '</p></div>';
    return;
}

// Filter, search, and sort parameters — all sourced from GET and sanitized.

$per_page = 20;
$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$offset   = ( $page - 1 ) * $per_page;

// Search
$search = isset( $_GET['sh_search'] ) ? sanitize_text_field( wp_unslash( $_GET['sh_search'] ) ) : '';

// Filters
$filter_status   = isset( $_GET['sh_status'] )   ? sanitize_key( $_GET['sh_status'] )   : '';
$filter_strategy = isset( $_GET['sh_strategy'] ) ? sanitize_key( $_GET['sh_strategy'] ) : '';
$filter_from     = isset( $_GET['sh_from'] )     ? sanitize_text_field( wp_unslash( $_GET['sh_from'] ) ) : '';
$filter_to       = isset( $_GET['sh_to'] )       ? sanitize_text_field( wp_unslash( $_GET['sh_to'] ) )   : '';

// Sorting
$allowed_orderby = array( 'order_id', 'amount', 'created_at', 'status' );
$orderby         = isset( $_GET['sh_orderby'] ) && in_array( sanitize_key( wp_unslash( $_GET['sh_orderby'] ) ), $allowed_orderby, true )
    ? sanitize_key( wp_unslash( $_GET['sh_orderby'] ) )
    : 'created_at';
$order_dir       = isset( $_GET['sh_order'] ) && strtolower( sanitize_key( wp_unslash( $_GET['sh_order'] ) ) ) === 'asc' ? 'ASC' : 'DESC';

// Valid statuses and strategies for whitelist
$valid_statuses   = array( 'authorized', 'pending', 'pending_manual', 'scheduled', 'failed', 'released', 'captured' );
$valid_strategies = array_keys( Securehold_Admin::get_strategy_labels() );

// Build the SQL query from validated parameters.

$where_clauses = array();
$where_values  = array();

// Status filter
if ( '' !== $filter_status && in_array( $filter_status, $valid_statuses, true ) ) {
    $where_clauses[] = 'h.status = %s';
    $where_values[]  = $filter_status;
}

// Date range filters
if ( '' !== $filter_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_from ) ) {
    $where_clauses[] = 'h.created_at >= %s';
    $where_values[]  = $filter_from . ' 00:00:00';
}
if ( '' !== $filter_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $filter_to ) ) {
    $where_clauses[] = 'h.created_at <= %s';
    $where_values[]  = $filter_to . ' 23:59:59';
}

// Search — join WC order meta for billing info, search across multiple fields
$search_join  = '';
$search_where = '';

if ( '' !== $search ) {
    $like = '%' . $wpdb->esc_like( $search ) . '%';

    // Determine if HPOS is active
    $hpos_active = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
        && method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
        && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    if ( $hpos_active ) {
        // HPOS: orders are in wc_orders + wc_order_addresses
        $search_join = " LEFT JOIN {$wpdb->prefix}wc_order_addresses oa ON oa.order_id = h.order_id AND oa.address_type = 'billing'";
        $search_where = $wpdb->prepare(
            " AND (
                CAST(h.order_id AS CHAR) LIKE %s
                OR h.intent_id LIKE %s
                OR oa.first_name LIKE %s
                OR oa.last_name LIKE %s
                OR oa.email LIKE %s
            )",
            $like, $like, $like, $like, $like
        );
    } else {
        // Legacy post meta
        $search_join = " LEFT JOIN {$wpdb->postmeta} pm_fn ON pm_fn.post_id = h.order_id AND pm_fn.meta_key = '_billing_first_name'"
            . " LEFT JOIN {$wpdb->postmeta} pm_ln ON pm_ln.post_id = h.order_id AND pm_ln.meta_key = '_billing_last_name'"
            . " LEFT JOIN {$wpdb->postmeta} pm_em ON pm_em.post_id = h.order_id AND pm_em.meta_key = '_billing_email'";
        $search_where = $wpdb->prepare(
            " AND (
                CAST(h.order_id AS CHAR) LIKE %s
                OR h.intent_id LIKE %s
                OR pm_fn.meta_value LIKE %s
                OR pm_ln.meta_value LIKE %s
                OR pm_em.meta_value LIKE %s
            )",
            $like, $like, $like, $like, $like
        );
    }
}

// Strategy filter — deterministic match on order meta '_securehold_timing_strategy'.
// Since v5.0.0: NO legacy LIKE fallback on notes. Legacy deposits without the meta
// will not appear when a Strategy filter is active. Use the migration tool in
// Tools > Advanced to backfill missing metadata.
$strategy_join  = '';
$strategy_where = '';
if ( '' !== $filter_strategy && in_array( $filter_strategy, $valid_strategies, true ) ) {

    // HPOS-aware INNER JOIN on order meta (INNER = only deposits with the meta)
    if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
        && method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
        && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
    ) {
        $strategy_join = " INNER JOIN {$wpdb->prefix}wc_orders_meta om_st ON om_st.order_id = h.order_id AND om_st.meta_key = '_securehold_timing_strategy'";
    } else {
        $strategy_join = " INNER JOIN {$wpdb->postmeta} om_st ON om_st.post_id = h.order_id AND om_st.meta_key = '_securehold_timing_strategy'";
    }

    $strategy_where = $wpdb->prepare( " AND om_st.meta_value = %s", $filter_strategy );

    if ( function_exists( 'securehold_log' ) ) {
        securehold_log( 'Deposits page: Strategy filter applied', array(
            'filter_strategy' => $filter_strategy,
        ), 'debug' );
    }
}

// Detect legacy deposits missing strategy metadata (for UX warning).
$legacy_untagged_count = 0;
if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
    && method_exists( 'Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' )
    && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
) {
    $legacy_untagged_count = (int) $wpdb->get_var(
        "SELECT COUNT( DISTINCT h.id )
         FROM {$table_name} h
         LEFT JOIN {$wpdb->prefix}wc_orders_meta om_st
             ON om_st.order_id = h.order_id AND om_st.meta_key = '_securehold_timing_strategy'
         WHERE om_st.meta_value IS NULL"
    );
} else {
    $legacy_untagged_count = (int) $wpdb->get_var(
        "SELECT COUNT( DISTINCT h.id )
         FROM {$table_name} h
         LEFT JOIN {$wpdb->postmeta} om_st
             ON om_st.post_id = h.order_id AND om_st.meta_key = '_securehold_timing_strategy'
         WHERE om_st.meta_value IS NULL"
    );
}

// Assemble WHERE
$where_sql = '';
if ( ! empty( $where_clauses ) ) {
    $where_sql = ' AND ' . implode( ' AND ', $where_clauses );
}

// Whitelist orderby column (already validated above but prefix with table alias)
$orderby_col = 'h.' . $orderby;

$count_sql = "SELECT COUNT( DISTINCT h.id ) FROM {$table_name} h {$search_join} {$strategy_join} WHERE 1=1 {$where_sql} {$search_where} {$strategy_where}";
if ( ! empty( $where_values ) ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query assembled from whitelisted identifiers; user-supplied values are bound via prepare().
    $total_items = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$where_values ) );
} else {
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query uses only plugin-controlled table name and whitelist-validated column identifiers; no user input.
    $total_items = (int) $wpdb->get_var( $count_sql );
}
$total_pages = (int) ceil( $total_items / $per_page );

$data_sql = "SELECT DISTINCT h.* FROM {$table_name} h {$search_join} {$strategy_join} WHERE 1=1 {$where_sql} {$search_where} {$strategy_where}"
    . " ORDER BY {$orderby_col} {$order_dir} LIMIT %d OFFSET %d";

$query_values = array_merge( $where_values, array( $per_page, $offset ) );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query assembled from whitelisted identifiers; all user-supplied values are bound via prepare().
$deposits     = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$query_values ) );

// Per-deposit currency from DB row; WC default as fallback for aggregate display.
// Legacy: was get_option('securehold_stripe_currency', 'USD') — wrong when WC is EUR/GBP.
$currency_setting = get_woocommerce_currency();
$currency_symbol  = get_woocommerce_currency_symbol();

// Helper: build URL preserving all current filters
$base_url = admin_url( 'admin.php?page=securehold-deposits' );

/**
 * Build a filter URL preserving all current GET params.
 *
 * @param array $overrides Key/value pairs to override.
 * @return string
 */
function securehold_deposits_filter_url( $overrides = array() ) {
    $params = array(
        'page'        => 'securehold-deposits',
        'sh_search'   => isset( $_GET['sh_search'] )   ? sanitize_text_field( wp_unslash( $_GET['sh_search'] ) )   : '',
        'sh_status'   => isset( $_GET['sh_status'] )    ? sanitize_key( $_GET['sh_status'] )    : '',
        'sh_strategy' => isset( $_GET['sh_strategy'] )  ? sanitize_key( $_GET['sh_strategy'] )  : '',
        'sh_from'     => isset( $_GET['sh_from'] )      ? sanitize_text_field( $_GET['sh_from'] ) : '',
        'sh_to'       => isset( $_GET['sh_to'] )        ? sanitize_text_field( $_GET['sh_to'] )   : '',
        'sh_orderby'  => isset( $_GET['sh_orderby'] )   ? sanitize_key( $_GET['sh_orderby'] )   : 'created_at',
        'sh_order'    => isset( $_GET['sh_order'] )     ? sanitize_key( $_GET['sh_order'] )     : 'desc',
    );

    $params = array_merge( $params, $overrides );

    // Remove empty values to keep URL clean
    $params = array_filter( $params, function( $v, $k ) {
        if ( $k === 'page' ) return true;
        return '' !== $v;
    }, ARRAY_FILTER_USE_BOTH );

    return add_query_arg( $params, admin_url( 'admin.php' ) );
}

/**
 * Render a sortable column header.
 *
 * @param string $col       Column key.
 * @param string $label     Display label.
 * @param string $current   Current orderby.
 * @param string $current_dir Current order direction.
 */
function securehold_sort_header( $col, $label, $current, $current_dir ) {
    $is_active  = ( $col === $current );
    $next_dir   = ( $is_active && strtolower( $current_dir ) === 'asc' ) ? 'desc' : 'asc';
    $url        = securehold_deposits_filter_url( array( 'sh_orderby' => $col, 'sh_order' => $next_dir, 'paged' => 1 ) );
    $arrow      = '';
    if ( $is_active ) {
        $arrow = strtolower( $current_dir ) === 'asc' ? ' ▲' : ' ▼';
    }
    echo '<a href="' . esc_url( $url ) . '" class="sh-deposits-sort-link' . ( $is_active ? ' sh-deposits-sort-active' : '' ) . '">';
    echo esc_html( $label ) . esc_html( $arrow );
    echo '</a>';
}

$has_active_filters = ( '' !== $search || '' !== $filter_status || '' !== $filter_strategy || '' !== $filter_from || '' !== $filter_to );

?>

<div class="wrap securehold-wrapper">

    <?php
    Securehold_Admin::render_page_header(
        get_admin_page_title(),
        __( 'View and manage all security deposits.', 'securehold-security-deposit-holds' ),
        'dashicons-list-view'
    );
    ?>

    <?php
    // Shared stats overview — uses local DB queries on wp_securehold_holds.
    // Works identically in FREE and PRO; PRO may augment via its own filters.
    if ( file_exists( SECUREHOLD_PLUGIN_DIR . 'admin/views/partials/stats-overview.php' ) ) {
        require SECUREHOLD_PLUGIN_DIR . 'admin/views/partials/stats-overview.php';
    }
    ?>

    <!-- ── Filters & Search ── -->
    <div class="sh-card sh-deposits-filters">
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
            <input type="hidden" name="page" value="securehold-deposits">

            <div class="sh-deposits-filters-grid">

                <!-- Search -->
                <div class="sh-deposits-filter-group sh-deposits-filter-search">
                    <label class="sh-deposits-filter-label" for="sh-filter-search">
                        <?php esc_html_e( 'Search', 'securehold-security-deposit-holds' ); ?>
                    </label>
                    <div class="sh-deposits-input-wrap">
                        <span class="dashicons dashicons-search sh-deposits-input-icon"></span>
                        <input type="text"
                               id="sh-filter-search"
                               name="sh_search"
                               value="<?php echo esc_attr( $search ); ?>"
                               placeholder="<?php esc_attr_e( 'Order ID, Customer, Email or Intent ID', 'securehold-security-deposit-holds' ); ?>"
                               class="sh-deposits-input">
                    </div>
                </div>

                <!-- Status -->
                <div class="sh-deposits-filter-group">
                    <label class="sh-deposits-filter-label" for="sh-filter-status">
                        <?php esc_html_e( 'Status', 'securehold-security-deposit-holds' ); ?>
                    </label>
                    <select id="sh-filter-status" name="sh_status" class="sh-deposits-select">
                        <option value=""><?php esc_html_e( 'All', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="authorized" <?php selected( $filter_status, 'authorized' ); ?>><?php esc_html_e( 'Authorized', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="pending" <?php selected( $filter_status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="pending_manual" <?php selected( $filter_status, 'pending_manual' ); ?>><?php esc_html_e( 'Pending Manual', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="scheduled" <?php selected( $filter_status, 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="failed" <?php selected( $filter_status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="released" <?php selected( $filter_status, 'released' ); ?>><?php esc_html_e( 'Released', 'securehold-security-deposit-holds' ); ?></option>
                        <option value="captured" <?php selected( $filter_status, 'captured' ); ?>><?php esc_html_e( 'Captured', 'securehold-security-deposit-holds' ); ?></option>
                    </select>
                </div>

                <!-- Strategy -->
                <div class="sh-deposits-filter-group">
                    <label class="sh-deposits-filter-label" for="sh-filter-strategy">
                        <?php esc_html_e( 'Strategy', 'securehold-security-deposit-holds' ); ?>
                    </label>
                    <select id="sh-filter-strategy" name="sh_strategy" class="sh-deposits-select">
                        <option value=""><?php esc_html_e( 'All', 'securehold-security-deposit-holds' ); ?></option>
                        <?php foreach ( Securehold_Admin::get_strategy_labels() as $strat_value => $strat_label ) : ?>
                        <option value="<?php echo esc_attr( $strat_value ); ?>" <?php selected( $filter_strategy, $strat_value ); ?>><?php echo esc_html( $strat_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Date From -->
                <div class="sh-deposits-filter-group">
                    <label class="sh-deposits-filter-label" for="sh-filter-from">
                        <?php esc_html_e( 'From', 'securehold-security-deposit-holds' ); ?>
                    </label>
                    <input type="date"
                           id="sh-filter-from"
                           name="sh_from"
                           value="<?php echo esc_attr( $filter_from ); ?>"
                           class="sh-deposits-input">
                </div>

                <!-- Date To -->
                <div class="sh-deposits-filter-group">
                    <label class="sh-deposits-filter-label" for="sh-filter-to">
                        <?php esc_html_e( 'To', 'securehold-security-deposit-holds' ); ?>
                    </label>
                    <input type="date"
                           id="sh-filter-to"
                           name="sh_to"
                           value="<?php echo esc_attr( $filter_to ); ?>"
                           class="sh-deposits-input">
                </div>

            </div>

            <!-- Actions row -->
            <div class="sh-deposits-filters-actions">
                <button type="submit" class="sh-btn sh-btn-primary sh-btn-sm">
                    <span class="dashicons dashicons-filter"></span>
                    <?php esc_html_e( 'Apply Filters', 'securehold-security-deposit-holds' ); ?>
                </button>
                <?php if ( $has_active_filters ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-deposits' ) ); ?>" class="sh-btn sh-btn-secondary sh-btn-sm">
                    <span class="dashicons dashicons-dismiss"></span>
                    <?php esc_html_e( 'Clear Filters', 'securehold-security-deposit-holds' ); ?>
                </a>
                <?php endif; ?>
                <span class="sh-deposits-result-count">
                    <?php
                    printf(
                        /* translators: %s = number of deposits */
                        esc_html( _n( '%s deposit', '%s deposits', $total_items, 'securehold-security-deposit-holds' ) ),
                        '<strong>' . esc_html( number_format_i18n( $total_items ) ) . '</strong>'
                    );
                    ?>
                </span>
            </div>

        </form>
    </div>

    <?php if ( $legacy_untagged_count > 0 && '' !== $filter_strategy ) : ?>
    <div class="notice notice-warning" style="margin: 0 0 1rem; padding: 0.75rem 1rem; border-left-color: #d97706;">
        <p style="margin: 0;">
            <span class="dashicons dashicons-info-outline" style="color: #d97706; vertical-align: middle;"></span>
            <?php
            echo wp_kses_post( sprintf(
                /* translators: %1$d = number of legacy deposits, %2$s = opening link tag, %3$s = closing link tag */
                _n(
                    '%1$d older deposit is missing Strategy metadata and is excluded from this filter. %2$sRun the migration tool%3$s to include it.',
                    '%1$d older deposits are missing Strategy metadata and are excluded from this filter. %2$sRun the migration tool%3$s to include them.',
                    $legacy_untagged_count,
                    'securehold-security-deposit-holds'
                ),
                absint( $legacy_untagged_count ),
                '<a href="' . esc_url( admin_url( 'admin.php?page=securehold-tools#advanced' ) ) . '">',
                '</a>'
            ) );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <!-- ── Deposits Table ── -->
    <div class="sh-card sh-deposits-table-card">

        <table class="widefat fixed striped sh-deposits-table">
            <thead>
                <tr>
                    <th class="sh-deposits-th"><?php securehold_sort_header( 'order_id', __( 'Order ID', 'securehold-security-deposit-holds' ), $orderby, $order_dir ); ?></th>
                    <th class="sh-deposits-th"><?php esc_html_e( 'Customer', 'securehold-security-deposit-holds' ); ?></th>
                    <th class="sh-deposits-th"><?php securehold_sort_header( 'amount', __( 'Amount', 'securehold-security-deposit-holds' ), $orderby, $order_dir ); ?></th>
                    <th class="sh-deposits-th"><?php securehold_sort_header( 'status', __( 'Status', 'securehold-security-deposit-holds' ), $orderby, $order_dir ); ?></th>
                    <th class="sh-deposits-th"><?php securehold_sort_header( 'created_at', __( 'Created', 'securehold-security-deposit-holds' ), $orderby, $order_dir ); ?></th>
                    <th class="sh-deposits-th"><?php esc_html_e( 'Expires', 'securehold-security-deposit-holds' ); ?></th>
                    <th class="sh-deposits-th sh-deposits-th-actions"><?php esc_html_e( 'Actions', 'securehold-security-deposit-holds' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $deposits ) ) : ?>
                    <?php foreach ( $deposits as $deposit ) : ?>
                        <?php
                        $order = wc_get_order( $deposit->order_id );

                        $status_class = Securehold_Admin::get_status_badge_class( $deposit->status );

                        // Amounts logic
                        $total    = floatval( $deposit->amount );
                        $captured = isset( $deposit->captured_amount ) ? floatval( $deposit->captured_amount ) : 0;
                        $remaining = $total - $captured;

                        // Check for valid intent ID
                        $intent_id = ! empty( $deposit->intent_id ) ? $deposit->intent_id : '';
                        ?>

                        <tr>
                            <td class="sh-deposits-td" data-colname="<?php esc_attr_e( 'Order ID', 'securehold-security-deposit-holds' ); ?>">
                                <div class="sh-deposits-value">
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-deposit-details&deposit_id=' . $deposit->id ) ); ?>" class="sh-deposits-order-link">
                                        #<?php echo esc_html( $deposit->order_id ); ?>
                                    </a>
                                    <?php if ( empty( $intent_id ) ) : ?>
                                        <span class="sh-deposits-missing-id"><?php esc_html_e( 'Missing ID', 'securehold-security-deposit-holds' ); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="sh-deposits-td" data-colname="<?php esc_attr_e( 'Customer', 'securehold-security-deposit-holds' ); ?>">
                                <div class="sh-deposits-value">
                                    <?php
                                    if ( $order ) {
                                        echo esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
                                    } else {
                                        echo '<span class="sh-deposits-deleted-order">' . esc_html__( '(Deleted Order)', 'securehold-security-deposit-holds' ) . '</span>';
                                    }
                                    ?>
                                </div>
                            </td>
                            <td class="sh-deposits-td sh-deposits-td-amount" data-colname="<?php esc_attr_e( 'Amount', 'securehold-security-deposit-holds' ); ?>">
                                <div class="sh-deposits-value">
                                    <?php
                                    $dep_currency = strtoupper( $deposit->currency ?: $currency_setting );
                                    $dep_price_args = array( 'currency' => $dep_currency );
                                    if ( $deposit->status === 'scheduled' ) {
                                        echo '<span class="sh-deposits-amount-pending" title="' . esc_attr__( 'Amount will be calculated when the hold is created.', 'securehold-security-deposit-holds' ) . '">' . esc_html__( 'Pending', 'securehold-security-deposit-holds' ) . '</span>';
                                    } elseif ( $deposit->status === 'failed' ) {
                                        if ( $total == 0 ) {
                                            echo '<span class="sh-deposits-amount-na" title="' . esc_attr__( 'Process failed before amount calculation', 'securehold-security-deposit-holds' ) . '">' . esc_html__( 'Not calculated', 'securehold-security-deposit-holds' ) . '</span>';
                                        } else {
                                            echo '<span class="sh-deposits-amount-failed">' . wp_kses_post( wc_price( $total, $dep_price_args ) ) . '</span>';
                                        }
                                    } elseif ( $deposit->status === 'captured' && $captured > 0 ) {
                                        echo '<span class="sh-deposits-amount-captured">' . wp_kses_post( wc_price( $captured, $dep_price_args ) ) . '</span>';
                                        echo '<span class="sh-deposits-amount-total"> / ' . wp_kses_post( wc_price( $total, $dep_price_args ) ) . '</span>';
                                    } else {
                                        echo wp_kses_post( wc_price( $total, $dep_price_args ) );
                                    }
                                    ?>
                                </div>
                            </td>
                            <td class="sh-deposits-td" data-colname="<?php esc_attr_e( 'Status', 'securehold-security-deposit-holds' ); ?>">
                                <div class="sh-deposits-value">
                                    <?php Securehold_Admin::render_status_badge( $deposit->status, true, $deposit->order_id, isset( $deposit->notes ) ? $deposit->notes : '' ); ?>
                                </div>
                            </td>
                            <td class="sh-deposits-td sh-deposits-td-date" data-colname="<?php esc_attr_e( 'Created', 'securehold-security-deposit-holds' ); ?>">
                                <div class="sh-deposits-value">
                                    <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deposit->created_at ) ) ); ?>
                                </div>
                            </td>
                            <td class="sh-deposits-td sh-deposits-td-date" data-colname="<?php esc_attr_e( 'Expires', 'securehold-security-deposit-holds' ); ?>">
                                <div class="sh-deposits-value">
                                    <?php
                                    if ( $deposit->status === 'scheduled' ) {
                                        $next_run = $order ? $order->get_meta( '_securehold_deposit_next_run', true ) : '';
                                        if ( ! empty( $next_run ) ) {
                                            echo '<span class="sh-deposits-scheduled-date" title="' . esc_attr__( 'Scheduled execution date', 'securehold-security-deposit-holds' ) . '">';
                                            echo '<span class="dashicons dashicons-calendar-alt sh-deposits-cal-icon"></span>';
                                            echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $next_run ) ) );
                                            echo '</span>';
                                        } else {
                                            echo '<span class="sh-deposits-scheduled-date">' . esc_html__( 'Queued', 'securehold-security-deposit-holds' ) . '</span>';
                                        }
                                    } elseif ( ! empty( $deposit->expires_at ) ) {
                                        echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deposit->expires_at ) ) );
                                    } else {
                                        echo '<span class="sh-deposits-no-date">&mdash;</span>';
                                    }
                                    ?>
                                </div>
                            </td>
                            <td class="sh-deposits-td sh-deposits-td-actions" data-colname="<?php esc_attr_e( 'Actions', 'securehold-security-deposit-holds' ); ?>">
                                <?php
                                $kebab_actions = Securehold_Admin::get_deposit_actions( $deposit, $total, $captured, $deposit->currency, $currency_symbol );
                                $kebab_links   = array();
                                $kebab_buttons = array();
                                foreach ( $kebab_actions as $ka ) {
                                    if ( $ka['type'] === 'link' ) {
                                        $kebab_links[] = $ka;
                                    } else {
                                        $kebab_buttons[] = $ka;
                                    }
                                }
                                ?>
                                <div class="sh-deposits-value">
                                    <div class="sh-kebab-wrapper">
                                    <button type="button" class="sh-kebab-btn" aria-haspopup="true" aria-expanded="false" aria-controls="sh-kebab-menu-<?php echo esc_attr( $deposit->id ); ?>" aria-label="<?php esc_attr_e( 'Actions', 'securehold-security-deposit-holds' ); ?>">
                                        <svg width="18" height="18" viewBox="0 0 18 18" fill="currentColor"><circle cx="9" cy="4" r="1.5"/><circle cx="9" cy="9" r="1.5"/><circle cx="9" cy="14" r="1.5"/></svg>
                                    </button>
                                    <div class="sh-kebab-menu" id="sh-kebab-menu-<?php echo esc_attr( $deposit->id ); ?>" role="menu" aria-hidden="true">
                                        <?php foreach ( $kebab_links as $kl ) : ?>
                                        <a href="<?php echo esc_url( $kl['url'] ); ?>" class="sh-kebab-item" role="menuitem"<?php if ( ! empty( $kl['target'] ) ) : ?> target="<?php echo esc_attr( $kl['target'] ); ?>" rel="noopener"<?php endif; ?>>
                                            <span class="dashicons dashicons-<?php echo esc_attr( $kl['icon'] ); ?>"<?php echo $kl['icon_color'] ? ' style="color:' . esc_attr( $kl['icon_color'] ) . ';"' : ''; ?>></span> <?php echo esc_html( $kl['label'] ); ?>
                                        </a>
                                        <?php endforeach; ?>

                                        <?php if ( ! empty( $kebab_buttons ) ) : ?>
                                        <div class="sh-kebab-divider"></div>
                                        <?php foreach ( $kebab_buttons as $kb ) :
                                            $data_attrs = '';
                                            foreach ( $kb['data'] as $dk => $dv ) {
                                                $data_attrs .= ' data-' . esc_attr( $dk ) . '="' . esc_attr( $dv ) . '"';
                                            }
                                            $danger_class = ( $kb['role'] === 'danger' ) ? ' sh-kebab-danger' : '';
                                        ?>
                                        <button type="button" class="sh-kebab-item sh-kebab-action<?php echo esc_attr( $danger_class ); ?> <?php echo esc_attr( $kb['classes'] ); ?>" role="menuitem"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- string built with esc_attr() per key/value in loop above ?>>
                                            <span class="dashicons dashicons-<?php echo esc_attr( $kb['icon'] ); ?>"<?php echo $kb['icon_color'] ? ' style="color:' . esc_attr( $kb['icon_color'] ) . ';"' : ''; ?>></span> <?php echo esc_html( $kb['label'] ); ?>
                                        </button>
                                        <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php if ( $deposit->status === 'failed' ) : ?>
                        <tr class="sh-diagnostic-row" id="sh-diag-row-<?php echo esc_attr( $deposit->id ); ?>" style="display:none;">
                            <td colspan="7" class="sh-deposits-diag-cell">
                                <div id="sh-diag-content-<?php echo esc_attr( $deposit->id ); ?>"></div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7" class="sh-deposits-empty">
                            <span class="dashicons dashicons-list-view sh-deposits-empty-icon"></span>
                            <p class="sh-deposits-empty-text">
                                <?php
                                if ( $has_active_filters ) {
                                    esc_html_e( 'No deposits match your filters.', 'securehold-security-deposit-holds' );
                                } else {
                                    esc_html_e( 'No security deposits found yet.', 'securehold-security-deposit-holds' );
                                }
                                ?>
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div class="sh-pagination-bar">
            <span class="sh-pagination-info">
                <?php
                $first_item = $offset + 1;
                $last_item  = min( $offset + $per_page, $total_items );
                printf(
                    /* translators: %1$d = first item, %2$d = last item, %3$d = total */
                    esc_html__( 'Showing %1$d–%2$d of %3$d deposits', 'securehold-security-deposit-holds' ),
                    absint( $first_item ),
                    absint( $last_item ),
                    absint( $total_items )
                );
                ?>
            </span>
            <div class="sh-pagination-links">
                <?php
                echo wp_kses_post( paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%' ),
                    'format'    => '',
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'total'     => $total_pages,
                    'current'   => $page,
                    'type'      => 'plain',
                ) ) );
                ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
/**
 * Capture modal — shared markup, also used by the order-edit screen metabox.
 * See Securehold_Admin::render_capture_modal().
 */
Securehold_Admin::render_capture_modal();
?>
