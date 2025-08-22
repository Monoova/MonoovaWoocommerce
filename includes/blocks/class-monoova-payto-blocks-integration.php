<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Monoova PayTo Blocks integration
 *
 * @since 1.0.2
 */
final class Monoova_PayTo_Blocks_Integration extends AbstractPaymentMethodType {
    /**
     * The gateway instance.
     *
     * @var Monoova_PayTo_Gateway
     */
    private $gateway;

    /**
     * Payment method name/id/slug.
     *
     * @var string
     */
    protected $name = 'monoova_payto';

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

        // Get the gateway instance
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = $gateways[$this->name] ?? null;
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
     * Logging method - writes to separate log file for payto blocks
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    private function log($message, $level = 'info') {
        if ($this->debug && $this->logger) {
            $formatted_message = '[PayTo Blocks] ' . $message;
            $this->logger->log($level, $formatted_message, array('source' => 'monoova-payto-blocks'));
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
        $asset_path = MONOOVA_PLUGIN_DIR . 'assets/js/build/monoova-payto-block.asset.php';
        $version      = null;
        $dependencies = array();

        if (file_exists($asset_path)) {
            $asset        = require $asset_path;
            $version      = isset($asset['version']) ? $asset['version'] : $version;
            $dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
        }

        wp_register_script(
            'wc-monoova-payto-blocks-integration',
            MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-payto-block.js',
            $dependencies,
            $version,
            true
        );
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

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-monoova-payto-blocks-integration', 'monoova-payments-for-woocommerce');
        }

        return array('wc-monoova-payto-blocks-integration');
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if (!$this->gateway) {
            return [];
        }

        $data = [
            'title'                        => $this->gateway->get_title(),
            'description'                  => $this->gateway->get_description(),
            'supports'                     => $this->gateway->supports,
            'icons'                        => $this->get_icon_urls(),
            'testmode'                     => $this->gateway->testmode,
            'is_available'                 => $this->gateway->is_available(),
            'is_user_logged_in'            => is_user_logged_in(),
            'plugin_url'                   => MONOOVA_PLUGIN_URL,
            'ajax_url'                     => admin_url('admin-ajax.php'),
            'nonce'                        => wp_create_nonce('monoova_payto_nonce'),
            'currency'                     => get_woocommerce_currency(),
            'merchant_name'                => get_bloginfo('name'),
            'maximum_amount'               => $this->gateway->maximum_amount ?? 1000,
            'order_received_url'           => wc_get_endpoint_url('order-received', '', wc_get_checkout_url()),

            // PayTo UI Style Settings
            'payto_checkout_ui_styles' => $this->gateway->get_option('payto_checkout_ui_styles', array(
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
                'submit_button' => array(
                    'font_family' => 'Helvetica, Arial, sans-serif',
                    'font_size' => '17px',
                    'background' => '#2ab5c4',
                    'border_radius' => '10px',
                    'border_color' => '#2ab5c4',
                    'font_weight' => 'bold',
                    'text_color' => '#000000',
                ),
            )),

            'i18n' => array(
                'payto_label'              => __('PayTo', 'monoova-payments-for-woocommerce'),
                'processing'               => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                'generic_error'            => __('An error occurred while processing your payment. Please try again.', 'monoova-payments-for-woocommerce'),
            ),
        ];

        return $data;
    }

    /**
     * Process payment method data from blocks checkout
     *
     * @param array $payment_data Payment data from blocks checkout
     * @return void
     */
    public function process_payment_method_data($payment_data) {
        // Add payment data to $_POST so the gateway can access it
        if (isset($payment_data['payto_payment_method'])) {
            $_POST['payto_payment_method'] = sanitize_text_field($payment_data['payto_payment_method']);
        }
        if (isset($payment_data['payto_payid_type'])) {
            $_POST['payto_payid_type'] = sanitize_text_field($payment_data['payto_payid_type']);
        }
        if (isset($payment_data['payto_payid_value'])) {
            $_POST['payto_payid_value'] = sanitize_text_field($payment_data['payto_payid_value']);
        }
        if (isset($payment_data['payto_account_name'])) {
            $_POST['payto_account_name'] = sanitize_text_field($payment_data['payto_account_name']);
        }
        if (isset($payment_data['payto_bsb'])) {
            $_POST['payto_bsb'] = sanitize_text_field($payment_data['payto_bsb']);
        }
        if (isset($payment_data['payto_account_number'])) {
            $_POST['payto_account_number'] = sanitize_text_field($payment_data['payto_account_number']);
        }
        if (isset($payment_data['payto_maximum_amount'])) {
            $_POST['payto_maximum_amount'] = sanitize_text_field($payment_data['payto_maximum_amount']);
        }
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

        // Add default PayTo icon if needed
        if (empty($icon_urls)) {
            $icon_urls[] = MONOOVA_PLUGIN_URL . 'assets/images/payto-logo.svg';
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
            return ['products', 'checkout']; // Default if gateway not loaded
        }
        // Return features supported by the gateway, defaulting to 'products' and 'checkout'
        $supports = $this->gateway->supports;
        $features = ['products', 'checkout']; // Base features
        if (in_array('subscriptions', $supports)) {
            $features[] = 'subscriptions';
        }
        // Add other features as needed based on $this->gateway->supports
        return $features;
    }
}
