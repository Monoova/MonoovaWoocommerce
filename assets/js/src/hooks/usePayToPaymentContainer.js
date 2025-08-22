import { useState, useEffect, useCallback, useRef } from "@wordpress/element"
import { usePersistentPaymentDetailsContainer } from "./usePersistentPaymentDetailsContainer"

export const usePayToPaymentContainer = ({
    settings,
    billing,
    orderId: existingOrderId,
    containerId = "payto-payment-container",
    paymentMethodId = "monoova_payto",
    hasRequiredInfo = true,
}) => {
    // PayTo-specific state management
    const [paymentMethod, setPaymentMethod] = useState("payid")
    const [payidValue, setPayidValue] = useState("")
    const [payidType, setPayidType] = useState("")
    const [accountName, setAccountName] = useState("")
    const [bsb, setBsb] = useState("")
    const [accountNumber, setAccountNumber] = useState("")
    const [maximumAmount, setMaximumAmount] = useState(settings.maximum_amount || 1000)
    const [errors, setErrors] = useState({})
    const [paymentStatus, setPaymentStatus] = useState("form")
    const [paymentInstructions, setPaymentInstructions] = useState(null)
    const [isSubmitting, setIsSubmitting] = useState(false)
    const [pollInterval, setPollInterval] = useState(null)
    const [hasExpressCheckout, setHasExpressCheckout] = useState(false)
    const [errorDetails, setErrorDetails] = useState(null)
    const [mandateInfo, setMandateInfo] = useState(null)
    const [countdown, setCountdown] = useState(86400)

    // Use refs to prevent duplicate API calls
    const isProcessingRef = useRef(false)
    const pollingIntervalRef = useRef(null)

    // Use persistent container management
    const {
        targetRef,
        containerElement,
        containerIdActive,
        isContainerInitialized,
        setContainerInitialized,
        setOrderData,
        getOrderData,
        clearOrderData,
        showContainer,
        hideContainer,
    } = usePersistentPaymentDetailsContainer(paymentMethodId, containerId)

    // Get persistent data
    const persistentData = getOrderData()
    const persistentPaymentData = persistentData?.instructions
    const persistentOrderId = persistentData?.orderId

    // Check if we should use existing data
    const shouldUseExistingData = isContainerInitialized && persistentPaymentData

    // Stop polling utility
    const stopPolling = useCallback(() => {
        if (pollingIntervalRef.current) {
            clearInterval(pollingIntervalRef.current)
            pollingIntervalRef.current = null
        }
        if (pollInterval) {
            clearInterval(pollInterval)
            setPollInterval(null)
        }
    }, [pollInterval])

    // Initialize with persistent data if available
    useEffect(() => {
        if (shouldUseExistingData && persistentPaymentData) {
            console.log("PayTo: Restoring persistent payment data:", persistentPaymentData)

            // Restore form state
            if (persistentPaymentData.formData) {
                setPaymentMethod(persistentPaymentData.formData.paymentMethod || "payid")
                setPayidValue(persistentPaymentData.formData.payidValue || "")
                setPayidType(persistentPaymentData.formData.payidType || "")
                setAccountName(persistentPaymentData.formData.accountName || "")
                setBsb(persistentPaymentData.formData.bsb || "")
                setAccountNumber(persistentPaymentData.formData.accountNumber || "")
                setMaximumAmount(persistentPaymentData.formData.maximumAmount || settings.maximum_amount || 1000)
            }

            // Restore payment state
            if (persistentPaymentData.paymentInstructions) {
                setPaymentInstructions(persistentPaymentData.paymentInstructions)
                setPaymentStatus(persistentPaymentData.paymentStatus || "instructions")
            }

            // Restore express checkout state
            if (persistentPaymentData.hasExpressCheckout !== undefined) {
                setHasExpressCheckout(persistentPaymentData.hasExpressCheckout)
            }

            if (persistentPaymentData.mandateInfo) {
                setMandateInfo(persistentPaymentData.mandateInfo)
            }
        }
    }, [shouldUseExistingData, persistentPaymentData, settings.maximum_amount])

    // Save current state to persistent storage
    const saveCurrentState = useCallback(() => {
        const currentState = {
            formData: {
                paymentMethod,
                payidValue,
                payidType,
                accountName,
                bsb,
                accountNumber,
                maximumAmount,
            },
            paymentInstructions,
            paymentStatus,
            hasExpressCheckout,
            mandateInfo,
            errors,
            errorDetails,
        }

        setOrderData(existingOrderId, null, currentState)
    }, [
        paymentMethod,
        payidValue,
        payidType,
        accountName,
        bsb,
        accountNumber,
        maximumAmount,
        paymentInstructions,
        paymentStatus,
        hasExpressCheckout,
        mandateInfo,
        errors,
        errorDetails,
        existingOrderId,
        setOrderData,
    ])

    // Save state whenever it changes
    useEffect(() => {
        if (isContainerInitialized) {
            saveCurrentState()
        }
    }, [saveCurrentState, isContainerInitialized])

    // Reset all states
    const resetStates = useCallback(() => {
        setPaymentMethod("payid")
        setPayidValue("")
        setPayidType("")
        setAccountName("")
        setBsb("")
        setAccountNumber("")
        setMaximumAmount(settings.maximum_amount || 1000)
        setErrors({})
        setPaymentStatus("form")
        setPaymentInstructions(null)
        setIsSubmitting(false)
        setHasExpressCheckout(false)
        setErrorDetails(null)
        setMandateInfo(null)
        setCountdown(86400)
        stopPolling()

        if (clearOrderData) {
            clearOrderData()
        }
    }, [settings.maximum_amount, stopPolling, clearOrderData])

    // Cleanup on unmount
    useEffect(() => {
        return () => {
            stopPolling()
        }
    }, [stopPolling])

    return {
        // Container management
        containerRef: targetRef,
        containerElement,
        containerIdActive,
        isInitialized: isContainerInitialized,
        setContainerInitialized,
        showContainer,
        hideContainer,

        // State management
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

        // Persistent data
        persistentData,
        shouldUseExistingData,
    }
}
