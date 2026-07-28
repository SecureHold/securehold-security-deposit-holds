<?php
/**
 * Add-ons & Extensions page — SecureHold WP.
 *
 * Passive informational page listing the SecureHold Pro add-on and planned
 * third-party integrations. No local features are locked or gated here.
 * All links lead to external sites hosted outside WordPress.org.
 *
 * When securehold_feature_enabled('extensions') is true (PRO installed),
 * delegates to PRO's full filterable extensions catalog view.
 *
 * @since 5.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_pro = securehold_feature_enabled( 'extensions' );

if ( $is_pro ) {
    $pro_view = defined( 'SECUREHOLD_PRO_DIR' ) ? SECUREHOLD_PRO_DIR . 'admin/views/extensions-page.php' : '';
    if ( $pro_view && file_exists( $pro_view ) ) {
        require_once $pro_view;
        return;
    }
}

// ── Add-on registry ────────────────────────────────────────────────────────────
// Each entry: title, description, status ('available'|'coming_soon'|'planned'|'resource'),
// icon (dashicons class), cta_label, cta_url (null = no link).
$addons = array(

    // ── SecureHold Pro ─────────────────────────────────────────────────────────
    array(
        'slug'        => 'securehold-pro',
        'title'       => __( 'SecureHold Pro', 'securehold-security-deposit-holds' ),
        'description' => __( 'A separate add-on for stores that need advanced deposit workflows, automation strategies, and deeper reporting. Hosted outside WordPress.org.', 'securehold-security-deposit-holds' ),
        'highlights'  => array(
            array(
                'icon'  => 'dashicons-networking',
                'title' => __( 'Advanced deposit rules', 'securehold-security-deposit-holds' ),
                'desc'  => __( 'Product and category based deposit rules for stores with complex catalogs.', 'securehold-security-deposit-holds' ),
            ),
            array(
                'icon'  => 'dashicons-controls-play',
                'title' => __( 'Automation workflows', 'securehold-security-deposit-holds' ),
                'desc'  => __( 'Delayed, scheduled, and status-based hold creation for more precise deposit timing.', 'securehold-security-deposit-holds' ),
            ),
            array(
                'icon'  => 'dashicons-chart-bar',
                'title' => __( 'Reporting &amp; audit insights', 'securehold-security-deposit-holds' ),
                'desc'  => __( 'Deeper activity views, audit trails, and deposit reporting for operational follow-up.', 'securehold-security-deposit-holds' ),
            ),
            array(
                'icon'  => 'dashicons-controls-play',
                'title' => __( 'Live Rule Simulator', 'securehold-security-deposit-holds' ),
                'desc'  => __( 'Per-product and per-category rule simulation with real-time draft preview and a full evaluation tree for complex carts.', 'securehold-security-deposit-holds' ),
            ),
        ),
        'features'    => array(),
        'note'        => __( 'Available from secureholdwp.com and hosted outside WordPress.org.', 'securehold-security-deposit-holds' ),
        'status'      => 'available',
        'featured'    => true,
        'icon'        => 'dashicons-shield-alt',
        'cta_label'   => __( 'Learn More', 'securehold-security-deposit-holds' ),
        'cta_url'     => SECUREHOLD_WP_URL_PRICING,
    ),

    // ── Integrations & planned add-ons ─────────────────────────────────────────
    array(
        'slug'        => 'woocommerce-bookings',
        'title'       => __( 'WooCommerce Bookings Integration', 'securehold-security-deposit-holds' ),
        'description' => __( 'Automatically apply security deposits to bookable products. Trigger hold creation from booking dates and statuses.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'coming_soon',
        'featured'    => false,
        'icon'        => 'dashicons-calendar-alt',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'calendar-ics-manager',
        'title'       => __( 'Calendar & ICS Manager', 'securehold-security-deposit-holds' ),
        'description' => __( 'Synchronize deposit availability with external calendars via ICS feeds. Connect with booking platforms and avoid double-scheduling.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'coming_soon',
        'featured'    => false,
        'icon'        => 'dashicons-calendar',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'checkout-templates',
        'title'       => __( 'Checkout Templates for Booking Flows', 'securehold-security-deposit-holds' ),
        'description' => __( 'Purpose-built WooCommerce checkout layouts optimized for deposit-first and booking-based purchase flows.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'coming_soon',
        'featured'    => false,
        'icon'        => 'dashicons-cart',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'advanced-email-automation',
        'title'       => __( 'Advanced Email Automation', 'securehold-security-deposit-holds' ),
        'description' => __( 'Automated email sequences for deposit confirmations, capture reminders, release notices, and guest follow-ups.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'coming_soon',
        'featured'    => false,
        'icon'        => 'dashicons-email-alt',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'deposit-reminder-automation',
        'title'       => __( 'Deposit Reminder Automation', 'securehold-security-deposit-holds' ),
        'description' => __( 'Schedule automatic reminders for pending deposit captures, upcoming release dates, and overdue holds.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'coming_soon',
        'featured'    => false,
        'icon'        => 'dashicons-bell',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'damage-claim-workflow',
        'title'       => __( 'Damage Claim Workflow', 'securehold-security-deposit-holds' ),
        'description' => __( 'Structured workflow for initiating, documenting, and resolving damage claims against held security deposits.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'planned',
        'featured'    => false,
        'icon'        => 'dashicons-warning',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'payment-recovery-assistant',
        'title'       => __( 'Payment Recovery Assistant', 'securehold-security-deposit-holds' ),
        'description' => __( 'Automated retry flows and merchant tools to recover failed deposit holds and incomplete Stripe payment intents.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'planned',
        'featured'    => false,
        'icon'        => 'dashicons-update',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'deposit-analytics-reporting',
        'title'       => __( 'Deposit Analytics & Reporting', 'securehold-security-deposit-holds' ),
        'description' => __( 'Advanced dashboards and exportable reports for deposit volume, capture rates, release timelines, and revenue impact.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'planned',
        'featured'    => false,
        'icon'        => 'dashicons-chart-bar',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'multi-property-manager',
        'title'       => __( 'Multi-Property & Multi-Brand Manager', 'securehold-security-deposit-holds' ),
        'description' => __( 'Manage deposit rules, branding, and reporting across multiple properties or brands from a single installation.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'planned',
        'featured'    => false,
        'icon'        => 'dashicons-networking',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'guest-verification-connector',
        'title'       => __( 'Guest Verification Connector', 'securehold-security-deposit-holds' ),
        'description' => __( 'Connect identity verification providers to validate guest identity before authorizing a security deposit hold.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'planned',
        'featured'    => false,
        'icon'        => 'dashicons-id-alt',
        'cta_label'   => '',
        'cta_url'     => null,
    ),
    array(
        'slug'        => 'rental-agreement-generator',
        'title'       => __( 'Rental Agreement & Waiver Generator', 'securehold-security-deposit-holds' ),
        'description' => __( 'Generate, send, and store legally structured rental agreements and damage waivers linked to deposit orders.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'planned',
        'featured'    => false,
        'icon'        => 'dashicons-media-document',
        'cta_label'   => '',
        'cta_url'     => null,
    ),

    // ── Developer resource ─────────────────────────────────────────────────────
    array(
        'slug'        => 'developer-hooks-reference',
        'title'       => __( 'Developer Hooks Reference', 'securehold-security-deposit-holds' ),
        'description' => __( 'Actions and filters for building custom integrations. Hook into deposit creation, capture, and release events.', 'securehold-security-deposit-holds' ),
        'features'    => array(),
        'note'        => '',
        'status'      => 'resource',
        'featured'    => false,
        'icon'        => 'dashicons-editor-code',
        'cta_label'   => __( 'View Documentation', 'securehold-security-deposit-holds' ),
        'cta_url'     => SECUREHOLD_WP_URL_DOCS,
    ),
);

/**
 * Allow external plugins to register additional add-ons in the catalog.
 * Required fields: slug, title, description, status, icon, cta_label, cta_url.
 * Optional fields: features (array), note, featured (bool).
 *
 * @since 5.8.0
 * @param array $addons Registered add-on definitions.
 */
$addons = apply_filters( 'securehold_addons_catalog', $addons );

// ── Status labels ──────────────────────────────────────────────────────────────
$status_labels = array(
    'available'   => __( 'Available',   'securehold-security-deposit-holds' ),
    'coming_soon' => __( 'Coming Soon', 'securehold-security-deposit-holds' ),
    'planned'     => __( 'Planned',     'securehold-security-deposit-holds' ),
    'resource'    => __( 'Free',        'securehold-security-deposit-holds' ),
);
$status_badge_classes = array(
    'available'   => 'sh-badge-success',
    'coming_soon' => 'sh-badge-info',
    'planned'     => 'sh-badge-secondary',
    'resource'    => 'sh-badge-success',
);

// ── Split: featured card vs grid cards ────────────────────────────────────────
$featured_addon = null;
$grid_addons    = array();
foreach ( $addons as $addon ) {
    if ( ! empty( $addon['featured'] ) && is_null( $featured_addon ) ) {
        $featured_addon = $addon;
    } else {
        $grid_addons[] = $addon;
    }
}
?>
<div class="wrap securehold-admin">

    <?php
    Securehold_Admin::render_page_header(
        __( 'Add-ons & Extensions', 'securehold-security-deposit-holds' ),
        __( 'Extend SecureHold WP with optional add-ons and integrations hosted outside WordPress.org.', 'securehold-security-deposit-holds' ),
        'dashicons-admin-plugins'
    );
    ?>

    <?php if ( $featured_addon ) : ?>
    <!-- ================================================================
         FEATURED: SecureHold Pro
         ================================================================ -->
    <div class="sh-card sh-addons-featured sh-section-top">
        <div class="sh-card-header">
            <h2 class="sh-card-title">
                <?php
                $sh_logo_path = defined( 'SECUREHOLD_PLUGIN_DIR' )
                    ? SECUREHOLD_PLUGIN_DIR . 'assets/images/securehold-logo.svg'
                    : plugin_dir_path( dirname( __DIR__ ) ) . 'assets/images/securehold-logo.svg';
                if ( file_exists( $sh_logo_path ) ) :
                    $sh_logo_url = defined( 'SECUREHOLD_PLUGIN_URL' )
                        ? SECUREHOLD_PLUGIN_URL . 'assets/images/securehold-logo.svg'
                        : plugin_dir_url( dirname( __DIR__ ) ) . 'assets/images/securehold-logo.svg';
                ?>
                <img
                    src="<?php echo esc_url( $sh_logo_url ); ?>"
                    alt="<?php echo esc_attr( 'SecureHold' ); ?>"
                    class="sh-addons-pro-logo"
                >
                <?php else : ?>
                <span class="dashicons <?php echo esc_attr( $featured_addon['icon'] ); ?>"></span>
                <?php endif; ?>
                <?php echo esc_html( $featured_addon['title'] ); ?>
            </h2>
        </div>
        <div class="sh-card-body sh-addons-featured-body">
            <div class="sh-addons-featured-desc">
                <p class="sh-card-desc"><?php echo esc_html( $featured_addon['description'] ); ?></p>

                <?php if ( ! empty( $featured_addon['highlights'] ) ) : ?>
                <div class="sh-addons-highlights">
                    <?php foreach ( $featured_addon['highlights'] as $hl ) : ?>
                    <div class="sh-addons-highlight">
                        <span class="dashicons <?php echo esc_attr( $hl['icon'] ); ?> sh-addons-highlight-icon"></span>
                        <div class="sh-addons-highlight-body">
                            <strong class="sh-addons-highlight-title"><?php echo esc_html( $hl['title'] ); ?></strong>
                            <span class="sh-addons-highlight-desc"><?php echo esc_html( $hl['desc'] ); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ( ! empty( $featured_addon['features'] ) ) : ?>
                <ul class="sh-addons-featured-list">
                    <?php foreach ( $featured_addon['features'] as $feat ) : ?>
                    <li>
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php echo esc_html( $feat ); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if ( ! empty( $featured_addon['note'] ) ) : ?>
                <p class="sh-addons-featured-note"><?php echo esc_html( $featured_addon['note'] ); ?></p>
                <?php endif; ?>
            </div>
            <div class="sh-addons-featured-cta">
                <?php if ( ! empty( $featured_addon['cta_url'] ) ) : ?>
                <a href="<?php echo esc_url( $featured_addon['cta_url'] ); ?>" target="_blank" rel="noopener noreferrer" class="sh-btn sh-btn-primary">
                    <span class="dashicons dashicons-external"></span>
                    <?php echo esc_html( $featured_addon['cta_label'] ); ?>
                </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( SECUREHOLD_WP_URL_SITE ); ?>" target="_blank" rel="noopener noreferrer" class="sh-btn sh-btn-ghost">
                    <?php esc_html_e( 'Visit secureholdwp.com', 'securehold-security-deposit-holds' ); ?>
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ================================================================
         GRID: Integrations, planned add-ons, developer resources
         ================================================================ -->
    <?php if ( ! empty( $grid_addons ) ) : ?>
    <h3 class="sh-addons-section-heading">
        <?php esc_html_e( 'Upcoming add-ons &amp; integrations', 'securehold-security-deposit-holds' ); ?>
    </h3>
    <div class="sh-addons-grid">
        <?php foreach ( $grid_addons as $addon ) :
            $status   = isset( $addon['status'] ) ? $addon['status'] : 'planned';
            $has_link = ( 'available' === $status || 'resource' === $status ) && ! empty( $addon['cta_url'] );

            // Badge class — compact, inline, card-specific
            $badge_modifier = 'planned';
            if ( 'coming_soon' === $status )              { $badge_modifier = 'coming-soon'; }
            elseif ( 'available' === $status || 'resource' === $status ) { $badge_modifier = 'available'; }

            $badge_lbl = isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status;
        ?>
        <div class="sh-addon-card">
            <div class="sh-addon-card-header">
                <div class="sh-addon-card-icon">
                    <span class="dashicons <?php echo esc_attr( $addon['icon'] ); ?>"></span>
                </div>
                <div class="sh-addon-card-meta">
                    <p class="sh-addon-card-title"><?php echo esc_html( $addon['title'] ); ?></p>
                    <span class="sh-addon-card-badge sh-addon-card-badge--<?php echo esc_attr( $badge_modifier ); ?>">
                        <?php echo esc_html( $badge_lbl ); ?>
                    </span>
                </div>
            </div>
            <p class="sh-addon-card-desc"><?php echo esc_html( $addon['description'] ); ?></p>
            <?php if ( $has_link ) : ?>
            <div class="sh-addon-card-footer">
                <a href="<?php echo esc_url( $addon['cta_url'] ); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-external"></span>
                    <?php echo esc_html( $addon['cta_label'] ); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div><!-- /.sh-addons-grid -->
    <?php endif; ?>

    <!-- ================================================================
         FOOTER NOTE
         ================================================================ -->
    <p class="sh-addons-footer-note">
        <?php esc_html_e( 'Add-ons marked as Coming Soon or Planned are under active development. All optional add-ons are hosted outside WordPress.org.', 'securehold-security-deposit-holds' ); ?>
    </p>

</div>
