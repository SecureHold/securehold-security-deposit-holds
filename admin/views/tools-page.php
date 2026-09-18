<?php
/**
 * Tools & Maintenance — Control Center (Tabbed Layout)
 *
 * FREE tools (always accessible):
 *   - Stripe Status & Compliance
 *   - Export Support Bundle (admin-only)
 *   - Configuration Self-Test
 *   - System Info
 *
 * Advanced tools (Scheduled Holds Monitor, Checkout Engine Status, Simulate
 * Hold, Database Integrity, Migrate Legacy Strategy, Manual Sync) are provided
 * by the separate SecureHold PRO plugin and injected here through the
 * securehold_tools_* action hooks. No PRO tool code lives in this FREE view.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// FREE ships diagnostics tools only. Advanced tools (Scheduled Holds Monitor,
// Checkout Engine Status, Simulate Hold, Database Integrity, Migrate Legacy
// Strategy, Manual Sync) are injected by PRO through the securehold_tools_*
// action hooks below — no PRO tool code lives in this FREE view.
?>

<div class="wrap securehold-wrapper" style="position: relative;">

    <?php
    Securehold_Admin::render_page_header(
        __( 'Tools & Maintenance', 'securehold-security-deposit-holds' ),
        __( 'Control Center — Diagnostics, monitoring, and maintenance tools for your SecureHold WP installation.', 'securehold-security-deposit-holds' ),
        'dashicons-admin-tools'
    );
    ?>

    <?php settings_errors( 'securehold_tools' ); ?>

    <!-- ================================================================
         TAB NAVIGATION
         ================================================================ -->
    <div class="sh-tabs-wrapper">
        <nav class="sh-tabs-nav" role="tablist">
            <a href="#diagnostics" class="sh-tab-link active" role="tab" aria-selected="true" aria-controls="sh-tab-diagnostics" data-tab="diagnostics">
                <span class="dashicons dashicons-chart-area"></span><?php esc_html_e( 'Diagnostics', 'securehold-security-deposit-holds' ); ?>
            </a>
            <?php
            /**
             * PRO injects the "Advanced Tools" tab nav link here.
             * FREE alone renders only Diagnostics and System.
             */
            do_action( 'securehold_tools_tab_nav' );

            // FREE preview only — shown when PRO's Advanced Tools are not active.
            // Same gate ( securehold_feature_enabled( 'tools' ) ) PRO itself uses,
            // so this link never appears alongside the real one.
            if ( ! securehold_feature_enabled( 'tools' ) ) :
                ?>
                <a href="#advanced" class="sh-tab-link" role="tab" aria-selected="false" aria-controls="sh-tab-advanced" data-tab="advanced">
                    <span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'Advanced Tools', 'securehold-security-deposit-holds' ); ?>
                    <span class="sh-pro-badge"><?php esc_html_e( 'PRO', 'securehold-security-deposit-holds' ); ?></span>
                </a>
            <?php endif; ?>
            <a href="#system" class="sh-tab-link" role="tab" aria-selected="false" aria-controls="sh-tab-system" data-tab="system">
                <span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'System', 'securehold-security-deposit-holds' ); ?>
            </a>
        </nav>
    </div>

    <!-- ================================================================
         TAB 1: DIAGNOSTICS
         ================================================================ -->
    <div id="sh-tab-diagnostics" class="sh-tab-panel sh-tab-panel--active" role="tabpanel">

        <div class="sh-tools-grid">

            <!-- BLOCK A: Stripe Status & Compliance — FREE -->
            <div class="sh-card sh-card--tool">
                <div class="sh-card-header">
                    <h2 class="sh-card-title">
                        <span class="dashicons dashicons-shield"></span>
                        <?php esc_html_e( 'Stripe Status &amp; Compliance', 'securehold-security-deposit-holds' ); ?>
                    </h2>
                </div>
                <div class="sh-card-body">
                    <p class="sh-card-desc">
                        <?php esc_html_e( 'Compact environment status — checks Stripe API connectivity, key configuration, and off-session readiness. Use the System self-test for the full end-to-end verification.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                    <div id="sh-inspector-results" class="sh-tool-results sh-compliance-wrapper" style="display:none;"></div>
                    <button type="button" id="btn-stripe-inspector" class="sh-btn sh-btn-primary sh-btn--full">
                        <span class="dashicons dashicons-search"></span>
                        <?php esc_html_e( 'Run Status &amp; Compliance Check', 'securehold-security-deposit-holds' ); ?>
                    </button>
                </div>
            </div>

            <?php
            /**
             * PRO injects the Diagnostics-tab advanced cards here
             * (Scheduled Holds Monitor, Checkout Engine Status).
             */
            do_action( 'securehold_tools_diagnostics_cards' );
            ?>

        </div>

    </div><!-- /sh-tab-diagnostics -->


    <!-- ================================================================
         TAB 3: SYSTEM
         ================================================================ -->
    <div id="sh-tab-system" class="sh-tab-panel" role="tabpanel" style="display:none;">

        <div class="sh-tools-grid">

            <!-- Configuration Self-Test — FREE -->
            <div class="sh-card sh-card--tool">
                <div class="sh-card-header">
                    <h2 class="sh-card-title">
                        <span class="dashicons dashicons-admin-network"></span>
                        <?php esc_html_e( 'Configuration Self-Test', 'securehold-security-deposit-holds' ); ?>
                    </h2>
                </div>
                <div class="sh-card-body">
                    <p class="sh-card-desc">
                        <?php esc_html_e( 'Detailed end-to-end report — creates a temporary Stripe Customer, Payment Method, and WooCommerce test order to validate the entire deposit flow. Results open in a step-by-step diagnostic modal.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                    <button type="button" id="btn-run-tool-test" class="sh-btn sh-btn-primary sh-btn--full">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e( 'Start Diagnostic', 'securehold-security-deposit-holds' ); ?>
                    </button>
                </div>
            </div>

            <?php
            /**
             * PRO injects System-tab advanced cards here (Manual Sync).
             */
            do_action( 'securehold_tools_system_cards' );
            ?>
            <!-- System Info — FREE -->
            <div class="sh-card sh-card--tool">
                <div class="sh-card-header">
                    <h2 class="sh-card-title">
                        <span class="dashicons dashicons-info"></span>
                        <?php esc_html_e( 'System Info', 'securehold-security-deposit-holds' ); ?>
                    </h2>
                </div>
                <div class="sh-card-body">
                    <ul class="sh-info-list">
                        <li>
                            <span><?php esc_html_e( 'Version', 'securehold-security-deposit-holds' ); ?></span>
                            <strong><?php echo esc_html( SECUREHOLD_VERSION ); ?></strong>
                        </li>
                        <li>
                            <span><?php esc_html_e( 'PHP Version', 'securehold-security-deposit-holds' ); ?></span>
                            <strong><?php echo esc_html( phpversion() ); ?></strong>
                        </li>
                        <li>
                            <span><?php esc_html_e( 'WooCommerce', 'securehold-security-deposit-holds' ); ?></span>
                            <strong><?php echo esc_html( class_exists( 'WooCommerce' ) ? WC()->version : __( 'Not detected', 'securehold-security-deposit-holds' ) ); ?></strong>
                        </li>
                        <li>
                            <span><?php esc_html_e( 'Timezone', 'securehold-security-deposit-holds' ); ?></span>
                            <strong><?php echo esc_html( wp_timezone_string() ); ?></strong>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Reset Setup Wizard — Admin only (no PRO gate) -->
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <div class="sh-card sh-card--tool">
                <div class="sh-card-header">
                    <h2 class="sh-card-title">
                        <span class="dashicons dashicons-backup"></span>
                        <?php esc_html_e( 'Reset Setup Wizard', 'securehold-security-deposit-holds' ); ?>
                    </h2>
                </div>
                <div class="sh-card-body">
                    <p class="sh-card-desc">
                        <?php esc_html_e( 'Restart the setup wizard from the beginning. This does not delete deposits or logs.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'securehold_reset_wizard_nonce' ); ?>
                        <input type="hidden" name="action" value="securehold_reset_wizard">
                        <label class="sh-tool-checkbox" style="margin-bottom: 1rem;">
                            <input type="checkbox" name="securehold_clear_keys" value="1">
                            <span><?php esc_html_e( 'Also clear Stripe API keys and Webhook secret (recommended if you want a clean reconfiguration).', 'securehold-security-deposit-holds' ); ?></span>
                        </label>
                        <button type="submit" class="sh-btn sh-btn-secondary sh-btn--full">
                            <span class="dashicons dashicons-update"></span>
                            <?php esc_html_e( 'Restart Setup Wizard', 'securehold-security-deposit-holds' ); ?>
                        </button>
                    </form>
                    <p class="sh-card-hint">
                        <?php esc_html_e( 'Tip: use this if your webhook secret is wrong or you switched from Test to Live.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- BLOCK: Export Support Bundle — FREE, admin only -->
            <?php if ( current_user_can( 'manage_options' ) ) : ?>
            <div class="sh-card sh-card--tool">
                <div class="sh-card-header">
                    <h2 class="sh-card-title">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Export Support Bundle', 'securehold-security-deposit-holds' ); ?>
                    </h2>
                </div>
                <div class="sh-card-body">
                    <p class="sh-card-desc">
                        <?php esc_html_e( 'Download a sanitized diagnostic bundle to share with support when troubleshooting deposits, webhooks, license, or configuration issues.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <?php wp_nonce_field( 'securehold_export_support_bundle_nonce' ); ?>
                        <input type="hidden" name="action" value="securehold_export_support_bundle">
                        <button type="submit" class="sh-btn sh-btn-primary sh-btn--full">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e( 'Download Bundle', 'securehold-security-deposit-holds' ); ?>
                        </button>
                    </form>
                    <p class="sh-card-hint">
                        <?php esc_html_e( 'The bundle contains environment info, sanitized settings, recent logs, and, when SecureHold PRO is installed, non-sensitive license status. API keys and secrets are never included in full.', 'securehold-security-deposit-holds' ); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

        </div>

    </div><!-- /sh-tab-system -->

    <?php
    /**
     * PRO injects extra tab panels here (e.g. the Advanced Tools panel),
     * rendered as a sibling of the FREE panels so the shared tab router
     * (admin-tools.js) shows/hides them by data-tab.
     */
    do_action( 'securehold_tools_extra_panels' );

    // FREE preview only — single locked panel, no PRO tool code loaded.
    if ( ! securehold_feature_enabled( 'tools' ) ) :
        ?>
        <div id="sh-tab-advanced" class="sh-tab-panel" role="tabpanel" style="display:none;">
            <?php
            $sh_locked_title       = __( 'Advanced Tools', 'securehold-security-deposit-holds' );
            $sh_locked_description = __( 'Scheduled holds monitoring, checkout engine status, a hold dry-run simulator, database integrity checks, legacy-strategy migration, and manual sync are available in SecureHold PRO.', 'securehold-security-deposit-holds' );
            $sh_locked_icon        = 'dashicons-admin-tools';
            include plugin_dir_path( __FILE__ ) . 'partials/pro-locked-panel.php';
            unset( $sh_locked_title, $sh_locked_description, $sh_locked_icon );
            ?>
        </div>
    <?php endif; ?>

</div><!-- /wrap -->

<!-- MODAL DIAGNOSTIC -->
<div id="sh-test-modal" class="sh-modal-overlay">
    <div class="sh-modal" style="max-width: 800px; background: white; border-radius: 0.75rem; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1);">
        <div class="sh-modal-header" style="padding: 1.5rem; border-bottom: 1px solid #e2e8f0; background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); border-radius: 0.75rem 0.75rem 0 0;">
            <h2 class="sh-modal-title" style="margin: 0; color: white; font-size: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
                <span class="dashicons dashicons-admin-network" style="background: rgba(255,255,255,0.2); padding: 8px; border-radius: 50%;"></span>
                <?php esc_html_e( 'Configuration Diagnostic', 'securehold-security-deposit-holds' ); ?>
            </h2>
        </div>
        <div class="sh-modal-body" style="padding: 1.5rem; max-height: 500px; overflow-y: auto;">
            <div class="sh-modal-loading" style="text-align: center; padding: 3rem 0;">
                <div class="spinner" style="display: inline-block; width: 50px; height: 50px; border: 4px solid #f3f4f6; border-top: 4px solid #2563eb; border-radius: 50%; animation: spin 1s linear infinite;"></div>
                <p style="margin-top: 1rem; color: #64748b; font-size: 1.1em;"><?php esc_html_e( 'Running diagnostics...', 'securehold-security-deposit-holds' ); ?></p>
            </div>
            <div class="sh-modal-results" style="display:none;"></div>
        </div>
        <div class="sh-modal-footer" style="padding: 1rem 1.5rem; border-top: 1px solid #e2e8f0; text-align: right; background: #f8fafc;">
            <button type="button" class="sh-modal-close-btn sh-btn sh-btn-secondary">
                <?php esc_html_e( 'Close', 'securehold-security-deposit-holds' ); ?>
            </button>
        </div>
    </div>
</div>

<?php /* Tab routing: assets/js/admin-tools.js (handle: securehold-admin-tools)
         Tool styles: assets/css/admin-tools.css (handle: securehold-admin-tools-css)
         Both enqueued by Securehold_Admin::enqueue_admin_assets() on the Tools page. */ ?>
