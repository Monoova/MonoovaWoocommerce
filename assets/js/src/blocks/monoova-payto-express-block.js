/**
 * External dependencies
 */
import { registerExpressPaymentMethod } from "@woocommerce/blocks-registry"
import { decodeEntities } from "@wordpress/html-entities"
import { getSetting } from "@woocommerce/settings"
import { __ } from "@wordpress/i18n"
import { createElement, useState, useCallback, useEffect } from "@wordpress/element"
import { useSelect } from "@wordpress/data"
import { CHECKOUT_STORE_KEY } from "@woocommerce/block-data"
import { NavigationCountdown } from "./NavigationCountdown"

/**
 * Defensive check for WooCommerce availability
 */
console.log("PayTo Express: Script loaded, checking dependencies")

if (typeof registerExpressPaymentMethod === "undefined") {
    console.warn(
        "WooCommerce Blocks registerExpressPaymentMethod is not available. This may be due to edit mode or missing dependencies."
    )
} else if (!window.wp || !window.wp.element) {
    console.error("WordPress element library is not available")
} else {
    if (typeof createElement === "undefined" || typeof useState === "undefined" || typeof useCallback === "undefined") {
        console.error("Required React hooks are not available")

        // Try fallback to window.wp.element
        const wpElement = window.wp.element
        if (wpElement && wpElement.createElement && wpElement.useState && wpElement.useCallback) {
            console.log("PayTo Express: Using fallback wp.element")
            const wpCreateElement = wpElement.createElement
            const wpUseState = wpElement.useState
            const wpUseCallback = wpElement.useCallback
            const wpUseEffect = wpElement.useEffect

            // Proceed with main logic using fallback functions
            initializePayToExpressCheckout(wpCreateElement, wpUseState, wpUseCallback, wpUseEffect)
        } else {
            console.error("Fallback wp.element also not available")
        }
    } else {
        console.log("PayTo Express: All dependencies available, proceeding with initialization")
        // Proceed with main logic using imported functions
        initializePayToExpressCheckout()
    }
}

/**
 * Initialize PayTo Express Checkout with provided React functions
 */
function initializePayToExpressCheckout() {
    console.log("PayTo Express: Starting initialization")

    // Try to get settings from WooCommerce settings registry first, then fallback to global variable
    let settings = {}
    try {
        // Check if getSetting is available before using it
        if (typeof getSetting !== "undefined") {
            settings = getSetting("monoova_payto_express_data", {})
            console.log("PayTo Express: Got settings from getSetting:", settings)
        }
    } catch (error) {
        console.warn("getSetting failed, trying fallback:", error)
    }

    // If settings are empty or getSetting failed, try window global fallback
    if (!settings || Object.keys(settings).length === 0) {
        settings = window.monoova_payto_express_blocks_params || {}
        console.log("PayTo Express: Got settings from window global:", settings)
    }

    // If still no settings, provide safe defaults
    if (!settings || Object.keys(settings).length === 0) {
        console.warn("PayTo Express settings not found, using defaults")
        settings = {
            title: "PayTo Express Checkout",
            description: "Pay quickly and securely with your existing PayTo mandate.",
            is_available: false,
            testmode: true,
            ajax_url: "/wp-admin/admin-ajax.php",
            check_mandate_nonce: "",
            express_payment_nonce: "",
            user_logged_in: false, // Add default
            i18n: {
                express_pay_button: "Pay with PayTo",
                login_required: "Please log in to use PayTo express checkout.",
                no_mandate: "No active PayTo mandate found.",
                mandate_available: "Use existing PayTo mandate",
                processing: "Processing payment...",
                error: "Payment failed. Please try again.",
            },
        }
    }

    console.log("PayTo Express: Final settings:", settings)

    const defaultTitle = __("PayTo Express Checkout", "monoova-payments-for-woocommerce")

    /**
     * PayTo Express Content Component
     */
    const PayToExpressContent = ({ onClose, onError, onSubmit, eventRegistration, emitResponse }) => {
        const [isLoading, setIsLoading] = useState(false)
        const [mandateData, setMandateData] = useState(null)
        const [isLoggedIn, setIsLoggedIn] = useState(false)
        const [shouldUseMandateCheck, setShouldUseMandateCheck] = useState(true)
        const [mandateLimitExceeded, setMandateLimitExceeded] = useState(false)
        const [showRedirect, setShowRedirect] = useState(false)

        // Get order ID from WooCommerce checkout store
        const { orderId } = useSelect(select => {
            const store = select(CHECKOUT_STORE_KEY)
            return {
                orderId: store.getOrderId(),
            }
        })

        // Check login status and mandate availability on mount
        useEffect(() => {
            checkLoginAndMandate()
        }, [])

        const checkLoginAndMandate = useCallback(async () => {
            setIsLoading(true)

            // First check if we're logged in according to PHP settings
            const isUserLoggedIn = settings.user_logged_in

            console.log("User logged in from settings:", isUserLoggedIn)
            console.log("Full settings:", settings)

            if (!isUserLoggedIn) {
                setIsLoggedIn(false)
                setMandateData(null)
                setIsLoading(false)
                return
            }

            // User is logged in, now check for mandate via AJAX
            try {
                const response = await fetch(settings.ajax_url, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({
                        action: settings.ajax_check_mandate_action || "monoova_payto_check_mandate",
                        nonce: settings.check_mandate_nonce,
                    }),
                })

                const result = await response.json()
                console.log("Mandate check result:", result)

                if (result.success) {
                    setIsLoggedIn(true)
                    if (result.data && result.data.mandate) {
                        setMandateData(result.data.mandate)
                        setShouldUseMandateCheck(result.data.should_use_mandate !== false)
                        setMandateLimitExceeded(result.data.mandate_limit_exceeded === true)

                        console.log("Mandate found:", result.data.mandate)
                        console.log("Should use mandate:", result.data.should_use_mandate)
                        console.log("Mandate limit exceeded:", result.data.mandate_limit_exceeded)

                        // If mandate limit is exceeded, show redirect message
                        if (result.data.mandate_limit_exceeded === true) {
                            setShowRedirect(true)
                        }
                    } else {
                        console.log("No mandate data in response")
                        setMandateData(null)
                        setShouldUseMandateCheck(false)
                        setMandateLimitExceeded(false)
                    }
                } else {
                    console.log("Mandate check failed:", result.data?.message || "Unknown error")
                    // User is logged in but mandate check failed
                    setIsLoggedIn(true)
                    setMandateData(null)
                    setShouldUseMandateCheck(false)
                    setMandateLimitExceeded(false)
                }
            } catch (error) {
                console.error("Error checking mandate:", error)
                // If AJAX fails, still show logged in state but no mandate
                setIsLoggedIn(true)
                setMandateData(null)
                setShouldUseMandateCheck(false)
                setMandateLimitExceeded(false)
            }

            setIsLoading(false)
        }, [])

        const handleExpressPayment = useCallback(
            async event => {
                console.log("PayTo Express: Button clicked, starting payment process")

                if (!mandateData) {
                    onError(settings.i18n.no_mandate)
                    return
                }

                console.log("PayTo Express: Starting payment process", {
                    mandateData,
                    settings: {
                        ajax_url: settings.ajax_url,
                        express_payment_nonce: settings.express_payment_nonce,
                    },
                })

                setIsLoading(true)

                try {
                    // For express checkout, we need to initiate the payment immediately
                    // This will create an order from the cart and process payment with existing mandate
                    const requestBody = new URLSearchParams({
                        action: settings.ajax_express_payment_action || "monoova_payto_express_payment",
                        nonce: settings.express_payment_nonce,
                        payment_agreement_uid: mandateData.payment_agreement_uid,
                    })

                    console.log("PayTo Express: Sending request", {
                        url: settings.ajax_url,
                        body: requestBody.toString(),
                    })

                    const response = await fetch(settings.ajax_url, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded",
                        },
                        body: requestBody,
                    })

                    const result = await response.json()
                    console.log("PayTo Express: Response received", result)

                    if (result.success) {
                        // Express checkout successful - redirect to order received page
                        const redirectUrl = result.data.order_received_url || result.data.redirect_url
                        console.log("PayTo Express: Redirecting to", redirectUrl)
                        if (redirectUrl) {
                            window.location.href = redirectUrl
                        } else {
                            // Fallback: close express checkout and show success
                            console.log("PayTo Express: No redirect URL, closing modal")
                            onClose()
                        }
                    } else {
                        console.error("PayTo Express: Payment failed", result.data)
                        const errorMessage = result.data?.message || settings.i18n.error
                        onError(errorMessage)
                    }
                } catch (error) {
                    console.error("PayTo Express: Network error", error)
                    onError(settings.i18n.error)
                }

                setIsLoading(false)
            },
            [mandateData, onSubmit, onError, emitResponse, orderId]
        )

        // If loading, show loading state
        if (isLoading) {
            return createElement(
                "div",
                {
                    className: "monoova-payto-express-loading",
                    style: { padding: "20px", textAlign: "center" },
                },
                settings.i18n.processing
            )
        }

        // If not logged in, show login message
        if (!isLoggedIn) {
            return createElement(
                "div",
                {
                    className: "monoova-payto-express-login-required",
                    style: { padding: "20px", textAlign: "center", color: "#666" },
                },
                settings.i18n.login_required
            )
        }

        // If no mandate available, show message
        if (!mandateData) {
            return createElement(
                "div",
                {
                    className: "monoova-payto-express-no-mandate",
                    style: { padding: "20px", textAlign: "center", color: "#666" },
                },
                settings.i18n.no_mandate
            )
        }

        // If mandate limit is exceeded, show redirect message with countdown
        if (mandateLimitExceeded || showRedirect) {
            const checkoutUrl = window.location.origin + "/checkout/"

            return createElement(
                "div",
                {
                    className: "monoova-payto-express-limit-exceeded",
                    style: {
                        padding: "20px",
                        textAlign: "center",
                        backgroundColor: "#fff3cd",
                        border: "1px solid #ffeaa7",
                        borderRadius: "5px",
                        color: "#856404",
                    },
                },
                [
                    createElement(
                        "div",
                        {
                            key: "message",
                            style: { marginBottom: "15px", fontSize: "14px" },
                        },
                        "Your current payment agreement has reached its limits. We will redirect you to the checkout page so that you can create another one."
                    ),
                    createElement(NavigationCountdown, {
                        key: "countdown",
                        initialSeconds: 5,
                        redirectUrl: checkoutUrl,
                        message: "Redirecting to checkout in {countdown} seconds...",
                    }),
                ]
            )
        }

        // If mandate should not be used (but not limit exceeded), don't show express checkout
        if (!shouldUseMandateCheck) {
            return createElement(
                "div",
                {
                    className: "monoova-payto-express-mandate-invalid",
                    style: { padding: "20px", textAlign: "center", color: "#666" },
                },
                "Your payment agreement cannot be used for this order. Please proceed to checkout."
            )
        }

        // Show express checkout option
        return createElement(
            "div",
            {
                className: "monoova-payto-express-checkout",
            },
            [
                createElement(
                    "div",
                    {
                        key: "mandate-info",
                        className: "mandate-info",
                        style: {
                            marginBottom: "15px",
                            padding: "10px",
                            backgroundColor: "#f0f8ff",
                            border: "1px solid #0073aa",
                            borderRadius: "5px",
                        },
                    },
                    [
                        createElement(
                            "h4",
                            {
                                key: "title",
                                style: { margin: "0 0 10px 0", color: "#0073aa" },
                            },
                            settings.i18n.mandate_available
                        ),
                        createElement(
                            "p",
                            {
                                key: "details",
                                style: { margin: "0", fontSize: "14px" },
                            },
                            [
                                createElement(
                                    "strong",
                                    { key: "label" },
                                    __("Maximum Amount: ", "monoova-payments-for-woocommerce")
                                ),
                                `$${mandateData.maximum_amount || "0.00"}`,
                            ]
                        ),
                    ]
                ),
                createElement(
                    "button",
                    {
                        key: "express-button",
                        type: "button",
                        className:
                            "wp-element-button wc-block-components-checkout-return-to-cart-button monoova-payto-express-btn",
                        style: {
                            width: "100%",
                            padding: "12px",
                            backgroundColor: settings.button_style?.color === "primary" ? "#0073aa" : "#666666",
                            color: "white",
                            border: "none",
                            borderRadius: `${settings.button_style?.borderRadius || 5}px`,
                            fontSize: "16px",
                            fontWeight: "bold",
                            cursor: isLoading ? "not-allowed" : "pointer",
                            opacity: isLoading ? 0.6 : 1,
                            height: `${settings.button_style?.height || 48}px`,
                        },
                        onClick: handleExpressPayment,
                        disabled: isLoading,
                    },
                    isLoading ? settings.i18n.processing : settings.i18n.express_pay_button
                ),
            ]
        )
    }

    /**
     * Can make payment check
     * Only show PayTo express checkout on cart page, hide on checkout page
     */
    const canMakePayment = ({
        cart,
        cartTotals,
        cartNeedsShipping,
        shippingAddress,
        billingAddress,
        selectedShippingMethods,
        paymentRequirements,
    }) => {
        // Check if we're on the checkout page
        const isCheckoutPage =
            document.body.classList.contains("woocommerce-checkout") ||
            window.location.pathname.includes("/checkout/") ||
            document.querySelector(".woocommerce-checkout") !== null ||
            document.querySelector(".wp-block-woocommerce-checkout") !== null

        // Check if we're on the cart page
        const isCartPage =
            document.body.classList.contains("woocommerce-cart") ||
            window.location.pathname.includes("/cart/") ||
            document.querySelector(".woocommerce-cart") !== null ||
            document.querySelector(".wp-block-woocommerce-cart") !== null

        // Additional check: ensure PayTo is available and user is logged in
        const isPayToAvailable = settings.is_available !== false
        const isUserLoggedIn = settings.user_logged_in

        console.log("PayTo Express: canMakePayment check", {
            isCheckoutPage,
            isCartPage,
            isPayToAvailable,
            isUserLoggedIn,
            settings,
            cart,
            cartTotals,
            cartNeedsShipping,
            shippingAddress,
            billingAddress,
            selectedShippingMethods,
            paymentRequirements,
        })

        // Only show express checkout on cart page, not on checkout page, and only if PayTo is available
        const canShow = isCartPage && !isCheckoutPage && isPayToAvailable && isUserLoggedIn
        return canShow
    }

    /**
     * Express Payment Configuration
     */
    const expressPaymentConfig = {
        name: "monoova_payto_express",
        label: createElement("span", {}, [
            // Add logo if available
            settings.logo_url &&
                createElement("img", {
                    key: "logo",
                    src: settings.logo_url,
                    alt: "PayTo",
                    style: { height: "20px", marginRight: "8px", verticalAlign: "middle" },
                }),
            createElement("span", { key: "title" }, settings.title || defaultTitle),
        ]),
        content: createElement(PayToExpressContent),
        edit: createElement("div", {}, __("PayTo Express Checkout", "monoova-payments-for-woocommerce")),
        canMakePayment: canMakePayment,
        paymentMethodId: "monoova_payto_express", // Use unique ID for express checkout
        supports: {
            features: ["products"],
        },
    }

    // Register the express payment method
    try {
        console.log("PayTo Express: Attempting to register with config:", expressPaymentConfig)

        // Check if another express payment method with same name exists
        console.log("PayTo Express: Checking existing registrations...")

        registerExpressPaymentMethod(expressPaymentConfig)
        console.log("PayTo Express Checkout registered successfully")

        // Verify registration worked by checking if method is available
        setTimeout(() => {
            const expressCheckoutArea = document.querySelector(".wc-block-components-express-payment")
            console.log("PayTo Express: Express checkout area found:", expressCheckoutArea)
        }, 2000)
    } catch (error) {
        console.error("Failed to register PayTo Express Checkout:", error)
    }
}

export default initializePayToExpressCheckout
