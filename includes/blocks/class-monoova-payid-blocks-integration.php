<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Monoova PayID Blocks integration
 *
 * @since 1.1.2
 */
final class Monoova_PayID_Blocks_Integration extends AbstractPaymentMethodType {

    /**
     * The gateway instance.
     *
     * @var Monoova_PayID_Gateway
     */
    private $gateway;

    /**
     * Payment method name/id, must match the gateway ID.
     *
     * @var string
     */
    protected $name = 'monoova_payid'; // Must match the ID of the Monoova_PayID_Gateway

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
        $this->settings = get_option('woocommerce_monoova_payid_settings', []);

        // Initialize debug mode and logger
        $this->init_debug_mode();

        // Get the gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();

        $this->gateway = $gateways[$this->name] ?? null;
    }

    /**
     * Initialize debug mode from gateway settings
     */
    private function init_debug_mode() {
        // Check debug setting from payid gateway
        if (isset($this->settings['debug']) && $this->settings['debug'] === 'yes') {
            $this->debug = true;
        }

        // Initialize logger if debug is enabled
        if ($this->debug) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Logging method - writes to separate log file for payid blocks
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    private function log($message, $level = 'info') {
        if ($this->debug && $this->logger) {
            $formatted_message = '[PayID Blocks] ' . $message;
            $this->logger->log($level, $formatted_message, array('source' => 'monoova-payid-blocks'));
        }
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        return !empty($this->gateway) && $this->gateway->is_available();
    }

    /**
     * Returns an array of script handles to enqueue for this payment method in the frontend context.
     *
     * @return string[]
     */
    public function get_payment_method_script_handles() {
        $asset_path = MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-payid-block.asset.php';
        $version      = null;
        $dependencies = array();
        if (file_exists($asset_path)) {
            $asset = require($asset_path);
            $version = $asset['version'];
            $dependencies = $asset['dependencies'];
        }
        // Ensure WordPress components CSS is enqueued
        wp_enqueue_style(
            'wp-components',
            includes_url('css/dist/components/style.min.css'),
            array(),
            get_bloginfo('version')
        );
        // Register and enqueue CSS for PayID instructions
        wp_register_style(
            'monoova-payid-instructions-blocks',
            MONOOVA_PLUGIN_URL . 'assets/css/payid-instructions-blocks.css',
            array(),
            $version
        );
        wp_enqueue_style('monoova-payid-instructions-blocks');

        // The main script is already registered by the main plugin class
        // We just need to register our blocks integration script that will use it
        wp_register_script(
            'wc-monoova-payid-blocks-integration',
            MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-payid-block.js',
            $dependencies,
            $version,
            true
        );

        // Data is now provided via get_payment_method_data() method instead of wp_localize_script

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-monoova-payid-blocks-integration', 'monoova-payments-for-woocommerce');
        }

        return array('wc-monoova-payid-blocks-integration');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if (!$this->gateway) {
            return array();
        }

        // Get unified gateway settings
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
        
        // Get gateway settings safely
        $is_test = 'yes' === $this->gateway->get_option('testmode', 'no');

        $data = [
            'title' => $this->gateway->get_title(),
            'description' => $this->gateway->get_option('description', ''),
            'supports' => array_filter($this->gateway->supports, [$this, 'filter_gateway_supports']),
            'icons' => $this->get_icon_urls(),
            'debug' => $this->debug,
            'testmode' => $is_test,
            'plugin_url' => MONOOVA_PLUGIN_URL,
            
            // PayID-specific data for block checkout
            'ajax_url' => admin_url('admin-ajax.php'),
            'generate_instructions_nonce' => wp_create_nonce('monoova_generate_instructions'),
            'create_order_nonce' => wp_create_nonce('woocommerce-process_checkout'),
            'reset_payment_status_nonce' => wp_create_nonce('monoova_reset_payment_status'),
            'payment_types' => $unified_settings['payment_types'] ?? ['payid', 'bank_transfer'],
            'show_reference_field' => ($unified_settings['payid_show_reference_field'] ?? 'yes') === 'yes',
            'instructions'             => $this->gateway->get_option( 'instructions' ),

            // PayID UI Style Settings
            'payid_checkout_ui_styles' => $this->gateway->get_option('payid_checkout_ui_styles', array(
                'scan_pay' => array(
                    'pay_label' => array(
                        'font_size' => '28px',
                        'font_weight' => '700',
                        'color' => '#000000',
                    ),
                    'amount' => array(
                        'font_size' => '28px',
                        'font_weight' => '700',
                        'color' => '#2CB5C5',
                    ),
                ),
            )),
            'strings'                  => [
                'payment_instructions'          => __( 'Payment Instructions', 'monoova-payments-for-woocommerce' ),
                'pay_with'                      => __( 'Pay with', 'monoova-payments-for-woocommerce' ),
                'payment_instructions_description' => __( 'Please make your payment using the details below. Your order will be processed once payment is confirmed.', 'monoova-payments-for-woocommerce' ),
                'scan_pay'                      => __( 'Pay', 'monoova-payments-for-woocommerce' ),
                'scan_pay_description'          => __( 'Make your payment easily from your banking app', 'monoova-payments-for-woocommerce' ),
                'reference'                     => __( 'Reference', 'monoova-payments-for-woocommerce' ),
                'or_divider'                    => __( 'Or Pay From Your Internet Banking', 'monoova-payments-for-woocommerce' ),
                'payid_method'                  => __( 'PayID', 'monoova-payments-for-woocommerce' ),
                'bank_method'                   => __( 'Bank Transfer', 'monoova-payments-for-woocommerce' ),
                'bank_transfer_details'         => __( 'Pay via Bank Transfer', 'monoova-payments-for-woocommerce' ),
                'account_name'                  => __( 'Account Name', 'monoova-payments-for-woocommerce' ),
                'bsb'                           => __( 'BSB', 'monoova-payments-for-woocommerce' ),
                'account_number'                => __( 'Account Number', 'monoova-payments-for-woocommerce' ),
                'amount'                        => __( 'Amount', 'monoova-payments-for-woocommerce' ),
                'payment_reference'             => __( 'Payment Reference', 'monoova-payments-for-woocommerce' ),
                'include_reference_with_payment' => __( 'Please use the following reference for your payment', 'monoova-payments-for-woocommerce' ),
                'payment_confirmed'             => __( 'Payment Received', 'monoova-payments-for-woocommerce' ),
                'payment_confirmed_message'     => __( 'Thank you! Your payment has been confirmed and your order is now being processed.', 'monoova-payments-for-woocommerce' ),
                'view_order_details'            => __( 'View Order Details', 'monoova-payments-for-woocommerce' ),
                'payment_expired'               => __( 'Payment Expired', 'monoova-payments-for-woocommerce' ),
                'payment_expired_message'       => __( 'The payment window for this order has expired. Please place a new order.', 'monoova-payments-for-woocommerce' ),
                'place_new_order'               => __( 'Place New Order', 'monoova-payments-for-woocommerce' ),
                'payment_failed'                => __( 'Payment Issue', 'monoova-payments-for-woocommerce' ),
                'payment_failed_message'        => __( 'There was an issue with your payment. Please check your bank account or contact us for assistance.', 'monoova-payments-for-woocommerce' ),
                'try_again'                     => __( 'Try Again', 'monoova-payments-for-woocommerce' ),
                'expires_in'                    => __( 'It\'s valid until: ', 'monoova-payments-for-woocommerce' ),
                'payment_confirmed_automatically' => __( 'You will receive a payment confirmation email once payment is received.', 'monoova-payments-for-woocommerce' ),
                'redirecting_to_order_page'     => __( 'You are being redirected to the Order Received page in {countdown} seconds...', 'monoova-payments-for-woocommerce' ),
            ],
            'checkout_url' => wc_get_checkout_url(),
            'order_received_url' => wc_get_endpoint_url('order-received', '', wc_get_checkout_url()),
        ];

        return $data;
    }

    /**
     * Get URLs for the payment method icons.
     *
     * @return array
     */
    private function get_icon_urls() {
        $icon_urls = [];
        
        // Check if the gateway has a get_icons_for_blocks method
        if (method_exists($this->gateway, 'get_icons_for_blocks')) {
            $icons = $this->gateway->get_icons_for_blocks();
            foreach ($icons as $icon) {
                if (isset($icon['src'])) {
                    $icon_urls[] = $icon['src'];
                }
            }
        } elseif (method_exists($this->gateway, 'get_icon') && !empty($this->gateway->get_icon())) {
            preg_match_all('/src="([^"]+)"/i', $this->gateway->get_icon(), $matches);
            if (!empty($matches[1])) {
                $icon_urls = $matches[1];
            } else {
                $icon_html = $this->gateway->get_icon();
                if (filter_var($icon_html, FILTER_VALIDATE_URL)) {
                    $icon_urls[] = $icon_html;
                }
            }
        }
        
        // Add default PayID icon if needed
        if (empty($icon_urls)) {
            $icon_urls[] = MONOOVA_PLUGIN_URL . 'assets/images/bank-transfer.png';
        }
        return $icon_urls;
    }

    /**
	 * Returns an array of script handles to be enqueued for the admin.
	 * 
	 * Include this if your payment method has a script you _only_ want to load in the editor context for the checkout block. 
	 * Include here any script from `get_payment_method_script_handles` that is also needed in the admin.
	 */
	public function get_payment_method_script_handles_for_admin() {
        // Also enqueue CSS for admin/editor
        wp_enqueue_style('monoova-payid-instructions-blocks');
		return $this->get_payment_method_script_handles();
	}

    /**
     * Returns an array of supported features.
     *
     * @return array
     */
    public function get_supported_features() {
        // Ensure the gateway instance is available
        if (!$this->gateway) {
            return ['products'];
        }
        // Return features supported by the gateway, defaulting to 'products' and 'checkout'
        // Specific features like 'subscriptions' might depend on gateway capabilities
        $supports = $this->gateway->supports;
        $features = ['products', 'checkout']; // Base features
        if (in_array('subscriptions', $supports)) {
            $features[] = 'subscriptions';
        }
        if (in_array('tokenization', $supports)) {
            $features[] = 'tokenization';
        }
        // Add other features as needed based on $this->gateway->supports
        return $features;
    }

    /**
     * Filter gateway supports array to only include valid block features
     */
    private function filter_gateway_supports($support) {
        $valid_supports = ['products', 'refunds', 'subscriptions', 'tokenization'];
        return in_array($support, $valid_supports);
    }
}
