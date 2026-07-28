<?php
/**
 * Deactivation Feedback Modal
 */
if (!defined('ABSPATH')) exit;
?>

<div id="securehold-deactivation-modal" class="securehold-deactivation-overlay" style="display: none;">
    <div class="securehold-deactivation-modal securehold-deactivation-dialog">

        <div class="securehold-modal-header">
            <h2><?php esc_html_e('Quick Feedback', 'securehold-security-deposit-holds'); ?></h2>
            <p><?php esc_html_e("If you have a moment, please let us know why you're deactivating", 'securehold-security-deposit-holds'); ?>
               <strong id="securehold-modal-plugin-name">SecureHold WP</strong>:</p>
        </div>

        <form id="securehold-deactivation-form">
            <input type="hidden" id="securehold-plugin-type" name="plugin" value="free">
            <div class="securehold-modal-body">
                <div class="securehold-reason-list">
                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="no_longer_needed" data-show-textarea="false">
                        <span><?php esc_html_e('I no longer need the plugin', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="found_better" data-show-textarea="true" data-placeholder="<?php esc_attr_e('Which plugin are you using instead?', 'securehold-security-deposit-holds'); ?>">
                        <span><?php esc_html_e('I found a better plugin', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="missing_features" data-show-textarea="true" data-placeholder="<?php esc_attr_e('Which features are you missing?', 'securehold-security-deposit-holds'); ?>">
                        <span><?php esc_html_e('The plugin is missing key features', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="broken" data-show-textarea="true" data-placeholder="<?php esc_attr_e('Please describe the issue...', 'securehold-security-deposit-holds'); ?>">
                        <span><?php esc_html_e('The plugin doesn\'t work / broke my site', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="temporary" data-show-textarea="false">
                        <span><?php esc_html_e('It\'s a temporary deactivation', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="too_expensive" data-show-textarea="true" data-placeholder="<?php esc_attr_e('What price would be reasonable?', 'securehold-security-deposit-holds'); ?>">
                        <span><?php esc_html_e('The plugin is too expensive', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="difficult_to_use" data-show-textarea="true" data-placeholder="<?php esc_attr_e('What was confusing?', 'securehold-security-deposit-holds'); ?>">
                        <span><?php esc_html_e('The plugin is difficult to use', 'securehold-security-deposit-holds'); ?></span>
                    </label>

                    <label class="securehold-reason-item">
                        <input type="radio" name="reason" value="other" data-show-textarea="true" data-placeholder="<?php esc_attr_e('Please tell us more...', 'securehold-security-deposit-holds'); ?>">
                        <span><?php esc_html_e('Other', 'securehold-security-deposit-holds'); ?></span>
                    </label>
                </div>

                <div id="securehold-reason-details" style="display: none;">
                    <textarea
                        id="securehold-reason-textarea"
                        name="details"
                        rows="4"
                        placeholder="<?php esc_attr_e('Please tell us more...', 'securehold-security-deposit-holds'); ?>"
                    ></textarea>
                </div>
            </div><!-- /.securehold-modal-body -->

            <div class="securehold-modal-footer">
                <button type="button" class="button" id="securehold-cancel-deactivation">
                    <?php esc_html_e('Cancel', 'securehold-security-deposit-holds'); ?>
                </button>
                <button type="button" class="button" id="securehold-skip-deactivation">
                    <?php esc_html_e('Skip & Deactivate', 'securehold-security-deposit-holds'); ?>
                </button>
                <button type="submit" class="button button-primary" id="securehold-submit-deactivation">
                    <?php esc_html_e('Submit & Deactivate', 'securehold-security-deposit-holds'); ?>
                </button>
            </div>
        </form>

    </div>
</div>

