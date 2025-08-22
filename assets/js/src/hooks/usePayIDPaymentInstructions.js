import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { usePersistentPaymentDetailsContainer } from './usePersistentPaymentDetailsContainer';

export const usePayIDPaymentInstructions = ({
    settings,
    billing,
    shippingData,
    orderId: existingOrderId, // Accept existing order ID from props
    containerId = "payid-instructions-container",
    paymentMethodId = "monoova_payid",
    hasRequiredInfo = true // New parameter to control API calling
}) => {
    const [isLoading, setIsLoading] = useState(false);
    const [instructions, setInstructions] = useState(null);
    const [error, setError] = useState(null);
    const [orderId, setOrderId] = useState(existingOrderId || null); // Initialize with existing order ID
    const [paymentStatus, setPaymentStatus] = useState('pending');
    const [paymentFailedReason, setPaymentFailedReason] = useState(null);
    const [expiryTime, setExpiryTime] = useState(null);
    
    // Use refs to prevent duplicate API calls
    const isGeneratingInstructionsRef = useRef(false);
    const pollingIntervalRef = useRef(null);
    const countdownIntervalRef = useRef(null);
    
    // Use persistent container management
    const {
        targetRef,
        containerElement,
        containerIdActive,
        isContainerInitialized,
        setContainerInitialized,
        setOrderData,
        getOrderData,
        clearOrderData, // Make sure this is available from the hook
        showContainer,
        hideContainer
    } = usePersistentPaymentDetailsContainer(paymentMethodId, containerId);
    
    // Get persistent instruction data
    const persistentData = getOrderData();
    const persistentInstructions = persistentData?.instructions;
    const persistentOrderId = persistentData?.orderId;
    
    // Check if we should use existing instructions
    const shouldUseExistingInstructions = isContainerInitialized && persistentInstructions;

    const stopPolling = useCallback(() => {
        if (pollingIntervalRef.current) {
            clearInterval(pollingIntervalRef.current);
            pollingIntervalRef.current = null;
        }
    }, []);

    // Internal function to fetch instructions from the backend
    const fetchPaymentInstructions = useCallback(async (isRegeneration = false) => {
        setIsLoading(true);
        setError(null);
        isGeneratingInstructionsRef.current = true;

        try {
            console.log(`PayID Instructions: ${isRegeneration ? 'Regenerating' : 'Generating'} payment instructions for order:`, existingOrderId);
            
            const instructionsData = new FormData();
            instructionsData.append('action', 'monoova_generate_payment_instructions_in_blocked_checkout');
            instructionsData.append('order_id', existingOrderId);
            instructionsData.append('nonce', settings.generate_instructions_nonce);

            if (isRegeneration) {
                instructionsData.append('regenerate', 'true');
            }

            const instructionsResponse = await fetch(settings.ajax_url, {
                method: 'POST',
                body: instructionsData
            });

            const instructionsResult = await instructionsResponse.json();
            
            if (!instructionsResult.success) {
                throw new Error(instructionsResult.data?.message || `Failed to ${isRegeneration ? 'regenerate' : 'generate'} payment instructions`);
            }

            const paymentInstructions = instructionsResult.data;
            setInstructions(paymentInstructions);
            setOrderId(existingOrderId);
            
            if (paymentInstructions.expiry_timestamp) {
                setExpiryTime(paymentInstructions.expiry_timestamp);
            }

            setOrderData(existingOrderId, null, paymentInstructions);
            setContainerInitialized();

        } catch (error) {
            console.error(`PayID Instructions: Error ${isRegeneration ? 'regenerating' : 'generating'} instructions:`, error);
            setError(error.message);
        } finally {
            setIsLoading(false);
            isGeneratingInstructionsRef.current = false;
        }
    }, [
        existingOrderId,
        settings,
        setOrderData,
        setContainerInitialized
    ]);

    // Generate payment instructions for existing order
    const generatePaymentInstructions = useCallback(async () => {
        if (isGeneratingInstructionsRef.current || instructions || persistentInstructions) {
            return;
        }

        if (!existingOrderId || !hasRequiredInfo) {
            return;
        }

        await fetchPaymentInstructions(false);
    }, [
        existingOrderId,
        hasRequiredInfo,
        instructions, 
        persistentInstructions,
        fetchPaymentInstructions
    ]);

    const regeneratePaymentInstructions = useCallback(async () => {
        // Stop any ongoing polling and timers
        stopPolling();
        if (countdownIntervalRef.current) {
            clearInterval(countdownIntervalRef.current);
            countdownIntervalRef.current = null;
        }

        // Reset all relevant states
        setInstructions(null);
        setPaymentStatus('pending');
        setPaymentFailedReason(null);
        setExpiryTime(null);
        
        // Clear persistent data
        if (clearOrderData) {
            clearOrderData();
        }
        
        await fetchPaymentInstructions(true);
    }, [
        existingOrderId,
        clearOrderData,
        stopPolling,
        fetchPaymentInstructions
    ]);

    // Payment status polling
    const checkPaymentStatus = useCallback(async () => {
        const currentOrderId = orderId || persistentOrderId || existingOrderId;
        if (!currentOrderId) return;

        // Get the order key and status check nonce from the current instructions data
        const currentInstructions = instructions || persistentInstructions;
        const orderKey = currentInstructions?.details?.order_key;
        const statusCheckNonce = currentInstructions?.status_check_nonce;
        
        if (!orderKey || !statusCheckNonce) {
            console.warn('PayID Instructions: Missing order key or nonce for status check');
            return;
        }

        try {
            const statusData = new FormData();
            statusData.append('action', 'monoova_check_payment_status');
            statusData.append('order_id', currentOrderId);
            statusData.append('order_key', orderKey); // Add order key parameter
            statusData.append('nonce', statusCheckNonce); // Use the nonce from backend


            const response = await fetch(settings.ajax_url, {
                method: 'POST',
                body: statusData
            });

            const result = await response.json();
            // mock data with a delay 10s
            // const result = await new Promise((resolve) => {
            //     setTimeout(() => {
            //         resolve({
            //             success: true,
            //             data: {
            //                 status: 'failed',
            //                 reason: 'Insufficient funds',
            //             }
            //         });
            //     }, 10000);
            // });

            if (result.success && result.data.status !== 'pending') {
                setPaymentStatus(result.data.status);
                setPaymentFailedReason(result.data.reason || null);
                stopPolling();
            }
        } catch (error) {
            console.error('PayID Instructions: Error checking payment status:', error);
        }
    }, [orderId, persistentOrderId, existingOrderId, instructions, persistentInstructions, settings, stopPolling]);

    // Start/stop polling
    const startPolling = useCallback(() => {
        if (pollingIntervalRef.current) return;
        
        // Initial check
        checkPaymentStatus();
        // Poll every 10 seconds
        pollingIntervalRef.current = setInterval(checkPaymentStatus, 10000);
    }, [checkPaymentStatus]);


    // Countdown timer
    const startCountdown = useCallback(() => {
        if (!expiryTime || countdownIntervalRef.current) return;
        
        countdownIntervalRef.current = setInterval(() => {
            const distance = expiryTime * 1000 - new Date().getTime();
            if (distance < 0) {
                setPaymentStatus('expired');
                clearInterval(countdownIntervalRef.current);
                countdownIntervalRef.current = null;
                stopPolling();
            }
        }, 1000);
    }, [expiryTime, stopPolling]);

    // Function to reset just the payment status to restart polling
    const resetPaymentStatus = useCallback(async () => {
        const currentOrderId = orderId || persistentOrderId || existingOrderId;
        if (!currentOrderId) {
            console.warn('PayID Instructions: No order ID available for payment status reset');
            return;
        }

        try {
            // Get the order key from current instructions for security
            const currentInstructions = instructions || persistentInstructions;
            const orderKey = currentInstructions?.details?.order_key;
            
            if (!orderKey) {
                console.warn('PayID Instructions: Missing order key for status reset');
                return;
            }

            const resetData = new FormData();
            resetData.append('action', 'monoova_reset_payment_status');
            resetData.append('order_id', currentOrderId);
            resetData.append('order_key', orderKey);
            resetData.append('nonce', settings.reset_payment_status_nonce);

            const response = await fetch(settings.ajax_url, {
                method: 'POST',
                body: resetData
            });

            const result = await response.json();
            
            if (result.success) {
                // Reset the payment status to pending to restart polling
                setPaymentStatus('pending');
                setPaymentFailedReason(null);
                
                console.log('PayID Instructions: Payment status reset successfully');
            } else {
                console.error('PayID Instructions: Failed to reset payment status:', result.data?.message);
                throw new Error(result.data?.message || 'Failed to reset payment status');
            }
        } catch (error) {
            console.error('PayID Instructions: Error resetting payment status:', error);
            setError(error.message);
        }
    }, [
        orderId, 
        persistentOrderId, 
        existingOrderId, 
        instructions, 
        persistentInstructions, 
        settings
    ]);

    // Reset function
    const resetStates = useCallback(() => {
        setInstructions(null);
        setOrderId(null);
        setError(null);
        setIsLoading(false);
        setPaymentStatus('pending');
        setExpiryTime(null);
        
        // Reset refs
        isGeneratingInstructionsRef.current = false;
        
        // Stop timers
        stopPolling();
        if (countdownIntervalRef.current) {
            clearInterval(countdownIntervalRef.current);
            countdownIntervalRef.current = null;
        }
    }, [stopPolling]);

    // Initialize payment instructions when conditions are met
    useEffect(() => {
        const initializeInstructions = async () => {
            if (shouldUseExistingInstructions) {
                setInstructions(persistentInstructions);
                setOrderId(persistentOrderId || existingOrderId);
                showContainer();
                
                // Start polling and countdown if we have persistent instructions
                if (persistentInstructions?.expiry_timestamp) {
                    setExpiryTime(persistentInstructions.expiry_timestamp);
                }
                return;
            }

            // Check if we have required data to generate instructions
            // Only proceed if guest has provided all required information
            if (existingOrderId &&
                hasRequiredInfo && 
                !isLoading && 
                !instructions &&
                !isGeneratingInstructionsRef.current) {
                
                await generatePaymentInstructions();
                showContainer();
            }
        };

        // Use a small delay to prevent rapid successive calls
        const timeoutId = setTimeout(initializeInstructions, 100);
        
        return () => clearTimeout(timeoutId);
    }, [
        existingOrderId,
        hasRequiredInfo, // Replace individual billing field dependencies with this
        shouldUseExistingInstructions,
        persistentInstructions,
        persistentOrderId,
        isLoading,
        instructions,
        generatePaymentInstructions,
        showContainer
    ]);

    // Start polling when we have instructions
    useEffect(() => {
        if ((instructions || persistentInstructions) && paymentStatus === 'pending') {
            startPolling();
            startCountdown();
        }

        return () => {
            stopPolling();
            if (countdownIntervalRef.current) {
                clearInterval(countdownIntervalRef.current);
            }
        };
    }, [instructions, persistentInstructions, paymentStatus, startPolling, startCountdown, stopPolling]);

    return {
        isLoading,
        instructions: instructions || persistentInstructions,
        error,
        orderId: orderId || persistentOrderId || existingOrderId, // Return existing order ID if available
        paymentStatus,
        paymentFailedReason,
        expiryTime,
        resetStates,
        regeneratePaymentInstructions, // Expose the new function
        resetPaymentStatus, // Expose the status reset function
        containerRef: targetRef,
        isInitialized: shouldUseExistingInstructions || !!instructions
    };
};
