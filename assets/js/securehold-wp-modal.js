/**
 * SecureHold Modal Controller
 * Handles automatic modal popup for setup wizard
 * Version: 3.0.0
 */

(function($) {
    'use strict';

    const SecureHoldModal = {
        
        /**
         * Initialize modal
         */
        init: function() {
            this.setupEventListeners();
            this.checkAndShowModal();
        },

        /**
         * Setup event listeners
         */
        setupEventListeners: function() {
            // Close button
            $(document).on('click', '.sh-modal-close', function() {
                SecureHoldModal.closeModal();
            });

            // Click outside modal
            $(document).on('click', '.sh-modal-overlay', function(e) {
                if ($(e.target).hasClass('sh-modal-overlay')) {
                    SecureHoldModal.closeModal();
                }
            });

            // Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    SecureHoldModal.closeModal();
                }
            });

            // Start setup button
            $(document).on('click', '#sh-start-setup', function(e) {
                e.preventDefault();
                SecureHoldModal.startSetup();
            });

            // Skip for now button
            $(document).on('click', '#sh-skip-setup', function(e) {
                e.preventDefault();
                SecureHoldModal.skipSetup();
            });
        },

        /**
         * Check if modal should be shown
         */
        checkAndShowModal: function() {
            // Check if setup is incomplete and modal wasn't skipped
            if (window.secureholdModalData && window.secureholdModalData.show_modal) {
                setTimeout(function() {
                    SecureHoldModal.showModal();
                }, 500);
            }
        },

        /**
         * Show modal
         */
        showModal: function() {
            $('.sh-modal-overlay').addClass('active');
            $('body').css('overflow', 'hidden');
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('.sh-modal-overlay').removeClass('active');
            $('body').css('overflow', '');
        },

        /**
         * Start setup wizard
         */
        startSetup: function() {
            // Save that user clicked start setup
            $.post(ajaxurl, {
                action: 'securehold_modal_action',
                modal_action: 'start_setup',
                nonce: window.secureholdModalData.nonce
            }, function() {
                // Redirect to wizard
                window.location.href = window.secureholdModalData.wizard_url;
            });
        },

        /**
         * Skip setup for now
         */
        skipSetup: function() {
            // Save that user dismissed setup
            $.post(ajaxurl, {
                action: 'securehold_modal_action',
                modal_action: 'dismiss',
                nonce: window.secureholdModalData.nonce
            }, function() {
                SecureHoldModal.closeModal();
            });
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        SecureHoldModal.init();
    });

})(jQuery);
