/**
 * SecureHold Frontend & Appearance Tab Scripts
 * Version: 3.8.0
 */

jQuery(document).ready(function($) {
    
    // ============================================
    // TOGGLE SECTIONS
    // ============================================
    
    // Toggle checkout message settings
    $('input[name="securehold_enable_checkout_message"]').on('change', function() {
        $('#checkout-message-settings').slideToggle(300);
    });
    
    // Toggle My Account settings
    $('input[name="securehold_enable_my_account_tab"]').on('change', function() {
        $('#my-account-settings').slideToggle(300);
    });
    
    // ============================================
    // COPY VARIABLE TO CLIPBOARD
    // ============================================
    
    $('.sh-variable').on('click', function() {
        var variable = $(this).data('variable');
        
        // Copy to clipboard
        var temp = $('<input>');
        $('body').append(temp);
        temp.val(variable).select();
        document.execCommand('copy');
        temp.remove();
        
        // Visual feedback
        $(this).css('background', '#10b981');
        $(this).css('color', 'white');
        $(this).css('border-color', '#10b981');
        
        setTimeout(function() {
            $('.sh-variable').css('background', 'white');
            $('.sh-variable').css('color', '#2563eb');
            $('.sh-variable').css('border-color', '#d1d5db');
        }, 500);
        
        // Show toast
        showToast('Variable copied: ' + variable);
    });
    
    // ============================================
    // COPY SHORTCODE TO CLIPBOARD
    // ============================================
    
    $('.sh-copy-shortcode').on('click', function() {
        var shortcode = $(this).data('shortcode');
        
        var temp = $('<input>');
        $('body').append(temp);
        temp.val(shortcode).select();
        document.execCommand('copy');
        temp.remove();
        
        showToast('Shortcode copied!');
    });
    
    // ============================================
    // PREVIEW CHECKOUT MESSAGE
    // ============================================
    
    $('#preview-checkout-message').on('click', function() {
        // Get message content from TinyMCE
        var message = '';
        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('securehold_checkout_message')) {
            message = tinyMCE.get('securehold_checkout_message').getContent();
        } else {
            message = $('#securehold_checkout_message').val();
        }
        
        var style = $('select[name="securehold_checkout_message_style"]').val();
        
        // Replace variables with example values
        message = message.replace(/{amount}/g, '$300.00');
        message = message.replace(/{amount_raw}/g, '300.00');
        message = message.replace(/{currency}/g, 'USD');
        message = message.replace(/{currency_symbol}/g, '$');
        message = message.replace(/{product_name}/g, 'Beach House Rental');
        message = message.replace(/{product_names}/g, 'Beach House Rental, Kayak Rental');
        message = message.replace(/{products_count}/g, '2');
        message = message.replace(/{order_total}/g, '$1,200.00');
        message = message.replace(/{release_date}/g, 'January 15, 2025');
        message = message.replace(/{release_days}/g, '7');
        
        // Get site name from global var if available, otherwise use placeholder
        var siteName = (typeof secureholdAdminParams !== 'undefined' && secureholdAdminParams.siteName) 
            ? secureholdAdminParams.siteName 
            : 'Your Site';
        message = message.replace(/{site_name}/g, siteName);
        
        // Style colors
        var colors = {
            'info': {bg: '#e3f2fd', border: '#2196f3', text: '#1565c0', icon: 'dashicons-info'},
            'warning': {bg: '#fff3e0', border: '#ff9800', text: '#e65100', icon: 'dashicons-warning'},
            'success': {bg: '#e8f5e9', border: '#4caf50', text: '#2e7d32', icon: 'dashicons-yes-alt'}
        };
        
        var color = colors[style] || colors['info'];
        
        // Build preview HTML
        var preview = '<div style="background:' + color.bg + ';border-left:4px solid ' + color.border + ';padding:1.25rem 1.5rem;border-radius:6px;display:flex;align-items:flex-start;gap:1rem;">';
        preview += '<span class="dashicons ' + color.icon + '" style="color:' + color.border + ';font-size:24px;margin-top:2px;flex-shrink:0;"></span>';
        preview += '<div style="flex:1;color:' + color.text + ';">' + message + '</div>';
        preview += '</div>';
        
        $('#checkout-message-preview').html(preview);
    });
    
    // ============================================
    // TOAST NOTIFICATION SYSTEM
    // ============================================
    
    function showToast(message) {
        var toast = $('<div class="sh-toast">' + message + '</div>');
        $('body').append(toast);
        
        setTimeout(function() {
            toast.addClass('show');
        }, 10);
        
        setTimeout(function() {
            toast.removeClass('show');
            setTimeout(function() {
                toast.remove();
            }, 300);
        }, 2000);
    }
    
});
