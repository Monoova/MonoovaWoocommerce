import { useEffect, useRef } from '@wordpress/element';

// Global container management for persistent Primer checkout
class PrimerCheckoutManager {
    constructor() {
        this.containers = new Map();
        this.activeContainers = new Set();
        this.isInitialized = false;
    }

    // Create or get existing container
    getOrCreateContainer(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        
        if (!this.containers.has(containerKey)) {
            // Create container element
            const container = document.createElement('div');
            container.id = containerId;
            container.className = 'primer-checkout-persistent-container';
            container.style.cssText = `
                position: absolute;
                top: -9999px;
                left: -9999px;
                width: 100%;
                visibility: hidden;
                opacity: 0;
                pointer-events: none;
                transition: all 0.3s ease;
            `;
            
            // Append to body to keep it persistent
            document.body.appendChild(container);
            
            this.containers.set(containerKey, {
                element: container,
                isInitialized: false,
                isVisible: false,
                orderId: null, // Store orderId for payment completion
                clientToken: null // Store clientToken as well
            });
        }
        
        return this.containers.get(containerKey);
    }

    // Show container in target element
    showContainer(paymentMethodId, containerId, targetElement) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        
        if (containerInfo && containerInfo.element) {
            // Hide all other containers first
            this.hideAllContainers();
            
            // Move container to target and show it
            if (targetElement) {
                targetElement.appendChild(containerInfo.element);
                containerInfo.element.style.cssText = `
                    position: relative;
                    top: auto;
                    left: auto;
                    width: 100%;
                    visibility: visible;
                    opacity: 1;
                    pointer-events: auto;
                    transition: all 0.3s ease;
                `;
                containerInfo.isVisible = true;
                this.activeContainers.add(containerKey);
            }
        }
    }

    // Hide container
    hideContainer(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        
        if (containerInfo && containerInfo.element) {
            // Move back to body and hide
            document.body.appendChild(containerInfo.element);
            containerInfo.element.style.cssText = `
                position: absolute;
                top: -9999px;
                left: -9999px;
                width: 100%;
                visibility: hidden;
                opacity: 0;
                pointer-events: none;
                transition: all 0.3s ease;
            `;
            containerInfo.isVisible = false;
            this.activeContainers.delete(containerKey);
        }
    }

    // Hide all containers
    hideAllContainers() {
        this.containers.forEach((containerInfo, containerKey) => {
            if (containerInfo.isVisible) {
                this.hideContainer(containerKey.split('_')[0], containerKey.split('_')[1]);
            }
        });
    }

    // Clear order data for a specific container
    clearOrderData(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);

        if (containerInfo) {
            containerInfo.orderId = null;
            containerInfo.clientToken = null;
            containerInfo.instructions = null; // Also clear instructions
            containerInfo.isInitialized = false; // Reset initialization state
        }
    }

    // Set initialization status
    setContainerInitialized(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        if (containerInfo) {
            containerInfo.isInitialized = true;
        }
    }

    // Check if container is initialized
    isContainerInitialized(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        return containerInfo ? containerInfo.isInitialized : false;
    }

    // Set order and token data
    setOrderData(paymentMethodId, containerId, orderId, clientToken, instructions = null) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        if (containerInfo) {
            containerInfo.orderId = orderId;
            containerInfo.clientToken = clientToken;
            containerInfo.instructions = instructions; // Add instructions storage (using for PayID/Bank Transfer)
        } else {
            console.warn(`[PrimerCheckoutManager] Container ${containerKey} not found for setting order data`);
        }
    }

    // Get order data
    getOrderData(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        const result = containerInfo ? {
            orderId: containerInfo.orderId,
            clientToken: containerInfo.clientToken,
            instructions: containerInfo.instructions // Include instructions in returned data
        } : { orderId: null, clientToken: null, instructions: null };
        
        return result;
    }

    // Get container element
    getContainerElement(paymentMethodId, containerId) {
        const containerKey = `${paymentMethodId}_${containerId}`;
        const containerInfo = this.containers.get(containerKey);
        return containerInfo ? containerInfo.element : null;
    }

    // Cleanup
    cleanup() {
        this.containers.forEach((containerInfo) => {
            if (containerInfo.element && containerInfo.element.parentNode) {
                containerInfo.element.parentNode.removeChild(containerInfo.element);
            }
        });
        this.containers.clear();
        this.activeContainers.clear();
    }
}

// Global instance
const primerCheckoutManager = new PrimerCheckoutManager();

// Hook for persistent container management
export const usePersistentPaymentDetailsContainer = (paymentMethodId, containerId) => {
    const targetRef = useRef(null);
    const isActiveRef = useRef(false);
    
    // Generate persistent container ID
    const persistentContainerId = `persistent-${paymentMethodId}-${containerId}`;

    useEffect(() => {
        // Create or get container
        const containerInfo = primerCheckoutManager.getOrCreateContainer(paymentMethodId, containerId);
        
        // Update the container's ID to match what Primer expects
        if (containerInfo.element) {
            containerInfo.element.id = persistentContainerId;
        }
        
        // Show container when component mounts
        if (targetRef.current && !isActiveRef.current) {
            primerCheckoutManager.showContainer(paymentMethodId, containerId, targetRef.current);
            isActiveRef.current = true;
        }

        // Cleanup on unmount
        return () => {
            if (isActiveRef.current) {
                primerCheckoutManager.hideContainer(paymentMethodId, containerId);
                isActiveRef.current = false;
            }
        };
    }, [paymentMethodId, containerId]);

    return {
        targetRef,
        containerElement: primerCheckoutManager.getContainerElement(paymentMethodId, containerId),
        containerIdActive: persistentContainerId, // Return the active container ID for Primer
        isContainerInitialized: primerCheckoutManager.isContainerInitialized(paymentMethodId, containerId),
        setContainerInitialized: () => primerCheckoutManager.setContainerInitialized(paymentMethodId, containerId),
        // Order data persistence methods
        setOrderData: (orderId, clientToken, instructions = null) => primerCheckoutManager.setOrderData(paymentMethodId, containerId, orderId, clientToken, instructions),
        getOrderData: () => primerCheckoutManager.getOrderData(paymentMethodId, containerId),
        clearOrderData: () => {
            primerCheckoutManager.clearOrderData(paymentMethodId, containerId);
        },
        showContainer: () => {
            if (targetRef.current && !isActiveRef.current) {
                primerCheckoutManager.showContainer(paymentMethodId, containerId, targetRef.current);
                isActiveRef.current = true;
            }
        },
        hideContainer: () => {
            if (isActiveRef.current) {
                primerCheckoutManager.hideContainer(paymentMethodId, containerId);
                isActiveRef.current = false;
            }
        }
    };
};

// Cleanup function for page unload
if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', () => {
        primerCheckoutManager.cleanup();
    });
}

export default primerCheckoutManager;
