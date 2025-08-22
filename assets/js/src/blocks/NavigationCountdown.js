import { useState, useEffect } from '@wordpress/element';

/**
 * Navigation Countdown Component
 * 
 * Shows a countdown timer and redirects to a URL when it reaches 0
 * 
 * @param {Object} props
 * @param {number} props.initialSeconds - Starting countdown time in seconds
 * @param {string} props.redirectUrl - URL to redirect to when countdown reaches 0
 * @param {string} props.message - Message template with {countdown} placeholder
 */
export const NavigationCountdown = ({ 
    initialSeconds = 5, 
    redirectUrl, 
    message = 'Redirecting in {countdown} seconds...' 
}) => {
    const [countdown, setCountdown] = useState(initialSeconds);

    useEffect(() => {
        if (countdown <= 0) {
            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
            return;
        }

        const timer = setTimeout(() => {
            setCountdown(prev => prev - 1);
        }, 1000);

        return () => clearTimeout(timer);
    }, [countdown, redirectUrl]);

    const formattedMessage = message.replace('{countdown}', countdown.toString());

    return (
        <div className="monoova-navigation-countdown" style={{ 
            marginTop: '15px', 
            padding: '10px',
            backgroundColor: '#f8f9fa',
            border: '1px solid #dee2e6',
            borderRadius: '4px',
            textAlign: 'center',
            fontSize: '14px',
            color: '#6c757d'
        }}>
            {formattedMessage}
        </div>
    );
};
