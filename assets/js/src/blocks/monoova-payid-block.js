/**
 * Monoova PayID Block for WooCommerce Blocks
 * 
 * Uses ES6 imports with defensive programming to handle cases where WooCommerce dependencies 
 * may not be available (edit mode, missing plugins, etc.)
 */

import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { getSetting } from '@woocommerce/settings';
import { decodeEntities } from '@wordpress/html-entities';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { CHECKOUT_STORE_KEY } from '@woocommerce/block-data';

// Import custom hooks and components
import { usePayIDPaymentInstructions } from '../hooks/usePayIDPaymentInstructions';
import { PayIDInstructionsContainer } from './PayIDInstructionsContainer';

// Load QR Code library
// if (typeof window !== 'undefined' && !window.QRCode) {
//     const script = document.createElement('script');
//     script.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
//     script.async = true;
//     document.head.appendChild(script);
// }


// If registerPaymentMethod is not available, we can't register the payment method
if (typeof registerPaymentMethod !== 'function') {
    console.warn('Monoova PayID Block: registerPaymentMethod not available. Available globals:', Object.keys(window.wc || {}));
} else {
    // Try to get settings
    let settings = {};
    if (typeof getSetting === 'function') {
        try {
            settings = getSetting('monoova_payid_data', {});
        } catch (error) {
            console.log('Monoova PayID Block: getSetting failed:', error);
        }
    }

    const defaultLabel = __('PayID / Bank Transfer', 'monoova-payments-for-woocommerce');
    const label = decodeEntities(settings.title) || defaultLabel;

    /**
     * Content component for PayID with payment instructions
     */
    const Content = ({ 
        billing, 
        shippingData, 
        checkoutStatus, 
        paymentStatus, 
        eventRegistration, 
        emitResponse 
    }) => {
        const [currentPaymentStatus, setCurrentPaymentStatus] = useState('pending');
        
        // Get the existing order ID from WooCommerce checkout store
        const { orderId } = useSelect((select) => {
            const store = select(CHECKOUT_STORE_KEY);
            return {
                orderId: store.getOrderId(),
            };
        });
        
        // Helper function to check if required guest information is available
        const hasRequiredGuestInfo = () => {
            const billingAddress = billing?.billingAddress;
            if (!billingAddress) return false;
            
            // Required fields for guest customers to generate PayID instructions
            return !!(
                billingAddress.email &&
                billingAddress.first_name &&
                billingAddress.last_name &&
                billingAddress.address_1 &&
                billingAddress.city &&
                billingAddress.postcode &&
                billingAddress.country
            );
        };
        
        // Use the PayID payment instructions hook
        const {
            isLoading,
            instructions,
            error,
            paymentStatus: hookPaymentStatus,
            paymentFailedReason,
            expiryTime,
            resetStates,
            regeneratePaymentInstructions, // Get the regeneration function
            resetPaymentStatus, // Get the status reset function
            containerRef,
            isInitialized
        } = usePayIDPaymentInstructions({
            settings,
            billing,
            shippingData,
            orderId, // Pass the existing order ID from checkout store
            containerId: "payid-instructions-container",
            paymentMethodId: "monoova_payid",
            hasRequiredInfo: hasRequiredGuestInfo() // Pass the validation result
        });

        useEffect(() => {
            // Selector for the block checkout's "Place Order" button
            const placeOrderButton = document.querySelector('.wc-block-components-button.wp-element-button.wc-block-components-checkout-place-order-button');

            if (placeOrderButton) {
                // Hide the button when Monoova Card is selected
                placeOrderButton.style.display = 'none';
            }

            // Cleanup function: runs when component unmounts (e.g., user selects another payment method)
            return () => {
                if (placeOrderButton) {
                    // Restore the button's default display style
                    placeOrderButton.style.display = '';
                }
            };
        }, []);

        // Update payment status
        useEffect(() => {
            if (hookPaymentStatus !== 'pending') {
                setCurrentPaymentStatus(hookPaymentStatus);
            }
        }, [hookPaymentStatus]);

        const description = decodeEntities(settings.description || '');

        // Handle payment status changes from the component
        const handlePaymentStatusChange = (status) => {
            if (status === 'pending') {
                // This will just change the UI view
                setCurrentPaymentStatus('pending');
                // This will restart the polling in the hook
                if (resetPaymentStatus) {
                    resetPaymentStatus();
                }
            } else {
                setCurrentPaymentStatus(status);
            }
        };

        // Loading state
        if (isLoading && !instructions) {
            return (
                <div className="monoova-payid-content">
                    <div
                        className="monoova-payid-description"
                        dangerouslySetInnerHTML={{ __html: description }}
                    />
                    <div className="monoova-payid-loading">
                        <div className="spinner"></div>
                        <p>{settings.strings?.loading || 'Generating payment instructions...'}</p>
                    </div>
                </div>
            );
        }

        // Show message when required information is missing
        if (!hasRequiredGuestInfo() && !instructions) {
            return (
                <div className="monoova-payid-content">
                    <div
                        className="monoova-payid-description"
                        dangerouslySetInnerHTML={{ __html: description }}
                    />
                    <div className="monoova-payid-info">
                        <p>Please complete your billing information to generate PayID payment instructions.</p>
                        <small>Required: Email, Name, Address, City, Postcode, and Country</small>
                    </div>
                </div>
            );
        }

        // Error state
        if (error) {
            return (
                <div className="monoova-payid-content">
                    <div
                        className="monoova-payid-description"
                        dangerouslySetInnerHTML={{ __html: description }}
                    />
                    <div className="monoova-payid-error">
                        <p>{error}</p>
                        <button type="button" onClick={resetStates}>
                            Try Again
                        </button>
                    </div>
                </div>
            );
        }

        return (
            <div className="monoova-payid-content">
                <div
                    className="monoova-payid-description"
                    dangerouslySetInnerHTML={{ __html: description }}
                />
                
                {/* Target container for persistent PayID instructions */}
                <div ref={containerRef} className="payid-container-target">
                    {instructions && (
                        <PayIDInstructionsContainer
                            instructions={instructions}
                            settings={settings}
                            paymentStatus={currentPaymentStatus}
                            expiryTime={expiryTime}
                            paymentFailedReason={paymentFailedReason}
                            onPaymentStatusChange={handlePaymentStatusChange}
                            onRegenerateInstructions={regeneratePaymentInstructions}
                            orderId={orderId}
                        />
                    )}
                </div>
            </div>
        );
    };

    /**
     * Label component
     */
    const Label = (props) => {
        const { PaymentMethodLabel } = props.components || {};
        return (
            <div style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'center',
                width: '100%'
            }}>
                <span style={{ fontWeight: 600 }}>{label}</span>
                <img
                    src={`${settings.plugin_url || '/wp-content/plugins/monoova-payments-for-woocommerce/'}assets/images/payid-logo.svg`}
                    alt="PayID logo"
                    style={{
                        height: '24px',
                        width: 'auto'
                    }}
                />
            </div>
        );
    };

    /**
     * Monoova PayID payment method config object
    /**
     * Edit component (for block editor - simplified view)
     */
    const Edit = () => {
        const description = decodeEntities(settings.description || '');
        return (
            <div className="monoova-payid-content">
                <div
                    className="monoova-payid-description"
                    dangerouslySetInnerHTML={{ __html: description }}
                />
                <div className="payid-instructions-preview">
                    <div style={{
                        border: '1px dashed #ccc',
                        padding: '20px',
                        textAlign: 'center',
                        borderRadius: '8px',
                        color: '#666',
                        margin: '15px 0'
                    }}>
                        <p><strong>PayID / Bank Transfer Instructions</strong></p>
                        <p>Payment instructions will appear here during checkout</p>
                    </div>
                </div>
            </div>
        );
    };

    /**
     * Monoova PayID payment method config object
     */
    const MonoovaPayID = {
        name: 'monoova_payid',
        label: <Label />,
        content: <Content />,
        edit: <Edit />,
        canMakePayment: function() { return true; },
        ariaLabel: label,
        supports: {
            features: settings.supports || []
        },
        icons: settings.icons || null
    };

    // Register the payment method
    try {
        registerPaymentMethod(MonoovaPayID);
    } catch (error) {
        console.error('Monoova PayID Block: Failed to register payment method:', error);
    }
}