/**
 * Monoova PayTo Block for WooCommerce Blocks
 *
 * Uses ES6 imports with defensive programming to handle cases where WooCommerce dependencies
 * may not be available (edit mode, missing plugins, etc.)
 */

import { registerPaymentMethod } from "@woocommerce/blocks-registry"
import { getSetting } from "@woocommerce/settings"
import { CHECKOUT_STORE_KEY } from "@woocommerce/block-data"
import { decodeEntities } from "@wordpress/html-entities"
import { __ } from "@wordpress/i18n"
import { useState, useEffect } from "@wordpress/element"
import { useSelect } from "@wordpress/data"
import { RadioControl, Button, TextControl } from '@wordpress/components';
// Import custom hooks
import { usePayToPaymentContainer } from "../hooks/usePayToPaymentContainer"

// Add CSS animation for spinner and TextControl styling
const spinnerStyles = `
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Custom styling for TextControl components */
.components-text-control__input.has-error {
    border-color: #ef4444 !important;
}

.components-text-control__input {
    font-weight: 500;
    font-size: 16px;
    line-height: 1.21em;
    color: #333;
    padding: 12px;
    border: 1px solid #e8e8e8;
    border-radius: 8px;
    background-color: #f8f9fa;
    min-height: 48px;
    box-sizing: border-box;
}

.components-text-control__input:focus {
    border-color: #2CB5C5;
    box-shadow: 0 0 0 1px #2CB5C5;
}

.components-text-control__input[readonly] {
    background-color: #f8f9fa;
    color: #333;
    cursor: default;
}

/* Input adornment styling for currency prefix */
.text-control-with-prefix {
    position: relative;
    display: inline-block;
    width: 100%;
}

.text-control-with-prefix .components-text-control__input {
    padding-left: 24px !important;
}

.text-control-prefix {
    position: absolute;
    left: 10px;
    top: 20.5%;
    font-weight: 500;
    font-size: 16px;
    color: #333;
    pointer-events: none;
    z-index: 1;
}

/* Error state for prefixed input */
.text-control-with-prefix .components-text-control__input.has-error + .text-control-prefix,
.text-control-with-prefix .has-error .components-text-control__input + .text-control-prefix {
    color: #ef4444;
}
`

// Inject the styles into the document head
if (typeof document !== "undefined") {
    const styleSheet = document.createElement("style")
    styleSheet.type = "text/css"
    styleSheet.innerText = spinnerStyles
    document.head.appendChild(styleSheet)
}

// If registerPaymentMethod is not available, we can't register the payment method
if (typeof registerPaymentMethod !== "function") {
    console.warn(
        "Monoova PayTo Block: registerPaymentMethod not available. Available globals:",
        Object.keys(window.wc || {})
    )
} else {
    // Try to get settings
    let settings = {}
    if (typeof getSetting === "function") {
        try {
            settings = getSetting("monoova_payto_data", {})
        } catch (error) {
            console.log("Monoova PayTo Block: getSetting failed:", error)
        }
    }

    // Fallback to global variable if getSetting didn't work
    if (!settings || Object.keys(settings).length === 0) {
        settings = window.monoova_payto_blocks_params || {}
        console.log("Monoova PayTo Block: Using fallback settings:", settings)
    }

    // Set defaults if no settings available
    if (!settings || Object.keys(settings).length === 0) {
        console.warn("Monoova PayTo Block: No settings found, using defaults")
        settings = {
            title: "PayTo",
            description: "Set up PayTo directly from your bank using BSB and Account Number or PayID.",
            supports: [],
        }
    }

    const defaultLabel = __("PayTo", "monoova-payments-for-woocommerce")
    const label = decodeEntities(settings.title) || defaultLabel

    /**
     * PayTo Payment Form Component
     */
    const PayToForm = ({ eventRegistration, emitResponse, billing }) => {
        // Get current order ID from WooCommerce checkout store
        const { orderId } = useSelect(select => {
            const store = select(CHECKOUT_STORE_KEY)
            return {
                orderId: store.getOrderId(),
            }
        })

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

        // Use the PayTo payment container hook for persistent state management
        const {
            containerRef,
            isInitialized,
            setContainerInitialized,

            // State management from hook
            paymentMethod,
            setPaymentMethod,
            payidValue,
            setPayidValue,
            payidType,
            setPayidType,
            accountName,
            setAccountName,
            bsb,
            setBsb,
            accountNumber,
            setAccountNumber,
            maximumAmount,
            setMaximumAmount,
            errors,
            setErrors,
            paymentStatus,
            setPaymentStatus,
            paymentInstructions,
            setPaymentInstructions,
            isSubmitting,
            setIsSubmitting,
            pollInterval,
            setPollInterval,
            hasExpressCheckout,
            setHasExpressCheckout,
            errorDetails,
            setErrorDetails,
            mandateInfo,
            setMandateInfo,
            countdown,
            setCountdown,

            // Utilities
            resetStates,
            stopPolling,
            saveCurrentState,
        } = usePayToPaymentContainer({
            settings,
            billing,
            orderId,
            containerId: "payto-payment-container",
            paymentMethodId: "monoova_payto",
            hasRequiredInfo: hasRequiredGuestInfo(),
        })

        // Get cart totals from WooCommerce
        const cartTotals = useSelect(select => {
            const store = select("wc/store/cart")
            if (store && store.getCartTotals) {
                return store.getCartTotals()
            }
            return null
        }, [])

        useEffect(() => {
            // Selector for the block checkout's "Place Order" button
            const placeOrderButton = document.querySelector(
                ".wc-block-components-button.wp-element-button.wc-block-components-checkout-place-order-button"
            )

            if (placeOrderButton) {
                // Hide the button when Monoova PayTo is selected
                placeOrderButton.style.display = "none"
            }

            // Cleanup function: runs when component unmounts (e.g., user selects another payment method)
            return () => {
                if (placeOrderButton) {
                    // Restore the button's default display style
                    placeOrderButton.style.display = ""
                }
            }
        }, [])

        // Get order total amount
        const orderTotal = cartTotals?.total_price ? parseFloat(cartTotals.total_price) / 100 : 0
        const currency = cartTotals?.currency_code || settings.currency || "AUD"

        const { onPaymentSetup } = eventRegistration
        const { responseTypes, noticeContexts } = emitResponse

        // Helper function to get status colors
        const getStatusColor = status => {
            const statusLower = status?.toLowerCase() || ""
            switch (statusLower) {
                case "completed":
                case "success":
                case "authorized":
                    return { background: "#dcfce7", text: "#166534" }
                case "processing":
                case "pending":
                case "created":
                    return { background: "#fef3c7", text: "#92400e" }
                case "failed":
                case "cancelled":
                case "rejected":
                    return { background: "#fee2e2", text: "#991b1b" }
                default:
                    return { background: "#f3f4f6", text: "#374151" }
            }
        }

        // Check for express checkout availability on mount
        useEffect(() => {
            console.log("PayTo: Component mounted, checking express checkout availability...")
            checkExpressCheckoutAvailability()
        }, [])

        // Cleanup polling on unmount
        useEffect(() => {
            return () => {
                if (pollInterval) {
                    clearInterval(pollInterval)
                }
            }
        }, [pollInterval])

        // Countdown timer effect
        useEffect(() => {
            const timer = setInterval(() => {
                setCountdown(prev => {
                    if (prev <= 1) {
                        clearInterval(timer)
                        return 0
                    }
                    return prev - 1
                })
            }, 1000)

            return () => clearInterval(timer)
        }, [])

        // Check if express checkout is available
        const checkExpressCheckoutAvailability = async () => {
            if (!settings.is_user_logged_in) {
                console.log("PayTo: User not logged in, skipping express checkout check")
                return
            }

            try {
                const response = await fetch(settings.ajax_url || "/wp-admin/admin-ajax.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({
                        action: "monoova_check_express_checkout",
                        nonce: settings.nonce,
                    }),
                })

                const result = await response.json()

                if (result.success && result.data.available) {
                    setHasExpressCheckout(true)
                    // Store mandate details for display
                    if (result.data.mandate_info) {
                        setMandateInfo(result.data.mandate_info)
                    }
                } else {
                    console.log("Express checkout not available or failed:", result)
                }
            } catch (error) {
                console.error("Error checking express checkout availability:", error)
            }
        }

        // Handle checkout with existing mandate
        const handleCheckoutWithExistingMandate = async () => {
            setIsSubmitting(true)
            setPaymentStatus("processing")
            setErrors({}) // Clear previous errors
            setErrorDetails(null) // Clear previous error details

            try {
                const response = await fetch(settings.ajax_url || "/wp-admin/admin-ajax.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({
                        action: "monoova_process_payment_with_existing_mandate",
                        nonce: settings.nonce,
                        order_id: orderId,
                        payment_agreement_uid: mandateInfo?.agreement_id,
                    }),
                })

                const result = await response.json()

                if (result.success) {
                    setPaymentInstructions({
                        order_id: result.data.order_id,
                        currency: currency,
                        amount: orderTotal.toFixed(2),
                        status: "Processing",
                        agreement_reference: result.data.order_id,
                        message: "Payment initiated with existing mandate",
                    })
                    setPaymentStatus("instructions")
                    startPaymentStatusPolling(result.data.order_id)
                } else {
                    const errorMessage = result.data?.message || "Express checkout failed"
                    const errorCode = result.data?.error_code || "UNKNOWN_ERROR"
                    const errorType = result.data?.error_type || "EXPRESS_CHECKOUT_ERROR"

                    // Check if this is an agreement limit error
                    if (errorCode === "payto_agreement_limit_exceeded" || errorType === "AGREEMENT_LIMIT_ERROR") {
                        setPaymentStatus("agreement_limit_exceeded")
                        setErrors({ general: errorMessage })
                        setErrorDetails({
                            message: errorMessage,
                            code: "AGREEMENT_LIMIT_EXCEEDED",
                            type: "AGREEMENT_LIMIT_ERROR",
                            context: "express_checkout",
                            requires_new_agreement: true,
                            limit_errors: result.data?.errors || [],
                            agreement_uid: result.data?.agreement_uid,
                        })
                    } else {
                        setPaymentStatus("failed")
                        setErrors({ general: errorMessage })
                        setErrorDetails({
                            message: errorMessage,
                            code: errorCode,
                            type: errorType,
                            context: "express_checkout",
                            details: result.data?.error_details || null,
                        })
                    }
                }
            } catch (error) {
                setPaymentStatus("failed")
                setErrors({ general: "Network error occurred during express checkout" })
                setErrorDetails({
                    message: "Network error occurred during express checkout",
                    code: "NETWORK_ERROR",
                    type: "CONNECTION_ERROR",
                    context: "express_checkout",
                    details: error.message,
                })
            } finally {
                setIsSubmitting(false)
            }
        }

        // Note: We're using a custom "Accept and Continue" button instead of the default place order button

        // PayID validation and auto-detection
        const validatePayID = value => {
            if (!value.trim()) {
                setPayidType("")
                setErrors(prev => ({ ...prev, payidValue: "" }))
                return
            }

            // Email regex pattern
            const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
            // Phone regex pattern (Australian format)
            const phonePattern = /^(\+61|0)[0-9]{9}$/

            if (emailPattern.test(value)) {
                setPayidType("Email")
                setErrors(prev => ({ ...prev, payidValue: "" }))
            } else if (phonePattern.test(value)) {
                setPayidType("PhoneNumber")
                setErrors(prev => ({ ...prev, payidValue: "" }))
            } else {
                setPayidType("")
                setErrors(prev => ({
                    ...prev,
                    payidValue: __(
                        "Please enter a valid email address or phone number.",
                        "monoova-payments-for-woocommerce"
                    ),
                }))
            }
        }

        // Validation function
        const validateForm = () => {
            const newErrors = {}

            if (paymentMethod === "payid") {
                if (!payidValue.trim()) {
                    newErrors.payidValue = __("PayID is required.", "monoova-payments-for-woocommerce")
                } else if (!payidType) {
                    newErrors.payidValue = __(
                        "Please enter a valid email address or phone number.",
                        "monoova-payments-for-woocommerce"
                    )
                }
            } else {
                if (!accountName.trim()) {
                    newErrors.accountName = __("Account name is required.", "monoova-payments-for-woocommerce")
                }
                if (!bsb.trim()) {
                    newErrors.bsb = __("BSB is required.", "monoova-payments-for-woocommerce")
                } else if (!/^\d{3}-?\d{3}$/.test(bsb.trim())) {
                    newErrors.bsb = __("Please enter a valid BSB (6 digits).", "monoova-payments-for-woocommerce")
                }
                if (!accountNumber.trim()) {
                    newErrors.accountNumber = __("Account number is required.", "monoova-payments-for-woocommerce")
                }
            }

            // Validate maximum amount
            const numericMaxAmount = parseFloat(maximumAmount)
            if (!maximumAmount || maximumAmount === '' || isNaN(numericMaxAmount) || numericMaxAmount <= 0) {
                newErrors.maximumAmount = __(
                    "Maximum amount must be greater than 0.",
                    "monoova-payments-for-woocommerce"
                )
            }

            setErrors(newErrors)
            return Object.keys(newErrors).length === 0
        }

        // Register payment setup handler
        React.useEffect(() => {
            const unsubscribe = onPaymentSetup(() => {
                if (!validateForm()) {
                    return {
                        type: responseTypes.ERROR,
                        message: __("Please correct the errors in the PayTo form.", "monoova-payments-for-woocommerce"),
                        messageContext: noticeContexts.PAYMENTS,
                    }
                }

                // Return payment data
                return {
                    type: responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            payto_payment_method: paymentMethod,
                            payto_payid_type: paymentMethod === "payid" ? payidType : "",
                            payto_payid_value: paymentMethod === "payid" ? payidValue : "",
                            payto_account_name: paymentMethod === "bsb_account" ? accountName : "",
                            payto_bsb: paymentMethod === "bsb_account" ? bsb : "",
                            payto_account_number: paymentMethod === "bsb_account" ? accountNumber : "",
                            payto_maximum_amount: maximumAmount.toString(),
                        },
                    },
                }
            })

            return unsubscribe
        }, [
            onPaymentSetup,
            paymentMethod,
            payidType,
            payidValue,
            accountName,
            bsb,
            accountNumber,
            maximumAmount,
            validateForm,
        ])

        // Custom submit handler for PayTo
        const handlePayToSubmit = async event => {
            if (event) {
                event.preventDefault()
                event.stopPropagation()
            }

            if (!validateForm()) {
                return
            }

            setIsSubmitting(true)
            setPaymentStatus("processing")
            setErrors({}) // Clear previous errors
            setErrorDetails(null) // Clear previous error details

            // Clear mandate info when creating new agreement
            setMandateInfo(null)
            setHasExpressCheckout(false)

            try {
                // Get billing data from props (provided by WooCommerce Blocks)
                let billingInfo = billing?.billingAddress || {}
                const response = await fetch(settings.ajax_url || "/wp-admin/admin-ajax.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({
                        action: "monoova_create_payto_agreement",
                        nonce: settings.nonce,
                        payto_payment_method: paymentMethod,
                        payto_payid_type: paymentMethod === "payid" ? payidType : "",
                        payto_payid_value: paymentMethod === "payid" ? payidValue : "",
                        payto_account_name: paymentMethod === "bsb_account" ? accountName : "",
                        payto_bsb: paymentMethod === "bsb_account" ? bsb : "",
                        payto_account_number: paymentMethod === "bsb_account" ? accountNumber : "",
                        payto_maximum_amount: maximumAmount.toString(),
                        billing_first_name: billingInfo.first_name || "",
                        billing_last_name: billingInfo.last_name || "",
                        billing_email: billingInfo.email || "",
                        order_id: orderId,
                        isCheckoutPage: true,
                    }),
                })

                const result = await response.json()

                if (result.success) {
                    setPaymentInstructions({
                        order_id: result.data.order_id,
                        order_key: result.data.order_key,
                        order_received_url: result.data.order_received_url,
                        currency: currency,
                        amount: orderTotal.toFixed(2),
                        status: "Processing",
                        agreement_reference: result.data.order_id,
                        message: "Payment agreement created successfully",
                    })
                    setPaymentStatus("instructions")
                    startPaymentStatusPolling(result.data.order_id)
                } else {
                    const errorMessage = result.data?.message || "Failed to create payment agreement"
                    const errorCode = result.data?.error_code || "UNKNOWN_ERROR"
                    const errorType = result.data?.error_type || "AGREEMENT_CREATION_ERROR"

                    // Reset payment status to allow user to try again
                    setPaymentStatus("failed")
                    setErrors({ general: errorMessage })
                    setErrorDetails({
                        message: errorMessage,
                        code: errorCode,
                        type: errorType,
                        context: "agreement_creation",
                        details: result.data?.error_details || null,
                        validation_errors: result.data?.validation_errors || null,
                    })
                }
            } catch (error) {
                setPaymentStatus("failed")
                setErrors({ general: "Network error occurred while creating payment agreement" })
                setErrorDetails({
                    message: "Network error occurred while creating payment agreement",
                    code: "NETWORK_ERROR",
                    type: "CONNECTION_ERROR",
                    context: "agreement_creation",
                    details: error.message,
                })
            } finally {
                setIsSubmitting(false)
            }
        }

        // Reset payment agreement when limits exceeded
        const resetPaymentAgreement = async () => {
            setIsSubmitting(true)
            setErrors({})
            setErrorDetails(null)

            try {
                const billingInfo = billing?.billingAddress || {}
                const response = await fetch(settings.ajax_url || "/wp-admin/admin-ajax.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded",
                    },
                    body: new URLSearchParams({
                        action: "monoova_payto_reset_agreement",
                        nonce: settings.nonce,
                        order_id: paymentInstructions?.order_id || orderId,
                        customer_email: billingInfo.email || "",
                    }),
                })

                const result = await response.json()

                if (result.success) {
                    // Reset all payment-related state
                    setPaymentInstructions(null)
                    setPaymentStatus("form")
                    setErrors({})
                    setErrorDetails(null)

                    // Reset form fields to allow new agreement creation
                    setPaymentMethod("payid")
                    setPayidType("email")
                    setPayidValue("")
                    setAccountName("")
                    setBsb("")
                    setAccountNumber("")
                    setMaximumAmount(orderTotal)
                } else {
                    setErrors({ general: result.data?.message || "Failed to reset payment agreement" })
                }
            } catch (error) {
                setErrors({ general: "Network error occurred while resetting payment agreement" })
            } finally {
                setIsSubmitting(false)
            }
        }

        // Payment status polling with auto-redirect (5-second interval)
        const startPaymentStatusPolling = orderId => {
            const interval = setInterval(async () => {
                try {
                    const response = await fetch(settings.ajax_url || "/wp-admin/admin-ajax.php", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded",
                        },
                        body: new URLSearchParams({
                            action: "get_payto_agreement_payment_initiation_status",
                            nonce: settings.nonce,
                            order_id: orderId,
                        }),
                    })

                    const result = await response.json()

                    if (result.success) {
                        const agreementStatus = result.data.agreement_status || result.data.mandate_status
                        const paymentStatus = result.data.payment_initiation_status
                        const orderStatus = result.data.order_status
                        const errorDetails = result.data.error_details

                        console.log("PayTo polling status:", {
                            agreementStatus,
                            paymentStatus,
                            orderStatus,
                            errorDetails,
                        })

                        // Check for agreement limit errors first
                        if (errorDetails && errorDetails.requires_new_agreement) {
                            clearInterval(interval)
                            setPollInterval(null)
                            setPaymentStatus("agreement_limit_exceeded")

                            const limitErrors = errorDetails.errors || []
                            const errorMessages = limitErrors.map(err => err.message).join(". ")

                            setErrors({ general: errorMessages })
                            setErrorDetails({
                                message: errorMessages,
                                code: "AGREEMENT_LIMIT_EXCEEDED",
                                type: "AGREEMENT_LIMIT_ERROR",
                                context: "payment_initiation",
                                agreement_uid: errorDetails.agreement_uid,
                                requires_new_agreement: true,
                                limit_errors: limitErrors,
                            })
                            return
                        }

                        // Update payment instructions with current status
                        if (paymentInstructions) {
                            setPaymentInstructions(prev => ({
                                ...prev,
                                status: agreementStatus || paymentStatus || "Processing",
                                agreement_status: agreementStatus,
                                payment_initiation_status: paymentStatus,
                                order_status: orderStatus,
                                order_key: result.data.order_key || prev.order_key,
                                order_received_url: result.data.order_received_url || prev.order_received_url,
                            }))
                        }

                        // Check for completed payment
                        if (
                            paymentStatus === "completed" ||
                            paymentStatus === "success" ||
                            orderStatus === "completed" ||
                            orderStatus === "processing"
                        ) {
                            clearInterval(interval)
                            setPollInterval(null)
                            setPaymentStatus("success")

                            // Auto-redirect to order received page (like PayID implementation)
                            setTimeout(() => {
                                const orderReceivedUrl =
                                    result.data.order_received_url || paymentInstructions?.order_received_url
                                if (orderReceivedUrl) {
                                    window.location.href = orderReceivedUrl
                                }
                            }, 2000) // 2 second delay to show success message
                        } else if (
                            paymentStatus === "failed" ||
                            paymentStatus === "cancelled" ||
                            agreementStatus === "failed" ||
                            agreementStatus === "cancelled" ||
                            agreementStatus === "rejected"
                        ) {
                            clearInterval(interval)
                            setPollInterval(null)

                            // Check if this is an agreement limit error
                            if (
                                errorDetails?.code === "payto_agreement_limit_exceeded" ||
                                errorDetails?.type === "AGREEMENT_LIMIT_ERROR" ||
                                result.data.error_code === "payto_agreement_limit_exceeded"
                            ) {
                                setPaymentStatus("agreement_limit_exceeded")
                                setErrors({
                                    general:
                                        errorDetails?.message ||
                                        result.data.message ||
                                        "Payment agreement limits have been exceeded",
                                })
                                setErrorDetails({
                                    message:
                                        errorDetails?.message ||
                                        result.data.message ||
                                        "Payment agreement limits have been exceeded",
                                    code: "AGREEMENT_LIMIT_EXCEEDED",
                                    type: "AGREEMENT_LIMIT_ERROR",
                                    context: "payment_polling",
                                    requires_new_agreement: true,
                                    limit_errors: result.data?.errors || errorDetails?.limit_errors || [],
                                    agreement_status: agreementStatus,
                                    payment_status: paymentStatus,
                                    order_status: orderStatus,
                                })
                            } else {
                                setPaymentStatus("failed")

                                // Set detailed error information based on status
                                let errorMessage = "Payment was cancelled or failed"
                                let errorCode = "UNKNOWN_ERROR"
                                let errorType = "PAYMENT_FAILED"

                                if (agreementStatus === "rejected") {
                                    errorMessage = "PayTo agreement was rejected by your bank"
                                    errorCode = "AGREEMENT_REJECTED"
                                    errorType = "AGREEMENT_ERROR"
                                } else if (agreementStatus === "cancelled") {
                                    errorMessage = "PayTo agreement was cancelled"
                                    errorCode = "AGREEMENT_CANCELLED"
                                    errorType = "AGREEMENT_ERROR"
                                } else if (agreementStatus === "failed") {
                                    errorMessage = "PayTo agreement creation failed"
                                    errorCode = "AGREEMENT_FAILED"
                                    errorType = "AGREEMENT_ERROR"
                                } else if (paymentStatus === "failed") {
                                    errorMessage = "Payment initiation failed"
                                    errorCode = "PAYMENT_FAILED"
                                    errorType = "PAYMENT_ERROR"
                                } else if (paymentStatus === "cancelled") {
                                    errorMessage = "Payment was cancelled"
                                    errorCode = "PAYMENT_CANCELLED"
                                    errorType = "PAYMENT_ERROR"
                                }

                                setErrors({ general: errorMessage })
                                setErrorDetails({
                                    message: errorMessage,
                                    code: errorCode,
                                    type: errorType,
                                    context: "payment_polling",
                                    agreement_status: agreementStatus,
                                    payment_status: paymentStatus,
                                    order_status: orderStatus,
                                })
                            }
                        }
                        // For "pending", "created", "authorized" or other statuses, continue polling
                    }
                } catch (error) {
                    console.error("Error checking payment status:", error)
                }
            }, 5000) // Poll every 5 seconds

            setPollInterval(interval)
        }

        // Render payment instructions
        const renderPaymentInstructions = () => {
            if (!paymentInstructions) return null

            return (
                <div className="monoova-payto-instructions-wrapper monoova-payid-bank-transfer-instructions-wrapper">
                    {/* Payment Amount */}
                    <div
                        style={{
                            textAlign: "center",
                            marginTop: "24px",
                        }}>
                        <div className="monoova-scan-pay">
                            <div
                                className="pay-label"
                                style={{
                                    fontSize: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_size || '28px',
                                    fontWeight: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_weight || '700',
                                    color: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.color || '#000000',
                                }}
                            >
                                Pay
                            </div>
                            <div
                                class="amount"
                                style={{
                                    fontSize: settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_size || '28px',
                                    fontWeight: settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_weight || '700',
                                    color: settings.payto_checkout_ui_styles?.scan_pay?.amount?.color || '#2CB5C5',
                                }}
                            >
                                <span class="woocommerce-Price-amount amount">
                                    <bdi>
                                        <span class="woocommerce-Price-currencySymbol">$</span>
                                        {paymentInstructions.amount}
                                    </bdi>
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Payment Method Selection */}
                    <div>
                        <div className="monoova-instruction-method-selection">
                            <div style={{ fontWeight: 600 }}>
                                Pay with
                            </div>
                            
                            <div style={{ display: "flex", gap: "16px", alignItems: "center" }}>
                                <label
                                    style={{
                                        display: "flex",
                                        alignItems: "center",
                                        cursor: "pointer",
                                        gap: "8px",
                                    }}>
                                    <div style={{ position: "relative" }}>
                                        <div
                                            style={{
                                                width: "24px",
                                                height: "24px",
                                                border: `1.5px solid ${
                                                    paymentMethod === "payid" ? "#2CB5C5" : "#ABABAB"
                                                }`,
                                                borderRadius: "50%",
                                                position: "relative",
                                            }}>
                                            {paymentMethod === "payid" && (
                                                <div
                                                    style={{
                                                        width: "14px",
                                                        height: "14px",
                                                        backgroundColor: "#2CB5C5",
                                                        borderRadius: "50%",
                                                        position: "absolute",
                                                        top: "50%",
                                                        left: "50%",
                                                        transform: "translate(-50%, -50%)",
                                                    }}></div>
                                            )}
                                        </div>
                                    </div>
                                    <span
                                        style={{
                                            fontWeight: "400",
                                            fontSize: "16px",
                                            lineHeight: "1.5em",
                                            color: "#333",
                                        }}>
                                        PayID
                                    </span>
                                </label>
                                <label
                                    style={{
                                        display: "flex",
                                        alignItems: "center",
                                        cursor: "pointer",
                                        gap: "8px",
                                    }}>
                                    <div style={{ position: "relative" }}>
                                        <div
                                            style={{
                                                width: "24px",
                                                height: "24px",
                                                border: `1.5px solid ${
                                                    paymentMethod === "bsb_account" ? "#2CB5C5" : "#ABABAB"
                                                }`,
                                                borderRadius: "50%",
                                                position: "relative",
                                            }}>
                                            {paymentMethod === "bsb_account" && (
                                                <div
                                                    style={{
                                                        width: "14px",
                                                        height: "14px",
                                                        backgroundColor: "#2CB5C5",
                                                        borderRadius: "50%",
                                                        position: "absolute",
                                                        top: "50%",
                                                        left: "50%",
                                                        transform: "translate(-50%, -50%)",
                                                    }}></div>
                                            )}
                                        </div>
                                    </div>
                                    <span
                                        style={{
                                            fontWeight: "400",
                                            fontSize: "16px",
                                            lineHeight: "1.5em",
                                            color: "#333",
                                        }}>
                                        BSB and account number
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>

                    {/* Form Fields Container */}
                    <div
                        style={{
                            border: "1px solid #E8E8E8",
                            borderRadius: "16px",
                            padding: "24px 24px 32px",
                            gap: "40px",
                            display: "flex",
                            flexDirection: "column",
                            alignItems: "center",
                        }}>
                        {/* PayID Fields */}
                        {paymentMethod === "payid" && (
                            <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                <div style={{ display: "flex", alignItems: "center" }}>
                                    <label
                                        style={{
                                            fontWeight: "600",
                                            fontSize: "16px",
                                            lineHeight: "1.5em",
                                            color: "#000000",
                                        }}>
                                        Enter your PayID
                                    </label>
                                </div>
                                <TextControl
                                    value={payidValue || ""}
                                    readOnly
                                    placeholder="0412 345 678"
                                />
                            </div>
                        )}

                        {/* BSB and Account Fields */}
                        {paymentMethod === "bsb_account" && (
                            <>
                                {/* Account Name Field */}
                                <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                    <div style={{ display: "flex", alignItems: "center" }}>
                                        <label
                                            style={{
                                                fontWeight: "600",
                                                fontSize: "16px",
                                                lineHeight: "1.5em",
                                                color: "#000000",
                                                display: "block",
                                                marginBottom: "4px",
                                            }}>
                                            Name associated with bank account
                                        </label>
                                    </div>
                                    <TextControl
                                        value={accountName || ""}
                                        readOnly
                                        placeholder="Enter your name"
                                    />
                                </div>

                                {/* BSB Field */}
                                <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                    <div style={{ display: "flex", alignItems: "center" }}>
                                        <label
                                            style={{
                                                fontWeight: "500",
                                                fontSize: "12px",
                                                lineHeight: "1.5em",
                                                color: "#666",
                                                display: "block",
                                                marginBottom: "4px",
                                            }}>
                                            BSB
                                        </label>
                                    </div>
                                    <TextControl
                                        value={bsb || ""}
                                        readOnly
                                        placeholder="123-456"
                                    />
                                </div>

                                {/* Account Number Field */}
                                <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                    <div style={{ display: "flex", alignItems: "center" }}>
                                        <label
                                            style={{
                                                fontWeight: "500",
                                                fontSize: "12px",
                                                lineHeight: "1.5em",
                                                color: "#666",
                                                display: "block",
                                                marginBottom: "4px",
                                            }}>
                                            Account Number
                                        </label>
                                    </div>
                                    <TextControl
                                        value={accountNumber || ""}
                                        readOnly
                                        placeholder="Enter your account number"
                                    />
                                </div>
                            </>
                        )}

                        {/* Maximum Amount Field */}
                        <div style={{ width: "100%", display: "flex", flexDirection: "column", gap: "12px" }}>
                            <div style={{ display: "flex", flexDirection: "column" }}>
                                <div style={{ display: "flex", alignItems: "center" }}>
                                    <label
                                        style={{
                                            fontWeight: "600",
                                            fontSize: "12px",
                                            lineHeight: "1.5em",
                                            color: "#666",
                                            display: "block",
                                            marginBottom: "4px",
                                        }}>
                                        Maximum payment agreement amount
                                    </label>
                                </div>
                                {/* <TextControl
                                    value={`$${maximumAmount || 1000}`}
                                    readOnly
                                /> */}
                                <div className="text-control-with-prefix">
                                    <span className="text-control-prefix">$</span>
                                    <TextControl
                                        value={`${parseFloat(maximumAmount) || 1000}`}
                                        readOnly
                                    />
                                </div>
                            </div>
                            <div
                                style={{
                                    fontWeight: "400",
                                    fontSize: "14px",
                                    lineHeight: "1.5",
                                    color: "#555",
                                }}>
                                This is the maximum amount that can be charged under this PayTo agreement. You can
                                modify this amount if needed.
                            </div>
                        </div>

                        {/* Accept and Continue Button (Disabled) */}
                        <Button
                            style={{
                                width: "100%",
                                display: "flex",
                                justifyContent: "center",
                                alignItems: "center",
                                gap: "10px",
                                backgroundColor: settings.payto_checkout_ui_styles?.submit_button?.background || "#2ab5c4",
                                color: settings.payto_checkout_ui_styles?.submit_button?.text_color || "#000000",
                                border: `1px solid ${settings.payto_checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}`,
                                borderRadius: `${settings.payto_checkout_ui_styles?.submit_button?.border_radius || 10}px`,
                                fontSize: settings.payto_checkout_ui_styles?.submit_button?.font_size || "17px",
                                fontWeight: settings.payto_checkout_ui_styles?.submit_button?.font_weight || "bold",
                                fontFamily: settings.payto_checkout_ui_styles?.submit_button?.font_family || "Helvetica, Arial, sans-serif",
                                minHeight: "48px",
                                opacity: 0.6,
                                cursor: "not-allowed",
                            }}>
                                Accept and Continue
                        </Button>

                        {/* Payment Status Section */}
                        <div style={{ width: "100%" }}>
                            <h4
                                style={{
                                    fontSize: "18px",
                                    fontWeight: "600",
                                    color: "#333",
                                    marginBottom: "16px",
                                }}>
                                Authorise recurring payments
                            </h4>

                            {/* Current payment status from polling */}
                            {(() => {
                                const currentStatus = paymentInstructions?.status?.toLowerCase() || "pending"
                                const agreementStatus =
                                    paymentInstructions?.agreement_status?.toLowerCase() || "pending"
                                const paymentStatus =
                                    paymentInstructions?.payment_initiation_status?.toLowerCase() || "pending"

                                // 3. Done State - Payment completed/successful (final state)
                                if (
                                    paymentStatus === "completed" ||
                                    paymentStatus === "success" ||
                                    paymentStatus === "processed"
                                ) {
                                    return (
                                        <div>
                                            {/* Payment received - completed */}
                                            <div style={{ marginBottom: "16px" }}>
                                                <div
                                                    style={{
                                                        display: "flex",
                                                        alignItems: "center",
                                                        gap: "12px",
                                                        borderRadius: "8px",
                                                        marginBottom: "8px",
                                                    }}>
                                                    <div
                                                        style={{
                                                            width: "20px",
                                                            height: "20px",
                                                            borderRadius: "50%",
                                                            backgroundColor: "#2CB5C5",
                                                            display: "flex",
                                                            alignItems: "center",
                                                            justifyContent: "center",
                                                            flexShrink: 0,
                                                        }}>
                                                        <svg
                                                            width="12"
                                                            height="9"
                                                            viewBox="0 0 12 9"
                                                            fill="none"
                                                            xmlns="http://www.w3.org/2000/svg">
                                                            <path
                                                                d="M1 4.5L4.5 8L11 1.5"
                                                                stroke="#ffffff"
                                                                strokeWidth="2"
                                                                strokeLinecap="round"
                                                                strokeLinejoin="round"
                                                            />
                                                        </svg>
                                                    </div>
                                                    <span
                                                        style={{
                                                            fontSize: "16px",
                                                            color: "#484848",
                                                            fontWeight: "400",
                                                        }}>
                                                        Payment received
                                                    </span>
                                                </div>
                                            </div>

                                            {/* Redirect notification */}
                                            <div
                                                style={{
                                                    display: "flex",
                                                    alignItems: "flex-start",
                                                    gap: "12px",
                                                    padding: "14px 10px",
                                                    backgroundColor: "#E6F7FF",
                                                    borderRadius: "8px",
                                                    marginBottom: "16px",
                                                }}>
                                                <div
                                                    style={{
                                                        width: "20px",
                                                        height: "20px",
                                                        borderRadius: "50%",
                                                        backgroundColor: "#1890FF",
                                                        display: "flex",
                                                        alignItems: "center",
                                                        justifyContent: "center",
                                                        flexShrink: 0,
                                                        marginTop: "1px",
                                                    }}>
                                                    <span
                                                        style={{
                                                            color: "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: "bold",
                                                        }}>
                                                        i
                                                    </span>
                                                </div>
                                                <p
                                                    style={{
                                                        margin: 0,
                                                        fontSize: "14px",
                                                        color: "#1890FF",
                                                        lineHeight: "1.5",
                                                        fontWeight: "500",
                                                    }}>
                                                    We will redirect you to Order Received page in{" "}
                                                    <strong>5 sec</strong>
                                                </p>
                                            </div>
                                        </div>
                                    )
                                }

                                // 2. Initiation State - Agreement authorized, payment being initiated
                                if (
                                    (agreementStatus === "authorized" || agreementStatus === "approved") &&
                                    (paymentStatus === "initiated" || paymentStatus === "processing")
                                ) {
                                    return (
                                        <div>
                                            {/* Initiate payment - active/loading */}
                                            <div style={{ marginBottom: "16px" }}>
                                                <div
                                                    style={{
                                                        display: "flex",
                                                        alignItems: "center",
                                                        gap: "12px",
                                                        borderRadius: "8px",
                                                        marginBottom: "8px",
                                                    }}>
                                                    <div
                                                        style={{
                                                            width: "20px",
                                                            height: "20px",
                                                            border: "2px solid #ffffff",
                                                            borderTop: "2px solid #2CB5C5",
                                                            borderRadius: "50%",
                                                            animation: "spin 1s linear infinite",
                                                            flexShrink: 0,
                                                        }}></div>
                                                    <span
                                                        style={{
                                                            fontSize: "16px",
                                                            color: "#484848",
                                                            fontWeight: "400",
                                                        }}>
                                                        Initiate payment
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    )
                                }

                                // 4. Failed State - Payment failed/cancelled/rejected
                                if (
                                    paymentStatus === "failed" ||
                                    paymentStatus === "cancelled" ||
                                    paymentStatus === "rejected" ||
                                    agreementStatus === "failed" ||
                                    agreementStatus === "cancelled" ||
                                    agreementStatus === "rejected"
                                ) {
                                    return (
                                        <div>
                                            {/* Payment failed - error state */}
                                            <div style={{ marginBottom: "16px", textAlign: "center" }}>
                                                <div
                                                    style={{
                                                        width: "22px",
                                                        height: "22px",
                                                        borderRadius: "50%",
                                                        backgroundColor: "#FF0000",
                                                        display: "flex",
                                                        alignItems: "center",
                                                        justifyContent: "center",
                                                        margin: "0 auto 16px",
                                                    }}>
                                                    <span
                                                        style={{
                                                            color: "#ffffff",
                                                            fontSize: "14px",
                                                            fontWeight: "bold",
                                                        }}>
                                                        i
                                                    </span>
                                                </div>
                                                <h3
                                                    style={{
                                                        fontSize: "24px",
                                                        fontWeight: "600",
                                                        color: "#484848",
                                                        margin: "0 0 8px 0",
                                                    }}>
                                                    Payment failed
                                                </h3>
                                                <p
                                                    style={{
                                                        fontSize: "16px",
                                                        color: "#484848",
                                                        margin: "0 0 24px 0",
                                                        lineHeight: "1.5",
                                                        fontWeight: "400",
                                                    }}>
                                                    {errors.general ||
                                                        errorDetails?.message ||
                                                        "Something went wrong with the payment."}
                                                    <br />
                                                    Please try again.
                                                </p>

                                                {/* Show detailed error information if available */}
                                                {errorDetails && (
                                                    <div
                                                        style={{
                                                            marginBottom: "24px",
                                                            padding: "16px",
                                                            backgroundColor: "#FFF2F2",
                                                            border: "1px solid #FFCDD2",
                                                            borderRadius: "8px",
                                                            textAlign: "left",
                                                            fontSize: "14px",
                                                        }}>
                                                        <h4
                                                            style={{
                                                                margin: "0 0 12px 0",
                                                                color: "#D32F2F",
                                                                fontSize: "14px",
                                                                fontWeight: "600",
                                                            }}>
                                                            Error Details
                                                        </h4>

                                                        {errorDetails.code && (
                                                            <div style={{ marginBottom: "8px" }}>
                                                                <strong style={{ color: "#D32F2F" }}>
                                                                    Error Code:
                                                                </strong>{" "}
                                                                <span style={{ color: "#666" }}>
                                                                    {errorDetails.code}
                                                                </span>
                                                            </div>
                                                        )}

                                                        {errorDetails.type && (
                                                            <div style={{ marginBottom: "8px" }}>
                                                                <strong style={{ color: "#D32F2F" }}>
                                                                    Error Type:
                                                                </strong>{" "}
                                                                <span style={{ color: "#666" }}>
                                                                    {errorDetails.type}
                                                                </span>
                                                            </div>
                                                        )}

                                                        {errorDetails.context && (
                                                            <div style={{ marginBottom: "8px" }}>
                                                                <strong style={{ color: "#D32F2F" }}>Context:</strong>{" "}
                                                                <span style={{ color: "#666" }}>
                                                                    {errorDetails.context}
                                                                </span>
                                                            </div>
                                                        )}

                                                        {errorDetails.validation_errors && (
                                                            <div style={{ marginTop: "12px", fontSize: "13px" }}>
                                                                <strong style={{ color: "#D32F2F" }}>
                                                                    Validation Errors:
                                                                </strong>
                                                                <div style={{ marginTop: "4px", color: "#666" }}>
                                                                    {typeof errorDetails.validation_errors === "object"
                                                                        ? Object.entries(
                                                                              errorDetails.validation_errors
                                                                          ).map(([field, error], index) => (
                                                                              <div
                                                                                  key={index}
                                                                                  style={{ marginBottom: "4px" }}>
                                                                                  <strong>{field}:</strong> {error}
                                                                              </div>
                                                                          ))
                                                                        : errorDetails.validation_errors}
                                                                </div>
                                                            </div>
                                                        )}
                                                    </div>
                                                )}
                                                <button
                                                    onClick={() => {
                                                        setPaymentStatus("form")
                                                        setErrors({})
                                                        setErrorDetails(null)
                                                        setPaymentInstructions(null)
                                                    }}
                                                    style={{
                                                        width: "100%",
                                                        padding: "12px 20px",
                                                        backgroundColor: settings.payto_checkout_ui_styles?.submit_button?.background || "#2ab5c4",
                                                        color: settings.payto_checkout_ui_styles?.submit_button?.text_color || "#000000",
                                                        border: `1px solid ${settings.payto_checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}`,
                                                        borderRadius: `${settings.payto_checkout_ui_styles?.submit_button?.border_radius || 10}px`,
                                                        cursor: "pointer",
                                                        fontSize: settings.payto_checkout_ui_styles?.submit_button?.font_size || "17px",
                                                        fontWeight: settings.payto_checkout_ui_styles?.submit_button?.font_weight || "bold",
                                                        fontFamily: settings.payto_checkout_ui_styles?.submit_button?.font_family || "Helvetica, Arial, sans-serif",
                                                        lineHeight: "1.21em",
                                                    }}>
                                                    Try Again
                                                </button>
                                            </div>
                                        </div>
                                    )
                                }

                                // 1. Waiting State - Default state waiting for agreement approval
                                return (
                                    <div>
                                        {/* PayTo approval - active/waiting */}
                                        <div style={{ marginBottom: "16px" }}>
                                            <div
                                                style={{
                                                    display: "flex",
                                                    alignItems: "flex-start",
                                                    gap: "12px",
                                                    padding: "14px 10px",
                                                    backgroundColor: "#EDFDFF",
                                                    borderRadius: "8px",
                                                    marginBottom: "8px",
                                                }}>
                                                <div
                                                    style={{
                                                        width: "20px",
                                                        height: "20px",
                                                        borderRadius: "50%",
                                                        backgroundColor: "#007A89",
                                                        display: "flex",
                                                        alignItems: "center",
                                                        justifyContent: "center",
                                                        flexShrink: 0,
                                                        marginTop: "1px",
                                                    }}>
                                                    <span
                                                        style={{
                                                            color: "#ffffff",
                                                            fontSize: "12px",
                                                            fontWeight: "bold",
                                                        }}>
                                                        !
                                                    </span>
                                                </div>
                                                <p
                                                    style={{
                                                        margin: 0,
                                                        fontSize: "14px",
                                                        color: "#007A89",
                                                        lineHeight: "1.5",
                                                        fontWeight: "500",
                                                    }}>
                                                    Approve your recurring payment in your online banking or banking app
                                                </p>
                                            </div>
                                            <div
                                                style={{
                                                    display: "flex",
                                                    alignItems: "center",
                                                    gap: "12px",
                                                    borderRadius: "8px",
                                                }}>
                                                <div
                                                    style={{
                                                        width: "20px",
                                                        height: "20px",
                                                        border: "2px solid #ffffff",
                                                        borderTop: "2px solid #007A89",
                                                        borderRadius: "50%",
                                                        animation: "spin 1s linear infinite",
                                                        flexShrink: 0,
                                                    }}></div>
                                                <span
                                                    style={{
                                                        fontSize: "16px",
                                                        color: "#000000",
                                                        fontWeight: "500",
                                                    }}>
                                                    Awaiting your approval
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                )
                            })()}
                        </div>
                    </div>
                </div>
            )
        }

        // Show different views based on payment status
        if (paymentStatus === "instructions") {
            return renderPaymentInstructions()
        }

        // Show agreement limit exceeded state with Pay Again option
        if (paymentStatus === "agreement_limit_exceeded") {
            return (
                <>
                    {/* PayTo Section Container */}
                    <div>
                        {/* Payment Amount */}
                        <div
                            style={{
                                textAlign: "center",
                                margin: "24px 0",
                            }}>
                            <div
                                style={{
                                    fontSize: "24px",
                                    fontWeight: "700",
                                    color: "#000",
                                    marginBottom: "8px",
                                }}>
                                Payment Agreement Limit Exceeded
                            </div>
                            <p
                                style={{
                                    fontSize: "14px",
                                    color: "#6b7280",
                                    margin: "0",
                                }}>
                                Your current payment agreement has reached its limits.
                            </p>
                        </div>

                        {/* Error Message */}
                        <div
                            style={{
                                backgroundColor: "#FFF2F2",
                                border: "1px solid #FFCDD2",
                                borderRadius: "8px",
                                padding: "16px",
                                marginBottom: "24px",
                            }}>
                            <div
                                style={{
                                    display: "flex",
                                    alignItems: "center",
                                    marginBottom: "12px",
                                }}>
                                <span
                                    style={{
                                        fontSize: "18px",
                                        marginRight: "8px",
                                    }}>
                                    ⚠️
                                </span>
                                <h3
                                    style={{
                                        fontSize: "16px",
                                        fontWeight: "600",
                                        color: "#D32F2F",
                                        margin: "0",
                                    }}>
                                    Agreement Limits Exceeded
                                </h3>
                            </div>
                            <p
                                style={{
                                    fontSize: "16px",
                                    color: "#000000",
                                    margin: "0 0 16px 0",
                                    lineHeight: "1.5",
                                }}>
                                {errors.general ||
                                    errorDetails?.message ||
                                    "Payment agreement limits have been exceeded."}
                            </p>

                            {/* Show specific limit errors */}
                            {errorDetails?.limit_errors && errorDetails.limit_errors.length > 0 && (
                                <div style={{ marginBottom: "16px" }}>
                                    <strong style={{ color: "#D32F2F", fontSize: "14px" }}>Specific Issues:</strong>
                                    <ul style={{ margin: "8px 0 0 20px", color: "#666", fontSize: "14px" }}>
                                        {errorDetails.limit_errors.map((error, index) => (
                                            <li key={index} style={{ marginBottom: "4px" }}>
                                                {error.message}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            <p style={{ fontSize: "14px", color: "#666", margin: "0" }}>
                                To continue with your payment, you'll need to create a new payment agreement with
                                updated limits.
                            </p>
                        </div>

                        {/* Try Again Button */}
                        <button
                            onClick={resetPaymentAgreement}
                            disabled={isSubmitting}
                            style={{
                                width: "100%",
                                padding: "12px 20px",
                                backgroundColor: isSubmitting ? "#ccc" : (settings.payto_checkout_ui_styles?.submit_button?.background || "#2ab5c4"),
                                color: settings.payto_checkout_ui_styles?.submit_button?.text_color || "#000000",
                                border: `1px solid ${settings.payto_checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}`,
                                borderRadius: `${settings.payto_checkout_ui_styles?.submit_button?.border_radius || 10}px`,
                                fontSize: settings.payto_checkout_ui_styles?.submit_button?.font_size || "17px",
                                fontWeight: settings.payto_checkout_ui_styles?.submit_button?.font_weight || "bold",
                                fontFamily: settings.payto_checkout_ui_styles?.submit_button?.font_family || "Helvetica, Arial, sans-serif",
                                cursor: isSubmitting ? "not-allowed" : "pointer",
                                transition: "background-color 0.2s",
                                marginBottom: "16px",
                            }}
                            onMouseOver={e => {
                                if (!isSubmitting) {
                                    e.target.style.backgroundColor = "#1a9aa8"
                                }
                            }}
                            onMouseOut={e => {
                                if (!isSubmitting) {
                                    e.target.style.backgroundColor = "#2CB5C5"
                                }
                            }}>
                            {isSubmitting ? (
                                <>
                                    <span
                                        style={{
                                            display: "inline-block",
                                            width: "16px",
                                            height: "16px",
                                            border: "2px solid #ffffff",
                                            borderTop: "2px solid transparent",
                                            borderRadius: "50%",
                                            animation: "spin 1s linear infinite",
                                            marginRight: "8px",
                                        }}></span>
                                    Resetting Agreement...
                                </>
                            ) : (
                                "Try Again with New Agreement"
                            )}
                        </button>
                    </div>
                </>
            )
        }

        // Show failure state in main form
        if (paymentStatus === "failed") {
            return (
                <>
                    {/* PayTo Section Container */}
                    <div>
                        {/* Payment Amount */}
                        <div
                            style={{
                                textAlign: "center",
                                margin: "24px 0",
                            }}>
                            <div className="monoova-scan-pay">
                                <div
                                    className="pay-label"
                                    style={{
                                        fontSize: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_size || '28px',
                                        fontWeight: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_weight || '700',
                                        color: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.color || '#000000',
                                    }}
                                >
                                    Pay
                                </div>
                                <div
                                    class="amount"
                                    style={{
                                        fontSize: settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_size || '28px',
                                        fontWeight: settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_weight || '700',
                                        color: settings.payto_checkout_ui_styles?.scan_pay?.amount?.color || '#2CB5C5',
                                    }}
                                >
                                    <span class="woocommerce-Price-amount amount">
                                        <bdi>
                                            <span class="woocommerce-Price-currencySymbol">$</span>
                                            {orderTotal.toFixed(2)}
                                        </bdi>
                                    </span>
                                </div>
                            </div>
                        </div>

                        {/* Failure State */}
                        <div
                            style={{
                                border: "1px solid #E8E8E8",
                                borderRadius: "16px",
                                padding: "24px 24px 32px",
                                gap: "16px",
                                display: "flex",
                                flexDirection: "column",
                                alignItems: "center",
                                textAlign: "center",
                            }}>
                            <div
                                style={{
                                    width: "45px",
                                    height: "45px",
                                    borderRadius: "50%",
                                    backgroundColor: "#FF0000",
                                    display: "flex",
                                    alignItems: "center",
                                    justifyContent: "center",
                                    margin: "0 auto 16px",
                                }}>
                                <span
                                    style={{
                                        color: "#ffffff",
                                        fontSize: "24px",
                                        fontWeight: "bold",
                                    }}>
                                    i
                                </span>
                            </div>
                            <h3
                                style={{
                                    fontSize: "24px",
                                    fontWeight: "600",
                                    color: "#000000",
                                    margin: "0 0 8px 0",
                                }}>
                                Payment failed
                            </h3>
                            <p
                                style={{
                                    fontSize: "16px",
                                    color: "#000000",
                                    margin: "0 0 24px 0",
                                    lineHeight: "1.5",
                                }}>
                                {errors.general || errorDetails?.message || "Something went wrong with the payment."}
                            </p>

                            {/* Show detailed error information if available */}
                            {errorDetails && (
                                <div
                                    style={{
                                        marginBottom: "24px",
                                        padding: "16px",
                                        backgroundColor: "#FFF2F2",
                                        border: "1px solid #FFCDD2",
                                        borderRadius: "8px",
                                        textAlign: "left",
                                        fontSize: "14px",
                                    }}>
                                    <h4
                                        style={{
                                            margin: "0 0 12px 0",
                                            color: "#D32F2F",
                                            fontSize: "14px",
                                            fontWeight: "600",
                                        }}>
                                        Error Details
                                    </h4>

                                    {errorDetails.code && (
                                        <div style={{ marginBottom: "8px" }}>
                                            <strong style={{ color: "#D32F2F" }}>Error Code:</strong>{" "}
                                            <span style={{ color: "#666" }}>{errorDetails.code}</span>
                                        </div>
                                    )}

                                    {errorDetails.type && (
                                        <div style={{ marginBottom: "8px" }}>
                                            <strong style={{ color: "#D32F2F" }}>Error Type:</strong>{" "}
                                            <span style={{ color: "#666" }}>{errorDetails.type}</span>
                                        </div>
                                    )}

                                    {errorDetails.context && (
                                        <div style={{ marginBottom: "8px" }}>
                                            <strong style={{ color: "#D32F2F" }}>Context:</strong>{" "}
                                            <span style={{ color: "#666" }}>{errorDetails.context}</span>
                                        </div>
                                    )}

                                    {(errorDetails.agreement_status ||
                                        errorDetails.payment_status ||
                                        errorDetails.order_status) && (
                                        <div style={{ marginBottom: "8px" }}>
                                            <strong style={{ color: "#D32F2F" }}>Status:</strong>{" "}
                                            <span style={{ color: "#666" }}>
                                                {[
                                                    errorDetails.agreement_status,
                                                    errorDetails.payment_status,
                                                    errorDetails.order_status,
                                                ]
                                                    .filter(Boolean)
                                                    .join(", ")}
                                            </span>
                                        </div>
                                    )}

                                    {errorDetails.details && (
                                        <div style={{ marginTop: "12px", fontSize: "13px" }}>
                                            <strong style={{ color: "#D32F2F" }}>Technical Details:</strong>
                                            <div
                                                style={{
                                                    marginTop: "4px",
                                                    padding: "8px",
                                                    backgroundColor: "#FFFFFF",
                                                    border: "1px solid #E0E0E0",
                                                    borderRadius: "4px",
                                                    color: "#666",
                                                    fontFamily: "monospace",
                                                    fontSize: "12px",
                                                    wordBreak: "break-word",
                                                }}>
                                                {typeof errorDetails.details === "string"
                                                    ? errorDetails.details
                                                    : JSON.stringify(errorDetails.details, null, 2)}
                                            </div>
                                        </div>
                                    )}

                                    {errorDetails.validation_errors && (
                                        <div style={{ marginTop: "12px", fontSize: "13px" }}>
                                            <strong style={{ color: "#D32F2F" }}>Validation Errors:</strong>
                                            <div style={{ marginTop: "4px", color: "#666" }}>
                                                {typeof errorDetails.validation_errors === "object"
                                                    ? Object.entries(errorDetails.validation_errors).map(
                                                          ([field, error], index) => (
                                                              <div key={index} style={{ marginBottom: "4px" }}>
                                                                  <strong>{field}:</strong> {error}
                                                              </div>
                                                          )
                                                      )
                                                    : errorDetails.validation_errors}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                            <button
                                onClick={() => {
                                    setPaymentStatus("form")
                                    setErrors({})
                                    setErrorDetails(null)
                                    setPaymentInstructions(null)
                                }}
                                style={{
                                    width: "100%",
                                    padding: "12px 20px",
                                    backgroundColor: settings.payto_checkout_ui_styles?.submit_button?.background || "#2ab5c4",
                                    color: settings.payto_checkout_ui_styles?.submit_button?.text_color || "#000000",
                                    border: `1px solid ${settings.payto_checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}`,
                                    borderRadius: `${settings.payto_checkout_ui_styles?.submit_button?.border_radius || 10}px`,
                                    cursor: "pointer",
                                    fontSize: settings.payto_checkout_ui_styles?.submit_button?.font_size || "17px",
                                    fontWeight: settings.payto_checkout_ui_styles?.submit_button?.font_weight || "bold",
                                    fontFamily: settings.payto_checkout_ui_styles?.submit_button?.font_family || "Helvetica, Arial, sans-serif",
                                    lineHeight: "1.21em",
                                }}>
                                Try Again
                            </button>
                        </div>
                    </div>
                </>
            )
        }

        return (
            <>
                {/* PayTo Section Container */}
                <div className="monoova-payto-instructions-wrapper monoova-payid-bank-transfer-instructions-wrapper" ref={containerRef}>
                    {/* Payment Amount */}
                    <div
                        style={{
                            textAlign: "center",
                            marginTop: "24px",
                        }}>
                        <div className="monoova-scan-pay">
                            <div
                                className="pay-label"
                                style={{
                                    fontSize: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_size || '28px',
                                    fontWeight: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_weight || '700',
                                    color: settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.color || '#000000',
                                }}
                            >
                                Pay
                            </div>
                            <div
                                class="amount"
                                style={{
                                    fontSize: settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_size || '28px',
                                    fontWeight: settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_weight || '700',
                                    color: settings.payto_checkout_ui_styles?.scan_pay?.amount?.color || '#2CB5C5',
                                }}
                            >
                                <span class="woocommerce-Price-amount amount">
                                    <bdi>
                                        <span class="woocommerce-Price-currencySymbol">$</span>
                                        {orderTotal.toFixed(2)}
                                    </bdi>
                                </span>
                            </div>
                        </div>
                    </div>

                    {/* Guest info validation message */}
                    {!hasRequiredGuestInfo() && (
                        <div style={{
                            padding: "16px",
                            backgroundColor: "#f3f4f6",
                            borderRadius: "8px",
                            margin: "16px 0",
                            textAlign: "center"
                        }}>
                            <p style={{ margin: "0 0 8px 0", fontSize: "14px", color: "#374151" }}>
                                Please complete your billing information to initialize the payment form.
                            </p>
                            <small style={{ fontSize: "12px", color: "#6b7280" }}>
                                Required: Email, Name, Address, City, Postcode, and Country
                            </small>
                        </div>
                    )}

                    {/* Express Checkout Button */}
                    {hasExpressCheckout && settings.is_user_logged_in && (
                        <div style={{ marginBottom: "20px", textAlign: "center" }}>
                            {/* Display mandate information if available */}
                            {mandateInfo && (
                                <div
                                    style={{
                                        marginBottom: "16px",
                                        padding: "16px",
                                        backgroundColor: "#f0f8ff",
                                        border: "1px solid #0073aa",
                                        borderRadius: "8px",
                                        textAlign: "left",
                                    }}>
                                    <h4 style={{ margin: "0 0 12px 0", color: "#0073aa", fontSize: "16px" }}>
                                        {__("Express Checkout Available", "monoova-payments-for-woocommerce")}
                                    </h4>
                                    <p style={{ margin: "0 0 12px 0", color: "#666", fontSize: "14px" }}>
                                        {__(
                                            "You have an active PayTo mandate that can be used for faster checkout.",
                                            "monoova-payments-for-woocommerce"
                                        )}
                                    </p>
                                    <div
                                        style={{
                                            backgroundColor: "#ffffff",
                                            padding: "12px",
                                            borderRadius: "4px",
                                            fontSize: "13px",
                                            color: "#333",
                                        }}>
                                        <div style={{ marginBottom: "6px" }}>
                                            <strong>{__("Agreement:", "monoova-payments-for-woocommerce")}</strong>{" "}
                                            {mandateInfo.agreement_id
                                                ? `...${mandateInfo.agreement_id.slice(-8)}`
                                                : "Available"}
                                        </div>
                                        <div style={{ marginBottom: "6px" }}>
                                            <strong>{__("Maximum Amount:", "monoova-payments-for-woocommerce")}</strong>{" "}
                                            {currency} ${parseFloat(mandateInfo.maximum_amount || 0).toFixed(2)}
                                        </div>
                                        <div style={{ marginBottom: "6px" }}>
                                            <strong>{__("Status:", "monoova-payments-for-woocommerce")}</strong>{" "}
                                            <span
                                                style={{
                                                    color: mandateInfo.status === "authorized" ? "#10b981" : "#f59e0b",
                                                    fontWeight: "500",
                                                }}>
                                                {mandateInfo.status || "Active"}
                                            </span>
                                        </div>
                                        {mandateInfo.num_of_transactions_permitted && (
                                            <div>
                                                <strong>
                                                    {__("Transactions:", "monoova-payments-for-woocommerce")}
                                                </strong>{" "}
                                                <span
                                                    style={{
                                                        color:
                                                            mandateInfo.remaining_transactions > 0
                                                                ? "#10b981"
                                                                : "#ef4444",
                                                        fontWeight: "500",
                                                    }}>
                                                    {mandateInfo.remaining_transactions || 0} remaining
                                                </span>{" "}
                                                <span style={{ color: "#6b7280", fontSize: "12px" }}>
                                                    ({mandateInfo.num_of_paid_transactions || 0}/
                                                    {mandateInfo.num_of_transactions_permitted || 10} used)
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={handleCheckoutWithExistingMandate}
                                disabled={isSubmitting}
                                style={{
                                    width: "100%",
                                    padding: "12px 20px",
                                    backgroundColor: isSubmitting ? "#9ca3af" : "#10b981",
                                    color: "#ffffff",
                                    border: "none",
                                    borderRadius: "8px",
                                    cursor: isSubmitting ? "not-allowed" : "pointer",
                                    fontSize: "14px",
                                    fontWeight: "600",
                                    marginBottom: "12px",
                                }}>
                                {isSubmitting
                                    ? __("Processing...", "monoova-payments-for-woocommerce")
                                    : __("Pay with existing PayTo mandate", "monoova-payments-for-woocommerce")}
                            </button>
                            <div
                                style={{
                                    fontSize: "12px",
                                    color: "#6b7280",
                                    marginBottom: "16px",
                                }}>
                                {__("or set up a new payment method below", "monoova-payments-for-woocommerce")}
                            </div>
                        </div>
                    )}

                    {
                        hasRequiredGuestInfo()
                        && <>
                            {/* Payment Method Selection */}
                            <div style={{ marginBottom: "24px" }}>
                                <div className="monoova-instruction-method-selection">
                                    <div style={{ fontWeight: 600 }}>
                                        Pay with
                                    </div>
                                    <RadioControl
                                        selected={paymentMethod}
                                        options={[
                                            { label: "PayID", value: 'payid' },
                                            { label: "BSB and account number", value: 'bsb_account' },
                                        ]}
                                        onChange={(v) => setPaymentMethod(v)}
                                    />
                                    
                                </div>
                            </div>

                            {/* Form Fields Container */}
                            <div
                                style={{
                                    border: "1px solid #E8E8E8",
                                    borderRadius: "16px",
                                    padding: "24px 24px 32px",
                                    gap: "16px",
                                    display: "flex",
                                    flexDirection: "column",
                                    alignItems: "center",
                                }}>
                                {/* PayID Fields */}
                                {paymentMethod === "payid" && (
                                    <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                        <div style={{ display: "flex", alignItems: "center" }}>
                                            <label
                                                style={{
                                                    fontWeight: "600",
                                                    fontSize: "16px",
                                                    lineHeight: "1.5em",
                                                    color: "#000000",
                                                }}>
                                                Enter your PayID
                                            </label>
                                        </div>
                                        <TextControl
                                            value={payidValue}
                                            onChange={value => {
                                                setPayidValue(value)
                                                validatePayID(value)
                                            }}
                                            placeholder="0412 345 678"
                                            className={errors.payidValue ? "has-error" : ""}
                                        />
                                        {errors.payidValue && (
                                            <div
                                                style={{
                                                    color: "#ef4444",
                                                    fontSize: "12px",
                                                    marginTop: "6px",
                                                    display: "flex",
                                                    alignItems: "center",
                                                    gap: "4px",
                                                }}>
                                                {errors.payidValue}
                                            </div>
                                        )}
                                    </div>
                                )}

                                {/* BSB and Account Fields */}
                                {paymentMethod === "bsb_account" && (
                                    <>
                                        {/* Account Name Field */}
                                        <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                            <div style={{ display: "flex", alignItems: "center" }}>
                                                <label
                                                    style={{
                                                        fontWeight: "600",
                                                        fontSize: "16px",
                                                        lineHeight: "1.5em",
                                                        color: "#000000"
                                                    }}>
                                                    Name associated with bank account
                                                </label>
                                            </div>
                                            <TextControl
                                                value={accountName}
                                                onChange={setAccountName}
                                                placeholder="Enter your name"
                                                className={errors.accountName ? "has-error" : ""}
                                            />
                                            {errors.accountName && (
                                                <div
                                                    style={{
                                                        color: "#ef4444",
                                                        fontSize: "12px",
                                                        marginTop: "6px",
                                                        display: "flex",
                                                        alignItems: "center",
                                                        gap: "4px",
                                                    }}>
                                                    {errors.accountName}
                                                </div>
                                            )}
                                        </div>

                                        {/* BSB Field */}
                                        <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                            <div style={{ display: "flex", alignItems: "center" }}>
                                                <label
                                                    style={{
                                                        fontWeight: "600",
                                                        fontSize: "16px",
                                                        lineHeight: "1.5em",
                                                        color: "#000000",
                                                    }}>
                                                    BSB
                                                </label>
                                            </div>
                                            <TextControl
                                                value={bsb}
                                                onChange={setBsb}
                                                placeholder="123-456"
                                                className={errors.bsb ? "has-error" : ""}
                                            />
                                            {errors.bsb && (
                                                <div
                                                    style={{
                                                        color: "#ef4444",
                                                        fontSize: "12px",
                                                        marginTop: "6px",
                                                        display: "flex",
                                                        alignItems: "center",
                                                        gap: "4px",
                                                    }}>
                                                    {errors.bsb}
                                                </div>
                                            )}
                                        </div>

                                        {/* Account Number Field */}
                                        <div style={{ width: "100%", display: "flex", flexDirection: "column" }}>
                                            <div style={{ display: "flex", alignItems: "center" }}>
                                                <label
                                                    style={{
                                                        fontWeight: "600",
                                                        fontSize: "16px",
                                                        lineHeight: "1.5em",
                                                        color: "#000000",
                                                    }}>
                                                    Account Number
                                                </label>
                                            </div>
                                            <TextControl
                                                value={accountNumber}
                                                onChange={setAccountNumber}
                                                placeholder="Enter your account number"
                                                className={errors.accountNumber ? "has-error" : ""}
                                            />
                                            {errors.accountNumber && (
                                                <div
                                                    style={{
                                                        color: "#ef4444",
                                                        fontSize: "12px",
                                                        marginTop: "6px",
                                                        display: "flex",
                                                        alignItems: "center",
                                                        gap: "4px",
                                                    }}>
                                                    {errors.accountNumber}
                                                </div>
                                            )}
                                        </div>
                                    </>
                                )}

                                {/* Maximum Amount Field */}
                                <div style={{ width: "100%", display: "flex", flexDirection: "column", gap: "12px" }}>
                                    <div style={{ display: "flex", flexDirection: "column" }}>
                                        <div style={{ display: "flex", alignItems: "center" }}>
                                            <label
                                                style={{
                                                    fontWeight: "600",
                                                    fontSize: "16px",
                                                    lineHeight: "1.5em",
                                                    color: "#000000",
                                                }}>
                                                Maximum payment agreement amount
                                            </label>
                                        </div>
                                        <div className="text-control-with-prefix">
                                            <span className="text-control-prefix">$</span>
                                            <TextControl
                                                type="number"
                                                value={maximumAmount}
                                                onChange={value => {
                                                    // Handle empty input
                                                    if (value === '' || value === null || value === undefined) {
                                                        setMaximumAmount('')
                                                        return
                                                    }

                                                    // Replace comma with dot for international decimal support
                                                    const normalizedValue = value.toString().replace(',', '.')

                                                    // Allow partial decimal inputs like "100." or ".5"
                                                    if (normalizedValue === '.' || normalizedValue.endsWith('.') || /^\d*\.?\d*$/.test(normalizedValue)) {
                                                        setMaximumAmount(normalizedValue)
                                                        return
                                                    }

                                                    // Parse valid numbers
                                                    const numericValue = parseFloat(normalizedValue)
                                                    if (!isNaN(numericValue)) {
                                                        setMaximumAmount(numericValue)
                                                    }
                                                }}
                                                min="0.01"
                                                step="0.01"
                                                placeholder="1000"
                                                className={errors.maximumAmount ? "has-error" : ""}
                                            />
                                        </div>
                                        {errors.maximumAmount && (
                                            <div
                                                style={{
                                                    color: "#ef4444",
                                                    fontSize: "12px",
                                                    marginTop: "6px",
                                                    display: "flex",
                                                    alignItems: "center",
                                                    gap: "4px",
                                                }}>
                                                {errors.maximumAmount}
                                            </div>
                                        )}
                                    </div>
                                    <div
                                        style={{
                                            fontWeight: "500",
                                            fontSize: "14px",
                                            lineHeight: "1.29em",
                                            color: "#909090",
                                        }}>
                                        This is the maximum amount that can be charged under this PayTo agreement. You can
                                        modify this amount if needed.
                                    </div>
                                </div>

                                {/* Accept and Continue Button */}
                                <Button
                                    style={{
                                        width: "100%",
                                        display: "flex",
                                        justifyContent: "center",
                                        alignItems: "center",
                                        gap: "10px",
                                        backgroundColor: isSubmitting ? "#ccc" : (settings.payto_checkout_ui_styles?.submit_button?.background || "#2ab5c4"),
                                        color: settings.payto_checkout_ui_styles?.submit_button?.text_color || "#000000",
                                        border: `1px solid ${settings.payto_checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}`,
                                        borderRadius: `${settings.payto_checkout_ui_styles?.submit_button?.border_radius || 10}px`,
                                        fontSize: settings.payto_checkout_ui_styles?.submit_button?.font_size || "17px",
                                        fontWeight: settings.payto_checkout_ui_styles?.submit_button?.font_weight || "bold",
                                        fontFamily: settings.payto_checkout_ui_styles?.submit_button?.font_family || "Helvetica, Arial, sans-serif",
                                        minHeight: "48px",
                                        cursor: isSubmitting ? "not-allowed" : "pointer",
                                    }}
                                    onClick={isSubmitting ? undefined : handlePayToSubmit}
                                    disabled={isSubmitting || paymentStatus === "processing"}
                                >

                                    {(isSubmitting || paymentStatus === "processing")
                                        ? "Processing..."
                                        : "Accept and Continue"}
                                </Button>
                            </div>
                        </>
                    }
                    
                </div>
            </>
        )
    }

    /**
     * Content component for PayTo
     */
    const Content = function (props) {
        const description = decodeEntities(settings.description || "");
        return (
            <div className="monoova-payto-content">
                <div
                    className="monoova-payto-description"
                    dangerouslySetInnerHTML={{ __html: description }}
                />
                <PayToForm {...props} />
            </div>
        )
    }

    /**
     * Label component with PayTo icon and description
     */
    const Label = props => {
        return (
            <div style={{ width: "100%" }}>
                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        width: "100%",
                    }}>
                    <span style={{ fontWeight: 600 }}>{label}</span>
                    <img
                        src={`${
                            settings.plugin_url || "/wp-content/plugins/monoova-payments-for-woocommerce/"
                        }assets/images/payto-logo.svg`}
                        alt="PayTo logo"
                        style={{
                            height: "24px",
                            width: "auto",
                        }}
                    />
                </div>
            </div>
        )
    }

    /**
     * Monoova PayTo payment method config object
     */
    const MonoovaPayTo = {
        name: "monoova_payto",
        label: <Label />,
        content: <Content />,
        edit: <Content />,
        canMakePayment: () => {
            return true
        },
        ariaLabel: label,
        supports: {
            features: settings.supports || [],
        },
        icons: settings.icons || null,
    }

    // Register the payment method
    try {
        registerPaymentMethod(MonoovaPayTo)
    } catch (error) {
        console.error("Monoova PayTo Block: Failed to register payment method:", error)
    }
}
