<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Monoova Card Blocks integration
 *
 * @since 1.1.2
 */
final class Monoova_Card_Blocks_Integration extends AbstractPaymentMethodType {

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
    protected $name = 'monoova_card'; // Must match the ID of the Monoova_Card_Gateway

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
        $this->settings = get_option( 'woocommerce_monoova_card_settings', [] );

        // Initialize debug mode and logger
        $this->init_debug_mode();

        // Get the gateway instance. We might need its settings.
        $gateways = WC()->payment_gateways->payment_gateways();

        $this->gateway  = $gateways[ $this->name ] ?? null;
    }

    /**
     * Initialize debug mode from gateway settings
     */
    private function init_debug_mode() {
        // Check debug setting from card gateway
        if (isset($this->settings['debug']) && $this->settings['debug'] === 'yes') {
            $this->debug = true;
        }

        // Initialize logger if debug is enabled
        if ($this->debug) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Logging method - writes to separate log file for card blocks
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    private function log($message, $level = 'info') {
        if ($this->debug && $this->logger) {
            $formatted_message = '[Card Blocks] ' . $message;
            $this->logger->log($level, $formatted_message, array('source' => 'monoova-card-blocks'));
        }
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        $gateway_exists = ! empty( $this->gateway );

        if ( $gateway_exists ) {
            $gateway_available = $this->gateway->is_available();

            $is_active = $gateway_exists && $gateway_available;

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
        $script_handle = 'wc-monoova-card-blocks-integration';
        if (wp_script_is($script_handle, 'registered')) {
            $this->log('Script already registered, skipping registration');
        } else {
            $asset_path = MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-card-block.asset.php';
            $version      = null;
            $dependencies = array();
            if (file_exists($asset_path)) {
                $asset        = require $asset_path;
                $version      = isset($asset['version']) ? $asset['version'] : $version;
                $dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
            }

            // Ensure WordPress components CSS is enqueued
            // wp_enqueue_style(
            //     'wp-components',
            //     includes_url('css/dist/components/style.min.css'),
            //     array(),
            //     get_bloginfo('version')
            // );

            // The main script is already registered by the main plugin class
            // We just need to register our blocks integration script that will use it
            $script_registered = wp_register_script(
                $script_handle,
                MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-card-block.js',
                $dependencies,
                $version,
                true
            );

            // Data is now provided via get_payment_method_data() method instead of wp_localize_script

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

        if ( ! $this->gateway ) {
            
            // Provide basic default data even when gateway is not available
            return [
                'title'                        => 'Credit / Debit Card (Monoova)',
                'description'                  => 'Pay securely with your credit or debit card via Monoova.',
                'supports'                     => [],
                'icons'                        => [],
                'testmode'                     => true,
                'is_available'                 => false,
                'ajax_url'                     => admin_url('admin-ajax.php'),
                'nonce'                        => wp_create_nonce('monoova-payment-nonce'),
                'create_session_nonce'         => wp_create_nonce('monoova-card-session-nonce'),
                'ajax_create_session_action'   => 'monoova_create_card_session',
                'session_token_response_key'   => 'sessionToken',
                'enable_apple_pay'             => false,
                'enable_google_pay'            => false,
                'currency'                     => get_woocommerce_currency(),
                'merchant_id'                  => '',
                'merchant_name'                => get_bloginfo('name'),
                'i18n' => array(
                    'card_number'          => __('Card Number', 'monoova-payments-for-woocommerce'),
                    'expiry_date'          => __('Expiry (MM/YY)', 'monoova-payments-for-woocommerce'),
                    'cvc'                  => __('CVC', 'monoova-payments-for-woocommerce'),
                    'processing'           => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                    'invalid_card_number'  => __('Invalid card number', 'monoova-payments-for-woocommerce'),
                    'invalid_expiry'       => __('Invalid expiration date', 'monoova-payments-for-woocommerce'),
                    'invalid_cvc'          => __('Invalid CVC code', 'monoova-payments-for-woocommerce'),
                    'generic_error'        => __('An error occurred while processing your payment. Please try again.', 'monoova-payments-for-woocommerce'),
                    'apple_pay_error'      => __('There was an error processing your Apple Pay payment.', 'monoova-payments-for-woocommerce'),
                    'google_pay_error'     => __('There was an error processing your Google Pay payment.', 'monoova-payments-for-woocommerce'),
                ),
            ];
        }

        // Get gateway settings safely
        $is_test = 'yes' === $this->gateway->get_option('testmode', 'no');
        $enable_apple_pay = 'yes' === $this->gateway->get_option('enable_apple_pay', 'no');
        $enable_google_pay = 'yes' === $this->gateway->get_option('enable_google_pay', 'no');
        
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
            'title'                        => $this->gateway->get_title(),
            'description'                  => $this->gateway->get_description(),
            'supports'                     => $this->gateway->supports,
            'testmode'                     => $is_test,
            'is_available'                 => $this->gateway->is_available(),
            'plugin_url'                   => MONOOVA_PLUGIN_URL,
            'ajax_url'                     => admin_url('admin-ajax.php'),
            'nonce'                        => wp_create_nonce('monoova-payment-nonce'),
            'create_session_nonce'         => wp_create_nonce('monoova-card-session-nonce'),
            'generate_token_nonce'         => wp_create_nonce('monoova-client-token'),
            'ajax_create_session_action'   => 'monoova_create_card_session',
            'session_token_response_key'   => 'sessionToken',
            'ajax_express_checkout_action' => 'monoova_express_checkout',
            'ajax_complete_express_action' => 'monoova_complete_express_checkout',
            'express_checkout_nonce'       => wp_create_nonce('monoova_express_checkout_nonce'),
            'enable_apple_pay'             => $enable_apple_pay,
            'enable_google_pay'            => $enable_google_pay,
            'currency'                     => get_woocommerce_currency(),
            'merchant_id'                  => $this->gateway->get_option('merchant_id', ''),
            'merchant_name'                => get_bloginfo('name'),
            'checkout_ui_styles'           => $checkout_ui_styles,
            'i18n' => array(
                'card_number'          => __('Card Number', 'monoova-payments-for-woocommerce'),
                'expiry_date'          => __('Expiry (MM/YY)', 'monoova-payments-for-woocommerce'),
                'cvc'                  => __('CVC', 'monoova-payments-for-woocommerce'),
                'processing'           => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                'invalid_card_number'  => __('Invalid card number', 'monoova-payments-for-woocommerce'),
                'invalid_expiry'       => __('Invalid expiration date', 'monoova-payments-for-woocommerce'),
                'invalid_cvc'          => __('Invalid CVC code', 'monoova-payments-for-woocommerce'),
                'generic_error'        => __('An error occurred while processing your payment. Please try again.', 'monoova-payments-for-woocommerce'),
                'apple_pay_error'      => __('There was an error processing your Apple Pay payment.', 'monoova-payments-for-woocommerce'),
                'google_pay_error'     => __('There was an error processing your Google Pay payment.', 'monoova-payments-for-woocommerce'),
            ),
        ];

        return $data;
    }
}
