<?php
/**
 * Settings > Integrations.
 *
 * Renders one card per declaration on the 'securehold_integrations' filter
 * (see includes/class-securehold-wp-integrations-registry.php) plus, when
 * the PRO 'multi_hold_groups' capability is active, a Grouping Source
 * selector so the admin can choose which registered policy is allowed to
 * originate new Hold Groups — exactly one at a time
 * (Securehold_Multi_Hold::grouping_source()).
 *
 * @since 3.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$sh_integrations       = class_exists( 'Securehold_Integrations_Registry' ) ? Securehold_Integrations_Registry::get_all() : array();
$sh_multi_hold_pro     = securehold_feature_enabled( 'multi_hold_groups' );
$sh_active_source      = class_exists( 'Securehold_Multi_Hold' ) ? Securehold_Multi_Hold::grouping_source() : 'woocommerce_order_item';
$sh_declared_policies  = class_exists( 'Securehold_Multi_Hold' ) ? Securehold_Multi_Hold::declared_grouping_policies() : array();
$sh_declared_keys      = wp_list_pluck( $sh_declared_policies, 'key' );

$sh_status_badge = array(
    'available'     => array( 'class' => 'sh-badge-success', 'label' => __( 'Available', 'securehold-security-deposit-holds' ) ),
    'coming_soon'   => array( 'class' => 'sh-badge-info',    'label' => __( 'Coming soon', 'securehold-security-deposit-holds' ) ),
    'not_detected'  => array( 'class' => 'sh-badge-secondary', 'label' => __( 'Not detected', 'securehold-security-deposit-holds' ) ),
);
?>

<div class="sh-card sh-card-animated">
    <div class="sh-card-header" style="background: linear-gradient(to right, var(--sh-gray-50), white); border-bottom: 2px solid var(--sh-gray-100);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--sh-gray-500), var(--sh-gray-700)); border-radius: var(--sh-radius-lg); display: flex; align-items: center; justify-content: center; box-shadow: var(--sh-shadow-md);">
                <span class="dashicons dashicons-admin-plugins" style="color: white; font-size: 24px; width: 24px; height: 24px;"></span>
            </div>
            <div style="flex: 1;">
                <h2 class="sh-card-title" style="margin: 0;">
                    <?php esc_html_e( 'Integrations', 'securehold-security-deposit-holds' ); ?>
                </h2>
                <p style="margin: 0.25rem 0 0 0; color: var(--sh-gray-600); font-size: 0.875rem;">
                    <?php esc_html_e( 'What SecureHold can build Hold Groups from, and what each integration actually supports today.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>
    </div>

    <div style="padding: 2rem;">
        <?php foreach ( $sh_integrations as $integration ) :
            $badge = isset( $sh_status_badge[ $integration['status'] ] ) ? $sh_status_badge[ $integration['status'] ] : $sh_status_badge['not_detected'];
            $badge_label = ! empty( $integration['status_label'] ) ? $integration['status_label'] : $badge['label'];
        ?>
        <div class="sh-rule-option" style="margin-bottom: 1.25rem; flex-direction: column; align-items: stretch;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap: 1rem;">
                <div>
                    <strong>
                        <?php echo esc_html( $integration['name'] ); ?>
                        <?php if ( ! empty( $integration['is_native'] ) ) : ?>
                            <span class="sh-recommended-badge"><?php esc_html_e( 'Native', 'securehold-security-deposit-holds' ); ?></span>
                        <?php elseif ( 'pro' === $integration['tier'] ) : ?>
                            <span class="sh-pro-badge">PRO</span>
                        <?php endif; ?>
                    </strong>
                    <p class="description" style="margin-top:4px;"><?php echo esc_html( $integration['description'] ); ?></p>
                </div>
                <span class="sh-badge <?php echo esc_attr( $badge['class'] ); ?>"><?php echo esc_html( $badge_label ); ?></span>
            </div>

            <?php if ( empty( $integration['hide_capabilities'] ) ) : ?>
            <div style="margin-top:10px; display:flex; flex-wrap:wrap; gap: 1.5rem; font-size: 12px; color: var(--sh-gray-600);">
                <?php if ( null !== $integration['plugin_detected'] ) : ?>
                <span>
                    <span class="dashicons <?php echo $integration['plugin_detected'] ? 'dashicons-yes-alt' : 'dashicons-dismiss'; ?>" style="font-size:16px; width:16px; height:16px; vertical-align:middle; color:<?php echo $integration['plugin_detected'] ? '#10b981' : '#94a3b8'; ?>;"></span>
                    <?php echo $integration['plugin_detected']
                        ? esc_html__( 'Plugin detected', 'securehold-security-deposit-holds' )
                        : esc_html__( 'Plugin not detected', 'securehold-security-deposit-holds' ); ?>
                </span>
                <?php endif; ?>

                <span>
                    <?php if ( ! empty( $integration['multi_hold_capable'] ) ) : ?>
                        <span class="dashicons dashicons-networking" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                        <?php
                        if ( $sh_multi_hold_pro ) {
                            esc_html_e( 'Multi-Hold: one hold per eligible order item', 'securehold-security-deposit-holds' );
                        } else {
                            esc_html_e( 'Multi-Hold capability: locked (PRO)', 'securehold-security-deposit-holds' );
                        }
                        ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-minus" style="font-size:16px; width:16px; height:16px; vertical-align:middle; color:#94a3b8;"></span>
                        <?php esc_html_e( 'No Multi-Hold capability', 'securehold-security-deposit-holds' ); ?>
                    <?php endif; ?>
                </span>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $integration['config_note'] ) ) : ?>
            <p class="description" style="margin-top:8px;"><?php echo esc_html( $integration['config_note'] ); ?></p>
            <?php endif; ?>

            <?php if ( ! empty( $integration['cta']['url'] ) && ! empty( $integration['cta']['label'] ) ) : ?>
            <div style="margin-top:12px;">
                <a href="<?php echo esc_url( $integration['cta']['url'] ); ?>" class="sh-btn sh-btn-secondary" target="_blank" rel="noopener">
                    <span class="dashicons dashicons-external" style="font-size:16px; width:16px; height:16px; vertical-align:middle;"></span>
                    <?php echo esc_html( $integration['cta']['label'] ); ?>
                </a>
            </div>
            <?php endif; ?>

            <?php if ( ! empty( $integration['toggle'] ) ) : ?>
            <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--sh-gray-100);">
                <label class="sh-toggle-row" style="display:flex;align-items:center;gap:0.75rem;cursor:pointer;">
                    <input type="checkbox"
                           name="<?php echo esc_attr( $integration['toggle']['option'] ); ?>"
                           value="yes"
                           <?php checked( get_option( $integration['toggle']['option'], 'no' ), 'yes' ); ?>>
                    <span class="sh-label-modern" style="margin:0;cursor:pointer;">
                        <?php echo esc_html( $integration['toggle']['label'] ); ?>
                    </span>
                </label>
                <p class="description" style="margin-top:0.5rem;">
                    <span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;vertical-align:middle;color:var(--sh-gray-400);"></span>
                    <?php echo esc_html( $integration['toggle']['description'] ); ?>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Marker: always present when this tab's form is submitted, so the
             save handler can tell "Integrations was saved" apart from "a
             checkbox rendered here was simply absent/unchecked" — same
             pattern already used by the other tabs' own guard fields. -->
        <input type="hidden" name="securehold_integrations_tab_submitted" value="1">

        <div class="sh-divider-gradient"></div>

        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-randomize" style="color: var(--sh-gray-600);"></span>
            <?php esc_html_e( 'Grouping Source', 'securehold-security-deposit-holds' ); ?>
        </h3>
        <p class="description" style="margin-top: -0.75rem; margin-bottom: 1.25rem;">
            <?php esc_html_e( 'Exactly one integration can originate new Hold Groups at a time. Existing Hold Groups are never affected by this choice.', 'securehold-security-deposit-holds' ); ?>
        </p>

        <?php if ( ! $sh_multi_hold_pro ) : ?>
            <p class="description">
                <span class="dashicons dashicons-lock sh-policy-help-icon"></span>
                <?php esc_html_e( 'Requires the Multi-Hold PRO capability. Select "Multiple Hold Groups" under Hold Structure once PRO is active to configure a grouping source.', 'securehold-security-deposit-holds' ); ?>
            </p>
        <?php else :
            $sh_capable_integrations = array_filter( $sh_integrations, function ( $i ) {
                return ! empty( $i['multi_hold_capable'] ) && ! empty( $i['grouping_source_key'] );
            } );
        ?>
        <input type="hidden" id="sh-grouping-source" name="securehold_multi_hold_grouping_source" value="<?php echo esc_attr( $sh_active_source ); ?>">
        <div class="sh-rule-option-group">
            <?php foreach ( $sh_capable_integrations as $integration ) :
                $key         = $integration['grouping_source_key'];
                $is_declared = in_array( $key, $sh_declared_keys, true );
            ?>
            <div class="sh-rule-option <?php echo $is_declared ? 'sh-rule-option--selectable' : ''; ?> <?php echo $sh_active_source === $key ? 'sh-rule-option--active' : ''; ?>"
                 <?php if ( $is_declared ) : ?>data-group="sh-grouping-source" data-value="<?php echo esc_attr( $key ); ?>"<?php endif; ?>>
                <div>
                    <strong><?php echo esc_html( $integration['name'] ); ?></strong>
                    <?php if ( ! $is_declared ) : ?>
                        <span class="sh-badge sh-badge-secondary"><?php esc_html_e( 'Not available yet', 'securehold-security-deposit-holds' ); ?></span>
                    <?php endif; ?>
                    <p class="description"><?php echo esc_html( $integration['description'] ); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ( ! in_array( $sh_active_source, $sh_declared_keys, true ) ) : ?>
        <p class="description" style="margin-top:8px;">
            <span class="dashicons dashicons-warning sh-policy-help-icon"></span>
            <?php esc_html_e( 'The configured grouping source is not currently available. No new Hold Groups will be created until a valid source is selected.', 'securehold-security-deposit-holds' ); ?>
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
