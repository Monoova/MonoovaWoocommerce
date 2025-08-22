<?php

/**
 * Abstract Monoova Gateway Class
 *
 * @package Monoova_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

abstract class Monoova_Gateway extends WC_Payment_Gateway {

    /**
     * API instance
     *
     * @var Monoova_API
     */
    public $api;

    /**
     * Constructor
     */
    public function __construct() {
        // Load settings from DB
        $this->init_settings();

        // Define essential properties for payment processing
        // Note: Most settings are now managed by the Unified Gateway
        $this->enabled            = $this->get_option('enabled');
        $this->title              = $this->get_option('title');
        $this->description        = $this->get_option('description');

        // Initialize Monoova API client
        $this->init_api_client();

        // Hooks
        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));
    }

    /**
     * Initialize API client from Unified Gateway settings.
     * Gets settings from the Unified Gateway.
     */
    protected function init_api_client() {
        // Get settings from Unified Gateway
        $unified_settings = $this->get_unified_gateway_settings();
        if (!$unified_settings || !is_array($unified_settings)) {
            $this->api = null;
            return;
        }

        // Use this gateway's own testmode setting instead of unified testmode
        $gateway_testmode = 'yes' === ($this->get_option('testmode') ?? 'yes');
        $debug = 'yes' === ($unified_settings['debug'] ?? 'no');

        $active_api_key = $gateway_testmode ?
            ($unified_settings['test_api_key'] ?? '') : ($unified_settings['live_api_key'] ?? '');

        // Determine API URLs based on this gateway's test mode with defaults
        $payments_api_url = $gateway_testmode ?
            ($unified_settings['monoova_payments_api_url_sandbox'] ?? 'https://api.m-pay.com.au') : ($unified_settings['monoova_payments_api_url_live'] ?? 'https://api.mpay.com.au');

        $card_api_url = $gateway_testmode ?
            ($unified_settings['monoova_card_api_url_sandbox'] ?? 'https://sand-api.monoova.com') : ($unified_settings['monoova_card_api_url_live'] ?? 'https://api.monoova.com');

        if (empty($active_api_key) || empty($payments_api_url) || empty($card_api_url)) {
            $this->api = null;
            // Only log once per request to avoid spam
            static $logged = false;
            if ($debug && !$logged) {
                error_log('Monoova API client not initialized: Missing API key or URLs for gateway: ' . $this->id);
                $logged = true;
            }
            return;
        }

        $this->api = new Monoova_API(
            $payments_api_url,
            $card_api_url,
            $active_api_key,
            $unified_settings['maccount_number'] ?? '',
            $gateway_testmode,
            true //$debug
        );
    }
    
    /**
     * Get settings from the Unified Gateway.
     * Reads directly from database to avoid circular dependencies during gateway initialization.
     *
     * @return array|null Unified gateway settings or null if not available.
     */
    protected function get_unified_gateway_settings() {
        // Prevent initialization during WordPress operations that might cause issues
        if (defined('WP_INSTALLING') || wp_installing()) {
            return null;
        }

        // Ensure WooCommerce is loaded
        if (!function_exists('WC') || !class_exists('WooCommerce')) {
            return null;
        }

        // Read settings directly from database to avoid circular dependencies
        $card_settings = get_option('woocommerce_monoova_card_settings', array());
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        $payto_settings = get_option('woocommerce_monoova_payto_settings', array());
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());


        // Check if unified gateway is enabled and controlling child gateways
        $unified_enabled = isset($unified_settings['enabled']) && $unified_settings['enabled'] === 'yes';
        if (!$unified_enabled) {
            return null; // Unified gateway not controlling, use individual gateway settings
        }

        // Build unified settings array (simplified version of get_current_settings)
        return array(
            // General settings
            'enabled' => $unified_enabled,
            'maccount_number' => $unified_settings['maccount_number'] ?? '',
            'test_api_key' => $unified_settings['test_api_key'] ?? '',
            'live_api_key' => $unified_settings['live_api_key'] ?? '',
            'testmode' => $unified_settings['testmode'] ?? 'yes',
            'debug' => $unified_settings['debug'] ?? 'yes',

            // API URLs with defaults
            'monoova_payments_api_url_sandbox' => $unified_settings['monoova_payments_api_url_sandbox'] ?? 'https://api.m-pay.com.au',
            'monoova_payments_api_url_live' => $unified_settings['monoova_payments_api_url_live'] ?? 'https://api.mpay.com.au',
            'monoova_card_api_url_sandbox' => $unified_settings['monoova_card_api_url_sandbox'] ?? 'https://sand-api.monoova.com',
            'monoova_card_api_url_live' => $unified_settings['monoova_card_api_url_live'] ?? 'https://api.monoova.com',

            // Card settings with defaults
            'capture' => isset($card_settings['capture']) && ($card_settings['capture'] === 'no' || $card_settings['capture'] === false) ? 'no' : 'yes',
            'saved_cards' => isset($card_settings['saved_cards']) && ($card_settings['saved_cards'] === 'no' || $card_settings['saved_cards'] === false) ? 'no' : 'yes',
            'apply_surcharge' => isset($card_settings['apply_surcharge']) && ($card_settings['apply_surcharge'] === 'yes' || $card_settings['apply_surcharge'] === true) ? 'yes' : 'no',
            'surcharge_amount' => (float) ($card_settings['surcharge_amount'] ?? 0.0),
            'enable_apple_pay' => isset($card_settings['enable_apple_pay']) && ($card_settings['enable_apple_pay'] === 'no' || $card_settings['enable_apple_pay'] === false) ? 'no' : 'yes',
            'enable_google_pay' => isset($card_settings['enable_google_pay']) && ($card_settings['enable_google_pay'] === 'no' || $card_settings['enable_google_pay'] === false) ? 'no' : 'yes',
            'enable_express_checkout' => isset($card_settings['enable_express_checkout']) && ($card_settings['enable_express_checkout'] === 'yes' || $card_settings['enable_express_checkout'] === true) ? 'yes' : 'no',
            'express_button_color' => $card_settings['express_button_color'] ?? 'primary',
            'express_button_height' => (int) ($card_settings['express_button_height'] ?? 48),
            'express_button_border_radius' => (int) ($card_settings['express_button_border_radius'] ?? 4),
            'order_button_text' => $card_settings['order_button_text'] ?? 'Pay with Card',

            // PayID settings
            'payment_instruction_method' => $payid_settings['payment_instruction_method'] ?? 'generate_unique_details',
            'payment_types' => $payid_settings['payment_types'] ?? array('payid', 'bank_transfer'),
            'expire_hours' => (int) ($payid_settings['expire_hours'] ?? 24),
            'account_name' => $payid_settings['account_name'] ?? get_bloginfo('name'),
            'instructions' => $payid_settings['instructions'] ?? __('Please make your payment using the details below. Your order will be processed once payment is confirmed.', 'monoova-payments-for-woocommerce'),
            'static_payid_name' => $payid_settings['static_payid_name'] ?? '',
            'static_payid_value' => $payid_settings['static_payid_value'] ?? '',
            'static_bank_account_name' => $payid_settings['static_bank_account_name'] ?? '',
            'static_bsb' => $payid_settings['static_bsb'] ?? '',
            'static_account_number' => $payid_settings['static_account_number'] ?? '',
            'static_reference_format' => $payid_settings['static_reference_format'] ?? 'Order {order_number}',
            'payid_order_button_text' => $payid_settings['order_button_text'] ?? 'Proceed to Payment Instructions',

            // PayTo settings
            'payto_title' => $payto_settings['title'] ?? 'PayTo',
            'payto_description' => $payto_settings['description'] ?? 'Approve a payment agreement to complete the purchase.',
            'payto_testmode' => isset($payto_settings['testmode']) && ($payto_settings['testmode'] === 'yes' || $payto_settings['testmode'] === true) ? 'yes' : 'no',
            'payto_debug' => isset($payto_settings['debug']) && ($payto_settings['debug'] === 'no' || $payto_settings['debug'] === false) ? 'no' : 'yes',
            'payto_purpose' => $payto_settings['purpose'] ?? 'OTHR',
            'payto_agreement_expiry_days' => isset($payto_settings['agreement_expiry_days']) ? intval($payto_settings['agreement_expiry_days']) : 0,
            'payto_payee_type' => $payto_settings['payee_type'] ?? 'ORGN',
            'payto_maximum_amount' => isset($payto_settings['maximum_amount']) ? floatval($payto_settings['maximum_amount']) : 1000,
        );
    }

    /**
     * Logging method.
     * Gets debug setting from Unified Gateway.
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    public function log($message, $level = 'debug') {
        $unified_settings = $this->get_unified_gateway_settings();
        $debug = 'yes' === ($unified_settings['debug'] ?? 'no');

        if ($debug) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array('source' => $this->id));
        }
    }

    public function get_icons_for_blocks() {
        // This method can be overridden by child classes to provide specific icons for blocks.
        // For now, we return an empty array as a placeholder.
        return array();
    }

    /**
     * Get payment description for Monoova.
     * To be overridden by child gateways if specific description logic is needed.
     *
     * @param WC_Order|null $order Order object, or null if not in order context.
     * @return string Payment description.
     */
    protected function get_payment_description(?WC_Order $order = null) {
        if ($order) {
            return sprintf(__('Order %s from %s', 'monoova-payments-for-woocommerce'), $order->get_order_number(), get_bloginfo('name'));
        }
        return sprintf(__('Payment from %s', 'monoova-payments-for-woocommerce'), get_bloginfo('name'));
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array Result of payment processing.
     */
    public function process_payment($order_id) {
        // This method will be implemented by child classes (Monoova_Card_Gateway, Monoova_PayID_Gateway)
        // Common logic can be added here if necessary, but typically payment processing is specific.
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log('Error: Could not retrieve order object for order ID: ' . $order_id, 'error');
            wc_add_notice(__('Error processing payment. Invalid order.', 'monoova-payments-for-woocommerce'), 'error');
            return array(
                'result'   => 'failure',
                'redirect' => '',
            );
        }
        return parent::process_payment($order_id);
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page($order_id) {
        // Implemented by child classes if specific thank you page content is needed.
    }

    /**
     * Enqueue payment scripts.
     */
    public function payment_scripts() {
        // Implemented by child classes to enqueue their specific JS and CSS.
        // Common scripts can be enqueued here if needed.
    }

    /**
     * Format amount for Monoova API
     */
    protected function format_amount($amount, $currency) {
        // Monoova expects amounts in the smallest currency unit
        return (int) round($amount * 100);
    }

    /**
     * Get API instance
     */
    public function get_api() {
        if (!isset($this->api)) {
            // API client should be initialized in constructor.
            // If not, try to initialize it now (e.g. if settings were saved after initial load)
            $this->init_api_client();
            if (!$this->api) {
                $this->log('Error: Monoova API client is not available. Check configuration.', 'error');
                return null;
            }
        }
        return $this->api;
    }

    /**
     * Check if payment method should be available at checkout.
     * Gets settings from the Unified Gateway.
     *
     * @return bool
     */
    public function is_available() {
        // First check the parent method (checks enabled status, etc.)
        if (!parent::is_available()) {
            return false;
        }

        // Get settings from Unified Gateway
        $unified_settings = $this->get_unified_gateway_settings();
        if (!$unified_settings) {
            return false;
        }

        // Check if required settings are configured
        $maccount_number = $unified_settings['maccount_number'] ?? '';
        if (empty($maccount_number)) {
            return false;
        }

        $testmode = 'yes' === ($unified_settings['testmode'] ?? 'no');
        $api_key = $testmode ?
            ($unified_settings['test_api_key'] ?? '') : ($unified_settings['live_api_key'] ?? '');

        if (empty($api_key)) {
            return false;
        }

        return true;
    }
}
