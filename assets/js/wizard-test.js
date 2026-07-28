/**
 * SecureHold Wizard Test Script
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        $('#securehold-test-config').on('click', function() {
            var $button = $(this);
            var $results = $('#securehold-test-results');
            var $steps = $('#securehold-test-steps');
            var $loading = $('.securehold-test-loading');
            
            // Disable button and show loading
            $button.prop('disabled', true)
                   .html('<span class="dashicons dashicons-update" style="animation: spin 0.6s linear infinite; margin-top: 4px;"></span> ' + secureholdWizardTest.testingText);
            
            // Show results container
            $results.slideDown();
            $steps.html('');
            $loading.show();
            
            // Make AJAX request
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'securehold_test_config',
                    nonce: secureholdWizardTest.nonce
                },
                success: function(response) {
                    // Hide loading spinner
                    $loading.hide();
                    
                    // Display each test step with animation
                    if (response.steps && response.steps.length > 0) {
                        response.steps.forEach(function(step, index) {
                            var icon = 'yes-alt';
                            var statusClass = step.status;
                            
                            if (step.status === 'error') {
                                icon = 'dismiss';
                            } else if (step.status === 'warning') {
                                icon = 'warning';
                            }
                            
                            setTimeout(function() {
                                var $step = $('<div class="securehold-test-step ' + statusClass + '" style="opacity:0;transform:translateX(-10px);">' +
                                    '<span class="dashicons dashicons-' + icon + '"></span>' +
                                    '<div><strong>' + step.name + ':</strong> ' +
                                    '<span>' + step.message + '</span></div>' +
                                '</div>');
                                
                                $steps.append($step);
                                
                                $step.animate({
                                    opacity: 1
                                }, 300);
                            }, index * 150);
                        });
                    }
                    
                    // Update button after all steps are shown
                    setTimeout(function() {
                        if (response.success) {
                            $button.html(secureholdWizardTest.successText)
                                   .removeClass('test-error')
                                   .addClass('test-success');
                        } else {
                            $button.html('<span class="dashicons dashicons-warning" style="margin-top: 4px;"></span> ' + secureholdWizardTest.errorText)
                                   .removeClass('test-success')
                                   .addClass('test-error')
                                   .prop('disabled', false);
                        }

                    }, response.steps.length * 150 + 500);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    $loading.hide();

                    // If the server returned JSON with a steps array, render it exactly
                    // the same way as the success handler so the real failure is visible.
                    if (jqXHR.responseJSON) {
                        var rj = jqXHR.responseJSON;
                        if (rj.steps && rj.steps.length > 0) {
                            rj.steps.forEach(function(step, index) {
                                var icon = step.status === 'error' ? 'dismiss' : (step.status === 'warning' ? 'warning' : 'yes-alt');
                                setTimeout(function() {
                                    var $step = $('<div class="securehold-test-step ' + step.status + '" style="opacity:0;transform:translateX(-10px);">' +
                                        '<span class="dashicons dashicons-' + icon + '"></span>' +
                                        '<div><strong>' + step.name + ':</strong> <span>' + step.message + '</span></div>' +
                                    '</div>');
                                    $steps.append($step);
                                    $step.animate({ opacity: 1 }, 300);
                                }, index * 150);
                            });
                            setTimeout(function() {
                                $button.html('<span class="dashicons dashicons-warning" style="margin-top: 4px;"></span> ' + secureholdWizardTest.errorText)
                                       .removeClass('test-success')
                                       .addClass('test-error')
                                       .prop('disabled', false);
                            }, rj.steps.length * 150 + 500);
                            return;
                        }
                    }

                    // Build a meaningful detail string from whatever the server sent.
                    var detail = '';
                    if (jqXHR.responseJSON) {
                        var rj = jqXHR.responseJSON;
                        detail = (rj.message) || (rj.data && rj.data.message) || '';
                    }
                    if (!detail && jqXHR.responseText) {
                        // Strip HTML tags and truncate to avoid dumping a full PHP stack trace.
                        detail = $('<div>').html(jqXHR.responseText.substring(0, 500)).text().trim().substring(0, 300);
                    }
                    if (!detail && (jqXHR.status || errorThrown)) {
                        detail = 'HTTP ' + jqXHR.status + (errorThrown ? ' \u2013 ' + errorThrown : '');
                    }
                    if (!detail) {
                        detail = secureholdWizardTest.ajaxErrorText;
                    }

                    $steps.html('<div class="securehold-test-step error">' +
                        '<span class="dashicons dashicons-dismiss"></span> ' +
                        '<div><strong>' + secureholdWizardTest.errorLabel + ':</strong> ' + detail + '</div>' +
                    '</div>');

                    $button.html('<span class="dashicons dashicons-warning" style="margin-top: 4px;"></span> ' + secureholdWizardTest.errorText)
                           .removeClass('test-success')
                           .addClass('test-error')
                           .prop('disabled', false);
                }
            });
        });
        
    });
    
    /**
     * Toggle secret visibility (eye icon)
     */
    $(document).on('click', '.toggle-secret-visibility', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var $icon = $button.find('.dashicons');
        var targetId = $button.data('target');
        var $input = $('#' + targetId);
        
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });
    
})(jQuery);

