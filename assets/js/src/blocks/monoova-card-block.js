import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { CHECKOUT_STORE_KEY } from '@woocommerce/block-data';
import { PrimerCheckoutContainer } from './primer-checkout-container';
import { usePrimerCheckoutInitialization } from '../hooks/usePrimerCheckoutInitialization';
/**
 * Internal dependencies
 */
// We might need specific components for card fields later
// import CardFields from './components/card-fields';

// Defensive check for WooCommerce availability
if (typeof registerPaymentMethod === 'undefined') {
    console.warn('WooCommerce Blocks registerPaymentMethod is not available. This may be due to edit mode or missing dependencies.');
} else {
    // Main logic - only execute if registerPaymentMethod is available
    
    // Try to get settings from WooCommerce settings registry first, then fallback to global variable
    let settings = {};
    try {
        // Check if getSetting is available before using it
        if (typeof getSetting !== 'undefined') {
            // The key should match the payment method name (monoova_card), not monoova_card_data
            settings = getSetting('monoova_card_data', {});
        } else {
            throw new Error('getSetting function not available');
        }
    } catch (error) {
        console.error('getSetting not available, trying fallback:', error);
        // Fallback to global variable if getSetting is not available (e.g., in edit mode)
        settings = window.monoova_card_blocks_params || {};
    }

    if (!settings || typeof settings !== 'object' || Object.keys(settings).length === 0) {
        console.warn("Monoova Card settings not found or empty, using defaults");
        settings = {
            title: 'Credit / Debit Card',
            description: 'Accept payments via Mastercard, Visa, Apple pay and Google pay',
            supports: []
        };
    }

    const defaultLabel = __('Credit / Debit Card', 'monoova-payments-for-woocommerce');
    const label = decodeEntities(settings.title) || defaultLabel;

    /**
     * Content component with Primer Universal Checkout
     */
    const Content = ({ 
        billing, 
        shippingData, 
        checkoutStatus, 
        paymentStatus, 
        eventRegistration, 
        emitResponse 
    }) => {
        const description = decodeEntities(settings.description || '');

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
            
            // Required fields for guest customers to generate client token
            return !!(
                billingAddress.email &&
                billingAddress.first_name &&
                billingAddress.last_name &&
                billingAddress.address_1 &&
                billingAddress.city &&
                billingAddress.postcode &&
                billingAddress.country &&
                billingAddress.state &&
                billingAddress.city
            );
        };

        // Use the custom hook for Primer checkout initialization
        const {
            isInitialized,
            isLoading,
            clientToken,
            orderId: hookOrderId,
            resetStates,
            containerRef
        } = usePrimerCheckoutInitialization({
            settings,
            billing,
            shippingData,
            orderId, // Pass the existing order ID from checkout store
            containerId: "in-checkout-primer-sdk-container",
            paymentMethodId: "monoova_card",
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

        return (
            <div>
                <div
                    className="monoova-card-content"
                    dangerouslySetInnerHTML={{ __html: description }}
                />
                {!hasRequiredGuestInfo() && !isInitialized && !isLoading && (
                    <div className="monoova-card-info">
                        <p>Please complete your billing information to initialize the payment form.</p>
                        <small>Required: Email, Name, Address, City, Postcode, and Country</small>
                    </div>
                )}
                <PrimerCheckoutContainer
                    containerId="in-checkout-primer-sdk-container"
                    isInitialized={isInitialized}
                    isLoading={isLoading}
                    containerRef={containerRef}
                />
            </div>
        );
    };

    /**
     * Label component
     *
     * @param {Object} props Props from payment API.
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
                    src={`${settings.plugin_url || '/wp-content/plugins/monoova-payments-for-woocommerce/'}assets/images/card-payment-method-types.svg`}
                    alt="Card payment methods"
                    style={{
                        height: '32px',
                        maxHeight: '32px',
                        width: 'auto'
                    }}
                />
            </div>
        );
    };

    /**
     * Edit component (for block editor - don't initialize payment)
     */
    const Edit = () => {
        const description = decodeEntities(settings.description || '');
        return (
            <div>
                <div
                    className="monoova-card-content"
                    dangerouslySetInnerHTML={{ __html: description }}
                />
                <div className="embedded-card-form">
                    <div id="in-checkout-primer-sdk-container">
                        
                    </div>
                </div>
            </div>
        );
    };

    /**
     * Monoova Card payment method config object.
     */
    const MonoovaCard = {
        name: "monoova_card", // Matches the name in PHP
        label: <Label />,
        content: <Content />,
        edit: <Edit />,
        canMakePayment: () => true, // Basic check, might need refinement
        ariaLabel: label,
        supports: {
            features: settings?.supports ?? [],
            // Add other supported features like savePaymentInfo if applicable
        },
        // Add icons if available in settings
        icons: settings?.icons ?? null,
    };

    // Register the payment method
    try {
        registerPaymentMethod(MonoovaCard);
    } catch (error) {
        console.error('Failed to register Monoova Card payment method:', error);
    }
}
