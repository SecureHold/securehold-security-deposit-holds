/**
 * SecureHold Admin Scripts
 * Version: 3.9.4 - Fix Settings Automation UI
 */
jQuery(document).ready(function($) {
    
    // ============================================
    // 1. INITIALIZATION
    // ============================================
    var select2Fn = $.fn.selectWoo ? 'selectWoo' : 'select2';

    if ($.fn[select2Fn] && $('#securehold_product_search').length) {
        $('#securehold_product_search')[select2Fn]({
            minimumInputLength: 0,
            allowClear: true,
            placeholder: $('#securehold_product_search').data('placeholder'),
            ajax: {
                url: secureholdAdminParams.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'securehold_search_products',
                        term: params.term || '',
                        security: secureholdAdminParams.nonces.productSearch
                    };
                },
                processResults: function (data) {
                    // Support both formats: {results:[...]} and {id:text,...}
                    if (data && data.results) {
                        return data;
                    }
                    var terms = [];
                    if (data) {
                        $.each(data, function(id, text) {
                            terms.push({ id: id, text: text });
                        });
                    }
                    return { results: terms };
                },
                cache: true
            }
        });
    }

    // Excluded Products multi-select (settings page exclusions).
    // Uses minimumInputLength: 0 so products appear immediately on open.
    if ($.fn[select2Fn] && $('.sh-product-search').length) {
        $('.sh-product-search').each(function () {
            var $el = $(this);
            $el[select2Fn]({
                minimumInputLength: 0,
                allowClear: true,
                placeholder: $el.data('placeholder') || '',
                ajax: {
                    url: secureholdAdminParams.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'securehold_search_products',
                            term: params.term || '',
                            security: secureholdAdminParams.nonces.productSearch
                        };
                    },
                    processResults: function (data) {
                        if (data && data.results) {
                            return data;
                        }
                        var terms = [];
                        if (data) {
                            $.each(data, function (id, text) {
                                terms.push({ id: id, text: text });
                            });
                        }
                        return { results: terms };
                    },
                    cache: true
                }
            });
        });
    }

    // ============================================
    // 2. SETTINGS PAGE: AUTOMATION TABS (TRIGGER STRATEGY)
    // ============================================
    // Fix: Re-implemented logic using proper Event Listeners instead of inline HTML onchange
    
    var $timingInputs = $('input[name="securehold_capture_timing"]');
    
    function updateTimingUI() {
        // Get currently checked value
        var selectedValue = $timingInputs.filter(':checked').val();
        
        if (!selectedValue) return;

        // 1. Hide all description sections
        $('.timing-settings').hide();
        
        // 2. Remove 'selected' class from all cards
        $('.timing-card').removeClass('selected');
        
        // 3. Show the relevant section
        $('#setting-' + selectedValue).fadeIn(200);
        
        // 4. Add 'selected' class to the active card
        $timingInputs.filter(':checked').closest('.timing-card').addClass('selected');
    }

    if ($timingInputs.length > 0) {
        // Initialize state on page load
        // (Hide all first to ensure clean state, then update)
        $('.timing-settings').hide(); 
        updateTimingUI();

        // Listen for changes
        $timingInputs.on('change', function() {
            updateTimingUI();
        });
    }
    
    // ============================================
    // 2B. SETTINGS PAGE: STRIPE MODE TOGGLE (Test / Live)
    // Shows/hides the correct API key fields immediately on radio change,
    // without requiring a page reload. Both field groups are pre-rendered in PHP.
    // ============================================

    (function initStripeModeToggle() {
        var $modeRadios     = $('input[name="securehold_stripe_mode"]');
        var $testFields     = $('#sh-api-fields-test');
        var $liveFields     = $('#sh-api-fields-live');

        if ( ! $modeRadios.length || ! $testFields.length || ! $liveFields.length ) {
            return;
        }

        function updateModeFields() {
            var mode = $modeRadios.filter(':checked').val();
            if ( mode === 'live' ) {
                $testFields.hide();
                $liveFields.show();
            } else {
                $liveFields.hide();
                $testFields.show();
            }
        }

        $modeRadios.on('change', updateModeFields);
        // Run once on page load to synchronise with saved mode value.
        updateModeFields();
    })();

    // ============================================
    // 2C. SETTINGS PAGE: SCHEDULED TIMING TOGGLE
    // ============================================
    
    /**
     * Toggle visibility of "Number of days" field based on direction
     * Hides the number field when "On the same day" is selected
     */
    function initScheduledTimingToggle() {
        var $daysNumberField = $('#securehold_scheduled_days_number');
        var $directionSelect = $('#securehold_scheduled_direction');
        var $descriptionEl = $('#scheduled-timing-description');
        var $numberWrapper = $daysNumberField.length ? $daysNumberField.closest('div[style*="flex: 0 0 120px"]') : $();

        // Exit if elements not found (not on settings page)
        if (!$daysNumberField.length || !$directionSelect.length || !$numberWrapper.length) {
            return;
        }

        /**
         * Update number field visibility and help text based on direction + days
         */
        function updateScheduledTimingUI() {
            var direction = $directionSelect.val();
            var isSameDay = direction === 'same_day';

            if (isSameDay) {
                // Hide number field for "same day" option
                $numberWrapper.hide();
                $daysNumberField.val('0');
            } else {
                // Show number field for "before" or "after" options
                $numberWrapper.show();

                // Restore to 1 if was 0 or empty
                var currentVal = $daysNumberField.val();
                if (currentVal === '0' || currentVal === '') {
                    $daysNumberField.val('1');
                }
            }

            // Update dynamic help text
            if ($descriptionEl.length) {
                var days = parseInt($daysNumberField.val(), 10) || 0;

                if (isSameDay || days === 0) {
                    $descriptionEl.text(
                        secureholdAdminParams.i18n.scheduledSameDay || 'The deposit will be captured on the same day as the specified date.'
                    );
                } else if (direction === 'after') {
                    $descriptionEl.text(
                        (secureholdAdminParams.i18n.scheduledAfter || 'The deposit will be captured %d days after the specified date.')
                            .replace('%d', days)
                    );
                } else {
                    // "before" (default)
                    $descriptionEl.text(
                        (secureholdAdminParams.i18n.scheduledBefore || 'The deposit will be captured %d days before the specified date.')
                            .replace('%d', days)
                    );
                }
            }
        }

        // Set initial state on page load
        updateScheduledTimingUI();

        // Listen for direction changes
        $directionSelect.on('change', updateScheduledTimingUI);

        // Listen for days number changes (real-time update)
        $daysNumberField.on('input change', updateScheduledTimingUI);
    }

    // Initialize scheduled timing toggle
    initScheduledTimingToggle();

    // ============================================
    // 3. PRODUCT CONFIGURATION LOGIC
    // ============================================
    
    function loadProductIntoForm(productId, productName) {
        var $container = $('#sh-custom-settings-panel');
        var $enableCheckbox = $('#sh-enable-product-config');
        var $displayArea = $('#sh-selected-product-display');
        var $modalInput = $('#sh-modal-product-id');
        
        $modalInput.val(productId);
        $('#sh-selected-product-name').text(productName);
        $('#sh-selected-product-meta').text('ID: ' + productId);
        $displayArea.slideDown(200);

        // Auto-Enable
        $enableCheckbox.prop('checked', true);

        if (!$container.is(':visible')) {
            $container.slideDown(300);
        }
        $container.css('opacity', '0.5');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_get_product_settings',
                product_id: productId,
                nonce: secureholdAdminParams.nonces.productConfig 
            },
            success: function(response) {
                if (response.success) {
                    var settings = response.data;
                    $('#sh-product-deposit-amount').val(settings.deposit_amount || '');
                    $('#sh-product-timing').val(settings.capture_timing || '');
                    $('#sh-product-delay-days').val(settings.delay_days || '');
                    $('#sh-product-date-field-key').val(settings.date_field_key || '');
                    $('#sh-product-trigger-status').val(settings.trigger_status || '');

                    // Scheduled timing: new fields with backward compat
                    var schedDays = settings.scheduled_days;
                    var schedDir = settings.scheduled_direction;

                    // Backward compat: if new fields empty but old days_before_date exists
                    if ((!schedDays && schedDays !== '0' && schedDays !== 0) && !schedDir) {
                        var oldDays = settings.days_before_date;
                        if (oldDays && oldDays !== '' && oldDays !== '0') {
                            schedDays = oldDays;
                            schedDir = 'before';
                        }
                    }

                    $('#sh-product-scheduled-days').val(schedDays || '');
                    $('#sh-product-scheduled-direction').val(schedDir || '');
                    // Sync hidden legacy field
                    $('#sh-product-days-before-date').val(settings.days_before_date || '');

                    $('#sh-product-timing').trigger('change');
                }
            },
            complete: function() {
                $container.css('opacity', '1');
            }
        });
    }

    $('#securehold_product_search').on('change', function() {
        var productId = $(this).val();
        if (productId) {
            var data = $(this)[select2Fn]('data');
            var productText = (data && data[0]) ? data[0].text : 'Product #' + productId;
            loadProductIntoForm(productId, productText);
        } else {
            $('#sh-enable-product-config').prop('checked', false);
            $('#sh-custom-settings-panel').slideUp(300);
            $('#sh-selected-product-display').slideUp(200);
            $('#sh-product-deposit-amount').val('');
        }
    });

    $(document).on('click', '.sh-edit-product-config', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('text');
        var $btn = $(this);
        var $panel = $('#sh-custom-settings-panel');
        var $display = $('#sh-selected-product-display');
        var currentProductId = $('#sh-modal-product-id').val();
        
        // Toggle behavior: if clicking the same product again, close the form
        if (currentProductId == id && $panel.is(':visible')) {
            // Close the form
            $panel.slideUp(300);
            $display.slideUp(200);
            $('#sh-modal-product-id').val('');
            
            // Remove active state from all Edit buttons
            $('.sh-edit-product-config').removeClass('sh-btn-active');
            
            // Clear form fields
            $('#sh-product-deposit-amount').val('');
            $('#sh-product-timing').val('');
            $('#sh-product-delay-days').val('');
            $('#sh-product-date-field-key').val('');
            $('#sh-product-days-before-date').val('');
            $('#sh-product-scheduled-days').val('');
            $('#sh-product-scheduled-direction').val('');
            $('#sh-product-trigger-status').val('');
            $('#sh-product-timing').trigger('change');
            
        } else {
            // Open/switch to this product
            $('html, body').animate({
                scrollTop: $("#sh-product-selector-field").offset().top - 100
            }, 500);
            
            // Remove active state from all Edit buttons, then add to current
            $('.sh-edit-product-config').removeClass('sh-btn-active');
            $btn.addClass('sh-btn-active');
            
            loadProductIntoForm(id, name);
        }
    });

    $(document).on('click', '.sh-delete-product-config', function(e) {
        e.preventDefault();
        if(!confirm('Are you sure you want to remove custom settings for this product?')) return;

        var id = $(this).data('id');
        var $row = $('#sh-product-row-' + id);
        $row.css('opacity', '0.5');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_delete_product_settings',
                product_id: id,
                nonce: secureholdAdminParams.nonces.productConfig
            },
            success: function(response) {
                if (response.success) {
                    $row.slideUp(300, function() { $(this).remove(); });
                    if ($('#sh-modal-product-id').val() == id) {
                         $('#sh-custom-settings-panel').slideUp();
                         $('#sh-selected-product-display').slideUp();
                         $('#sh-modal-product-id').val('');
                    }
                } else {
                    alert('Error removing settings');
                    $row.css('opacity', '1');
                }
            }
        });
    });

    // Timing sub-options (Product Config) - Show/hide conditional fields
    $('#sh-product-timing').on('change', function() {
        var timing = $(this).val();

        // Hide all conditional fields first
        $('#sh-product-delay-days-field').hide();
        $('#sh-product-scheduled-block').hide();
        $('#sh-product-trigger-status-field').hide();

        // Legacy support for old modal system
        $('.sh-product-timing-setting').hide();
        if (timing) {
            $('#sh-product-timing-' + timing).show();
        }

        // Show relevant fields based on selection (Product Rules Tab)
        if (timing === 'delayed') {
            $('#sh-product-delay-days-field').show();
        } else if (timing === 'scheduled') {
            $('#sh-product-scheduled-block').show();
            updateProductScheduledUI();
        } else if (timing === 'status') {
            $('#sh-product-trigger-status-field').show();
        }
    });

    // ============================================
    // 3B. PRODUCT RULES: SCHEDULED TIMING BLOCK
    // ============================================

    /**
     * Helper dropdown populates the date meta key input
     */
    $('#sh-product-date-helper').on('change', function() {
        var val = $(this).val();
        if (val) {
            $('#sh-product-date-field-key').val(val);
        }
    });

    /**
     * Update product scheduled UI: hide days when same_day, update help text
     */
    function updateProductScheduledUI() {
        var direction = $('#sh-product-scheduled-direction').val();
        var $daysWrapper = $('#sh-product-days-number-wrapper');
        var $daysInput = $('#sh-product-scheduled-days');
        var $desc = $('#sh-product-scheduled-description');

        if (direction === 'same_day') {
            $daysWrapper.hide();
            $daysInput.val('0');
        } else {
            $daysWrapper.show();
            if (direction && ($daysInput.val() === '0')) {
                $daysInput.val('1');
            }
        }

        // Update dynamic help text
        var days = parseInt($daysInput.val(), 10);
        if (!direction) {
            $desc.text(secureholdAdminParams.i18n.productScheduledGlobalFallback || 'Leave empty to use the global scheduled settings.');
        } else if (direction === 'same_day' || days === 0) {
            $desc.text(secureholdAdminParams.i18n.scheduledSameDay || 'The deposit will be captured on the same day as the specified date.');
        } else if (direction === 'after') {
            $desc.text(
                (secureholdAdminParams.i18n.scheduledAfter || 'The deposit will be captured %d days after the specified date.')
                    .replace('%d', days || '')
            );
        } else if (direction === 'before') {
            $desc.text(
                (secureholdAdminParams.i18n.scheduledBefore || 'The deposit will be captured %d days before the specified date.')
                    .replace('%d', days || '')
            );
        }
    }

    // React to direction changes
    $('#sh-product-scheduled-direction').on('change', function() {
        updateProductScheduledUI();
        updateProductConfigPreview();
    });
    // React to days changes (for help text update)
    $('#sh-product-scheduled-days').on('input change', function() {
        updateProductScheduledUI();
        updateProductConfigPreview();
    });

    // React to all form field changes for preview update
    $('#sh-product-deposit-amount, #sh-product-timing, #sh-product-delay-days, #sh-product-date-field-key, #sh-product-trigger-status').on('change input', function() {
        updateProductConfigPreview();
    });

    // ============================================
    // 3C. PRODUCT RULES: APPLIED CONFIGURATION PREVIEW
    // ============================================

    /**
     * Build a real-time preview of the applied configuration
     * Shows what will be stored and how it compares to global defaults
     */
    function updateProductConfigPreview() {
        var $preview = $('#sh-product-config-preview');
        var $content = $('#sh-product-config-preview-content');
        var timing = $('#sh-product-timing').val();
        var amount = $('#sh-product-deposit-amount').val();

        // Only show preview when at least one field has a value
        if (!timing && !amount) {
            $preview.slideUp(200);
            return;
        }

        var html = '';

        // Amount row
        if (amount) {
            html += '<div class="sh-preview-row">';
            html += '<span class="sh-preview-label">Amount:</span>';
            html += '<span class="sh-preview-value">' + shEsc(amount) + '</span>';
            html += '<span class="sh-preview-source">Product Rule</span>';
            html += '</div>';
        }

        // Strategy row
        if (timing) {
            html += '<div class="sh-preview-row">';
            html += '<span class="sh-preview-label">Strategy:</span>';
            html += '<span class="sh-preview-value">' + shEsc(timing.charAt(0).toUpperCase() + timing.slice(1)) + '</span>';
            html += '<span class="sh-preview-source">Product Rule</span>';
            html += '</div>';

            // Strategy-specific details
            if (timing === 'delayed') {
                var delayDays = $('#sh-product-delay-days').val();
                if (delayDays) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Delay:</span>';
                    html += '<span class="sh-preview-value">' + shEsc(delayDays) + ' days after order</span>';
                    html += '</div>';
                }
            } else if (timing === 'scheduled') {
                var metaKey = $('#sh-product-date-field-key').val();
                var direction = $('#sh-product-scheduled-direction').val();
                var days = $('#sh-product-scheduled-days').val();

                if (metaKey) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Date Key:</span>';
                    html += '<span class="sh-preview-value"><code>' + shEsc(metaKey) + '</code></span>';
                    html += '</div>';
                }
                if (direction) {
                    var timingText = '';
                    if (direction === 'same_day') {
                        timingText = 'On the same day as the date';
                    } else if (direction === 'before') {
                        timingText = (days || '?') + ' days before the date';
                    } else if (direction === 'after') {
                        timingText = (days || '?') + ' days after the date';
                    }
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Timing:</span>';
                    html += '<span class="sh-preview-value">' + shEsc(timingText) + '</span>';
                    html += '</div>';
                }
            } else if (timing === 'status') {
                var triggerStatus = $('#sh-product-trigger-status').val();
                if (triggerStatus) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Trigger:</span>';
                    html += '<span class="sh-preview-value">On status change to "' + shEsc(triggerStatus) + '"</span>';
                    html += '</div>';
                }
            }
        }

        if (html) {
            $content.html(html);
            $preview.slideDown(200);
        } else {
            $preview.slideUp(200);
        }
    }

    // ============================================
    // 3D. GLOBAL: APPLIED CONFIGURATION PREVIEW
    // ============================================

    /**
     * Build a real-time preview of the applied Global configuration.
     * Mirrors updateProductConfigPreview() but reads from Global field IDs.
     */
    function updateGlobalConfigPreview() {
        var $preview = $('#sh-global-config-preview');
        var $content = $('#sh-global-config-preview-content');

        if (!$preview.length) return;

        var timing = $('input[name="securehold_capture_timing"]:checked').val();
        var amount = $('input[name="securehold_default_hold_amount"]').val();

        // Always show preview when amount or timing are available (Global always has values)
        if (!timing && !amount) {
            $content.html('<em style="color:var(--sh-gray-400);">No configuration set.</em>');
            return;
        }

        var html = '';

        // Amount row
        if (amount) {
            html += '<div class="sh-preview-row">';
            html += '<span class="sh-preview-label">Amount:</span>';
            html += '<span class="sh-preview-value">' + shEsc(amount) + '</span>';
            html += '<span class="sh-preview-source">Global</span>';
            html += '</div>';
        }

        // Strategy row
        if (timing) {
            html += '<div class="sh-preview-row">';
            html += '<span class="sh-preview-label">Strategy:</span>';
            html += '<span class="sh-preview-value">' + shEsc(timing.charAt(0).toUpperCase() + timing.slice(1)) + '</span>';
            html += '<span class="sh-preview-source">Global</span>';
            html += '</div>';

            // Strategy-specific details
            if (timing === 'delayed') {
                var delayDays = $('input[name="securehold_delay_days"]').val();
                if (delayDays) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Delay:</span>';
                    html += '<span class="sh-preview-value">' + shEsc(delayDays) + ' days after order</span>';
                    html += '</div>';
                }
            } else if (timing === 'scheduled') {
                var metaKey = $('#securehold_date_field_key').val();
                var direction = $('#securehold_scheduled_direction').val();
                var days = $('#securehold_scheduled_days_number').val();

                if (metaKey) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Date Key:</span>';
                    html += '<span class="sh-preview-value"><code>' + shEsc(metaKey) + '</code></span>';
                    html += '</div>';
                }
                if (direction) {
                    var timingText = '';
                    if (direction === 'same_day') {
                        timingText = 'On the same day as the date';
                    } else if (direction === 'before') {
                        timingText = (days || '?') + ' days before the date';
                    } else if (direction === 'after') {
                        timingText = (days || '?') + ' days after the date';
                    }
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Timing:</span>';
                    html += '<span class="sh-preview-value">' + shEsc(timingText) + '</span>';
                    html += '</div>';
                }
            } else if (timing === 'status') {
                var triggerStatus = $('select[name="securehold_trigger_status"]').val();
                if (triggerStatus) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Trigger:</span>';
                    html += '<span class="sh-preview-value">On status change to "' + shEsc(triggerStatus) + '"</span>';
                    html += '</div>';
                }
            }
        }

        $content.html(html || '<em style="color:var(--sh-gray-400);">No configuration set.</em>');
    }

    // Wire Global field changes to preview update
    $('input[name="securehold_capture_timing"]').on('change', updateGlobalConfigPreview);
    $('input[name="securehold_default_hold_amount"]').on('input change', updateGlobalConfigPreview);
    $('input[name="securehold_delay_days"]').on('input change', updateGlobalConfigPreview);
    $('#securehold_date_field_key').on('input change', updateGlobalConfigPreview);
    $('#securehold_scheduled_days_number').on('input change', updateGlobalConfigPreview);
    $('#securehold_scheduled_direction, select[name="securehold_trigger_status"]').on('change', updateGlobalConfigPreview);

    // Initialize Global preview on page load
    updateGlobalConfigPreview();

    // ============================================
    // 3E. CATEGORY: APPLIED CONFIGURATION PREVIEW
    // ============================================

    /**
     * Build a real-time preview of the applied Category configuration.
     * Mirrors updateProductConfigPreview() but reads from Category field IDs.
     */
    function updateCategoryConfigPreview() {
        var $preview = $('#sh-category-config-preview');
        var $content = $('#sh-category-config-preview-content');

        if (!$preview.length) return;

        var timing = $('#sh-category-timing').val();
        var amount = $('#sh-category-deposit-amount').val();

        // Only show preview when at least one field has a value
        if (!timing && !amount) {
            $preview.slideUp(200);
            return;
        }

        var html = '';

        // Amount row
        if (amount) {
            html += '<div class="sh-preview-row">';
            html += '<span class="sh-preview-label">Amount:</span>';
            html += '<span class="sh-preview-value">' + shEsc(amount) + '</span>';
            html += '<span class="sh-preview-source">Category Rule</span>';
            html += '</div>';
        }

        // Strategy row
        if (timing) {
            html += '<div class="sh-preview-row">';
            html += '<span class="sh-preview-label">Strategy:</span>';
            html += '<span class="sh-preview-value">' + shEsc(timing.charAt(0).toUpperCase() + timing.slice(1)) + '</span>';
            html += '<span class="sh-preview-source">Category Rule</span>';
            html += '</div>';

            // Strategy-specific details
            if (timing === 'delayed') {
                var delayDays = $('#sh-category-delay-days').val();
                if (delayDays) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Delay:</span>';
                    html += '<span class="sh-preview-value">' + shEsc(delayDays) + ' days after order</span>';
                    html += '</div>';
                }
            } else if (timing === 'scheduled') {
                var metaKey = $('#sh-category-date-field-key').val();
                var direction = $('#sh-category-scheduled-direction').val();
                var days = $('#sh-category-scheduled-days').val();

                if (metaKey) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Date Key:</span>';
                    html += '<span class="sh-preview-value"><code>' + shEsc(metaKey) + '</code></span>';
                    html += '</div>';
                }
                if (direction) {
                    var timingText = '';
                    if (direction === 'same_day') {
                        timingText = 'On the same day as the date';
                    } else if (direction === 'before') {
                        timingText = (days || '?') + ' days before the date';
                    } else if (direction === 'after') {
                        timingText = (days || '?') + ' days after the date';
                    }
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Timing:</span>';
                    html += '<span class="sh-preview-value">' + shEsc(timingText) + '</span>';
                    html += '</div>';
                }
            } else if (timing === 'status') {
                var triggerStatus = $('#sh-category-trigger-status').val();
                if (triggerStatus) {
                    html += '<div class="sh-preview-row">';
                    html += '<span class="sh-preview-label">Trigger:</span>';
                    html += '<span class="sh-preview-value">On status change to "' + shEsc(triggerStatus) + '"</span>';
                    html += '</div>';
                }
            }
        }

        if (html) {
            $content.html(html);
            $preview.slideDown(200);
        } else {
            $preview.slideUp(200);
        }
    }

    // Wire Category field changes to preview update
    $('#sh-category-timing').on('change', updateCategoryConfigPreview);
    $('#sh-category-deposit-amount').on('input change', updateCategoryConfigPreview);
    $('#sh-category-delay-days').on('input change', updateCategoryConfigPreview);
    $('#sh-category-date-field-key').on('input change', updateCategoryConfigPreview);
    $('#sh-category-scheduled-days').on('input change', updateCategoryConfigPreview);
    $('#sh-category-scheduled-direction, #sh-category-trigger-status').on('change', updateCategoryConfigPreview);

    // Save Button - Product Configuration
    $('#sh-save-product-config').on('click', function(e) {
        e.preventDefault();

        var productId = $('#securehold_product_search').val() || $('#sh-modal-product-id').val();
        if (!productId) {
            alert(secureholdAdminParams.i18n.selectProduct || 'Please select a product first');
            return;
        }

        // ── Mandatory fields validation (Option A: amount + strategy required) ──
        var $panel = $('#sh-custom-settings-panel');
        shClearValidationErrors($panel);
        var mandatoryValid = true;
        var i18n = (typeof secureholdAdminParams !== 'undefined' && secureholdAdminParams.i18n) ? secureholdAdminParams.i18n : {};

        var amountVal = $.trim($('#sh-product-deposit-amount').val());
        var selectedTiming = $('#sh-product-timing').val();

        // Deposit Amount: required, must be positive number or percentage
        if (!amountVal) {
            shMarkFieldError($('#sh-product-deposit-amount'), i18n.validationAmountRequired || 'Deposit Amount is required.');
            if (mandatoryValid) $('#sh-product-deposit-amount').focus();
            mandatoryValid = false;
        } else {
            var cleanAmount = amountVal.replace('%', '');
            if (isNaN(cleanAmount) || parseFloat(cleanAmount) <= 0) {
                shMarkFieldError($('#sh-product-deposit-amount'), i18n.validationAmountInvalid || 'Deposit Amount must be a positive number (e.g. 500 or 50%).');
                if (mandatoryValid) $('#sh-product-deposit-amount').focus();
                mandatoryValid = false;
            }
        }

        // Capture Timing Strategy: required (not empty placeholder)
        if (!selectedTiming) {
            shMarkFieldError($('#sh-product-timing'), i18n.validationStrategyRequired || 'Capture Timing Strategy is required.');
            if (mandatoryValid) $('#sh-product-timing').focus();
            mandatoryValid = false;
        }

        // Strategy-specific: delayed requires delay_days
        if (selectedTiming === 'delayed') {
            var delayVal = $.trim($('#sh-product-delay-days').val());
            if (!delayVal || isNaN(delayVal) || parseInt(delayVal, 10) < 1) {
                shMarkFieldError($('#sh-product-delay-days'), i18n.validationDelayRequired || 'Delay Duration is required (at least 1 day).');
                if (mandatoryValid) $('#sh-product-delay-days').focus();
                mandatoryValid = false;
            }
        }

        // Strategy-specific: status requires trigger_status
        if (selectedTiming === 'status') {
            var statusVal = $('#sh-product-trigger-status').val();
            if (!statusVal) {
                shMarkFieldError($('#sh-product-trigger-status'), i18n.validationStatusRequired || 'Trigger Status is required for the By Status strategy.');
                if (mandatoryValid) $('#sh-product-trigger-status').focus();
                mandatoryValid = false;
            }
        }

        if (!mandatoryValid) {
            $('html, body').animate({ scrollTop: $panel.find('.sh-field-error').first().offset().top - 80 }, 300);
            return;
        }

        // Validate Scheduled config if timing is "scheduled"
        if (selectedTiming === 'scheduled') {
            var $block = $('#sh-product-scheduled-block');
            shClearValidationErrors($block);

            var valid = shValidateScheduledFields(
                $('#sh-product-date-field-key').val(),
                $('#sh-product-scheduled-direction').val(),
                $('#sh-product-scheduled-days').val(),
                $('#sh-product-date-field-key'),
                $('#sh-product-scheduled-direction'),
                $('#sh-product-scheduled-days')
            );

            if (!valid) {
                $('html, body').animate({ scrollTop: $block.offset().top - 80 }, 300);
                return;
            }
        }

        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 2s infinite linear;"></span> Saving...');

        var data = {
            action: 'securehold_save_product_settings',
            nonce: secureholdAdminParams.nonces.productConfig,
            product_id: productId,
            enabled: true,
            deposit_amount: $('#sh-product-deposit-amount').val(),
            capture_timing: $('#sh-product-timing').val(),
            delay_days: $('#sh-product-delay-days').val(),
            date_field_key: $('#sh-product-date-field-key').val(),
            days_before_date: $('#sh-product-days-before-date').val(),
            scheduled_days: $('#sh-product-scheduled-days').val(),
            scheduled_direction: $('#sh-product-scheduled-direction').val(),
            trigger_status: $('#sh-product-trigger-status').val()
        };
        
        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes"></span> Saved!');
                    setTimeout(function() {
                         $btn.prop('disabled', false).html(originalText);
                         location.reload(); 
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert('Connection error');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Reset to Global Button - Product Configuration
    $('#sh-reset-product-to-global').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(secureholdAdminParams.i18n.confirmReset || 'Are you sure you want to reset this product to global defaults?')) {
            return;
        }
        
        var productId = $('#securehold_product_search').val() || $('#sh-modal-product-id').val();
        if (!productId) {
            alert(secureholdAdminParams.i18n.selectProduct || 'Please select a product first');
            return;
        }
        
        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 2s infinite linear;"></span> ' + (secureholdAdminParams.i18n.resetting || 'Resetting...'));
        
        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_delete_product_settings',
                nonce: secureholdAdminParams.nonces.productConfig,
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes"></span> ' + (secureholdAdminParams.i18n.reset || 'Reset!'));
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert(secureholdAdminParams.i18n.connectionError || 'Connection error');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // ============================================
    // 3C. SCHEDULED VALIDATION (Automation + Product Rules)
    // ============================================

    /**
     * Clear all validation error states
     */
    function shClearValidationErrors($context) {
        $context.find('.sh-field-error').removeClass('sh-field-error');
        $context.find('.sh-validation-msg').remove();
    }

    /**
     * Mark a field as error and append message
     */
    function shMarkFieldError($field, message) {
        $field.addClass('sh-field-error');
        // Append error message after the field's parent .sh-input-field or .sh-input-wrapper
        var $container = $field.closest('.sh-input-field');
        if (!$container.length) $container = $field.parent();
        // Remove existing msg on this container before adding
        $container.find('.sh-validation-msg').remove();
        $container.append('<p class="sh-validation-msg" style="color:#dc2626; font-size:0.8rem; margin:6px 0 0; font-weight:500;">' + message + '</p>');
    }

    /**
     * Validate scheduled fields. Returns true if valid, false if errors.
     * @param {string} dateMetaKeyVal - value of the date meta key input
     * @param {string} directionVal   - value of the direction select
     * @param {string} daysVal        - value of the days number input
     * @param {jQuery} $dateField     - the date meta key input element
     * @param {jQuery} $directionField - the direction select element
     * @param {jQuery} $daysField     - the days number input element
     */
    function shValidateScheduledFields(dateMetaKeyVal, directionVal, daysVal, $dateField, $directionField, $daysField) {
        var valid = true;
        var i18n = (typeof secureholdAdminParams !== 'undefined' && secureholdAdminParams.i18n) ? secureholdAdminParams.i18n : {};

        // 1. Date Meta Key obligatoire
        if (!dateMetaKeyVal || $.trim(dateMetaKeyVal) === '') {
            shMarkFieldError($dateField, i18n.validationDateKeyRequired || 'Date Meta Key is required when using the Scheduled strategy.');
            if (valid) $dateField.focus();
            valid = false;
        }

        // 2. Direction obligatoire
        if (!directionVal || !$.inArray(directionVal, ['before', 'after', 'same_day']) === -1) {
            // Note: since we removed "Use Global Setting" from Product Rules and Automation never had it,
            // this should rarely trigger, but it's a safety net.
            if (directionVal !== 'before' && directionVal !== 'after' && directionVal !== 'same_day') {
                shMarkFieldError($directionField, i18n.validationDirectionRequired || 'Please select a timing direction.');
                if (valid) $directionField.focus();
                valid = false;
            }
        }

        // 3. Number of days: obligatoire et >= 0 si direction != same_day
        if (directionVal !== 'same_day') {
            var daysNum = parseInt(daysVal, 10);
            if (daysVal === '' || isNaN(daysNum) || daysNum < 0) {
                shMarkFieldError($daysField, i18n.validationDaysRequired || 'Number of days is required (integer >= 0).');
                if (valid) $daysField.focus();
                valid = false;
            }
        }

        return valid;
    }

    /**
     * AUTOMATION: Intercept Save Settings click to validate Scheduled config
     * Uses click handler on button (same pattern as Product Rules) for reliable blocking.
     */
    $('#sh-save-settings-btn').on('click', function(e) {
        // Only validate if the selected capture timing is "scheduled"
        var selectedTiming = $('input[name="securehold_capture_timing"]:checked').val();
        if (selectedTiming !== 'scheduled') {
            return true; // Let the form submit normally
        }

        var $scheduledSection = $('#setting-scheduled');
        shClearValidationErrors($scheduledSection);

        var valid = shValidateScheduledFields(
            $('#securehold_date_field_key').val(),
            $('#securehold_scheduled_direction').val(),
            $('#securehold_scheduled_days_number').val(),
            $('#securehold_date_field_key'),
            $('#securehold_scheduled_direction'),
            $('#securehold_scheduled_days_number')
        );

        if (!valid) {
            e.preventDefault();
            $('html, body').animate({ scrollTop: $scheduledSection.offset().top - 80 }, 300);
            return false;
        }

        return true;
    });

    // ============================================
    // 4. UTILS & MODALS
    // ============================================
    $('.sh-tab-link').on('click', function() {
        $('.sh-tab-link').removeClass('active');
        $(this).addClass('active');
    });

    $('.copy-trigger, #copy-webhook-btn').on('click', function(e) {
        e.preventDefault();
        var $input = $(this).siblings('input');
        if($input.length === 0) $input = $(this).parent().find('input'); 
        $input.select();
        document.execCommand('copy');
        var $btn = $(this);
        var original = $btn.html();
        $btn.text('Copied!');
        setTimeout(function() { $btn.html(original); }, 2000);
    });

    // Password visibility toggle — works on any .sh-toggle-password button.
    // Switches input type password <-> text and updates the dashicons eye icon.
    $(document).on('click', '.sh-toggle-password', function () {
        var $btn   = $(this);
        var $input = $btn.closest('.sh-input-wrapper').find('input');
        var $icon  = $btn.find('.dashicons');
        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            $btn.attr('aria-pressed', 'true');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            $btn.attr('aria-pressed', 'false');
        }
    });

    $(document).on('click', '.sh-btn-capture', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var total = parseFloat($(this).data('amount'));
        var captured = parseFloat($(this).data('captured'));
        var currency = $(this).data('currency');
        var remaining = total - captured;

        $('#sh-capture-id').val(id);
        $('#sh-capture-total-display').text(total.toFixed(2) + ' ' + currency);
        $('#sh-capture-captured-display').text(captured.toFixed(2) + ' ' + currency);
        $('#sh-capture-remaining-display').text(remaining.toFixed(2) + ' ' + currency);
        $('#sh-capture-currency-symbol').text(currency);
        $('#sh-capture-amount').val(remaining.toFixed(2)).attr('max', remaining.toFixed(2));
        $('#sh-capture-modal-overlay').css('display', 'flex');
    });

    $('#sh-capture-cancel, #sh-capture-modal-overlay').on('click', function(e) {
        if (e.target === this || e.target.id === 'sh-capture-cancel') {
            $('#sh-capture-modal-overlay').hide();
        }
    });

    $('#sh-capture-form').on('submit', function(e) {
        e.preventDefault();
        if (!confirm(secureholdAdminParams.i18n.confirmCapture)) return;
        var $btn = $('#sh-capture-submit');
        $btn.prop('disabled', true);
        
        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_capture_hold',
                nonce: secureholdAdminParams.nonces.capture,
                deposit_id: $('#sh-capture-id').val(),
                amount: $('#sh-capture-amount').val()
            },
            success: function(r) { 
                if(r.success) location.reload(); 
                else alert(r.data.message); 
                $btn.prop('disabled', false);
            }
        });
    });

    // ============================================
    // 4B. RELEASE HOLD (Cancel on Stripe)
    // ============================================
    $(document).on('click', '.sh-btn-release', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var depositId = $btn.data('id');
        var orderId = $btn.data('order-id');
        var amount = $btn.data('amount');

        var i18n = (typeof secureholdAdminParams !== 'undefined' && secureholdAdminParams.i18n) ? secureholdAdminParams.i18n : {};
        var confirmMsg = i18n.confirmRelease || 'Are you sure you want to release this hold? The customer will no longer be charged.';

        if (!confirm(confirmMsg + '\n\nOrder #' + orderId + ' — ' + amount)) {
            return;
        }

        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation:spin 1s infinite linear; vertical-align:middle;"></span>');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_release_hold',
                nonce: secureholdAdminParams.nonces.release,
                deposit_id: depositId
            },
            success: function(r) {
                if (r.success) {
                    location.reload();
                } else {
                    alert(r.data.message || 'Release failed.');
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert(i18n.connectionError || 'Connection error');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // ============================================
    // 4C. KEBAB MENU (3-dots dropdown) — Deposits Table
    // ============================================
    (function initKebabMenus() {

        function closeAllKebabMenus() {
            // Reset fixed-position coords set on open, then remove open state
            $('.sh-kebab-menu.is-open')
                .removeClass('is-open')
                .attr('aria-hidden', 'true')
                .css({ position: '', top: '', bottom: '', right: '', left: '' });
            $('.sh-kebab-btn[aria-expanded="true"]').attr('aria-expanded', 'false');
        }

        // Open / close toggle
        $(document).on('click', '.sh-kebab-btn', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $btn  = $(this);
            var $menu = $btn.siblings('.sh-kebab-menu');
            var isOpen = $menu.hasClass('is-open');

            // Close every other open menu first
            closeAllKebabMenus();

            if (!isOpen) {
                $menu.addClass('is-open');
                $btn.attr('aria-expanded', 'true');
                $menu.attr('aria-hidden', 'false');

                // Use position:fixed so the menu escapes overflow:hidden on .sh-deposits-table-card.
                // Measure actual rendered height now that .is-open applied display:block.
                var rect       = $btn[0].getBoundingClientRect();
                var menuH      = $menu.outerHeight(true) || 0;
                var gap        = 6;  // px gap between button edge and menu
                var safetyGap  = 8;  // breathing room so menu never hugs the viewport edge
                var spaceBelow = window.innerHeight - rect.bottom - gap;

                if (spaceBelow >= menuH + safetyGap) {
                    $menu.css({
                        position: 'fixed',
                        top:    (rect.bottom + gap) + 'px',
                        bottom: 'auto',
                        right:  (window.innerWidth - rect.right) + 'px',
                        left:   'auto'
                    });
                } else {
                    // Flip above the button when insufficient space below
                    $menu.css({
                        position: 'fixed',
                        top:    'auto',
                        bottom: (window.innerHeight - rect.top + gap) + 'px',
                        right:  (window.innerWidth - rect.right) + 'px',
                        left:   'auto'
                    });
                }

                // Focus first visible item
                setTimeout(function() {
                    $menu.find('.sh-kebab-item:visible').first().focus();
                }, 20);
            }
        });

        // Close on click outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.sh-kebab-wrapper').length) {
                closeAllKebabMenus();
            }
        });

        // Prevent menu body click from bubbling
        $(document).on('click', '.sh-kebab-menu', function(e) {
            e.stopPropagation();
        });

        // Close on Escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' || e.keyCode === 27) {
                var $openMenu = $('.sh-kebab-menu.is-open');
                if ($openMenu.length) {
                    var $btn = $openMenu.siblings('.sh-kebab-btn');
                    closeAllKebabMenus();
                    $btn.focus();
                }
            }
        });

        // Keyboard navigation inside menu
        $(document).on('keydown', '.sh-kebab-menu', function(e) {
            var $items  = $(this).find('.sh-kebab-item:visible');
            var $focused = $items.filter(':focus');
            var idx     = $items.index($focused);

            if (e.key === 'ArrowDown' || e.keyCode === 40) {
                e.preventDefault();
                $items.eq((idx + 1) % $items.length).focus();
            } else if (e.key === 'ArrowUp' || e.keyCode === 38) {
                e.preventDefault();
                $items.eq((idx - 1 + $items.length) % $items.length).focus();
            } else if (e.key === 'Tab') {
                closeAllKebabMenus();
            }
        });

        // Close on scroll or resize to prevent positional drift (menu is position:fixed)
        // .off() before .on() guards against double-binding
        $(window).off('scroll.kebab resize.kebab').on('scroll.kebab resize.kebab', function() {
            closeAllKebabMenus();
        });
    })();

    // ============================================
    // 4B. COLLAPSIBLE CARD TOGGLE (Technical Logs)
    // ============================================
    $(document).on('click', '.sh-collapse-toggle', function() {
        var $btn  = $(this);
        var $body = $btn.next('.sh-collapse-body');
        var isOpen = $btn.attr('aria-expanded') === 'true';

        if (isOpen) {
            // Collapse: pin current height, reflow, then let CSS animate to 0
            $body.css('max-height', $body[0].scrollHeight + 'px');
            $body[0].offsetHeight; // force reflow
            $body.removeClass('is-open');
            $body.css('max-height', '0');
            $btn.attr('aria-expanded', 'false');
            // Clean up inline style after transition
            setTimeout(function() { $body.css('max-height', ''); }, 320);
        } else {
            // Expand: set explicit target height so CSS can transition
            var targetHeight = $body.addClass('is-open').css('max-height', 'none')[0].scrollHeight;
            $body.css('max-height', '0');
            $body[0].offsetHeight; // force reflow
            $body.css('max-height', targetHeight + 'px');
            $btn.attr('aria-expanded', 'true');
            // After transition, let CSS class handle overflow
            setTimeout(function() {
                if ($btn.attr('aria-expanded') === 'true') {
                    $body.css('max-height', '');
                }
            }, 320);
        }
    });

    // ============================================
    // 5. DEPOSIT DIAGNOSTIC SYSTEM
    // ============================================

    // Toggle diagnostic row and load data via AJAX
    $(document).on('click', '.sh-btn-diagnose', function(e) {
        e.preventDefault();
        var depositId = $(this).data('deposit-id');
        var $row = $('#sh-diag-row-' + depositId);
        var $content = $('#sh-diag-content-' + depositId);

        // Toggle: if already visible, just hide
        if ($row.is(':visible')) {
            $row.hide();
            return;
        }

        // Show row with loading spinner
        $row.show();
        $content.html('<div style="text-align:center; padding:20px; color:#6b7280;"><span class="dashicons dashicons-update" style="animation:spin 1s infinite linear; font-size:24px; width:24px; height:24px;"></span><p style="margin:8px 0 0;">Loading diagnostic data…</p></div>');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_diagnose_deposit',
                nonce: secureholdAdminParams.nonces.diagnose,
                deposit_id: depositId
            },
            success: function(response) {
                if (response.success) {
                    $content.html(shBuildDiagnosticHTML(response.data));
                } else {
                    $content.html('<div style="color:#dc2626; padding:10px;">Error: ' + shEsc(response.data.message) + '</div>');
                }
            },
            error: function() {
                $content.html('<div style="color:#dc2626; padding:10px;">Connection error. Please try again.</div>');
            }
        });
    });
    
    // ============================================
    // MANUAL MODE: Create Hold Button
    // ============================================
    $(document).on('click', '.sh-btn-create-hold', function(e) {
        e.preventDefault();
        
        if (!confirm(secureholdAdminParams.i18n.confirmCreateHold || 'Create security deposit for this order?')) {
            return;
        }
        
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        var depositId = $btn.data('deposit-id');
        var originalHtml = $btn.html();
        
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 2s infinite linear;"></span> Creating...');
        
        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_create_hold_manual',
                nonce: secureholdAdminParams.nonces.productConfig,
                order_id: orderId,
                deposit_id: depositId
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes"></span> Created!').css({'background': '#10b981', 'border-color': '#10b981'});
                    setTimeout(function() {
                        // Redirect to the newly created deposit (old one was deleted)
                        var newId = response.data && response.data.deposit_id;
                        if (newId) {
                            window.location.href = secureholdAdminParams.ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=securehold-deposit-details&deposit_id=' + newId;
                        } else {
                            // Fallback: go to deposits list
                            window.location.href = secureholdAdminParams.ajaxurl.replace('admin-ajax.php', 'admin.php') + '?page=securehold-deposits';
                        }
                    }, 1500);
                } else {
                    var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
                    $btn.html('<span class="dashicons dashicons-warning"></span> Failed').css({'background': '#dc2626', 'border-color': '#dc2626', 'color': '#fff'});
                    alert('Error: ' + errorMsg);
                    // Reload page to show updated Failed status
                    setTimeout(function() {
                        window.location.reload();
                    }, 500);
                }
            },
            error: function() {
                alert(secureholdAdminParams.i18n.connectionError || 'Connection error');
                $btn.prop('disabled', false).html(originalHtml);
            }
        });
    });

    /**
     * Build complete diagnostic HTML from server response.
     * Displays: header, primary failure, secondary effects, data grid, logs, actions.
     */
    function shBuildDiagnosticHTML(d) {
        var html = '';

        // Header
        html += '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">';
        html += '<h4 style="margin:0; color:#374151; font-size:14px;">🔍 Diagnostic — Order #' + shEsc(d.order_id) + '</h4>';
        html += '<span style="font-size:11px; color:#9ca3af;">Deposit #' + shEsc(d.deposit_id) + '</span>';
        html += '</div>';

        // === PRIMARY FAILURE (red box) ===
        var pf = d.primary_failure;
        if (pf) {
            html += '<div class="sh-diag-primary">';
            html += '<strong>⛔ ' + shEsc(pf.label || 'Error') + '</strong>';
            html += '<div class="sh-diag-msg">' + shEsc(pf.message || 'No details available.') + '</div>';
            var srcLabel = {
                'deposit_record': 'Source: deposit record (notes)',
                'log_entry': 'Source: WooCommerce log' + (pf.log_time ? ' at ' + pf.log_time : ''),
                'config_check': 'Source: configuration check',
                'record_check': 'Source: deposit record analysis',
                'none': 'Source: no data found'
            };
            html += '<div class="sh-diag-src">' + shEsc(srcLabel[pf.source] || pf.source) + '</div>';
            html += '</div>';
        }

        // === SECONDARY EFFECTS (orange box) ===
        if (d.secondary_effects && d.secondary_effects.length > 0) {
            html += '<div class="sh-diag-secondary">';
            html += '<strong>⚡ Secondary Effects (' + d.secondary_effects.length + ')</strong>';
            html += '<ul>';
            for (var i = 0; i < d.secondary_effects.length; i++) {
                html += '<li>' + shEsc(d.secondary_effects[i]) + '</li>';
            }
            html += '</ul></div>';
        }

        // === DATA GRID ===
        html += '<dl class="sh-diag-grid" style="margin-top:12px;">';
        html += shDiagRow('Order', d.order_exists
            ? '<a href="' + shEsc(d.order_url) + '">#' + shEsc(d.order_id) + '</a> (status: ' + shEsc(d.order_status) + ')'
            : '#' + shEsc(d.order_id) + ' <span class="sh-diag-badge sh-diag-badge-err">Deleted</span>');
        html += shDiagRow('Payment Method', shEsc(d.wc_payment_method || '—'));
        html += shDiagRow('Stripe Mode', d.stripe_mode === 'test'
            ? '<span class="sh-diag-badge sh-diag-badge-test">TEST</span>'
            : '<span class="sh-diag-badge sh-diag-badge-live">LIVE</span>');
        html += shDiagRow('API Keys', (d.has_secret_key ? '<span class="sh-diag-badge sh-diag-badge-ok">Secret ✓</span>' : '<span class="sh-diag-badge sh-diag-badge-err">Secret ✗</span>') + ' '
            + (d.has_publishable_key ? '<span class="sh-diag-badge sh-diag-badge-ok">Publishable ✓</span>' : '<span class="sh-diag-badge sh-diag-badge-err">Publishable ✗</span>'));
        html += shDiagRow('Customer ID', d.customer_id ? '<code>' + shEsc(d.customer_id) + '</code>' + (d.customer_id_source ? ' (' + shEsc(d.customer_id_source) + ')' : '') : '<span class="sh-diag-badge sh-diag-badge-err">Not found</span>');
        html += shDiagRow('Intent ID', d.intent_id ? '<code>' + shEsc(d.intent_id) + '</code>' : '<span class="sh-diag-badge sh-diag-badge-err">Not found</span>');
        html += shDiagRow('Payment Method ID', d.payment_method_id ? '<code>' + shEsc(d.payment_method_id) + '</code>' : '<span class="sh-diag-badge sh-diag-badge-err">Not found</span>');
        html += shDiagRow('Amount', shEsc(d.amount) + ' ' + shEsc(d.currency ? d.currency.toUpperCase() : ''));
        html += shDiagRow('Strategy', shEsc(d.capture_timing || 'immediate'));
        if (d.date_field_key) {
            html += shDiagRow('Date Field', '<code>' + shEsc(d.date_field_key) + '</code> (days before: ' + shEsc(d.days_before_date) + ')');
        }
        html += shDiagRow('Created', shEsc(d.created_at || '—'));
        html += '</dl>';

        // === RAW LOGS (collapsible) ===
        if (d.logs && d.logs.length > 0) {
            html += '<button type="button" class="sh-diag-logs-toggle" data-target="sh-diag-logs-' + d.deposit_id + '">▶ Show Raw Logs (' + d.logs.length + ' entries)</button>';
            html += '<div class="sh-diag-logs-box" id="sh-diag-logs-' + d.deposit_id + '">';
            for (var j = 0; j < d.logs.length; j++) {
                var log = d.logs[j];
                var logColor = (log.severity === 'error' || log.severity === 'warning' || log.message.indexOf('❌') !== -1) ? '#dc2626' : '#6b7280';
                html += '<div style="color:' + logColor + '; margin-bottom:4px;">';
                html += '<span style="color:#9ca3af;">[' + shEsc(log.created_at) + ']</span> ';
                html += shEsc(log.message);
                if (log.data) {
                    html += ' <span style="color:#a78bfa;">' + shEsc(log.data.substring(0, 200)) + '</span>';
                }
                html += '</div>';
            }
            html += '</div>';
        }

        // === ACTION BUTTONS ===
        html += '<div class="sh-diag-actions">';
        if (pf && pf.action) {
            var urls = (typeof secureholdDiagUrls !== 'undefined') ? secureholdDiagUrls : {};
            if (pf.action === 'settings' && urls.settings) {
                html += '<a href="' + urls.settings + '" class="button button-small" style="background:#d97706; border-color:#d97706; color:white;">Open Settings</a>';
            }
            if (pf.action === 'logs' && urls.logs) {
                html += '<a href="' + urls.logs + '" class="button button-small" style="background:#2563eb; border-color:#2563eb; color:white;">Open Logs</a>';
            }
            if (pf.action === 'health_check' && urls.healthCheck) {
                html += '<a href="' + urls.healthCheck + '" class="button button-small" style="background:#2563eb; border-color:#2563eb; color:white;">Health Check</a>';
            }
            if (pf.action === 'stripe_dashboard' && d.intent_id && d.intent_id.indexOf('pi_') === 0) {
                var stripeBase = d.stripe_mode === 'test' ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';
                html += '<a href="' + stripeBase + '/payments/' + shEsc(d.intent_id) + '" target="_blank" class="button button-small" style="background:#7c3aed; border-color:#7c3aed; color:white;">View in Stripe ↗</a>';
            }
        }
        html += '<button type="button" class="button button-small sh-diag-copy-btn" data-deposit-id="' + d.deposit_id + '">📋 Copy Diagnostic</button>';
        html += '<span class="sh-diag-copy-ok" id="sh-diag-copy-ok-' + d.deposit_id + '">✓ Copied</span>';
        html += '<button type="button" class="button button-small sh-diag-close-btn" data-deposit-id="' + d.deposit_id + '" style="margin-left:auto;">Close</button>';
        html += '</div>';

        return html;
    }

    // Toggle logs visibility
    $(document).on('click', '.sh-diag-logs-toggle', function() {
        var target = $(this).data('target');
        var $box = $('#' + target);
        if ($box.is(':visible')) {
            $box.slideUp(200);
            $(this).text($(this).text().replace('▼', '▶'));
        } else {
            $box.slideDown(200);
            $(this).text($(this).text().replace('▶', '▼'));
        }
    });

    // Copy diagnostic to clipboard (no sensitive data)
    $(document).on('click', '.sh-diag-copy-btn', function() {
        var depId = $(this).data('deposit-id');
        var $container = $('#sh-diag-content-' + depId);
        var text = $container.find('.sh-diag-primary .sh-diag-msg').text() || '';
        var secondaries = [];
        $container.find('.sh-diag-secondary li').each(function() {
            secondaries.push('- ' + $(this).text());
        });
        var copyText = 'SecureHold Diagnostic\n';
        copyText += '====================\n';
        copyText += 'Primary: ' + ($container.find('.sh-diag-primary strong').text() || 'Unknown') + '\n';
        copyText += 'Detail: ' + text + '\n';
        if (secondaries.length) {
            copyText += '\nSecondary Effects:\n' + secondaries.join('\n') + '\n';
        }
        // Try modern clipboard API, fallback to textarea
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(copyText);
        } else {
            var $ta = $('<textarea>').val(copyText).appendTo('body').select();
            document.execCommand('copy');
            $ta.remove();
        }
        var $ok = $('#sh-diag-copy-ok-' + depId);
        $ok.show();
        setTimeout(function() { $ok.fadeOut(300); }, 2000);
    });

    // Close diagnostic row
    $(document).on('click', '.sh-diag-close-btn', function() {
        var depId = $(this).data('deposit-id');
        $('#sh-diag-row-' + depId).hide();
    });

    // Helpers
    function shEsc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function shDiagRow(label, valueHtml) {
        return '<dt>' + shEsc(label) + '</dt><dd>' + valueHtml + '</dd>';
    }

    // ============================================
    // 6. CATEGORY RULES CONFIGURATION
    // ============================================

    // Category search Select2 init
    if ($.fn[select2Fn] && $('#securehold_category_search').length) {
        $('#securehold_category_search')[select2Fn]({
            minimumInputLength: 0,
            allowClear: true,
            placeholder: $('#securehold_category_search').data('placeholder'),
            ajax: {
                url: secureholdAdminParams.ajaxurl,
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'securehold_search_categories',
                        term: params.term || '',
                        security: secureholdAdminParams.nonces.categorySearch
                    };
                },
                processResults: function (data) {
                    if (data && data.results) {
                        return data;
                    }
                    var terms = [];
                    if (data) {
                        $.each(data, function(id, text) {
                            terms.push({ id: id, text: text });
                        });
                    }
                    return { results: terms };
                },
                cache: true
            }
        });
    }

    function loadCategoryIntoForm(termId, termName) {
        var $container = $('#sh-category-settings-panel');
        var $display = $('#sh-selected-category-display');
        var $modalInput = $('#sh-modal-category-id');

        $modalInput.val(termId);
        $('#sh-selected-category-name').text(termName);
        $('#sh-selected-category-meta').text('ID: ' + termId);
        $display.slideDown(200);

        if (!$container.is(':visible')) {
            $container.slideDown(300);
        }
        $container.css('opacity', '0.5');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_get_category_settings',
                term_id: termId,
                nonce: secureholdAdminParams.nonces.categoryConfig
            },
            success: function(response) {
                if (response.success) {
                    var s = response.data;
                    $('#sh-category-deposit-amount').val(s.deposit_amount || '');
                    $('#sh-category-timing').val(s.capture_timing || '');
                    $('#sh-category-delay-days').val(s.delay_days || '');
                    $('#sh-category-date-field-key').val(s.date_field_key || '');
                    $('#sh-category-scheduled-days').val(s.scheduled_days || '');
                    $('#sh-category-scheduled-direction').val(s.scheduled_direction || '');
                    $('#sh-category-trigger-status').val(s.trigger_status || '');
                    $('#sh-category-timing').trigger('change');
                }
            },
            complete: function() {
                $container.css('opacity', '1');
            }
        });
    }

    $('#securehold_category_search').on('change', function() {
        var termId = $(this).val();
        if (termId) {
            var data = $(this)[select2Fn]('data');
            var termText = (data && data[0]) ? data[0].text : 'Category #' + termId;
            loadCategoryIntoForm(termId, termText);
        } else {
            $('#sh-category-settings-panel').slideUp(300);
            $('#sh-selected-category-display').slideUp(200);
        }
    });

    // Edit existing category rule
    $(document).on('click', '.sh-edit-category-config', function(e) {
        e.preventDefault();
        var id = $(this).data('id');
        var name = $(this).data('text');
        var $btn = $(this);
        var $panel = $('#sh-category-settings-panel');
        var $display = $('#sh-selected-category-display');
        var currentId = $('#sh-modal-category-id').val();

        if (currentId == id && $panel.is(':visible')) {
            $panel.slideUp(300);
            $display.slideUp(200);
            $('#sh-modal-category-id').val('');
            $('.sh-edit-category-config').removeClass('sh-btn-active');
        } else {
            $('html, body').animate({
                scrollTop: $('#sh-category-selector-field').offset().top - 100
            }, 500);
            $('.sh-edit-category-config').removeClass('sh-btn-active');
            $btn.addClass('sh-btn-active');
            loadCategoryIntoForm(id, name);
        }
    });

    // Delete category rule
    $(document).on('click', '.sh-delete-category-config', function(e) {
        e.preventDefault();
        if (!confirm(secureholdAdminParams.i18n.confirmResetCategory || 'Remove this category rule?')) return;

        var id = $(this).data('id');
        var $row = $('#sh-category-row-' + id);
        $row.css('opacity', '0.5');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_delete_category_settings',
                term_id: id,
                nonce: secureholdAdminParams.nonces.categoryConfig
            },
            success: function(response) {
                if (response.success) {
                    $row.slideUp(300, function() { $(this).remove(); });
                    if ($('#sh-modal-category-id').val() == id) {
                        $('#sh-category-settings-panel').slideUp();
                        $('#sh-selected-category-display').slideUp();
                        $('#sh-modal-category-id').val('');
                    }
                } else {
                    alert('Error removing category rule');
                    $row.css('opacity', '1');
                }
            }
        });
    });

    // Category timing sub-options toggle
    $('#sh-category-timing').on('change', function() {
        var timing = $(this).val();

        $('#sh-category-delay-days-field').hide();
        $('#sh-category-scheduled-block').hide();
        $('#sh-category-trigger-status-field').hide();

        if (timing === 'delayed') {
            $('#sh-category-delay-days-field').show();
        } else if (timing === 'scheduled') {
            $('#sh-category-scheduled-block').show();
        } else if (timing === 'status') {
            $('#sh-category-trigger-status-field').show();
        }
    });

    // Category date helper dropdown
    $('#sh-category-date-helper').on('change', function() {
        var val = $(this).val();
        if (val) {
            $('#sh-category-date-field-key').val(val);
        }
    });

    // Category scheduled timing toggle (mirrors updateProductScheduledUI)
    function updateCategoryScheduledUI() {
        var direction = $('#sh-category-scheduled-direction').val();
        var $daysInput = $('#sh-category-scheduled-days');

        if (direction === 'same_day') {
            $daysInput.val('0');
        } else if (direction && ($daysInput.val() === '0' || $daysInput.val() === '')) {
            $daysInput.val('1');
        }
    }

    $('#sh-category-scheduled-direction').on('change', function() {
        updateCategoryScheduledUI();
        updateCategoryConfigPreview();
    });
    $('#sh-category-scheduled-days').on('input change', function() {
        updateCategoryScheduledUI();
        updateCategoryConfigPreview();
    });

    // Save category config
    $('#sh-save-category-config').on('click', function(e) {
        e.preventDefault();

        var termId = $('#sh-modal-category-id').val();
        if (!termId) {
            alert(secureholdAdminParams.i18n.selectCategory || 'Please select a category first');
            return;
        }

        // ── Mandatory fields validation (Option A: amount + strategy required) ──
        var $catPanel = $('#sh-category-settings-panel');
        shClearValidationErrors($catPanel);
        var catValid = true;
        var i18n = (typeof secureholdAdminParams !== 'undefined' && secureholdAdminParams.i18n) ? secureholdAdminParams.i18n : {};

        var catAmountVal = $.trim($('#sh-category-deposit-amount').val());
        var selectedTiming = $('#sh-category-timing').val();

        // Deposit Amount: required
        if (!catAmountVal) {
            shMarkFieldError($('#sh-category-deposit-amount'), i18n.validationAmountRequired || 'Deposit Amount is required.');
            if (catValid) $('#sh-category-deposit-amount').focus();
            catValid = false;
        } else {
            var cleanCatAmount = catAmountVal.replace('%', '');
            if (isNaN(cleanCatAmount) || parseFloat(cleanCatAmount) <= 0) {
                shMarkFieldError($('#sh-category-deposit-amount'), i18n.validationAmountInvalid || 'Deposit Amount must be a positive number (e.g. 500 or 50%).');
                if (catValid) $('#sh-category-deposit-amount').focus();
                catValid = false;
            }
        }

        // Capture Timing Strategy: required
        if (!selectedTiming) {
            shMarkFieldError($('#sh-category-timing'), i18n.validationStrategyRequired || 'Capture Timing Strategy is required.');
            if (catValid) $('#sh-category-timing').focus();
            catValid = false;
        }

        // Strategy-specific: delayed requires delay_days
        if (selectedTiming === 'delayed') {
            var catDelayVal = $.trim($('#sh-category-delay-days').val());
            if (!catDelayVal || isNaN(catDelayVal) || parseInt(catDelayVal, 10) < 1) {
                shMarkFieldError($('#sh-category-delay-days'), i18n.validationDelayRequired || 'Delay Duration is required (at least 1 day).');
                if (catValid) $('#sh-category-delay-days').focus();
                catValid = false;
            }
        }

        // Strategy-specific: status requires trigger_status
        if (selectedTiming === 'status') {
            var catStatusVal = $('#sh-category-trigger-status').val();
            if (!catStatusVal) {
                shMarkFieldError($('#sh-category-trigger-status'), i18n.validationStatusRequired || 'Trigger Status is required for the By Status strategy.');
                if (catValid) $('#sh-category-trigger-status').focus();
                catValid = false;
            }
        }

        if (!catValid) {
            $('html, body').animate({ scrollTop: $catPanel.find('.sh-field-error').first().offset().top - 80 }, 300);
            return;
        }

        // Validate scheduled config
        if (selectedTiming === 'scheduled') {
            var $block = $('#sh-category-scheduled-block');
            shClearValidationErrors($block);

            var valid = shValidateScheduledFields(
                $('#sh-category-date-field-key').val(),
                $('#sh-category-scheduled-direction').val(),
                $('#sh-category-scheduled-days').val(),
                $('#sh-category-date-field-key'),
                $('#sh-category-scheduled-direction'),
                $('#sh-category-scheduled-days')
            );

            if (!valid) {
                $('html, body').animate({ scrollTop: $block.offset().top - 80 }, 300);
                return;
            }
        }

        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 2s infinite linear;"></span> Saving...');

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_save_category_settings',
                nonce: secureholdAdminParams.nonces.categoryConfig,
                term_id: termId,
                deposit_amount: $('#sh-category-deposit-amount').val(),
                capture_timing: $('#sh-category-timing').val(),
                delay_days: $('#sh-category-delay-days').val(),
                date_field_key: $('#sh-category-date-field-key').val(),
                scheduled_days: $('#sh-category-scheduled-days').val(),
                scheduled_direction: $('#sh-category-scheduled-direction').val(),
                trigger_status: $('#sh-category-trigger-status').val()
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes"></span> Saved!');
                    setTimeout(function() {
                        $btn.prop('disabled', false).html(originalText);
                        location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert(secureholdAdminParams.i18n.connectionError || 'Connection error');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // Reset category to global
    $('#sh-reset-category-to-global').on('click', function(e) {
        e.preventDefault();

        if (!confirm(secureholdAdminParams.i18n.confirmResetCategory || 'Remove this category rule?')) return;

        var termId = $('#sh-modal-category-id').val();
        if (!termId) {
            alert(secureholdAdminParams.i18n.selectCategory || 'Please select a category first');
            return;
        }

        var $btn = $(this);
        var originalText = $btn.html();
        $btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: spin 2s infinite linear;"></span> ' + (secureholdAdminParams.i18n.resetting || 'Resetting...'));

        $.ajax({
            url: secureholdAdminParams.ajaxurl,
            type: 'POST',
            data: {
                action: 'securehold_delete_category_settings',
                nonce: secureholdAdminParams.nonces.categoryConfig,
                term_id: termId
            },
            success: function(response) {
                if (response.success) {
                    $btn.html('<span class="dashicons dashicons-yes"></span> ' + (secureholdAdminParams.i18n.reset || 'Reset!'));
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $btn.prop('disabled', false).html(originalText);
                }
            },
            error: function() {
                alert(secureholdAdminParams.i18n.connectionError || 'Connection error');
                $btn.prop('disabled', false).html(originalText);
            }
        });
    });

    // ============================================
    // 7. EXPLAIN APPLIED RULE MODAL
    // ============================================
    (function() {
        var $modal   = $('#sh-explain-rule-modal');
        var $btn     = $('#sh-btn-explain-rule');
        var $closeBtn = $('#sh-explain-modal-close');

        if (!$modal.length || !$btn.length) return;

        // Open
        $btn.on('click', function(e) {
            e.preventDefault();
            $modal.css('display', 'flex');
            // Focus the close button for accessibility
            $closeBtn.focus();
        });

        // Close helper
        function closeModal() {
            $modal.hide();
            // Return focus to trigger button
            $btn.focus();
        }

        // Close on X button
        $closeBtn.on('click', function(e) {
            e.stopPropagation();
            closeModal();
        });

        // Close on overlay click (but not modal box)
        $modal.on('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });

        // Prevent modal box clicks from bubbling to overlay
        $modal.find('.sh-modal-box').on('click', function(e) {
            e.stopPropagation();
        });

        // Close on Escape
        $(document).on('keydown', function(e) {
            if ((e.key === 'Escape' || e.keyCode === 27) && $modal.is(':visible')) {
                closeModal();
            }
        });

        // Simple focus trap
        $modal.on('keydown', function(e) {
            if (e.key !== 'Tab') return;
            var $focusable = $modal.find('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])').filter(':visible');
            if (!$focusable.length) return;
            var $first = $focusable.first();
            var $last  = $focusable.last();
            if (e.shiftKey && document.activeElement === $first[0]) {
                e.preventDefault();
                $last.focus();
            } else if (!e.shiftKey && document.activeElement === $last[0]) {
                e.preventDefault();
                $first.focus();
            }
        });
    })();

    // ============================================
    // 8. ADVANCED RULE ENGINE SIMULATOR (V2 — Cart)
    //    Multi-product cart simulation via AJAX.
    //    No business logic in JS — server does all resolution.
    // ============================================
    (function() {
        var $panel = $('#sh-rule-sim-panel');
        if (!$panel.length) return;

        var $searchField = $('#sh-sim-product-search');
        var $cart        = $('#sh-sim-cart');
        var $cartItems   = $('#sh-sim-cart-items');
        var $cartCount   = $('#sh-sim-cart-count');
        var $loading     = $('#sh-sim-loading');
        var $empty       = $('#sh-sim-empty');
        var $results     = $('#sh-sim-results');

        // ── Cart state (persisted in sessionStorage across reloads) ──
        var SIM_STORAGE_KEY = 'securehold_sim_cart';
        var cartProducts = (function() {
            try {
                var stored = sessionStorage.getItem(SIM_STORAGE_KEY);
                if (stored) {
                    var parsed = JSON.parse(stored);
                    if (Array.isArray(parsed) && parsed.length > 0) return parsed;
                }
            } catch (e) { /* ignore */ }
            return [];
        })();
        var simXhr       = null;

        function persistCart() {
            try {
                sessionStorage.setItem(SIM_STORAGE_KEY, JSON.stringify(cartProducts));
            } catch (e) { /* quota or private mode — ignore */ }
        }

        // ── Label maps ──
        var STRATEGY_LABELS = {
            'immediate': 'Immediate',
            'delayed':   'Delayed',
            'scheduled': 'Scheduled',
            'status':    'By Status',
            'manual':    'Manual'
        };

        var SOURCE_LABELS = {
            'product_rule':  'Product Rule',
            'category_rule': 'Category Rule',
            'global':        'Global'
        };

        var SOURCE_BADGES = {
            'product_rule':  'sh-badge-product-rule',
            'category_rule': 'sh-badge-category-rule',
            'global':        'sh-badge-global'
        };

        var POLICY_LABELS = {
            'priority_chain':       'Priority Chain',
            'highest_deposit_wins': 'Highest Deposit Wins'
        };

        var AGG_LABELS = {
            'per_order':            'Per Order',
            'per_item_aggregated':  'Per Item Aggregated'
        };

        // ── Init Select2 for product search (add-to-cart mode) ──
        if ($.fn[select2Fn]) {
            $searchField[select2Fn]({
                minimumInputLength: 0,
                allowClear: true,
                placeholder: $searchField.data('placeholder'),
                ajax: {
                    url: secureholdAdminParams.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'securehold_search_products',
                            term: params.term || '',
                            security: secureholdAdminParams.nonces.productSearch
                        };
                    },
                    processResults: function (data) {
                        if (data && data.results) return data;
                        var terms = [];
                        if (data) {
                            $.each(data, function(id, text) {
                                terms.push({ id: id, text: text });
                            });
                        }
                        return { results: terms };
                    },
                    cache: true
                }
            });
        }

        // ── On product selection: add to cart ──
        $searchField.on('select2:select', function(e) {
            var data = e.params ? e.params.data : null;
            if (!data || !data.id) return;

            var id   = parseInt(data.id, 10);
            var text = data.text || '#' + id;

            // Check if already in cart.
            var existing = null;
            for (var i = 0; i < cartProducts.length; i++) {
                if (cartProducts[i].product_id === id) {
                    existing = i;
                    break;
                }
            }

            if (existing !== null) {
                cartProducts[existing].qty++;
            } else {
                cartProducts.push({
                    product_id: id,
                    product_name: text,
                    qty: 1
                });
            }

            // Clear search field without triggering add.
            $searchField.val(null).trigger('change');

            persistCart();
            renderCart();
            runCartSimulation();
        });

        // ── Render cart product list ──
        function renderCart() {
            if (cartProducts.length === 0) {
                $cart.hide();
                $results.hide();
                $empty.show();
                return;
            }

            $cart.show();
            $empty.hide();

            var totalQty = 0;
            var html = '';

            for (var i = 0; i < cartProducts.length; i++) {
                var p = cartProducts[i];
                totalQty += p.qty;

                html += '<div class="sh-sim-cart-item" data-index="' + i + '">';
                html += '<div class="sh-sim-cart-item-info">';
                html += '<span class="sh-sim-cart-item-name" title="' + shEsc(p.product_name) + '">' + shEsc(p.product_name) + '</span>';
                html += '</div>';
                html += '<div class="sh-sim-cart-item-controls">';
                html += '<button type="button" class="sh-sim-qty-btn sh-sim-qty-minus" data-index="' + i + '"' + (p.qty <= 1 ? ' disabled' : '') + '>&minus;</button>';
                html += '<input type="number" class="sh-sim-qty-input" value="' + p.qty + '" min="1" max="99" data-index="' + i + '">';
                html += '<button type="button" class="sh-sim-qty-btn sh-sim-qty-plus" data-index="' + i + '">+</button>';
                html += '<button type="button" class="sh-sim-cart-remove" data-index="' + i + '" title="Remove">';
                html += '<span class="dashicons dashicons-no-alt"></span>';
                html += '</button>';
                html += '</div>';
                html += '</div>';
            }

            $cartItems.html(html);
            $cartCount.text(totalQty);
        }

        // ── Cart interaction events (delegated) ──
        $cartItems.on('click', '.sh-sim-qty-minus', function() {
            var idx = $(this).data('index');
            if (cartProducts[idx] && cartProducts[idx].qty > 1) {
                cartProducts[idx].qty--;
                persistCart();
                renderCart();
                runCartSimulation();
            }
        });

        $cartItems.on('click', '.sh-sim-qty-plus', function() {
            var idx = $(this).data('index');
            if (cartProducts[idx] && cartProducts[idx].qty < 99) {
                cartProducts[idx].qty++;
                persistCart();
                renderCart();
                runCartSimulation();
            }
        });

        $cartItems.on('change', '.sh-sim-qty-input', function() {
            var idx = $(this).data('index');
            var val = parseInt($(this).val(), 10);
            if (isNaN(val) || val < 1) val = 1;
            if (val > 99) val = 99;
            if (cartProducts[idx]) {
                cartProducts[idx].qty = val;
                persistCart();
                renderCart();
                runCartSimulation();
            }
        });

        $cartItems.on('click', '.sh-sim-cart-remove', function() {
            var idx = $(this).data('index');
            cartProducts.splice(idx, 1);
            persistCart();
            renderCart();
            if (cartProducts.length > 0) {
                runCartSimulation();
            }
        });

        // Clear cart button.
        $('#sh-sim-cart-clear').on('click', function() {
            cartProducts = [];
            persistCart();
            renderCart();
        });

        // ── Collect draft global settings from the current form ──
        function collectDraftConfig() {
            var draft = {};
            var $policy = $('#sh-resolution-policy');
            var $agg    = $('#sh-aggregation-mode');

            // Only build draft if global fields exist on this page.
            if (!$policy.length && !$agg.length) return draft;

            draft.global = {};
            var $engine = $('#sh-engine-version');
            if ($policy.length) draft.global.resolution_policy = $policy.val();
            if ($agg.length)    draft.global.aggregation_mode  = $agg.val();
            if ($engine.length) draft.global.engine_version    = $engine.val();

            var $timing = $('input[name="securehold_capture_timing"]:checked');
            if ($timing.length) draft.global.capture_timing = $timing.val();

            var $amount = $('input[name="securehold_default_hold_amount"]');
            if ($amount.length) draft.global.deposit_amount = $amount.val();

            var $delay = $('input[name="securehold_delay_days"]');
            if ($delay.length) draft.global.delay_days = $delay.val();

            var $dateKey = $('#securehold_date_field_key');
            if ($dateKey.length) draft.global.date_field_key = $dateKey.val();

            var $schedDays = $('#securehold_scheduled_days_number');
            if ($schedDays.length) draft.global.scheduled_days_number = $schedDays.val();

            var $schedDir = $('#securehold_scheduled_direction');
            if ($schedDir.length) draft.global.scheduled_direction = $schedDir.val();

            var $triggerStatus = $('select[name="securehold_trigger_status"]');
            if ($triggerStatus.length) draft.global.trigger_status = $triggerStatus.val();

            return draft;
        }

        // ── Run cart simulation ──
        function runCartSimulation() {
            // Allow simulation with zero products — shows global config preview.
            if (simXhr) simXhr.abort();

            $empty.hide();
            $results.hide();
            $loading.show();

            var products = [];
            for (var i = 0; i < cartProducts.length; i++) {
                products.push({
                    product_id: cartProducts[i].product_id,
                    qty: cartProducts[i].qty
                });
            }

            var ajaxData = {
                action: 'securehold_simulate_cart',
                products: products,
                nonce: secureholdAdminParams.nonces.simulateCart
            };

            // Attach draft_config so the server simulates unsaved values.
            var draft = collectDraftConfig();
            if (draft.global) {
                ajaxData.draft_config = draft;
            }

            simXhr = $.ajax({
                url: secureholdAdminParams.ajaxurl,
                type: 'POST',
                data: ajaxData,
                success: function(response) {
                    $loading.hide();
                    if (response.success) {
                        renderCartSimulation(response.data);
                        $results.show();
                    } else {
                        $empty.html('<p style="color:var(--sh-danger);">' + shEsc(response.data.message || 'Error') + '</p>').show();
                    }
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus === 'abort') return;
                    $loading.hide();
                    $empty.html('<p style="color:var(--sh-danger);">Connection error.</p>').show();
                }
            });
        }

        // ── Live preview: auto-recalculate on settings change ──
        // Fires even without products so the config summary stays live.
        var simDebounceTimer = null;
        function triggerLiveSimulation() {
            clearTimeout(simDebounceTimer);
            simDebounceTimer = setTimeout(runCartSimulation, 400);
        }

        // Card-style option selector — PRO Rule Conflict Strategy & Aggregation Mode.
        var shCardHelpMap = {
            'sh-resolution-policy': {
                'priority_chain':       '#sh-policy-help-priority-chain',
                'highest_deposit_wins': '#sh-policy-help-highest-deposit-wins'
            },
            'sh-aggregation-mode': {
                'per_order':           '#sh-agg-help-per-order',
                'per_item_aggregated': '#sh-agg-help-per-item'
            },
            'sh-engine-version': {
                'v2':     '#sh-engine-help-v2',
                'legacy': '#sh-engine-help-legacy'
            }
        };
        $(document).on('click', '.sh-rule-option--selectable', function() {
            var $card = $(this);
            var group = $card.data('group');
            var value = $card.data('value');
            $('[data-group="' + group + '"]').removeClass('sh-rule-option--active');
            $card.addClass('sh-rule-option--active');
            $('#' + group).val(value).trigger('change');
            if (shCardHelpMap[group]) {
                $.each(shCardHelpMap[group], function(v, selector) {
                    $(selector).toggleClass('sh-policy-help--hidden', v !== value);
                });
            }
        });

        // Global Configuration fields.
        $('#sh-resolution-policy, #sh-aggregation-mode, #sh-engine-version').on('change', triggerLiveSimulation);
        $('input[name="securehold_capture_timing"]').on('change', triggerLiveSimulation);
        $('input[name="securehold_default_hold_amount"], input[name="securehold_delay_days"]')
            .on('input change', triggerLiveSimulation);
        $('#securehold_date_field_key').on('input change', triggerLiveSimulation);
        $('#securehold_scheduled_days_number').on('input change', triggerLiveSimulation);
        $('#securehold_scheduled_direction, select[name="securehold_trigger_status"]')
            .on('change', triggerLiveSimulation);

        // Product / Category rule saves — re-simulate with saved DB values.
        $(document).on('ajaxComplete', function(event, xhr, settings) {
            if (!settings || !settings.data || cartProducts.length === 0) return;
            var dataStr = typeof settings.data === 'string' ? settings.data : '';
            if (dataStr.indexOf('securehold_save_product_settings') !== -1 ||
                dataStr.indexOf('securehold_delete_product_settings') !== -1 ||
                dataStr.indexOf('securehold_save_category_settings') !== -1 ||
                dataStr.indexOf('securehold_delete_category_settings') !== -1) {
                setTimeout(runCartSimulation, 500);
            }
        });

        // ── Restore cart from sessionStorage and run initial preview ──
        if (cartProducts.length > 0) {
            renderCart();
        }
        // Always run preview on page load — shows config summary even with empty cart.
        setTimeout(runCartSimulation, 300);

        // ══════════════════════════════════════════
        //  RENDER — dispatches by aggregation mode
        // ══════════════════════════════════════════

        function renderCartSimulation(data) {
            // When no products in the cart, skip context/evaluation —
            // only render the configuration summary (resolution block).
            if ( !data.context || !data.context.products || data.context.products.length === 0 ) {
                $('#sh-sim-context').html('');
                $('#sh-sim-evaluation').html('');
                renderOrderResolution(data.resolution);
                return;
            }

            renderContext(data.context);

            if (data.mode === 'per_item_aggregated') {
                renderPerItemEvaluation(data.items);
                renderPerItemAggregate(data.aggregate);
            } else {
                renderOrderEvaluation(data.steps, data.candidates);
                renderOrderResolution(data.resolution);
            }
        }

        // ── Block 1: Context ──
        function renderContext(ctx) {
            var html = '';
            var productNames = [];
            for (var i = 0; i < ctx.products.length; i++) {
                var p = ctx.products[i];
                productNames.push(shEsc(p.product_name) + ' <span style="color:var(--sh-gray-400);">\u00d7' + p.qty + '</span>');
            }
            html += simContextRow('Products', productNames.join(', '));
            html += simContextRow('Total Items', String(ctx.total_qty));
            html += simContextRow('Aggregation', '<span class="sh-badge sh-sim-ctx-badge">' + shEsc(AGG_LABELS[ctx.aggregation] || ctx.aggregation) + '</span>');
            html += simContextRow('Policy', '<span class="sh-badge sh-sim-ctx-badge">' + shEsc(POLICY_LABELS[ctx.policy] || ctx.policy) + '</span>');
            $('#sh-sim-context').html(html);
        }

        function simContextRow(label, valueHtml) {
            return '<div class="sh-sim-ctx-row">' +
                '<span class="sh-sim-ctx-label">' + shEsc(label) + '</span>' +
                '<span class="sh-sim-ctx-value">' + valueHtml + '</span>' +
                '</div>';
        }

        // ── Block 2A: Per Item Evaluation ──
        function renderPerItemEvaluation(items) {
            var html = '';

            for (var i = 0; i < items.length; i++) {
                var item = items[i];
                var sourceBadge = SOURCE_BADGES[item.resolution.source] || 'sh-badge-global';
                var sourceLabel = SOURCE_LABELS[item.resolution.source] || item.resolution.source;

                html += '<div class="sh-sim-section">';
                html += '<h4 class="sh-sim-heading">';
                html += '<span class="dashicons dashicons-tag" style="font-size:14px;width:14px;height:14px;"></span> ';
                html += shEsc(item.product_name);
                if (item.qty > 1) html += ' <span style="color:var(--sh-gray-400);">\u00d7' + item.qty + '</span>';
                html += '</h4>';

                // Evaluation steps.
                html += '<div class="sh-sim-flow">';
                html += renderStepsHtml(item.steps);
                html += '</div>';

                // Per-item resolution summary.
                html += '<div class="sh-sim-item-result">';
                html += '<span class="sh-badge ' + sourceBadge + '" style="font-size:0.625rem;">' + shEsc(sourceLabel) + '</span>';
                html += ' <span class="sh-sim-res-deposit" style="font-size:0.875rem;">' + shEsc(item.resolution.deposit) + '</span>';
                if (item.qty > 1) {
                    html += ' <span style="color:var(--sh-gray-400);font-size:0.75rem;">\u00d7 ' + item.qty + ' = ' + shEsc(String(item.resolution.deposit_line)) + '</span>';
                }
                html += ' <span style="color:var(--sh-gray-400);font-size:0.6875rem;">(' + shEsc(STRATEGY_LABELS[item.resolution.strategy] || item.resolution.strategy) + ')</span>';
                html += '</div>';

                html += '</div>';

                if (i < items.length - 1) {
                    html += '<div class="sh-sim-divider"></div>';
                }
            }

            $('#sh-sim-evaluation').html(html);
            $('#sh-sim-resolution-title').text('Cart Total');
        }

        // ── Block 2B: Per Order Evaluation ──
        function renderOrderEvaluation(steps, candidates) {
            var html = '';

            html += '<div class="sh-sim-section">';
            html += '<h4 class="sh-sim-heading">';
            html += '<span class="dashicons dashicons-randomize" style="font-size:14px;width:14px;height:14px;"></span> ';
            html += 'Rule Evaluation Flow';
            html += '</h4>';

            // Candidates table (only when multiple).
            if (candidates && candidates.length > 1) {
                html += '<div class="sh-sim-candidates">';
                html += '<div class="sh-sim-candidates-header">';
                html += '<span>Candidates</span>';
                html += '<span class="sh-sim-candidates-count">' + candidates.length + '</span>';
                html += '</div>';

                for (var i = 0; i < candidates.length; i++) {
                    var c = candidates[i];
                    var badge = SOURCE_BADGES[c.type] || 'sh-badge-global';
                    html += '<div class="sh-sim-candidate-row">';
                    html += '<span class="sh-badge ' + badge + '" style="font-size:0.5625rem;">' + shEsc(SOURCE_LABELS[c.type] || c.type) + '</span>';
                    html += '<span class="sh-sim-candidate-label">' + shEsc(c.label) + '</span>';
                    html += '<span class="sh-sim-candidate-amount">' + shEsc(c.amount_raw) + '</span>';
                    html += '<span class="sh-sim-candidate-timing">' + shEsc(STRATEGY_LABELS[c.timing] || c.timing) + '</span>';
                    html += '</div>';
                }
                html += '</div>';
            }

            // Steps.
            html += '<div class="sh-sim-flow" style="margin-top:0.5rem;">';
            html += renderStepsHtml(steps);
            html += '</div>';

            html += '</div>';

            $('#sh-sim-evaluation').html(html);
            $('#sh-sim-resolution-title').text('Final Resolution');
        }

        // ── Shared: render steps HTML ──
        function renderStepsHtml(steps) {
            var html = '';
            for (var i = 0; i < steps.length; i++) {
                var step = steps[i];
                var stepNum = i + 1;
                var statusClass = 'sh-sim-step-' + step.status;

                if (i > 0) {
                    html += '<div class="sh-sim-flow-arrow">\u2193</div>';
                }

                html += '<div class="sh-sim-flow-step ' + statusClass + '">';
                html += '<div class="sh-sim-step-header">';
                html += '<span class="sh-sim-step-num">' + stepNum + '</span>';
                html += '<span class="sh-sim-step-label">' + shEsc(step.label) + '</span>';

                if (step.status === 'winner') {
                    html += '<span class="sh-sim-step-icon sh-sim-step-icon--winner" title="Winner"><span class="dashicons dashicons-yes-alt"></span></span>';
                } else if (step.status === 'fallback') {
                    html += '<span class="sh-sim-step-icon sh-sim-step-icon--fallback" title="Fallback"><span class="dashicons dashicons-migrate"></span></span>';
                } else {
                    html += '<span class="sh-sim-step-icon sh-sim-step-icon--skipped" title="Skipped"><span class="dashicons dashicons-minus"></span></span>';
                }

                html += '</div>';
                html += '<div class="sh-sim-step-detail">' + shEsc(step.detail) + '</div>';
                html += '</div>';
            }
            return html;
        }

        // ── Block 3A: Per Item Aggregate ──
        function renderPerItemAggregate(agg) {
            var html = '';

            html += '<div class="sh-sim-res-row sh-sim-res-row--winner">';
            html += '<span class="sh-sim-res-label">Total Deposit</span>';
            html += '<span class="sh-sim-res-value sh-sim-res-deposit">' + shEsc(String(agg.total_deposit)) + '</span>';
            html += '</div>';

            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Items</span>';
            html += '<span class="sh-sim-res-value">' + agg.unique_products + ' products, ' + agg.item_count + ' items</span>';
            html += '</div>';

            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Effective Strategy</span>';
            html += '<span class="sh-sim-res-value">' + shEsc(STRATEGY_LABELS[agg.effective_strategy] || agg.effective_strategy) + '</span>';
            html += '</div>';

            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Aggregation</span>';
            html += '<span class="sh-sim-res-value">' + shEsc(AGG_LABELS[agg.aggregation] || agg.aggregation) + '</span>';
            html += '</div>';

            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Policy</span>';
            html += '<span class="sh-sim-res-value">' + shEsc(POLICY_LABELS[agg.policy] || agg.policy) + '</span>';
            html += '</div>';

            if (agg.strategy_reason) {
                html += '<div class="sh-sim-res-fallbacks">';
                html += '<span class="dashicons dashicons-info-outline" style="font-size:12px;width:12px;height:12px;color:var(--sh-info,#3b82f6);"></span> ';
                html += '<span>' + shEsc(agg.strategy_reason) + '</span>';
                html += '</div>';
            }

            $('#sh-sim-resolution').html(html);
        }

        // ── Block 3B: Per Order Resolution ──
        function renderOrderResolution(res) {
            var html = '';
            var sourceBadge = SOURCE_BADGES[res.source] || 'sh-badge-global';
            var sourceLabel = SOURCE_LABELS[res.source] || res.source;
            var strategyLabel = STRATEGY_LABELS[res.strategy] || res.strategy;

            // Winning Source.
            html += '<div class="sh-sim-res-row sh-sim-res-row--winner">';
            html += '<span class="sh-sim-res-label">Winning Source</span>';
            html += '<span class="sh-badge ' + sourceBadge + '">' + shEsc(sourceLabel) + '</span>';
            html += '</div>';

            // Source Label.
            if (res.source_label && res.source !== 'global') {
                html += '<div class="sh-sim-res-row">';
                html += '<span class="sh-sim-res-label">Rule</span>';
                html += '<span class="sh-sim-res-value">' + shEsc(res.source_label) + '</span>';
                html += '</div>';
            }

            // Deposit.
            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Deposit</span>';
            html += '<span class="sh-sim-res-value sh-sim-res-deposit">' + shEsc(res.deposit) + '</span>';
            html += '</div>';

            // Strategy.
            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Strategy</span>';
            html += '<span class="sh-sim-res-value">' + shEsc(strategyLabel) + '</span>';
            html += '</div>';

            // Strategy-specific detail.
            if (res.strategy === 'delayed' && res.delay_days) {
                html += '<div class="sh-sim-res-row">';
                html += '<span class="sh-sim-res-label">Delay</span>';
                html += '<span class="sh-sim-res-value">' + shEsc(res.delay_days) + ' days</span>';
                html += '</div>';
            } else if (res.strategy === 'scheduled') {
                if (res.date_field_key) {
                    html += '<div class="sh-sim-res-row">';
                    html += '<span class="sh-sim-res-label">Date Key</span>';
                    html += '<span class="sh-sim-res-value"><code>' + shEsc(res.date_field_key) + '</code></span>';
                    html += '</div>';
                }
                if (res.scheduled_direction) {
                    var timingDesc = '';
                    if (res.scheduled_direction === 'same_day') {
                        timingDesc = 'On the same day';
                    } else {
                        timingDesc = (res.scheduled_days || '0') + ' days ' + res.scheduled_direction;
                    }
                    html += '<div class="sh-sim-res-row">';
                    html += '<span class="sh-sim-res-label">Timing</span>';
                    html += '<span class="sh-sim-res-value">' + shEsc(timingDesc) + '</span>';
                    html += '</div>';
                }
            } else if (res.strategy === 'status' && res.trigger_status) {
                html += '<div class="sh-sim-res-row">';
                html += '<span class="sh-sim-res-label">Trigger</span>';
                html += '<span class="sh-sim-res-value">On ' + shEsc(res.trigger_status) + '</span>';
                html += '</div>';
            }

            // Winner reason.
            if (res.winner_reason) {
                html += '<div class="sh-sim-res-row">';
                html += '<span class="sh-sim-res-label">Reason</span>';
                html += '<span class="sh-sim-res-value" style="font-size:0.6875rem;max-width:65%;">' + shEsc(res.winner_reason) + '</span>';
                html += '</div>';
            }

            // Aggregation.
            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Aggregation</span>';
            html += '<span class="sh-sim-res-value">' + shEsc(AGG_LABELS[res.aggregation] || res.aggregation) + '</span>';
            html += '</div>';

            // Policy.
            html += '<div class="sh-sim-res-row">';
            html += '<span class="sh-sim-res-label">Policy</span>';
            html += '<span class="sh-sim-res-value">' + shEsc(POLICY_LABELS[res.policy] || res.policy) + '</span>';
            html += '</div>';

            // Fallbacks.
            if (res.fallbacks && res.fallbacks.length > 0) {
                html += '<div class="sh-sim-res-fallbacks">';
                html += '<span class="dashicons dashicons-info-outline" style="font-size:12px;width:12px;height:12px;color:var(--sh-warning);"></span> ';
                html += '<span>Fallback to global for: ' + shEsc(res.fallbacks.join(', ')) + '</span>';
                html += '</div>';
            }

            $('#sh-sim-resolution').html(html);
        }

    })();

});