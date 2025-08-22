export const PrimerCheckoutContainer = ({
    containerId,
    isLoading = false,
    isInitialized = false,
    containerRef = null
}) => {
    return (
        <div className="embedded-card-form" style={{ marginTop: '20px' }}>
            {isLoading && !isInitialized && (
                <div className="primer-loading-indicator" style={{
                    padding: '20px',
                    textAlign: 'center',
                    color: '#666'
                }}>
                    Loading payment form...
                </div>
            )}
            {/* Target container for persistent Primer checkout */}
            <div ref={containerRef} className="primer-container-target">
                <div id={containerId}></div>
            </div>
        </div>
    );
};
