<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Setup Wizard View
$steps = Securehold_Setup_Wizard::get_steps();
$current_step = isset($_GET['step']) ? intval($_GET['step']) : 1;
$total_steps = count($steps);

settings_errors('securehold_wizard');

// Stripe credential verdict from the API Keys step. Stored in a transient
// because that handler redirects, which discards add_settings_error().
$securehold_stripe_notice = get_transient( 'securehold_wizard_stripe_notice_' . get_current_user_id() );
if ( is_array( $securehold_stripe_notice ) ) {
    delete_transient( 'securehold_wizard_stripe_notice_' . get_current_user_id() );
} else {
    $securehold_stripe_notice = null;
}
?>

<?php if ( $securehold_stripe_notice ) :
    $sh_notice_class = in_array( $securehold_stripe_notice['type'], array( 'success', 'warning', 'error' ), true )
        ? 'notice-' . $securehold_stripe_notice['type']
        : 'notice-info';
    ?>
    <div class="notice <?php echo esc_attr( $sh_notice_class ); ?>" style="margin: 1rem 0;">
        <p><strong><?php echo esc_html( $securehold_stripe_notice['message'] ); ?></strong></p>
        <?php if ( ! empty( $securehold_stripe_notice['details'] ) ) : ?>
            <ul style="margin: 0 0 1em 1.5em; list-style: disc;">
                <?php foreach ( $securehold_stripe_notice['details'] as $sh_notice_detail ) : ?>
                    <li><?php echo esc_html( $sh_notice_detail ); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="wrap securehold-wrapper securehold-setup-wizard">
    
    <div class="sh-page-header" style="background: linear-gradient(135deg, var(--sh-primary, #2563eb) 0%, #1e40af 100%); color: white; position: relative; overflow: hidden; padding: 2rem; border-radius: 0.75rem; margin-bottom: 2rem;">
        
        <div style="position: absolute; top: -50px; right: -50px; width: 200px; height: 200px; background: rgba(255,255,255,0.1); border-radius: 50%; z-index: 1; pointer-events: none;"></div>
        <div style="position: absolute; bottom: -30px; left: -30px; width: 150px; height: 150px; background: rgba(255,255,255,0.05); border-radius: 50%; z-index: 1; pointer-events: none;"></div>
        
        <div style="position: relative; z-index: 2; text-align: center;">
            <h1 class="sh-page-title" style="color: white; margin: 0; font-size: 2em; display: inline-flex; align-items: center; gap: 10px;">
                <span class="dashicons dashicons-admin-generic" style="background: rgba(255,255,255,0.2); padding: 8px; border-radius: 50%;"></span>
                <?php esc_html_e('SecureHold WP Setup Wizard', 'securehold-security-deposit-holds'); ?>
            </h1>
        </div>
    </div>
    
    <div class="securehold-wizard-progress">
        <?php foreach ($steps as $step_num => $step) : ?>
            <div class="wizard-step <?php echo $step_num <= $current_step ? 'active' : ''; ?> <?php echo $step_num === $current_step ? 'current' : ''; ?>">
                <div class="step-number"><?php echo absint( $step_num ); ?></div>
                <div class="step-title"><?php echo esc_html($step['title']); ?></div>
            </div>
            <?php if ($step_num < $total_steps) : ?>
                <div class="wizard-connector <?php echo $step_num < $current_step ? 'completed' : ''; ?>"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <div class="securehold-wizard-content">
        <?php
        switch ($current_step) {
            case 1:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-welcome.php';
                break;
            case 2:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-dependencies.php';
                break;
            case 3:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-woocommerce-config.php';
                break;
            case 4:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-stripe-sdk.php';
                break;
            case 5:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-api-keys.php';
                break;
            case 6:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-webhook.php';
                break;
            case 7:
                require_once SECUREHOLD_PLUGIN_DIR . 'admin/views/wizard/step-complete.php';
                break;
        }
        ?>
    </div>
</div>
