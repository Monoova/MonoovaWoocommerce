<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Monoova Card Express Blocks integration
 *
 * @since 1.1.2
 */
final class Monoova_Card_Express_Blocks_Integration extends AbstractPaymentMethodType {

    /**
     * The gateway instance.
     *
     * @var Monoova_Card_Gateway
     */
    private $gateway;

    /**
     * Payment method name/id, must match the gateway ID.
     *
     * @var string
     */
    protected $name = 'monoova_card_express'; // Express payment method name

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
        $this->settings = get_option('woocommerce_monoova_card_settings', []);

        // Initialize debug mode and logger
        $this->init_debug_mode();

        // Get the gateway instance. We might need its settings.
        $gateways = WC()->payment_gateways->payment_gateways();

        $this->gateway  = $gateways['monoova_card'] ?? null; // Use the regular gateway settings
    }

    /**
     * Initialize debug mode from gateway settings
     */
    private function init_debug_mode() {
        // Check debug setting from card gateway (since express uses card gateway settings)
        if (isset($this->settings['debug']) && $this->settings['debug'] === 'yes') {
            $this->debug = true;
        }

        // Initialize logger if debug is enabled
        if ($this->debug) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Logging method - writes to separate log file for card express blocks
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    private function log($message, $level = 'info') {
        if ($this->debug && $this->logger) {
            $formatted_message = '[Card Express Blocks] ' . $message;
            $this->logger->log($level, $formatted_message, array('source' => 'monoova-card-express-blocks'));
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
            $express_enabled = 'yes' === $this->gateway->get_option('enable_express_checkout', 'no');
            $user_logged_in = is_user_logged_in();

            // Check if we're on the cart page (express checkout should only be available on cart page)
            $is_cart_page = is_cart() || has_block('woocommerce/cart') || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('cart'));

            // Check if we're on the checkout page (express checkout should NOT be available on checkout page)
            $is_checkout_page = is_checkout() || has_block('woocommerce/checkout') || (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('checkout'));

            // Check priority setting if both Card and PayTo express checkout are enabled
            $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
            $payto_settings = get_option('woocommerce_monoova_payto_settings', array());

            $payto_enabled = isset($payto_settings['enabled']) && $payto_settings['enabled'] === 'yes';
            $payto_express_enabled = isset($payto_settings['enable_payto_express_checkout']) &&
                ($payto_settings['enable_payto_express_checkout'] === 'yes' || $payto_settings['enable_payto_express_checkout'] === true);

            $priority = isset($unified_settings['express_checkout_method_priority']) ? $unified_settings['express_checkout_method_priority'] : 'card';

            // If both are enabled, respect priority
            if ($express_enabled && $payto_enabled && $payto_express_enabled && $priority === 'payto') {
                // PayTo has priority, hide card express checkout
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
        $script_handle = 'wc-monoova-card-express-blocks-integration';
        if (wp_script_is($script_handle, 'registered')) {
            $this->log('Script already registered, skipping registration');
        } else {
            $asset_path = MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-card-express-block.asset.php';
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
                MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-card-express-block.js',
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
                'title'                        => 'Monoova Express Checkout',
                'description'                  => 'Pay quickly and securely with Monoova Express Checkout.',
                'supports'                     => ['products'],
                'style'                        => ['height', 'borderRadius'],
                'icons'                        => [],
                'testmode'                     => true,
                'is_available'                 => false,
                'user_logged_in'               => is_user_logged_in(),
                'ajax_url'                     => admin_url('admin-ajax.php'),
                'nonce'                        => wp_create_nonce('monoova-payment-nonce'),
                'create_session_nonce'         => wp_create_nonce('monoova-card-session-nonce'),
                'ajax_create_session_action'   => 'monoova_create_card_session',
                'enable_apple_pay'             => false,
                'enable_google_pay'            => false,
                'currency'                     => get_woocommerce_currency(),
                'merchant_id'                  => '',
                'merchant_name'                => get_bloginfo('name'),
                'button_style' => [
                    'height' => 48,
                    'borderRadius' => 4,
                    'color' => 'primary'
                ],
                'i18n' => array(
                    'express_pay_button'       => __('Pay with Monoova', 'monoova-payments-for-woocommerce'),
                    'processing'               => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                    'generic_error'            => __('An error occurred while processing your payment. Please try again.', 'monoova-payments-for-woocommerce'),
                ),
            ];
        }

        // Get gateway settings safely
        $is_test = 'yes' === $this->gateway->get_option('testmode', 'no');
        $enable_apple_pay = 'yes' === $this->gateway->get_option('enable_apple_pay', 'no');
        $enable_google_pay = 'yes' === $this->gateway->get_option('enable_google_pay', 'no');
        $button_color = $this->gateway->get_option('express_button_color', 'primary');
        $button_height = (int) $this->gateway->get_option('express_button_height', 48);
        $button_border_radius = (int) $this->gateway->get_option('express_button_border_radius', 4);

        // Get checkout UI style settings
        $checkout_ui_styles = $this->gateway->get_option('checkout_ui_styles', array(
            'input_label' => array(
                'font_family' => 'Helvetica, Arial, sans-serif',
                'font_weight' => 'normal',
                'font_size' => '14px',
                'color' => '#000000'
            ),
            'input' => array(
                'font_family' => 'Helvetica, Arial, sans-serif',
                'font_weight' => 'normal',
                'font_size' => '14px',
                'background_color' => '#FAFAFA',
                'border_color' => '#E8E8E8',
                'border_radius' => '8px',
                'text_color' => '#000000'
            ),
            'submit_button' => array(
                'font_family' => 'Helvetica, Arial, sans-serif',
                'font_size' => '17px',
                'background' => '#2ab5c4',
                'border_radius' => '10px',
                'border_color' => '#2ab5c4',
                'font_weight' => 'bold',
                'text_color' => '#000000'
            )
        ));

        $data = [
            'title'                        => 'Monoova Express Checkout',
            'description'                  => 'Pay quickly and securely with Monoova Express Checkout.',
            'gatewayId'                    => 'monoova_card', // Point to the main gateway settings
            'supports'                     => ['products'],
            'style'                        => ['height', 'borderRadius'],
            //'icons'                        => $this->get_icon_urls(),
            'testmode'                     => $is_test,
            'is_available'                 => $this->gateway->is_available(),
            'user_logged_in'               => is_user_logged_in(),
            'ajax_url'                     => admin_url('admin-ajax.php'),
            'nonce'                        => wp_create_nonce('monoova-payment-nonce'),
            'create_session_nonce'         => wp_create_nonce('monoova-card-session-nonce'),
            'express_checkout_nonce'       => wp_create_nonce('monoova_express_checkout_nonce'),
            'ajax_create_session_action'   => 'monoova_create_card_session',
            'ajax_express_checkout_action' => 'monoova_express_checkout',
            'ajax_complete_express_action' => 'monoova_complete_express_checkout',
            'enable_apple_pay'             => $enable_apple_pay,
            'enable_google_pay'            => $enable_google_pay,
            'currency'                     => get_woocommerce_currency(),
            'merchant_id'                  => $this->gateway->get_option('merchant_id', ''),
            'merchant_name'                => get_bloginfo('name'),
            'button_style' => [
                'height' => $button_height,
                'borderRadius' => $button_border_radius,
                'color' => $button_color
            ],
            'checkout_ui_styles' => $checkout_ui_styles,
            'i18n' => array(
                'express_pay_button'       => $this->gateway->get_option('order_button_text', __('Pay with Card', 'monoova-payments-for-woocommerce')),
                'processing'               => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                'generic_error'            => __('An error occurred while processing your payment. Please try again.', 'monoova-payments-for-woocommerce'),
                'apple_pay_error'          => __('There was an error processing your Apple Pay payment.', 'monoova-payments-for-woocommerce'),
                'google_pay_error'         => __('There was an error processing your Google Pay payment.', 'monoova-payments-for-woocommerce'),
                'login_required'           => __('Please log in to use express checkout.', 'monoova-payments-for-woocommerce'),
            ),
        ];

        return $data;
    }

    // /**
    //  * Get URLs for the payment method icons.
    //  *
    //  * @return array
    //  */
    // private function get_icon_urls() {
    //     $icon_urls = [];

    //     // For express checkout, we want to show the Monoova logo
    //     $icon_urls[] = MONOOVA_PLUGIN_URL . 'assets/images/monoova.png';

    //     // Also add card icons
    //     $card_types = ['visa', 'mastercard'];
    //     foreach ($card_types as $type) {
    //         $icon_path = MONOOVA_PLUGIN_DIR . 'assets/images/' . $type . '.png';
    //         if (file_exists($icon_path)) {
    //             $icon_urls[] = MONOOVA_PLUGIN_URL . 'assets/images/' . $type . '.png';
    //         }
    //     }

    //     return $icon_urls;
    // }

    // /**
    //  * Returns an array of script handles to be enqueued for the admin.
    //  * 
    //  * Include this if your payment method has a script you _only_ want to load in the editor context for the checkout block. 
    //  * Include here any script from `get_payment_method_script_handles` that is also needed in the admin.
    //  */
    // public function get_payment_method_script_handles_for_admin() {
    // 	return $this->get_payment_method_script_handles();
    // }

    // public function get_supported_features() {
    //     return [ 'products', 'checkout' ];
    // }
}
