/**
 * Monoova Admin JavaScript
 *
 * Handles interactions in the admin dashboard
 */
(function($) {
    'use strict';
    
    /**
     * Monoova Admin object
     */
    var MonoovaAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.setupCopyButtons();
            this.setupAPITestButtons();
        },
        
        /**
         * Setup copy buttons
         */
        setupCopyButtons: function() {
            $('.monoova-copy-webhook').on('click', function() {
                var textToCopy = $(this).data('clipboard-text');
                MonoovaAdmin.copyToClipboard(textToCopy, $(this));
            });
        },
        
        /**
         * Copy text to clipboard
         *
         * @param {string} text     Text to copy
         * @param {object} $button  jQuery button object
         */
        copyToClipboard: function(text, $button) {
            // Create temporary input
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val(text).select();
            
            // Execute copy command
            var success = document.execCommand("copy");
            $temp.remove();
            
            // Show success/error message
            if (success) {
                var originalText = $button.text();
                
                $button.text(monoova_admin_params.i18n.success);
                
                setTimeout(function() {
                    $button.text(originalText);
                }, 2000);
            }
        },
        
        /**
         * Setup API test buttons
         */
        setupAPITestButtons: function() {
            $('.monoova-api-test-button').on('click', function(e) {
                e.preventDefault();
                
                var $button = $(this);
                var $resultContainer = $button.closest('tr').find('.monoova-api-test-result');
                
                // Disable button and show loading
                $button.prop('disabled', true);
                $button.text(monoova_admin_params.i18n.loading);
                $resultContainer.html('');
                
                // API credentials from form
                var mode = $('#woocommerce_monoova_card_testmode').is(':checked') ? 'test' : 'live';
                var apiKey = mode === 'test' 
                    ? $('#woocommerce_monoova_card_test_api_key').val()
                    : $('#woocommerce_monoova_card_live_api_key').val();
                
                if (!apiKey) {
                    $resultContainer.html('<div class="monoova-test-error">Please enter an API key.</div>');
                    $button.prop('disabled', false);
                    $button.text('Test API Connection');
                    return;
                }
                
                // Make AJAX call to test API
                $.ajax({
                    url: monoova_admin_params.ajax_url,
                    method: 'POST',
                    data: {
                        action: 'monoova_test_api_connection',
                        api_key: apiKey,
                        mode: mode,
                        nonce: monoova_admin_params.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $resultContainer.html('<div class="monoova-test-success">API connection successful!</div>');
                        } else {
                            $resultContainer.html('<div class="monoova-test-error">API connection failed: ' + response.data.message + '</div>');
                        }
                    },
                    error: function() {
                        $resultContainer.html('<div class="monoova-test-error">Request failed. Please try again.</div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                        $button.text('Test API Connection');
                    }
                });
            });
        },
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        MonoovaAdmin.init();
    });
    
})(jQuery);