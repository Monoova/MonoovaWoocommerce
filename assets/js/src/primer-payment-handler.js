/**
 * Primer Payment Handler for Monoova Payments Redirect Flow
 *
 * This script handles:
 * 1. Fetching the client session token via AJAX.
 * 2. Initializing the Primer Universal Checkout on the redirect page.
 * 3. Handling payment completion and redirecting back to WooCommerce.
 * 4. Dynamically configuring Primer options based on gateway admin settings.
 *
 * Gateway Settings Integration:
 * - saved_cards: Controls customer vault/tokenization
 * - enable_apple_pay: Shows/hides Apple Pay button
 * - enable_google_pay: Shows/hides Google Pay button  
 * - capture: Controls immediate capture vs authorization
 */
(function($) {
    'use strict';

    let primerCheckoutInstance = null;
    let isInitializingPrimer = false;

    // Check if primerRedirectData is available (localized from PHP)
    if (typeof primerRedirectData === 'undefined') {
        console.error('Primer Redirect Data not found. AJAX URL or Order ID is missing.');
        displayError('Critical error: Payment page configuration is missing. Please contact support.');
        return;
    }

    const { ajax_url, order_id, client_token_nonce, complete_payment_nonce, i18n, gateway_settings, checkout_url } = primerRedirectData;

    /**
     * Displays an error message on the page.
     * @param {string} message The error message to display.
     */
    function displayError(message) {
        const errorBox = $('#error-box');
        if (errorBox.length) {
            errorBox.addClass('active').html(`<span class="ErrorText">${message}</span>`);
        } else {
            alert(message); // Fallback if error-box is not present
        }
        // Unblock UI if it was blocked
        if ($.unblockUI) $.unblockUI(); 
        $('#checkout-container').hide();
    }

    /**
     * Fetches the client token from the server.
     */
    async function fetchClientToken() {
        try {
            const response = await $.ajax({
                url: ajax_url,
                type: 'POST',
                data: {
                    action: 'monoova_get_client_token', // Action hook in class-monoova-card-gateway.php
                    order_id: order_id,
                    _wpnonce: client_token_nonce // Nonce for fetching client token
                }
            });

            if (response.success && response.data && response.data.clientToken) {
                return response.data;
            } else {
                throw new Error(response.data && response.data.message ? response.data.message : (i18n.error_fetching_token || 'Could not retrieve payment session.'));
            }
        } catch (error) {
            console.error('Error fetching client token:', error);
            let errorMessage = i18n.error_fetching_token || 'Could not retrieve payment session.';
            if (error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
                errorMessage = error.responseJSON.data.message;
            } else if (error.message) {
                errorMessage = error.message;
            }
            displayError(errorMessage);
            // Redirect back to checkout page after a short delay
            setTimeout(() => {
                window.location.href = checkout_url || '/';
            }, 3000); // 3-second delay to allow user to read the message
            throw error; // Re-throw to stop further execution
        }
    }

    /**
     * Initializes the Primer Universal Checkout.
     * @param {object} tokenData - The token data object containing clientToken, clientTransactionUniqueReference, etc.
     */
    async function initializePrimerCheckout(tokenData) {
        if (isInitializingPrimer) {
            return;
        }
        isInitializingPrimer = true;

        if (!tokenData || !tokenData.clientToken) {
            displayError(i18n.error_invalid_token || 'Invalid payment session token provided.');
            isInitializingPrimer = false;
            return;
        }

        if (primerCheckoutInstance) {
            try {
                await primerCheckoutInstance.destroy();
            } catch (error) {
                console.warn('Error destroying existing Primer instance:', error);
            }
            primerCheckoutInstance = null;
        }

        // TODO: read Primer SDK docs to add/modify options
        const options = {
            container: '#checkout-container',
            clientSessionCachingEnabled: false, // Keep this consistent with original settings
            // // Configure payment methods based on admin settings
            //     // Enable/disable Apple Pay based on admin setting
            // applePay: gateway_settings?.enable_apple_pay ? {
            //     buttonType: 'plain',
            //     buttonStyle: 'black'
            // } : null,
            // // Enable/disable Google Pay based on admin setting
            // googlePay: gateway_settings?.enable_google_pay ? {
            //     buttonType: 'plain'
            // } : null,
            // // Configure customer vault (saved cards) based on admin setting
            // customer: gateway_settings?.saved_cards ? {
            //     vaultOnSuccess: true
            // } : {
            //     vaultOnSuccess: false
            // },
            // // Configure capture behavior based on admin setting
            // clientSession: {
            //     captureVaultedCardCvv: gateway_settings?.capture !== false
            // },
            // submitButton: {
            //     amountVisible: true, // Show amount in the checkout
            // },
            style: {
                inputLabel: {
                    fontFamily: gateway_settings?.checkout_ui_styles?.input_label?.font_family || 'Helvetica, Arial, sans-serif',
                    fontSize: gateway_settings?.checkout_ui_styles?.input_label?.font_size || '14px',
                    fontWeight: gateway_settings?.checkout_ui_styles?.input_label?.font_weight || 'normal',
                    color: gateway_settings?.checkout_ui_styles?.input_label?.color || '#000000',
                },
                input: {
                    base: {
                        fontFamily: gateway_settings?.checkout_ui_styles?.input?.font_family || 'Helvetica, Arial, sans-serif',
                        fontSize: gateway_settings?.checkout_ui_styles?.input?.font_size || '14px',
                        fontWeight: gateway_settings?.checkout_ui_styles?.input?.font_weight || 'normal',
                        background: gateway_settings?.checkout_ui_styles?.input?.background_color || '#FAFAFA',
                        borderColor: gateway_settings?.checkout_ui_styles?.input?.border_color || '#E8E8E8',
                        borderRadius: gateway_settings?.checkout_ui_styles?.input?.border_radius || '8px',
                        color: gateway_settings?.checkout_ui_styles?.input?.text_color || '#000000',
                    },
                },
                submitButton: {
                    base: {
                        color: gateway_settings?.checkout_ui_styles?.submit_button?.text_color || '#000000',
                        background: gateway_settings?.checkout_ui_styles?.submit_button?.background || '#2ab5c4',
                        borderRadius: gateway_settings?.checkout_ui_styles?.submit_button?.border_radius || '10px',
                        borderColor: gateway_settings?.checkout_ui_styles?.submit_button?.border_color || '#2ab5c4',
                        fontFamily: gateway_settings?.checkout_ui_styles?.submit_button?.font_family || 'Helvetica, Arial, sans-serif',
                        fontSize: gateway_settings?.checkout_ui_styles?.submit_button?.font_size || '17px',
                        fontWeight: gateway_settings?.checkout_ui_styles?.submit_button?.font_weight || 'bold',
                        boxShadow: 'none'
                    },
                    disabled: {
                        color: '#9b9b9b',
                        background: '#e1deda'
                    }
                },
                loadingScreen: {
                    color: gateway_settings?.checkout_ui_styles?.submit_button?.background || '#2ab5c4'
                }
            },
            errorMessage: {
                disabled: true, // We handle errors with displayError
                onErrorMessageShow(message) {
                    displayError(message);
                },
                onErrorMessageHide() {
                    const errorBox = $('#error-box');
                    if (errorBox.length) {
                        errorBox.removeClass('active').empty();
                    }
                }
            },
            successScreen: false, // Disable success screen since we handle redirects manually
            onCheckoutComplete: async (data) => {
                // Extract the Primer payment ID or token
                const primerPaymentId = data?.payment?.id;

                // Payment successful according to Primer UI, now verify and complete with WooCommerce backend
                try {
                    const paymentResult = await $.ajax({
                        url: ajax_url,
                        type: 'POST',
                        data: {
                            action: 'monoova_complete_checkout', // New AJAX action to handle completion
                            order_id: order_id,
                            primer_payment_id: primerPaymentId, // Send Primer's payment ID/token
                            client_transaction_ref: tokenData.clientTransactionUniqueReference, // Send the original client ref
                            _wpnonce: complete_payment_nonce // Nonce for completing payment
                        }
                    });

                    if (paymentResult.success && paymentResult.data && paymentResult.data.redirect_url) {
                        window.location.href = paymentResult.data.redirect_url;
                    } else {
                        throw new Error(paymentResult.data && paymentResult.data.message ? paymentResult.data.message : (i18n.error_finalizing_order || 'Could not finalize your order.'));
                    }
                } catch (error) {
                    console.error('Error completing payment with backend:', error);
                    let errorMessage = i18n.error_finalizing_order || 'Could not finalize your order.';
                     if (error.responseJSON && error.responseJSON.data && error.responseJSON.data.message) {
                        errorMessage = error.responseJSON.data.message;
                    } else if (error.message) {
                        errorMessage = error.message;
                    }
                    displayError(errorMessage);
                }
            },
            onCheckoutFail: (error, { payment }, handler) => {
                console.error('Primer onCheckoutFail:', error, payment);
                let errorMessage = i18n.payment_failed_try_again || 'Your payment could not be processed. Please try again later.';
                if (error && error.message) {
                    errorMessage = error.message;
                } else if (payment && payment.processor && payment.processor.message) {
                    errorMessage = payment.processor.message;
                } else if (typeof error === 'string') {
                    errorMessage = error;
                }
                displayError(errorMessage);
                // Optionally, could call handler.showErrorMessage(errorMessage) if not using custom display
                isInitializingPrimer = false;
            },
            // Optional: Add other lifecycle callbacks from client.js if needed
            onBeforeCheckoutStart: () => console.log('Primer: onBeforeCheckoutStart'),
            onCheckoutStart: () => console.log('Primer: onCheckoutStart'),
            onCheckoutCancel: () => {
                console.log('Primer: onCheckoutCancel');
                displayError(i18n.checkout_cancelled || 'Checkout was cancelled.');
                // Optionally redirect to cart or previous page
                // window.location.href = primerRedirectData.cart_url || '/'; 
                isInitializingPrimer = false;
            },
            onPaymentMethodShow: () => console.log('Primer: onPaymentMethodShow'),
            onPaymentMethodHide: () => console.log('Primer: onPaymentMethodHide'),
            onAuthorizationSuccess: (data) => console.log('Primer: onAuthorizationSuccess', data),
            onAuthorizationFailed: (data) => {
                console.log('Primer: onAuthorizationFailed', data);
                displayError(i18n.auth_failed || 'Payment authorization failed.');
            },
            onPaymentComplete: (data) => console.log('Primer: onPaymentComplete (different from onCheckoutComplete)', data),
            onPaymentFailed: (data) => {
                console.log('Primer: onPaymentFailed', data);
                displayError(i18n.payment_failed || 'Payment failed.');
            }
        };

        try {
            $('#checkout-container').show(); // Ensure container is visible before showing checkout
            primerCheckoutInstance = await Primer.showUniversalCheckout(tokenData.clientToken, options);
        } catch (error) {
            console.error('Error initializing Primer Universal Checkout:', error);
            displayError((i18n.error_init_payment_form || 'Failed to initialize payment form: ') + (error.message || ''));
        }
        isInitializingPrimer = false;
    }

    // Main execution flow on document ready (modern syntax)
    $(async function() {
        // Hide loading message and show checkout container once JS is ready to fetch token
        $('#loading-payment-message').hide();
        // $('#checkout-container').show(); // This will be shown after successful token fetch before Primer init

        if (!order_id) {
            displayError(i18n.error_missing_order_id || 'Order ID is missing. Cannot proceed with payment.');
            return;
        }
        if (!client_token_nonce) {
            displayError(i18n.error_missing_nonce || 'Security token is missing. Cannot proceed.');
            return;
        }

        try {
            const tokenData = await fetchClientToken();
            if (tokenData && tokenData.clientToken) {
                initializePrimerCheckout(tokenData);
            }
        } catch (error) {
            // Error already displayed by fetchClientToken or initializePrimerCheckout
            console.error('Failed to initialize payment process:', error);
        }
    });

})(jQuery);