/**
 * External dependencies
 */
import { registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { createElement, useState, useCallback } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { CHECKOUT_STORE_KEY } from '@woocommerce/block-data';

/**
 * Defensive check for WooCommerce availability
 */
if (typeof registerExpressPaymentMethod === 'undefined') {
    console.warn('WooCommerce Blocks registerExpressPaymentMethod is not available. This may be due to edit mode or missing dependencies.');
} else if (!window.wp || !window.wp.element) {
    console.error('WordPress element library is not available');
} else {
    if (typeof createElement === 'undefined' || typeof useState === 'undefined' || typeof useCallback === 'undefined') {
        console.error('Required React hooks are not available');
        
        // Try fallback to window.wp.element
        const wpElement = window.wp.element;
        if (wpElement && wpElement.createElement && wpElement.useState && wpElement.useCallback) {
            const wpCreateElement = wpElement.createElement;
            const wpUseState = wpElement.useState;
            const wpUseCallback = wpElement.useCallback;
            
            // Proceed with main logic using fallback functions
            initializeExpressCheckout(wpCreateElement, wpUseState, wpUseCallback);
        } else {
            console.error('Fallback wp.element also not available');
        }
    } else {
        // Proceed with main logic using imported functions
        initializeExpressCheckout();
    }
}

/**
 * Initialize Express Checkout with provided React functions
 */
function initializeExpressCheckout() {
    
    // Try to get settings from WooCommerce settings registry first, then fallback to global variable
    let settings = {};
    try {
        // Check if getSetting is available before using it
        if (typeof getSetting !== 'undefined') {
            // The key should match the payment method name
            settings = getSetting('monoova_card_express_data', {});
        } else {
            throw new Error('getSetting function not available');
        }
    } catch (error) {
        console.error('getSetting not available, trying fallback:', error);
        // Fallback to global variable if getSetting is not available (e.g., in edit mode)
        settings = window.monoova_card_express_blocks_params || {};
    }

    if (!settings || typeof settings !== 'object' || Object.keys(settings).length === 0) {
        console.warn("Monoova Card Express settings not found or empty, using defaults");
        settings = {
            title: 'Monoova Express Checkout',
            description: 'Pay quickly and securely with Monoova Express Checkout.',
            supports: ['products'],
            style: ['height', 'borderRadius'],
            is_available: false,
            user_logged_in: false,
            button_style: {
                height: 48,
                borderRadius: 4,
                color: 'primary'
            },
            i18n: {
                express_pay_button: 'Pay with Monoova',
                processing: 'Processing payment...',
                generic_error: 'An error occurred while processing your payment. Please try again.',
                login_required: 'Please log in to use express checkout.'
            }
        };
    }

    const defaultTitle = __('Monoova Express Checkout', 'monoova-payments-for-woocommerce');
    const title = decodeEntities(settings.title) || defaultTitle;

    /**
     * Express Payment Content component
     */
    const ExpressContent = ({ 
        onClick, 
        onClose, 
        buttonAttributes,
        billing,
        shippingData,
        cartData,
        emitResponse,
        eventRegistration,
        activePaymentMethod,
        checkoutStatus,
        paymentStatus,
        ...rest
    }) => {
        /* 
            {
                "cartData": {
                    "cartItems": [
                        {
                            "key": "ea5d2f1c4608232e07d3aa3d998e5135",
                            "id": 64,
                            "type": "simple",
                            "quantity": 1,
                            "quantity_limits": {
                                "minimum": 1,
                                "maximum": 9999,
                                "multiple_of": 1,
                                "editable": true
                            },
                            "name": "Product 1",
                            "short_description": "",
                            "description": "<p>Product 1 description</p>",
                            "sku": "",
                            "low_stock_remaining": null,
                            "backorders_allowed": false,
                            "show_backorder_badge": false,
                            "sold_individually": false,
                            "permalink": "http://localhost:8881/?product=product-1",
                            "images": [],
                            "variation": [],
                            "item_data": [],
                            "prices": {
                                "price": "100",
                                "regular_price": "100",
                                "sale_price": "100",
                                "price_range": null,
                                "currency_code": "AUD",
                                "currency_symbol": "$",
                                "currency_minor_unit": 2,
                                "currency_decimal_separator": ".",
                                "currency_thousand_separator": ",",
                                "currency_prefix": "$",
                                "currency_suffix": "",
                                "raw_prices": {
                                    "precision": 6,
                                    "price": "1000000",
                                    "regular_price": "1000000",
                                    "sale_price": "1000000"
                                }
                            },
                            "totals": {
                                "line_subtotal": "100",
                                "line_subtotal_tax": "0",
                                "line_total": "100",
                                "line_total_tax": "0",
                                "currency_code": "AUD",
                                "currency_symbol": "$",
                                "currency_minor_unit": 2,
                                "currency_decimal_separator": ".",
                                "currency_thousand_separator": ",",
                                "currency_prefix": "$",
                                "currency_suffix": ""
                            },
                            "catalog_visibility": "visible",
                            "extensions": {}
                        }
                    ],
                    "cartFees": [],
                    "extensions": {}
                },
                "emitResponse": {
                    "noticeContexts": {
                        "CART": "wc/cart",
                        "CHECKOUT": "wc/checkout",
                        "PAYMENTS": "wc/checkout/payments",
                        "EXPRESS_PAYMENTS": "wc/checkout/express-payments",
                        "CONTACT_INFORMATION": "wc/checkout/contact-information",
                        "SHIPPING_ADDRESS": "wc/checkout/shipping-address",
                        "BILLING_ADDRESS": "wc/checkout/billing-address",
                        "SHIPPING_METHODS": "wc/checkout/shipping-methods",
                        "CHECKOUT_ACTIONS": "wc/checkout/checkout-actions",
                        "ORDER_INFORMATION": "wc/checkout/order-information"
                    },
                    "responseTypes": {
                        "SUCCESS": "success",
                        "FAIL": "failure",
                        "ERROR": "error"
                    }
                },
                "eventRegistration": {},
                "activePaymentMethod": "monoova_card",
                "billing": {
                    "appliedCoupons": [],
                    "billingAddress": {
                        "first_name": "Nhan",
                        "last_name": "Nguyen",
                        "company": "",
                        "address_1": "North Sydney",
                        "address_2": "",
                        "city": "North Sydney",
                        "state": "NSW",
                        "postcode": "2060",
                        "country": "AU",
                        "email": "nhan.ng@whitefox.cloud",
                        "phone": ""
                    },
                    "billingData": {
                        "first_name": "Nhan",
                        "last_name": "Nguyen",
                        "company": "",
                        "address_1": "North Sydney",
                        "address_2": "",
                        "city": "North Sydney",
                        "state": "NSW",
                        "postcode": "2060",
                        "country": "AU",
                        "email": "nhan.ng@whitefox.cloud",
                        "phone": ""
                    },
                    "cartTotal": {
                        "label": "Total",
                        "value": 100
                    },
                    "cartTotalItems": [
                        {
                            "key": "total_items",
                            "label": "Subtotal:",
                            "value": 100,
                            "valueWithTax": 100
                        },
                        {
                            "key": "total_fees",
                            "label": "Fees:",
                            "value": 0,
                            "valueWithTax": 0
                        },
                        {
                            "key": "total_discount",
                            "label": "Discount:",
                            "value": 0,
                            "valueWithTax": 0
                        },
                        {
                            "key": "total_tax",
                            "label": "Taxes:",
                            "value": 0,
                            "valueWithTax": 0
                        }
                    ],
                    "currency": {
                        "code": "AUD",
                        "symbol": "$",
                        "thousandSeparator": ",",
                        "decimalSeparator": ".",
                        "minorUnit": 2,
                        "prefix": "$",
                        "suffix": ""
                    },
                    "customerId": 0,
                    "displayPricesIncludingTax": false
                },
                "checkoutStatus": {
                    "isCalculating": false,
                    "isComplete": false,
                    "isIdle": true,
                    "isProcessing": false
                },
                "components": {},
                "paymentStatus": {
                    "isPristine": true,
                    "isIdle": true,
                    "isStarted": false,
                    "isProcessing": false,
                    "isFinished": false,
                    "hasError": false,
                    "hasFailed": false,
                    "isSuccessful": false,
                    "isReady": false,
                    "isDoingExpressPayment": false
                },
                "shippingData": {
                    "isSelectingRate": false,
                    "needsShipping": false,
                    "selectedRates": {},
                    "shippingAddress": {
                        "first_name": "Nhan",
                        "last_name": "Nguyen",
                        "company": "",
                        "address_1": "North Sydney",
                        "address_2": "",
                        "city": "North Sydney",
                        "state": "NSW",
                        "postcode": "2060",
                        "country": "AU",
                        "phone": ""
                    },
                    "shippingRates": [],
                    "shippingRatesLoading": false
                },
                "shippingStatus": {
                    "shippingErrorStatus": {
                        "isPristine": true,
                        "isValid": true,
                        "hasInvalidAddress": false,
                        "hasError": false
                    },
                    "shippingErrorTypes": {
                        "NONE": "none",
                        "INVALID_ADDRESS": "invalid_address",
                        "UNKNOWN": "unknown_error"
                    }
                },
                "shouldSavePayment": false
            }
        
        */
        const [isProcessing, setIsProcessing] = useState(false);
        const [paymentData, setPaymentData] = useState(null);

        const { orderId } = useSelect((select) => {
            const store = select(CHECKOUT_STORE_KEY);
            return {
                orderId: store.getOrderId(),
            };
        });
        
        // Extract address data from the correct props structure
        const billingAddress = billing?.billingAddress || {};
        const shippingAddress = shippingData?.shippingAddress || {};
        const cartTotal = billing?.cartTotal || {};
        
        // Helper function to format address for backend
        const formatAddress = (address) => {
            return {
                first_name: address.first_name || '',
                last_name: address.last_name || '',
                company: address.company || '',
                address_1: address.address_1 || '',
                address_2: address.address_2 || '',
                city: address.city || '',
                state: address.state || '',
                postcode: address.postcode || '',
                country: address.country || '',
                email: address.email || '',
                phone: address.phone || ''
            };
        };
        // Apply button attributes (height, borderRadius) from block settings
        const buttonStyle = {
            height: buttonAttributes?.height || settings.button_style?.height || 48,
            borderRadius: buttonAttributes?.borderRadius || settings.button_style?.borderRadius || 4,
            backgroundColor: settings.button_style?.color === 'primary' ? '#2271b1' : '#000',
            color: '#fff',
            border: 'none',
            fontSize: '16px',
            fontWeight: '600',
            cursor: 'pointer',
            width: '100%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '8px',
            padding: '0 16px',
            boxSizing: 'border-box',
            transition: 'all 0.2s ease'
        };

        // Handle express payment button click
        const handleExpressPayment = useCallback(async () => {
            if (isProcessing) return;

            try {
                setIsProcessing(true);
                
                // Signal to checkout that express payment method is taking over
                onClick();

                // Detect if we're on checkout page to prevent order duplication
                const isCheckoutPage = document.body.classList.contains('woocommerce-checkout') || 
                                     window.location.pathname.includes('/checkout/') ||
                                     document.querySelector('.woocommerce-checkout') !== null;
                

                // Extract billing and shipping addresses from the correct props structure
                const billingAddr = billing?.billingAddress || {};
                const shippingAddr = shippingData?.shippingAddress || billingAddr; // Fallback to billing if no shipping
                
                // CREATE ORDER: This is where the order gets created and we get the order ID
                // Unlike regular checkout, express checkout creates the order before payment processing
                const response = await fetch(settings.ajax_url, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: settings.ajax_express_checkout_action || 'monoova_express_checkout',
                        nonce: settings.express_checkout_nonce,
                        orderId: orderId,
                        billingAddress: JSON.stringify(formatAddress(billingAddr)),
                        shippingAddress: JSON.stringify(formatAddress(shippingAddr)),
                        shippingOption: '', // Will be selected during checkout
                        cardType: 'visa', // Default to visa, can be made configurable
                        isCheckoutPage: isCheckoutPage // Pass page context to server
                    })
                });

                if (!response.ok) {
                    // hide popup
                    setIsProcessing(false);
                    onClose();
                    throw new Error('Network response was not ok');

                }

                const result = await response.json();

                if (!result.success) {
                    // hide popup
                    setIsProcessing(false);
                    onClose();
                    throw new Error(result.data?.message || settings.i18n?.generic_error || 'Payment failed');
                }

                // Store payment data for completion
                setPaymentData(result.data);

                // Initialize Primer with the client token returned from the order creation
                await initializePrimerExpressCheckout(result.data);

            } catch (error) {
                console.error('Express payment error:', error);
                setIsProcessing(false);
                
                // Return control to checkout on error
                onClose();
                
                // Show error message
                alert(error.message || settings.i18n?.generic_error || 'Payment failed');
            }
        }, [isProcessing, onClick, onClose, cartData, billing, shippingData]);

        // Initialize Primer for express checkout
        const initializePrimerExpressCheckout = async (checkoutData) => {
            try {
                // Load Primer SDK if not already loaded
                if (!window.Primer) {
                    await loadPrimerSDK();
                }

                // checkoutData now contains orderId, clientToken, clientTransactionUniqueReference from ajax_express_checkout
                if (!checkoutData.clientToken || !checkoutData.orderId) {
                    throw new Error('Missing required payment data from server');
                }

                // Create the dialog element
                const dialog = document.createElement('dialog');
                dialog.id = 'primer-express-checkout-dialog';

                // Create a style element for the dialog and its backdrop
                const dialogStyle = document.createElement('style');
                dialogStyle.textContent = `
                    #primer-express-checkout-dialog {
                        border: none;
                        border-radius: 8px;
                        padding: 20px;
                        width: 100%;
                        max-width: 500px;
                        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
                        min-height: 300px;
                        max-height: 90vh;
                        overflow-x: hidden;
                    }
                    #primer-express-checkout-dialog::backdrop {
                        background: rgba(0, 0, 0, 0.8);
                    }
                    /* only hide #primer-checkout-other-payment-methods (inside #primer-express-checkout) if it doesn't include class PrimerCheckout--enter and PrimerCheckout--entered */
                    /* Hide other payment methods if not in enter state */
                    #primer-express-checkout #primer-checkout-other-payment-methods:not(.PrimerCheckout--enter) {
                        display: none;
                    }
                `;
                document.head.appendChild(dialogStyle);

                const closeDialog = () => {
                    if (document.body.contains(dialog)) {
                        dialog.close();
                        document.head.removeChild(dialogStyle);
                        document.body.removeChild(dialog);
                    }
                    setIsProcessing(false);
                    onClose();
                };

                const closeButton = document.createElement('button');
                closeButton.innerHTML = '×';
                closeButton.style.cssText = `
                    position: absolute;
                    top: 10px;
                    right: 15px;
                    background: none;
                    border: none;
                    font-size: 24px;
                    cursor: pointer;
                    color: #666;
                    z-index: 1;
                `;
                closeButton.onclick = () => {
                    closeDialog();
                };

                const primerContainer = document.createElement('div');
                primerContainer.id = 'primer-express-checkout';
                primerContainer.style.paddingTop = '20px';

                dialog.appendChild(closeButton);
                dialog.appendChild(primerContainer);
                document.body.appendChild(dialog);

                dialog.showModal();

                // Configure Primer options similar to primer-payment-handler.js
                const primerOptions = {
                    container: '#primer-express-checkout',
                    clientSessionCachingEnabled: false,
                    style: {
                        inputLabel: {
                            fontFamily: settings.checkout_ui_styles?.input_label?.font_family || 'Helvetica, Arial, sans-serif',
                            fontSize: settings.checkout_ui_styles?.input_label?.font_size || '14px',
                            fontWeight: settings.checkout_ui_styles?.input_label?.font_weight || 'normal',
                            color: settings.checkout_ui_styles?.input_label?.color || '#000000',
                        },
                        input: {
                            base: {
                                fontFamily: settings.checkout_ui_styles?.input?.font_family || 'Helvetica, Arial, sans-serif',
                                fontSize: settings.checkout_ui_styles?.input?.font_size || '14px',
                                fontWeight: settings.checkout_ui_styles?.input?.font_weight || 'normal',
                                background: settings.checkout_ui_styles?.input?.background_color || '#FAFAFA',
                                borderColor: settings.checkout_ui_styles?.input?.border_color || '#E8E8E8',
                                borderRadius: settings.checkout_ui_styles?.input?.border_radius || '8px',
                                color: settings.checkout_ui_styles?.input?.text_color || '#000000',
                            },
                        },
                        submitButton: {
                            base: {
                                color: settings.checkout_ui_styles?.submit_button?.text_color || '#000000',
                                background: settings.checkout_ui_styles?.submit_button?.background || '#2ab5c4',
                                borderRadius: settings.checkout_ui_styles?.submit_button?.border_radius || '10px',
                                borderColor: settings.checkout_ui_styles?.submit_button?.border_color || '#2ab5c4',
                                fontFamily: settings.checkout_ui_styles?.submit_button?.font_family || 'Helvetica, Arial, sans-serif',
                                fontSize: settings.checkout_ui_styles?.submit_button?.font_size || '17px',
                                fontWeight: settings.checkout_ui_styles?.submit_button?.font_weight || 'bold',
                                boxShadow: 'none'
                            },
                            disabled: {
                                color: '#9b9b9b',
                                background: '#e1deda'
                            }
                        },
                        loadingScreen: {
                            color: settings.checkout_ui_styles?.submit_button?.background || '#2ab5c4'
                        }
                    },
                    errorMessage: {
                        disabled: true
                    },
                    successScreen: false, // Disable success screen since we handle redirects manually
                    onCheckoutComplete: async (data) => {
                        closeDialog();
                        // Extract payment information
                        const primerPaymentId = data?.payment?.id;
                        
                        if (!primerPaymentId) {
                            throw new Error('Payment ID not received from Primer');
                        }
                        // Complete the order on the server using the new flow
                        await handleExpressPaymentComplete(data, checkoutData);
                    },
                    onCheckoutFail: (error, { payment }) => {
                        console.error('Primer express checkout failed:', error, payment);

                        closeDialog();
                        let errorMessage = settings.i18n?.generic_error || 'Payment failed';
                        if (error && error.message) {
                            errorMessage = error.message;
                        } else if (payment && payment.processor && payment.processor.message) {
                            errorMessage = payment.processor.message;
                        }
                        alert(errorMessage);
                    },
                    onCheckoutCancel: () => {
                        console.log('Primer express checkout cancelled');
                        closeDialog();
                    },
                    onAuthorizationFailed: (data) => {
                        console.log('Primer express checkout authorization failed:', data);
                        closeDialog();
                        alert(settings.i18n?.auth_failed || 'Payment authorization failed');
                    },
                    onPaymentFailed: (data) => {
                        console.log('Primer express checkout payment failed:', data);
                        closeDialog();
                        alert(settings.i18n?.payment_failed || 'Payment failed');
                    }
                };

                // Initialize Primer Universal Checkout with the client token from express checkout
                await window.Primer.showUniversalCheckout(checkoutData.clientToken, primerOptions);

            } catch (error) {
                console.error('Primer express checkout initialization error:', error);
                setIsProcessing(false);
                onClose();
                alert(settings.i18n?.generic_error || 'Payment failed');
            }
        };

        // Handle express payment completion
        const handleExpressPaymentComplete = async (primerData, checkoutData) => {
            try {
                const primerPaymentId = primerData?.payment?.id;
                const clientTransactionRef = checkoutData?.clientTransactionUniqueReference || '';
                const orderId = checkoutData?.orderId;

                if (!orderId) {
                    throw new Error('Order ID not available');
                }

                // Complete the order on the server using the same flow as normal checkout
                const response = await fetch(settings.ajax_url, {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: settings.ajax_complete_express_action || 'monoova_complete_express_checkout',
                        nonce: settings.express_checkout_nonce,
                        orderId: orderId,
                        primerPaymentId: primerPaymentId,
                        clientRef: clientTransactionRef
                    })
                });

                const result = await response.json();

                if (result.success) {
                    // Redirect to success page
                    window.location.href = result.data.redirect_url;
                } else {
                    throw new Error(result.data?.message || 'Payment completion failed');
                }

            } catch (error) {
                console.error('Express payment completion error:', error);
                setIsProcessing(false);
                onClose();
                alert(error.message || settings.i18n?.generic_error || 'Payment completion failed');
            }
        };

        // Load Primer SDK dynamically
        const loadPrimerSDK = () => {
            return new Promise((resolve, reject) => {
                if (window.Primer) {
                    resolve();
                    return;
                }

                // need to load 2 CDNs below:
                // <link rel="stylesheet" href="https://sdk.primer.io/web/v2.54.5/Checkout.css" />
                //<script src="https://sdk.primer.io/web/v2.54.5/Primer.min.js" integrity="sha384-yUCj6Q8h0Q6zFc35iT7v7pFoqlgpBD/xVr5SQxOgAnu2Cq286mf7IAyrFBJ8OsIa" crossorigin="anonymous"></script>

                // load css
                const link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://sdk.primer.io/web/v2.54.5/Checkout.css';
                document.head.appendChild(link);

                const script = document.createElement('script');
                script.src = 'https://sdk.primer.io/web/v2.54.5/Primer.min.js';
                script.crossOrigin = 'anonymous';

                script.onload = resolve;
                script.onerror = reject;
                document.head.appendChild(script);
            });
        };

        return createElement('button', {
            style: buttonStyle,
            onClick: handleExpressPayment,
            disabled: isProcessing,
            className: 'monoova-express-pay-button'
        }, [
            // Monoova logo (optional)
            // settings.icons?.[0] && createElement('img', {
            //     key: 'logo',
            //     src: settings.icons[0],
            //     alt: 'Monoova',
            //     style: {
            //         height: '20px',
            //         width: 'auto'
            //     }
            // }),
            // Button text
            createElement('span', {
                key: 'text'
            }, isProcessing 
                ? (settings.i18n?.processing || 'Processing...')
                : (settings.i18n?.express_pay_button || 'Pay with Monoova')
            )
        ]);
    };

    /**
     * Express Payment Edit component (for block editor)
     */
    const ExpressEdit = ({ buttonAttributes }) => {
        const buttonStyle = {
            height: buttonAttributes?.height || settings.button_style?.height || 48,
            borderRadius: buttonAttributes?.borderRadius || settings.button_style?.borderRadius || 4,
            backgroundColor: '#ccc',
            color: '#666',
            border: 'none',
            fontSize: '16px',
            fontWeight: '600',
            cursor: 'not-allowed',
            width: '100%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '8px',
            padding: '0 16px',
            boxSizing: 'border-box'
        };

        return createElement('button', {
            style: buttonStyle,
            disabled: true,
            className: 'monoova-express-pay-button monoova-express-pay-button--preview'
        }, [
            settings.icons?.[0] && createElement('img', {
                key: 'logo',
                src: settings.icons[0],
                alt: 'Monoova',
                style: {
                    height: '20px',
                    width: 'auto'
                }
            }),
            createElement('span', {
                key: 'text'
            }, settings.i18n?.express_pay_button || 'Pay with Monoova')
        ]);
    };

    /**
     * Can make payment check
     * Only show express checkout on cart page, hide on checkout page
     */
    const canMakePayment = ({
        cart,
        cartTotals,
        cartNeedsShipping,
        shippingAddress,
        billingAddress,
        selectedShippingMethods,
        paymentRequirements
    }) => {
        // Check if we're on the checkout page
        const isCheckoutPage = document.body.classList.contains('woocommerce-checkout') || 
                              window.location.pathname.includes('/checkout/') ||
                              document.querySelector('.woocommerce-checkout') !== null ||
                              document.querySelector('.wp-block-woocommerce-checkout') !== null;
        
        // Check if we're on the cart page
        const isCartPage = document.body.classList.contains('woocommerce-cart') || 
                          window.location.pathname.includes('/cart/') ||
                          document.querySelector('.woocommerce-cart') !== null ||
                          document.querySelector('.wp-block-woocommerce-cart') !== null;
        
        // Only show express checkout on cart page, not on checkout page
        return isCartPage && !isCheckoutPage;
    };

    /**
     * Monoova Card Express payment method config object.
     */
    const MonoovaCardExpress = {
        name: "monoova_card_express",
        title: title,
        description: decodeEntities(settings.description || ''),
        gatewayId: settings.gatewayId || 'monoova_card', // Points to main gateway for settings
        content: createElement(ExpressContent),
        edit: createElement(ExpressEdit),
        canMakePayment: canMakePayment,
        paymentMethodId: 'monoova_card', // Use the main gateway for processing
        supports: {
            features: settings?.supports ?? ['products'],
            style: settings?.style ?? ['height', 'borderRadius']
        }
    };

    // Register the express payment method
    try {
        registerExpressPaymentMethod(MonoovaCardExpress);
    } catch (error) {
        console.error('Failed to register Monoova Card Express payment method:', error);
    }
} // End of initializeExpressCheckout function
