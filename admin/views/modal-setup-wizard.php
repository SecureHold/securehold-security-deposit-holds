<?php
/**
 * SecureHold Setup Wizard Modal
 * Auto-opens after activation to start setup
 * Version: 3.0.3 - COMPACT
 */

if (!defined('ABSPATH')) exit;
?>

<div class="sh-modal-overlay" id="securehold-setup-modal">
    <div class="sh-modal sh-modal-compact">
        <div class="sh-modal-header">
            <h2 class="sh-modal-title">
                <span class="dashicons dashicons-shield-alt" style="color: var(--sh-primary);"></span>
                <?php esc_html_e('Welcome to SecureHold WP!', 'securehold-security-deposit-holds'); ?>
            </h2>
        </div>
        
        <div class="sh-modal-body">
            <p style="font-size: 0.9rem; color: var(--sh-gray-700); margin-bottom: 0.75rem; line-height: 1.5;">
                <?php esc_html_e('Thank you for installing SecureHold WP! To get started with automated security deposits, we need to configure a few settings.', 'securehold-security-deposit-holds'); ?>
            </p>
            
            <p style="font-size: 0.9rem; color: var(--sh-gray-700); margin-bottom: 1rem; line-height: 1.5;">
                <?php esc_html_e('The setup wizard will guide you through installing dependencies, connecting Stripe, and configuring webhooks. It takes about 5 minutes.', 'securehold-security-deposit-holds'); ?>
            </p>
            
            <div style="background: var(--sh-info-light); border-left: 4px solid var(--sh-info); padding: 0.75rem; border-radius: var(--sh-radius-md); margin-bottom: 1rem;">
                <p style="margin: 0; font-size: 0.8rem; color: #1E40AF; line-height: 1.4;">
                    <span class="dashicons dashicons-info" style="vertical-align: middle; font-size: 16px;"></span>
                    <?php esc_html_e('The wizard will automatically install WooCommerce and Stripe Gateway if needed.', 'securehold-security-deposit-holds'); ?>
                </p>
            </div>
            
            <h3 style="font-size: 0.9rem; font-weight: 600; color: var(--sh-gray-900); margin-bottom: 0.5rem;">
                <?php esc_html_e('Setup includes:', 'securehold-security-deposit-holds'); ?>
            </h3>
            
            <ul style="list-style: none; padding: 0; margin: 0;">
                <li style="padding: 0.4rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="dashicons dashicons-yes-alt" style="color: var(--sh-success); font-size: 18px;"></span>
                    <span style="color: var(--sh-gray-700); font-size: 0.85rem;"><?php esc_html_e('Install required plugins (WooCommerce, Stripe)', 'securehold-security-deposit-holds'); ?></span>
                </li>
                <li style="padding: 0.4rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="dashicons dashicons-yes-alt" style="color: var(--sh-success); font-size: 18px;"></span>
                    <span style="color: var(--sh-gray-700); font-size: 0.85rem;"><?php esc_html_e('Install Stripe PHP SDK automatically', 'securehold-security-deposit-holds'); ?></span>
                </li>
                <li style="padding: 0.4rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="dashicons dashicons-yes-alt" style="color: var(--sh-success); font-size: 18px;"></span>
                    <span style="color: var(--sh-gray-700); font-size: 0.85rem;"><?php esc_html_e('Connect your Stripe account', 'securehold-security-deposit-holds'); ?></span>
                </li>
                <li style="padding: 0.4rem 0; display: flex; align-items: center; gap: 0.5rem;">
                    <span class="dashicons dashicons-yes-alt" style="color: var(--sh-success); font-size: 18px;"></span>
                    <span style="color: var(--sh-gray-700); font-size: 0.85rem;"><?php esc_html_e('Configure webhook with 1-click', 'securehold-security-deposit-holds'); ?></span>
                </li>
            </ul>
        </div>
        
        <div class="sh-modal-footer">
            <button type="button" class="sh-btn sh-btn-ghost" id="sh-skip-setup">
                <?php esc_html_e('Skip for now', 'securehold-security-deposit-holds'); ?>
            </button>
            <button type="button" class="sh-btn sh-btn-primary" id="sh-start-setup">
                <span class="dashicons dashicons-arrow-right-alt"></span>
                <?php esc_html_e('Start Setup Wizard', 'securehold-security-deposit-holds'); ?>
            </button>
        </div>
    </div>
</div>


