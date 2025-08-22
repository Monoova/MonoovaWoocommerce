/**
 * Monoova Payment Settings - Entry Point
 */

import { createRoot } from '@wordpress/element';
import PaymentSettings from './payment-settings';

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('monoova-payment-settings-container');
    if (container) {
        const root = createRoot(container);
        root.render(<PaymentSettings />);
    }
});
