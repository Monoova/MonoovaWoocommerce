import { useEffect, useState, useCallback, useRef } from '@wordpress/element';
import { usePersistentPaymentDetailsContainer } from './usePersistentPaymentDetailsContainer';

export const usePrimerCheckoutInitialization = ({
    settings,
    billing,
    shippingData,
    orderId: existingOrderId, // Accept existing order ID from props
    containerId = "in-checkout-primer-sdk-container",
    paymentMethodId = "monoova_card",
    hasRequiredInfo = true // New parameter to control API calling
}) => {
    const [isInitialized, setIsInitialized] = useState(false);
    const [clientToken, setClientToken] = useState(null);
    const [orderId, setOrderId] = useState(existingOrderId || null); // Initialize with existing order ID
    const [isLoading, setIsLoading] = useState(false);
    const [billingRef, setBillingRef] = useState(null);
    
    // Use persistent container management
    const {
        targetRef,
        containerElement,
        containerIdActive, // This will be the actual ID of the persistent container
        isContainerInitialized,
        setContainerInitialized,
        setOrderData, // Store order data in persistent container
        getOrderData, // Retrieve order data from persistent container
        showContainer,
        hideContainer
    } = usePersistentPaymentDetailsContainer(paymentMethodId, containerId);
    
    // Use refs to prevent duplicate API calls
    const isCreatingOrderRef = useRef(false);
    const isInitializingPrimerRef = useRef(false);

    // Check if we should use existing initialized container
    const shouldUseExistingContainer = isContainerInitialized && containerElement;
    
    // Get persistent order data
    const persistentOrderData = getOrderData();
    const persistentOrderId = persistentOrderData?.orderId;
    const persistentClientToken = persistentOrderData?.clientToken;

    // Reset function for retry scenarios
    const resetStates = useCallback(() => {
        setIsInitialized(false);
        setClientToken(null);
        setOrderId(null);
        setIsLoading(false);
        setBillingRef(null);
        
        // Reset refs to allow new initialization
        isCreatingOrderRef.current = false;
        isInitializingPrimerRef.current = false;
        
        // Note: We don't reset the persistent container here as it should remain available
    }, []);
    const loadPrimerSDK = useCallback(() => {
        return new Promise((resolve, reject) => {
            // check if Primer and 2 CDNs are already loaded
            if (
                window.Primer && document.querySelector('link[href="https://sdk.primer.io/web/v2.54.5/Checkout.css"]') &&
                document.querySelector('script[src="https://sdk.primer.io/web/v2.54.5/Primer.min.js"]')
            ) {
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
    }, []);

    const createOrderAndGetToken = useCallback(async () => {
        // Prevent duplicate API calls
        if (isCreatingOrderRef.current) {
            return null;
        }

        // If we already have a token, don't create another one
        if (clientToken || persistentClientToken) {
            const existingToken = clientToken || persistentClientToken;
            const finalOrderId = existingOrderId || orderId || persistentOrderId;
            
            // Update local state if using persistent data
            if (!clientToken && persistentClientToken) {
                setClientToken(persistentClientToken);
            }
            if (!orderId && finalOrderId) {
                setOrderId(finalOrderId);
            }
            
            return { token: existingToken, orderId: finalOrderId, checkoutData: null };
        }

        // Use existing order ID if available (WooCommerce checkout draft order)
        const finalOrderId = existingOrderId || orderId || persistentOrderId;
        
        if (!finalOrderId) {
            throw new Error('No order ID available for token generation');
        }

        try {
            isCreatingOrderRef.current = true;

            // Format address data with fallbacks for checkout page
            const formatAddress = (address) => ({
                first_name: address?.first_name || '',
                last_name: address?.last_name || '',
                company: address?.company || '',
                address_1: address?.address_1 || '',
                address_2: address?.address_2 || '',
                city: address?.city || '',
                state: address?.state || '',
                postcode: address?.postcode || '',
                country: address?.country || '',
                email: address?.email || '',
                phone: address?.phone || ''
            });

            // Use billing address or create empty object for checkout
            const billingAddress = billing?.billingAddress || {};

            // Generate client token for existing order via AJAX
            const response = await fetch(settings.ajax_url, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'monoova_get_client_token', // Use the client token endpoint instead of express checkout
                    _wpnonce: settings.generate_token_nonce,
                    order_id: finalOrderId, // Pass the existing order ID,
                    is_in_checkout: !!document.body.classList.contains('woocommerce-checkout'),
                    billingAddress: JSON.stringify(formatAddress(billingAddress)), // Include billing address as fallback
                    card_type: 'visa' // Default card type
                })
            });

            const result = await response.json();

            if (!result.success) {
                throw new Error(result.data?.message || 'Failed to generate client token');
            }

            const { clientToken: token } = result.data;
            setClientToken(token);
            setOrderId(finalOrderId);
            
            // Store in persistent container for later use
            setOrderData(finalOrderId, token);

            return { token, orderId: finalOrderId, checkoutData: result.data };

        } catch (error) {
            console.error('Error generating client token:', error);
            throw error;
        } finally {
            isCreatingOrderRef.current = false;
        }
    }, [settings, billing, shippingData, clientToken, orderId, existingOrderId, persistentClientToken, persistentOrderId]);

    // Handle payment completion
    const handlePaymentComplete = useCallback(async (data, checkoutData) => {
        try {
            const primerPaymentId = data?.payment?.id;
            if (!primerPaymentId) {
                throw new Error('Payment ID not received from Primer');
            }

            // Get the most current order data at payment completion time
            const currentOrderData = getOrderData();
            const currentOrderId = orderId || currentOrderData?.orderId;
            const currentClientToken = clientToken || currentOrderData?.clientToken;

            if (!currentOrderId) {
                throw new Error('Order ID not available for payment completion');
            }

            // Complete payment via AJAX
            const response = await fetch(settings.ajax_url, {
                method: 'POST',
                body: new URLSearchParams({
                    action: settings.ajax_complete_express_action,
                    nonce: settings.express_checkout_nonce,
                    orderId: currentOrderId,
                    primerPaymentId: primerPaymentId,
                    clientRef: checkoutData?.clientTransactionUniqueReference || '',
                    is_in_checkout: !!document.body.classList.contains('woocommerce-checkout'),
                })
            });

            const result = await response.json();

            if (result.success) {
                // Payment successful - redirect to success page
                window.location.href = result.data.redirect_url;
            } else {
                throw new Error(result.data?.message || 'Payment completion failed');
            }

        } catch (error) {
            console.error('Error completing payment:', error);
            alert(error.message || settings.i18n?.generic_error || 'Payment completion failed');
            resetStates();
        }
    }, [settings, orderId, clientToken, getOrderData, resetStates]);

    // Initialize Primer Universal Checkout with token
    const initializePrimerCheckout = useCallback(async (token, checkoutData) => {
        // Prevent duplicate Primer initialization
        if (isInitializingPrimerRef.current) {
            return;
        }

        // If already initialized, don't initialize again
        if (isContainerInitialized || isInitialized) {
            return;
        }

        try {
            isInitializingPrimerRef.current = true;

            // Load Primer SDK
            await loadPrimerSDK();

            const { Primer } = window;

            if (!Primer) {
                throw new Error('Primer SDK not loaded');
            }

            const primerOptions = {
                container: `#${containerIdActive || containerId}`, // Use persistent container ID or fallback to original
                clientSessionCachingEnabled: true,
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
                        focus: {
                            borderColor: '#2ab5c4',
                            boxShadow: '0 0 0 2px rgba(42, 181, 196, 0.2)',
                        },
                        error: {
                            borderColor: '#d63638',
                            color: '#d63638',
                        }
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
                successScreen: false, // Disable success screen as we handle it manually
                onCheckoutComplete: (data) => {
                    console.log('Primer checkout complete:', data);
                    handlePaymentComplete(data, checkoutData);
                },
                onCheckoutFail: (error, { payment }) => {
                    console.error('Primer checkout failed:', error, payment);
                    
                    let errorMessage = settings.i18n?.generic_error || 'Payment failed';
                    if (error && error.message) {
                        errorMessage = error.message;
                    } else if (payment && payment.processor && payment.processor.message) {
                        errorMessage = payment.processor.message;
                    }
                    
                    alert(errorMessage);
                    resetStates();
                },
                onCheckoutCancel: () => {
                    console.log('Primer checkout cancelled');
                    resetStates();
                }
            };

            // Initialize Primer Universal Checkout - this will show the form with loading indicator
            await Primer.showUniversalCheckout(token, primerOptions);
            
            // Mark as initialized
            setIsInitialized(true);
            setContainerInitialized(); // Mark persistent container as initialized
            setIsLoading(false);
        } catch (error) {
            console.error('Error initializing Primer checkout:', error);
            setIsLoading(false);
            alert(error.message || settings.i18n?.generic_error || 'Failed to initialize payment form');
        } finally {
            isInitializingPrimerRef.current = false;
        }
    }, [settings, containerId, loadPrimerSDK, handlePaymentComplete, resetStates, isInitialized, containerIdActive, setContainerInitialized]);


    // Initialize payment when conditions are met - with persistent container support
    useEffect(() => {
        const initializePayment = async () => {
            // If we have an existing initialized container, just show it and return
            if (shouldUseExistingContainer) {
                setIsInitialized(true);
                setIsLoading(false);
                
                // Restore persistent data to local state
                if (persistentClientToken && !clientToken) {
                    setClientToken(persistentClientToken);
                }
                if (persistentOrderId && !orderId) {
                    setOrderId(persistentOrderId);
                }
                
                showContainer();
                return;
            }

            // Prevent any initialization if already done or in progress
            if (isInitialized || isLoading || isCreatingOrderRef.current || isInitializingPrimerRef.current) {
                return;
            }

            // Check if we have an existing order ID (required for token generation)
            if (!existingOrderId) {
                return;
            }

            // Check if guest has provided all required information before proceeding
            if (!hasRequiredInfo) {
                return;
            }

            // Create unique reference for billing data to prevent duplicate initialization
            const currentBillingRef = billing?.billingAddress ? 
                `${billing.billingAddress.email}_${billing.billingAddress.first_name}_${billing.billingAddress.last_name}` : null;
            
            // We should initialize if we haven't already, and we have billing info or are on checkout page
            const hasBillingData = billing?.billingAddress || document.body.classList.contains('woocommerce-checkout');
            const billingDataChanged = currentBillingRef !== billingRef;
            
            if (!hasBillingData || !billingDataChanged) {
                return;
            }
            
            try {
                setIsLoading(true);
                setBillingRef(currentBillingRef); // Set ref to prevent re-initialization with same data

                const result = await createOrderAndGetToken();
                
                if (result && result.token) {
                    await initializePrimerCheckout(result.token, result.checkoutData);
                } else {
                    console.warn('No token received from order creation');
                    setIsLoading(false);
                }
                
            } catch (error) {
                console.error('Error during payment initialization:', error);
                setIsLoading(false);
                alert(error.message || 'Failed to initialize payment form.');
            }
        };

        // Use a small delay to prevent rapid successive calls during React hydration
        const timeoutId = setTimeout(initializePayment, 100);
        
        return () => clearTimeout(timeoutId);
    }, [
        existingOrderId, // Include existing order ID to trigger when it becomes available
        hasRequiredInfo, // Replace individual billing field dependencies with this
        shouldUseExistingContainer,
        showContainer,
        persistentOrderId,
        persistentClientToken
    ]); // Only depend on required info validation and container state

    return {
        isInitialized: isInitialized || shouldUseExistingContainer,
        isLoading,
        clientToken: clientToken || persistentClientToken, // Return persistent token if available
        orderId: existingOrderId || orderId || persistentOrderId, // Prioritize existing order ID
        resetStates,
        containerRef: targetRef // Return ref for the target container
    };
};