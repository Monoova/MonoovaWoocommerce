/**
 * Monoova Admin Interface Entry Point
 */

import { createRoot } from '@wordpress/element';
import PaymentSettings from './payment-settings';

// Initialize the React interface when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('monoova-payment-settings-container');

    const root = createRoot(container);
    root.render(
        <PaymentSettings />
    );
});
