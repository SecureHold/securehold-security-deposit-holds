<?php
/**
 * SecureHold Dashboard Page
 * Version: 3.6.0 - Uses Stats Partial
 */

if (!defined('ABSPATH')) exit;

$setup_completed = get_option('securehold_setup_completed', false);

// Fetch recent holds only (Stats are now handled in the partial)
global $wpdb;
$table_name = $wpdb->prefix . 'securehold_holds';
$recent_holds = [];
if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $table_name ) ) ) === $table_name ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is plugin-controlled.
    $recent_holds = $wpdb->get_results( "SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 5" );
}

// Use each deposit's own currency from the DB; for aggregate display
// wc_price() without explicit currency uses WooCommerce's configured currency.
// Legacy: was get_option('securehold_stripe_currency', 'USD') — wrong when WC is EUR/GBP.
?>

<div class="wrap securehold-wrapper" style="position: relative;">

    <?php
    Securehold_Admin::render_page_header(
        __('Dashboard', 'securehold-security-deposit-holds'),
        __('Overview of your security deposit management', 'securehold-security-deposit-holds'),
        'dashicons-dashboard'
    ); 
    ?>

    <?php
    /**
     * Dashboard analytics blocks.
     *
     * When PRO is active, it renders its own extended analytics via the hook.
     * Otherwise the shared stats-overview partial renders the FREE stat cards
     * (Total / Active / Captured / Released) from the local holds table.
     */
    if ( securehold_feature_enabled( 'dashboard' ) ) {
        do_action( 'securehold_dashboard_blocks' );
    } elseif ( file_exists( SECUREHOLD_PLUGIN_DIR . 'admin/views/partials/stats-overview.php' ) ) {
        require SECUREHOLD_PLUGIN_DIR . 'admin/views/partials/stats-overview.php';
    }
    ?>

    <div class="sh-dashboard-grid">
        
        <div class="sh-card">
            <div class="sh-card-header">
                <h2 class="sh-card-title">
                    <span class="dashicons dashicons-list-view"></span>
                    <?php esc_html_e('Recent Activity', 'securehold-security-deposit-holds'); ?>
                </h2>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=securehold-deposits' ) ); ?>" class="sh-btn sh-btn-ghost">
                    <?php esc_html_e('View All', 'securehold-security-deposit-holds'); ?>
                </a>
            </div>
            
            <?php if (!empty($recent_holds)): ?>
                <div class="sh-dashboard-table-wrap">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--sh-gray-200);">
                            <th style="padding: 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--sh-gray-600);">Order</th>
                            <th style="padding: 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--sh-gray-600);">Amount</th>
                            <th style="padding: 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--sh-gray-600);">Status</th>
                            <th style="padding: 1rem; text-align: left; font-size: 0.75rem; font-weight: 600; text-transform: uppercase; color: var(--sh-gray-600);">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_holds as $hold): 
                            $total = floatval($hold->amount);
                            $captured = isset($hold->captured_amount) ? floatval($hold->captured_amount) : 0;
                            
                            $status_class = Securehold_Admin::get_status_badge_class($hold->status);
                        ?>
                        <tr style="border-bottom: 1px solid var(--sh-gray-200);">
                            <td style="padding: 1rem;">
                                <a href="<?php echo esc_url( admin_url( 'post.php?post=' . absint( $hold->order_id ) . '&action=edit' ) ); ?>" style="color: var(--sh-primary); text-decoration: none; font-weight: 600;">
                                    #<?php echo absint( $hold->order_id ); ?>
                                </a>
                            </td>
                            <td style="padding: 1rem; font-weight: 600;">
                                <?php
                                // Use per-deposit currency from DB row; fall back to WC default.
                                $hold_currency   = ! empty( $hold->currency ) ? strtoupper( $hold->currency ) : get_woocommerce_currency();
                                $wc_price_args   = array( 'currency' => $hold_currency );
                                if ($hold->status === 'captured' && $captured > 0) {
                                    echo '<span style="color:#d97706;">' . wp_kses_post( wc_price( $captured, $wc_price_args ) ) . '</span>';
                                    echo '<span style="color:#9ca3af; font-size:0.9em; font-weight:400;"> / ' . wp_kses_post( wc_price( $total, $wc_price_args ) ) . '</span>';
                                } else {
                                    echo wp_kses_post( wc_price( $total, $wc_price_args ) );
                                }
                                ?>
                            </td>
                            <td style="padding: 1rem;">
                                <?php Securehold_Admin::render_status_badge($hold->status, true, $hold->order_id, isset($hold->notes) ? $hold->notes : ''); ?>
                            </td>
                            <td style="padding: 1rem; color: var(--sh-gray-500); font-size: 0.875rem;">
                                <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $hold->created_at ) ) ); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div><!-- /.sh-dashboard-table-wrap -->
            <?php else: ?>
                <div style="text-align: center; padding: 3rem; color: var(--sh-gray-500);">
                    <p style="font-size: 1.125rem; font-weight: 600; margin-bottom: 0.5rem;">No holds yet</p>
                </div>
            <?php endif; ?>
        </div>

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
    </div>
</div>