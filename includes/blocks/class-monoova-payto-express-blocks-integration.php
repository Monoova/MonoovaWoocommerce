<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Monoova PayTo Express Blocks integration
 *
 * @since 1.1.2
 */
final class Monoova_PayTo_Express_Blocks_Integration extends AbstractPaymentMethodType {

    /**
     * The gateway instance.
     *
     * @var Monoova_PayTo_Gateway
     */
    private $gateway;

    /**
     * Payment method name/id, must match the gateway ID.
     *
     * @var string
     */
    protected $name = 'monoova_payto_express'; // Express payment method name

    /**
     * Debug Mode
     *
     * @var bool
     */
    private $debug = false;

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    private $logger;

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_monoova_payto_settings', []);

        // Initialize debug mode and logger
        $this->init_debug_mode();

        // Get the gateway instance. We might need its settings.
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways['monoova_payto'] ?? null;

        // Fallback: If gateway not found in registry, try to instantiate directly
        if (!$this->gateway && class_exists('Monoova_PayTo_Gateway')) {
            $this->gateway = new Monoova_PayTo_Gateway();
        }
    }

    /**
     * Initialize debug mode from gateway settings
     */
    private function init_debug_mode() {
        // Check debug setting from payto gateway
        if (isset($this->settings['debug']) && $this->settings['debug'] === 'yes') {
            $this->debug = true;
        }

        // Initialize logger if debug is enabled
        if ($this->debug) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Logging method - writes to separate log file for payto express blocks
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    private function log($message, $level = 'info') {
        if ($this->debug && $this->logger) {
            $formatted_message = '[PayTo Express Blocks] ' . $message;
            $this->logger->log($level, $formatted_message, array('source' => 'monoova-payto-express-blocks'));
        }
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        $gateway_exists = ! empty($this->gateway);

        if ($gateway_exists) {
            $gateway_available = $this->gateway->is_available();

            // Check if PayTo express checkout is enabled using unified settings
            $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
            $express_enabled = isset($unified_settings['enable_payto_express_checkout']) &&
                ($unified_settings['enable_payto_express_checkout'] === 'yes' || $unified_settings['enable_payto_express_checkout'] === true);

            $user_logged_in = is_user_logged_in();

            // Check if we're on the cart page (express checkout should only be available on cart page)
            $is_cart_page = is_cart() || has_block('woocommerce/cart') || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('cart'));

            // Check if we're on the checkout page (express checkout should NOT be available on checkout page)
            $is_checkout_page = is_checkout() || has_block('woocommerce/checkout') || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('checkout'));

            // Check priority setting if both Card and PayTo express checkout are enabled
            $card_settings = get_option('woocommerce_monoova_card_settings', array());
            $card_enabled = isset($card_settings['enabled']) && $card_settings['enabled'] === 'yes';
            $card_express_enabled = isset($card_settings['enable_express_checkout']) && $card_settings['enable_express_checkout'] === 'yes';

            $priority = isset($unified_settings['express_checkout_method_priority']) ? $unified_settings['express_checkout_method_priority'] : 'card';

            // If both are enabled, respect priority
            if ($express_enabled && $card_enabled && $card_express_enabled && $priority === 'card') {
                // Card has priority, hide PayTo express checkout
                return false;
            }

            $is_active = $gateway_exists && $gateway_available && $express_enabled && $user_logged_in && $is_cart_page && !$is_checkout_page;

            return $is_active;
        }

        return false;
    }

    /**
     * Returns an array of script handles to enqueue for this payment method in the frontend context.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        // Check if scripts have already been registered to avoid duplicate registration
        $script_handle = 'wc-monoova-payto-express-blocks-integration';
        if (wp_script_is($script_handle, 'registered')) {
            $this->log('Script already registered, skipping registration');
        } else {
            $asset_path = MONOOVA_PLUGIN_DIR . 'assets/js/build/monoova-payto-express-block.asset.php';
            $version      = null;
            $dependencies = array();
            if (file_exists($asset_path)) {
                $asset        = require $asset_path;
                $version      = isset($asset['version']) ? $asset['version'] : $version;
                $dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
            }

            // Ensure WordPress components CSS is enqueued
            wp_enqueue_style(
                'wp-components',
                includes_url('css/dist/components/style.min.css'),
                array(),
                get_bloginfo('version')
            );

            // Register the express payment method script
            $script_registered = wp_register_script(
                $script_handle,
                MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-payto-express-block.js',
                $dependencies,
                $version,
                true
            );

            if (function_exists('wp_set_script_translations')) {
                wp_set_script_translations($script_handle, 'monoova-payments-for-woocommerce');
            }
        }

        return array($script_handle);
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if (! $this->gateway) {
            // Provide basic default data even when gateway is not available
            return [
                'title'                        => 'PayTo Express Checkout',
                'description'                  => 'Pay quickly and securely with your existing PayTo mandate.',
                'supports'                     => ['products'],
                'style'                        => ['height', 'borderRadius'],
                'icons'                        => [],
                'testmode'                     => true,
                'is_available'                 => false,
                'user_logged_in'               => is_user_logged_in(),
                'ajax_url'                     => admin_url('admin-ajax.php'),
                'nonce'                        => wp_create_nonce('monoova-payment-nonce'),
                'check_mandate_nonce'          => wp_create_nonce('monoova_payto_check_mandate'),
                'express_payment_nonce'        => wp_create_nonce('monoova_payto_express_payment'),
                'ajax_check_mandate_action'    => 'monoova_payto_check_mandate',
                'ajax_express_payment_action'  => 'monoova_payto_express_payment',
                'currency'                     => get_woocommerce_currency(),
                'merchant_name'                => get_bloginfo('name'),
                'button_style' => [
                    'height' => 48,
                    'borderRadius' => 4,
                    'color' => 'primary'
                ],
                'i18n' => array(
                    'express_pay_button'       => __('Pay with PayTo', 'monoova-payments-for-woocommerce'),
                    'login_required'           => __('Please log in to use PayTo express checkout.', 'monoova-payments-for-woocommerce'),
                    'no_mandate'               => __('No active PayTo mandate found.', 'monoova-payments-for-woocommerce'),
                    'mandate_available'        => __('Use existing PayTo mandate', 'monoova-payments-for-woocommerce'),
                    'processing'               => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                    'error'                    => __('Payment failed. Please try again.', 'monoova-payments-for-woocommerce'),
                ),
            ];
        }

        // Get gateway settings safely
        $is_test = 'yes' === $this->gateway->get_option('testmode', 'no');
        $button_color = $this->gateway->get_option('express_button_color', 'primary');
        $button_height = (int) $this->gateway->get_option('express_button_height', 48);
        $button_border_radius = (int) $this->gateway->get_option('express_button_border_radius', 4);

        $data = [
            'title'                        => 'PayTo Express Checkout',
            'description'                  => 'Pay quickly and securely with your existing PayTo mandate.',
            'gatewayId'                    => 'monoova_payto', // Point to the main gateway settings
            'supports'                     => ['products'],
            'style'                        => ['height', 'borderRadius'],
            'testmode'                     => $is_test,
            'is_available'                 => $this->gateway->is_available(),
            'user_logged_in'               => is_user_logged_in(),
            'ajax_url'                     => admin_url('admin-ajax.php'),
            'nonce'                        => wp_create_nonce('monoova-payment-nonce'),
            'check_mandate_nonce'          => wp_create_nonce('monoova_payto_check_mandate'),
            'express_payment_nonce'        => wp_create_nonce('monoova_payto_express_payment'),
            'ajax_check_mandate_action'    => 'monoova_payto_check_mandate',
            'ajax_express_payment_action'  => 'monoova_payto_express_payment',
            'currency'                     => get_woocommerce_currency(),
            'merchant_name'                => get_bloginfo('name'),
            'maximum_amount'               => $this->gateway->maximum_amount ?? 1000,
            'button_style' => [
                'height' => $button_height,
                'borderRadius' => $button_border_radius,
                'color' => $button_color
            ],
            'i18n' => array(
                'express_pay_button'       => $this->gateway->get_option('order_button_text', __('Pay with PayTo', 'monoova-payments-for-woocommerce')),
                'login_required'           => __('Please log in to use PayTo express checkout.', 'monoova-payments-for-woocommerce'),
                'no_mandate'               => __('No active PayTo mandate found.', 'monoova-payments-for-woocommerce'),
                'mandate_available'        => __('Use existing PayTo mandate', 'monoova-payments-for-woocommerce'),
                'processing'               => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                'error'                    => __('Payment failed. Please try again.', 'monoova-payments-for-woocommerce'),
            ),
        ];

        return $data;
    }
}
