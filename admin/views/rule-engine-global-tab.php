<?php
/**
 * Rule Engine — Global Configuration Sub-Tab
 * Default fallback configuration for deposits and automation.
 *
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="sh-card sh-card-animated">
    <div class="sh-card-header" style="background: linear-gradient(to right, var(--sh-gray-50), white); border-bottom: 2px solid var(--sh-gray-100);">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="width: 48px; height: 48px; background: linear-gradient(135deg, var(--sh-gray-500), var(--sh-gray-700)); border-radius: var(--sh-radius-lg); display: flex; align-items: center; justify-content: center; box-shadow: var(--sh-shadow-md);">
                <span class="dashicons dashicons-admin-settings" style="color: white; font-size: 24px; width: 24px; height: 24px;"></span>
            </div>
            <div style="flex: 1;">
                <h2 class="sh-card-title" style="margin: 0;">
                    <?php esc_html_e( 'Global Configuration', 'securehold-security-deposit-holds' ); ?>
                    <span class="sh-badge sh-badge-global"><?php esc_html_e( 'Global', 'securehold-security-deposit-holds' ); ?></span>
                </h2>
                <p style="margin: 0.25rem 0 0 0; color: var(--sh-gray-600); font-size: 0.875rem;">
                    <?php esc_html_e( 'Default fallback configuration.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>
    </div>

    <div style="padding: 2rem;">

        <!-- Priority Info Banner -->
        <div class="sh-alert sh-alert-info" style="margin-bottom: 1.5rem;">
            <span class="dashicons dashicons-info"></span>
            <div>
                <strong><?php esc_html_e( 'Resolution Priority', 'securehold-security-deposit-holds' ); ?></strong>
                <p style="margin: 0.25rem 0 0 0;">
                    <?php esc_html_e( 'Product Rule', 'securehold-security-deposit-holds' ); ?>
                    <span style="color: var(--sh-gray-400); margin: 0 0.25rem;">&rsaquo;</span>
                    <?php esc_html_e( 'Category Rule', 'securehold-security-deposit-holds' ); ?>
                    <span style="color: var(--sh-gray-400); margin: 0 0.25rem;">&rsaquo;</span>
                    <strong style="color: #2563eb;"><?php esc_html_e( 'Global Settings', 'securehold-security-deposit-holds' ); ?></strong>
                </p>
            </div>
        </div>

        <!-- Rule Conflict Strategy Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-randomize" style="color: var(--sh-gray-600);"></span>
            <?php esc_html_e( 'Rule Conflict Strategy', 'securehold-security-deposit-holds' ); ?>
        </h3>
        <p class="description" style="margin-top: -0.75rem; margin-bottom: 1.25rem;">
            <?php esc_html_e( 'Defines how competing rules are resolved when multiple deposits apply.', 'securehold-security-deposit-holds' ); ?>
        </p>

        <?php
        $resolution_policy = get_option( 'securehold_resolution_policy', 'priority_chain' );
        ?>
        <input type="hidden"
               id="sh-resolution-policy"
               name="securehold_resolution_policy"
               value="<?php echo esc_attr( $resolution_policy ); ?>">
        <div class="sh-rule-option-group">
            <div class="sh-rule-option sh-rule-option--selectable <?php echo $resolution_policy === 'priority_chain' ? 'sh-rule-option--active' : ''; ?>"
                 data-group="sh-resolution-policy" data-value="priority_chain">
                <div>
                    <strong><?php esc_html_e( 'Priority Chain', 'securehold-security-deposit-holds' ); ?></strong>
                    <span class="sh-recommended-badge"><?php esc_html_e( 'Recommended', 'securehold-security-deposit-holds' ); ?></span>
                    <p class="description"><?php esc_html_e( 'Product rule overrides category, overrides global. First match at the highest-priority level wins.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
            <div class="sh-rule-option sh-rule-option--selectable <?php echo $resolution_policy === 'highest_deposit_wins' ? 'sh-rule-option--active' : ''; ?>"
                 data-group="sh-resolution-policy" data-value="highest_deposit_wins">
                <div>
                    <strong><?php esc_html_e( 'Highest Deposit Wins', 'securehold-security-deposit-holds' ); ?></strong>
                    <p class="description"><?php esc_html_e( 'All applicable rules are evaluated. The candidate with the highest resolved deposit amount wins.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
            <div id="sh-policy-help-priority-chain"
                 class="sh-policy-help <?php echo $resolution_policy !== 'priority_chain' ? 'sh-policy-help--hidden' : ''; ?>">
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'Product rule overrides category, overrides global. First match at the highest-priority level wins. This is the default behavior.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
            <div id="sh-policy-help-highest-deposit-wins"
                 class="sh-policy-help <?php echo $resolution_policy !== 'highest_deposit_wins' ? 'sh-policy-help--hidden' : ''; ?>">
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'All applicable rules (product, category, global) are evaluated for the order. The candidate with the highest resolved deposit amount wins. Tie-break: strategy priority, then rule type, then ID.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>
        <div class="sh-divider-gradient"></div>

        <?php
        /**
         * Resolution Engine Version — only visible when WP_DEBUG is enabled.
         * Default is V2 (deterministic). Legacy is a developer/debug option.
         */
        $engine_version = get_option( 'securehold_engine_version', 'v2' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) :
        ?>
        <!-- Resolution Engine Version (debug only) -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-admin-tools sh-section-icon"></span>
            <?php esc_html_e( 'Resolution Engine Version', 'securehold-security-deposit-holds' ); ?>
            <span class="sh-badge sh-badge--debug">DEBUG</span>
        </h3>
        <input type="hidden"
               id="sh-engine-version"
               name="securehold_engine_version"
               value="<?php echo esc_attr( $engine_version ); ?>">
        <div class="sh-rule-option-group">
            <div class="sh-rule-option sh-rule-option--selectable <?php echo $engine_version === 'v2' ? 'sh-rule-option--active' : ''; ?>"
                 data-group="sh-engine-version" data-value="v2">
                <div>
                    <strong><?php esc_html_e( 'V2 — Deterministic', 'securehold-security-deposit-holds' ); ?></strong>
                    <p class="description"><?php esc_html_e( 'Stable tie-breaking so the result never depends on cart item order. Recommended.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
            <div class="sh-rule-option sh-rule-option--selectable <?php echo $engine_version === 'legacy' ? 'sh-rule-option--active' : ''; ?>"
                 data-group="sh-engine-version" data-value="legacy">
                <div>
                    <strong><?php esc_html_e( 'Legacy — First match wins', 'securehold-security-deposit-holds' ); ?></strong>
                    <p class="description"><?php esc_html_e( 'Preserves original behavior. The winning rule may depend on cart item order.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
            <div id="sh-engine-help-v2"
                 class="sh-policy-help <?php echo $engine_version !== 'v2' ? 'sh-policy-help--hidden' : ''; ?>">
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'V2 uses stable tie-breaking (amount DESC, strategy priority ASC, source ID ASC) so the result never depends on cart item order. Per Item Aggregated mode respects the active policy per item.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
            <div id="sh-engine-help-legacy"
                 class="sh-policy-help <?php echo $engine_version !== 'legacy' ? 'sh-policy-help--hidden' : ''; ?>">
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'Legacy mode preserves the original "first product rule wins" behavior. The winning rule may depend on the order items appear in the cart. Per Item Aggregated always uses Priority Chain.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>

        <div class="sh-divider-gradient"></div>
        <?php else : ?>
        <!-- Engine version is V2 (default) — hidden when WP_DEBUG is off -->
        <input type="hidden" name="securehold_engine_version" value="<?php echo esc_attr( $engine_version ); ?>">
        <?php endif; ?>

        <!-- Aggregation Mode Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-networking" style="color: var(--sh-gray-600);"></span>
            <?php esc_html_e( 'Aggregation Mode', 'securehold-security-deposit-holds' ); ?>
        </h3>

        <?php
        $aggregation_mode = get_option( 'securehold_aggregation_mode', 'per_order' );
        ?>
        <input type="hidden"
               id="sh-aggregation-mode"
               name="securehold_aggregation_mode"
               value="<?php echo esc_attr( $aggregation_mode ); ?>">
        <div class="sh-rule-option-group">
            <div class="sh-rule-option sh-rule-option--selectable <?php echo $aggregation_mode === 'per_order' ? 'sh-rule-option--active' : ''; ?>"
                 data-group="sh-aggregation-mode" data-value="per_order">
                <div>
                    <strong><?php esc_html_e( 'Per Order', 'securehold-security-deposit-holds' ); ?></strong>
                    <span class="sh-recommended-badge"><?php esc_html_e( 'Recommended', 'securehold-security-deposit-holds' ); ?></span>
                    <p class="description"><?php esc_html_e( 'One deposit calculated for the entire cart. A single winning rule determines the deposit amount for the whole order.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
            <div class="sh-rule-option sh-rule-option--selectable <?php echo $aggregation_mode === 'per_item_aggregated' ? 'sh-rule-option--active' : ''; ?>"
                 data-group="sh-aggregation-mode" data-value="per_item_aggregated">
                <div>
                    <strong><?php esc_html_e( 'Per Item Aggregated', 'securehold-security-deposit-holds' ); ?></strong>
                    <p class="description"><?php esc_html_e( 'Each product resolved independently, then combined. The most restrictive strategy wins.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
            <div id="sh-agg-help-per-order"
                 class="sh-policy-help <?php echo $aggregation_mode !== 'per_order' ? 'sh-policy-help--hidden' : ''; ?>">
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'One deposit calculated for the entire cart. A single winning rule determines the deposit amount for the whole order.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
            <div id="sh-agg-help-per-item"
                 class="sh-policy-help <?php echo $aggregation_mode !== 'per_item_aggregated' ? 'sh-policy-help--hidden' : ''; ?>">
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'Each product resolved independently, then combined. The most restrictive strategy wins.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>
        <div class="sh-divider-gradient"></div>

        <!-- Deposit Amount Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-money-alt sh-section-icon"></span>
            <?php esc_html_e( 'Default Deposit Amount', 'securehold-security-deposit-holds' ); ?>
        </h3>

        <div class="sh-input-grid-2">
            <div class="sh-input-field">
                <label class="sh-label-modern"><?php esc_html_e( 'Default Hold Amount', 'securehold-security-deposit-holds' ); ?></label>
                <div class="sh-input-wrapper">
                    <input type="text" name="securehold_default_hold_amount" value="<?php echo esc_attr( $deposit_amount ); ?>" placeholder="e.g. 300 or 20%" class="sh-input-modern">
                </div>
                <p class="description"><?php esc_html_e( 'Fixed amount or percentage. Used when no Product/Category Rule overrides it.', 'securehold-security-deposit-holds' ); ?></p>
            </div>

            <div class="sh-input-field">
                <label class="sh-label-modern"><?php esc_html_e( 'Auto-Release After (Days)', 'securehold-security-deposit-holds' ); ?></label>
                <div class="sh-input-wrapper">
                    <input type="number" name="securehold_auto_release_days" value="<?php echo esc_attr( $auto_release_days ); ?>" min="1" max="7" class="sh-input-modern">
                </div>
                <p class="description"><?php esc_html_e( 'Automatically release the blocked amount after X days (1 to 7, default: 7).', 'securehold-security-deposit-holds' ); ?></p>
            </div>
        </div>

        <div class="sh-divider-gradient"></div>

        <!-- Minimum Cart Amount Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-cart sh-section-icon"></span>
            <?php esc_html_e( 'Minimum Cart Amount', 'securehold-security-deposit-holds' ); ?>
        </h3>

        <?php $min_cart_amount = get_option( 'securehold_min_cart_amount', '' ); ?>
        <div class="sh-section-fields">
            <div class="sh-input-field">
                <label class="sh-label-modern"><?php esc_html_e( 'Minimum cart total to require a deposit', 'securehold-security-deposit-holds' ); ?></label>
                <div class="sh-input-wrapper">
                    <input type="number" name="securehold_min_cart_amount" value="<?php echo esc_attr( $min_cart_amount ); ?>" min="0" step="0.01" class="sh-input-modern" placeholder="0">
                </div>
                <p class="description">
                    <span class="dashicons dashicons-info-outline sh-policy-help-icon"></span>
                    <?php esc_html_e( 'Leave empty or set to 0 to always require a deposit. If set, orders below this amount (incl. tax) will skip the deposit entirely.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>

        <div class="sh-divider-gradient"></div>

        <!-- Product & Category Exclusions Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-dismiss sh-section-icon"></span>
            <?php esc_html_e( 'Exclusions', 'securehold-security-deposit-holds' ); ?>
        </h3>
        <p class="description sh-section-description">
            <?php esc_html_e( 'Products and categories listed here will never trigger a deposit, even if a rule applies.', 'securehold-security-deposit-holds' ); ?>
        </p>

        <?php
        $excluded_products   = get_option( 'securehold_excluded_products', array() );
        $excluded_categories = get_option( 'securehold_excluded_categories', array() );
        if ( ! is_array( $excluded_products ) )   $excluded_products   = array();
        if ( ! is_array( $excluded_categories ) ) $excluded_categories = array();
        ?>
        <div class="sh-exclusions-grid">
            <div class="sh-input-field">
                <label class="sh-label-modern"><?php esc_html_e( 'Excluded Products', 'securehold-security-deposit-holds' ); ?></label>
                <select class="sh-product-search sh-select-modern" multiple="multiple"
                        name="securehold_excluded_products[]"
                        data-placeholder="<?php esc_attr_e( 'Search for products…', 'securehold-security-deposit-holds' ); ?>">
                    <?php foreach ( $excluded_products as $pid ) :
                        $product = wc_get_product( $pid );
                        if ( $product ) : ?>
                            <option value="<?php echo esc_attr( $pid ); ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
                        <?php endif;
                    endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e( 'These products will never require a deposit.', 'securehold-security-deposit-holds' ); ?></p>
            </div>

            <div class="sh-input-field">
                <label class="sh-label-modern"><?php esc_html_e( 'Excluded Categories', 'securehold-security-deposit-holds' ); ?></label>
                <select class="wc-enhanced-select sh-select-modern" multiple="multiple"
                        name="securehold_excluded_categories[]"
                        data-placeholder="<?php esc_attr_e( 'Select categories…', 'securehold-security-deposit-holds' ); ?>">
                    <?php
                    $all_cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
                    if ( ! is_wp_error( $all_cats ) ) :
                        foreach ( $all_cats as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( $cat->term_id, $excluded_categories, false ) ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach;
                    endif;
                    ?>
                </select>
                <p class="description"><?php esc_html_e( 'Products in these categories will never require a deposit.', 'securehold-security-deposit-holds' ); ?></p>
            </div>
        </div>

        <div class="sh-divider-gradient"></div>

        <!-- Automation Strategy Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-clock" style="color: var(--sh-gray-600);"></span>
            <?php esc_html_e( 'Default Automation Strategy', 'securehold-security-deposit-holds' ); ?>
        </h3>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 2rem;">
            <label class="timing-card <?php echo $capture_timing === 'immediate' ? 'selected' : ''; ?>">
                <input type="radio" name="securehold_capture_timing" value="immediate" <?php checked( $capture_timing, 'immediate' ); ?>>
                <span class="dashicons dashicons-yes-alt"></span>
                <strong><?php esc_html_e( 'Immediate', 'securehold-security-deposit-holds' ); ?></strong>
            </label>
            <?php do_action( 'securehold_timing_cards', $capture_timing ); ?>
            <label class="timing-card <?php echo $capture_timing === 'manual' ? 'selected' : ''; ?>">
                <input type="radio" name="securehold_capture_timing" value="manual" <?php checked( $capture_timing, 'manual' ); ?>>
                <span class="dashicons dashicons-admin-users"></span>
                <strong><?php esc_html_e( 'Manual', 'securehold-security-deposit-holds' ); ?></strong>
            </label>
        </div>

        <div class="sh-divider-gradient"></div>

        <!-- Strategy-specific panels (same as original automation tab) -->
        <div id="setting-immediate" class="timing-settings" style="display: <?php echo $capture_timing === 'immediate' ? 'block' : 'none'; ?>;">
            <div class="sh-alert sh-alert-success" style="background: #f0fdf4; border-color: #10b981;">
                <span class="dashicons dashicons-yes-alt" style="color: #10b981;"></span>
                <div>
                    <strong><?php esc_html_e( 'Instant Protection', 'securehold-security-deposit-holds' ); ?></strong>
                    <p><?php esc_html_e( 'The security deposit will be secured immediately after the order is placed.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
        </div>

        <?php do_action( 'securehold_timing_panels', $capture_timing ); ?>

        <div id="setting-manual" class="timing-settings" style="display: <?php echo $capture_timing === 'manual' ? 'block' : 'none'; ?>;">
            <div class="sh-alert sh-alert-success" style="background: #f0f9ff; border-color: #0ea5e9;">
                <span class="dashicons dashicons-info" style="color: #0ea5e9;"></span>
                <div>
                    <strong><?php esc_html_e( 'Automation Disabled', 'securehold-security-deposit-holds' ); ?></strong>
                    <p><?php esc_html_e( 'No security deposits will be created automatically. You must click "Create Hold Now" on each order page.', 'securehold-security-deposit-holds' ); ?></p>
                </div>
            </div>
        </div>

        <div class="sh-divider-gradient"></div>

        <!-- Deposit Failure Handling Section -->
        <h3 class="sh-section-heading">
            <span class="dashicons dashicons-shield-alt" style="color: var(--sh-gray-600);"></span>
            <?php esc_html_e( 'Deposit Failure Handling', 'securehold-security-deposit-holds' ); ?>
        </h3>

        <?php $require_deposit_auth = get_option( 'securehold_require_deposit_auth', '1' ); ?>
        <div style="margin-bottom: 2rem;">
            <div class="sh-input-field">
                <label class="sh-toggle-row" style="display:flex;align-items:center;gap:0.75rem;cursor:pointer;">
                    <input type="checkbox"
                           name="securehold_require_deposit_auth"
                           value="1"
                           <?php checked( $require_deposit_auth, '1' ); ?>>
                    <span class="sh-label-modern" style="margin:0;cursor:pointer;">
                        <?php esc_html_e( 'Require successful deposit authorization to place an order', 'securehold-security-deposit-holds' ); ?>
                    </span>
                </label>
                <p class="description" style="margin-top:0.5rem;">
                    <span class="dashicons dashicons-info-outline" style="font-size:14px;width:14px;height:14px;vertical-align:middle;color:var(--sh-gray-400);"></span>
                    <?php esc_html_e( 'When enabled, if the security deposit authorization fails after payment, the order will be placed on hold and the customer will be notified. When disabled, orders proceed normally and the deposit failure is logged for manual review.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
        </div>

        <!-- Applied Configuration Preview (same as Product/Category Rules) -->
        <div id="sh-global-config-preview" class="sh-config-preview" style="margin-top: 1.5rem;">
            <div class="sh-config-preview-header">
                <span class="dashicons dashicons-visibility"></span>
                <strong><?php esc_html_e( 'Applied Configuration Preview', 'securehold-security-deposit-holds' ); ?></strong>
                <p class="description" style="margin: 0.25rem 0 0 0; font-weight: normal;">
                    <?php esc_html_e( 'This is the effective configuration applied at runtime.', 'securehold-security-deposit-holds' ); ?>
                </p>
            </div>
            <div id="sh-global-config-preview-content" class="sh-config-preview-content">
                <!-- Populated dynamically by JS -->
            </div>
        </div>

    </div>
</div>
