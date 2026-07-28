<?php
/**
 * Logs page view.
 *
 * When securehold_feature_enabled('logs') is true (PRO installed), delegates
 * to PRO's full log view (search, filters, export). Otherwise the FREE plugin
 * renders a simple paginated table from the wp_securehold_logs table populated
 * by the local securehold_log() helper.
 *
 * Menu registration lives in the FREE plugin
 * (Securehold_Admin::add_plugin_admin_menu / display_logs_page).
 *
 * @since 5.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_pro = securehold_feature_enabled( 'logs' );

if ( $is_pro ) {
    $pro_view = defined( 'SECUREHOLD_PRO_DIR' ) ? SECUREHOLD_PRO_DIR . 'admin/views/logs-page.php' : '';
    if ( $pro_view && file_exists( $pro_view ) ) {
        require_once $pro_view;
        return;
    }
}

global $wpdb;
$logs_table = $wpdb->prefix . 'securehold_logs';
$table_exists = ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $logs_table ) ) ) === $logs_table );

// Pagination — 25 rows per page is sane for a basic viewer.
$per_page     = 25;
$current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$offset       = ( $current_page - 1 ) * $per_page;

$total_rows = 0;
$rows       = array();
if ( $table_exists ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $total_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $rows = (array) $wpdb->get_results( $wpdb->prepare(
        "SELECT id, created_at, severity, event_type, message, order_id, hold_id FROM {$logs_table} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ) );
}

$total_pages = $per_page > 0 ? (int) ceil( $total_rows / $per_page ) : 1;
?>
<div class="wrap securehold-admin">

    <?php
    Securehold_Admin::render_page_header(
        __( 'Logs', 'securehold-security-deposit-holds' ),
        __( 'Recent SecureHold events recorded by the plugin.', 'securehold-security-deposit-holds' ),
        'dashicons-media-text'
    );
    ?>

    <div class="sh-card sh-section-top">
        <?php if ( ! $table_exists ) : ?>
            <div class="sh-card-body">
                <p><?php esc_html_e( 'The log table has not been created yet. It will be initialised automatically the next time a hold is processed.', 'securehold-security-deposit-holds' ); ?></p>
            </div>
        <?php elseif ( empty( $rows ) ) : ?>
            <div class="sh-card-body">
                <p><?php esc_html_e( 'No log entries yet.', 'securehold-security-deposit-holds' ); ?></p>
            </div>
        <?php else : ?>
            <table class="widefat striped sh-logs-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Date', 'securehold-security-deposit-holds' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Severity', 'securehold-security-deposit-holds' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Event', 'securehold-security-deposit-holds' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Order', 'securehold-security-deposit-holds' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Message', 'securehold-security-deposit-holds' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) :
                        $severity   = isset( $row->severity )   ? (string) $row->severity   : 'info';
                        $event_type = isset( $row->event_type ) ? (string) $row->event_type : '';
                        $message    = isset( $row->message )    ? (string) $row->message    : '';
                        $order_id   = isset( $row->order_id )   ? absint( $row->order_id )  : 0;
                        $created_at = isset( $row->created_at ) ? (string) $row->created_at : '';
                        $time_str   = $created_at
                            ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $created_at ) )
                            : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $time_str ); ?></td>
                        <td><code><?php echo esc_html( $severity ); ?></code></td>
                        <td><?php echo esc_html( $event_type ); ?></td>
                        <td>
                            <?php if ( $order_id ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>">#<?php echo esc_html( $order_id ); ?></a>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $message ); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $total_pages > 1 ) :
                $base_url = admin_url( 'admin.php?page=securehold-logs' );
                $links    = paginate_links( array(
                    'base'      => add_query_arg( 'paged', '%#%', $base_url ),
                    'format'    => '',
                    'current'   => $current_page,
                    'total'     => $total_pages,
                    'prev_text' => '&laquo;',
                    'next_text' => '&raquo;',
                    'type'      => 'plain',
                ) );
            ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php echo wp_kses_post( $links ); ?>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

</div>
