<?php

/**
 * Monoova Unified Payment Gateway
 * 
 * Combines Card and PayID gateways into a single unified interface
 * with React-based admin settings tabs.
 * 
 * @package Monoova_Payments_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova Unified Payment Gateway Class
 */
class Monoova_Unified_Gateway extends Monoova_Gateway {

    /**
     * Gateway ID
     *
     * @var string
     */
    public $id = 'monoova_unified';

    /**
     * Gateway title
     *
     * @var string
     */
    public $method_title = 'Monoova Payments';

    /**
     * Gateway description
     *
     * @var string
     */
    public $method_description = 'Unified Monoova payment gateway supporting Card and PayID/PayTo payments.';

    /**
     * Constructor for the gateway
     */
    public function __construct() {
        $this->icon = apply_filters('woocommerce_monoova_unified_icon', MONOOVA_PLUGIN_URL . 'assets/images/monoova.png');
        $this->has_fields = false; // We'll use React-based admin interface

        // Initialize basic properties before calling parent constructor
        $this->enabled = 'no'; // Default value, will be overridden by load_react_settings
        $this->title = 'Monoova Payments'; // Default value
        $this->description = 'Accept payments via Monoova'; // Default value
        $this->testmode = true; // Default value

        // Call parent constructor
        parent::__construct();

        // Override enabled status to read from React-saved settings
        $this->load_react_settings();

        // Add admin hooks
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));

        // Hide individual gateways from admin payment settings
        add_filter('woocommerce_payment_gateways', array($this, 'filter_admin_payment_gateways_list'), 999);

        // Ensure testmode property is always up-to-date when accessed
        add_action('init', array($this, 'refresh_testmode_property'), 20);
        add_action('woocommerce_admin_settings_sanitize_option', array($this, 'refresh_testmode_property'), 10);
        add_action('woocommerce_settings_checkout', array($this, 'refresh_testmode_property'), 5);
    }

    /**
     * Load settings from React-saved options
     */
    private function load_react_settings() {
        // Get settings saved by React interface
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());

        // Override the enabled status from React settings
        if (isset($unified_settings['enabled'])) {
            $this->enabled = $unified_settings['enabled'];
        } else {
            $this->enabled = 'no'; // Default to disabled if not set
        }

        // Override other basic settings if they exist
        if (isset($unified_settings['title'])) {
            $this->title = $unified_settings['title'];
        } else {
            $this->title = 'Monoova Payments'; // Default title
        }

        if (isset($unified_settings['description'])) {
            $this->description = $unified_settings['description'];
        } else {
            $this->description = 'Accept payments via Monoova'; // Default description
        }

        // Override testmode setting from React settings or derive from child gateways
        $card_settings = get_option('woocommerce_monoova_card_settings', array());
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        $derived_testmode = $this->get_derived_testmode($unified_settings, $card_settings, $payid_settings);
        $this->testmode = 'yes' === $derived_testmode;
    }

    /**
     * Initialize form fields - Override parent to use custom React interface
     */
    public function init_form_fields() {
        // We don't need any form fields since we're using React
        $this->form_fields = array();
    }

    /**
     * Override admin_options to render React interface directly
     * This method is called by WooCommerce to display the settings page
     */
    public function admin_options() {
?>
        <h2><?php esc_html_e('Monoova Payments', 'monoova-payments-for-woocommerce'); ?></h2>
        <p><?php esc_html_e('Unified Monoova payment gateway supporting Card and PayID/PayTo payments.', 'monoova-payments-for-woocommerce'); ?></p>

        <table class="form-table">
            <tr valign="top">
                <td class="forminp">
                    <div id="monoova-payment-settings-container">
                        <p>Loading Monoova Payment Settings...</p>
                        <p style="font-size: 12px; color: #666;">If this message persists, please check the browser console for JavaScript errors.</p>
                    </div>
                </td>
            </tr>
        </table>
<?php
    }

    /**
     * Enqueue admin scripts for React interface
     */
    public function admin_scripts($hook) {
        // Only load on WooCommerce settings pages
        if (!in_array($hook, array('woocommerce_page_wc-settings'))) {
            return;
        }

        // Check if we're on the payments tab with our gateway
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return;
        }

        if (!isset($_GET['section']) || $_GET['section'] !== $this->id) {
            return;
        }

        // Enqueue the built React admin script
        $asset_file = MONOOVA_PLUGIN_DIR . 'assets/js/build/admin-payment-settings.asset.php';

        if (file_exists($asset_file)) {
            $asset = include $asset_file;

            wp_enqueue_script(
                'monoova-admin-payment-settings',
                MONOOVA_PLUGIN_URL . 'assets/js/build/admin-payment-settings.js',
                $asset['dependencies'],
                $asset['version'],
                true
            );

            // Enqueue admin CSS
            wp_enqueue_style(
                'monoova-admin-payment-settings',
                MONOOVA_PLUGIN_URL . 'assets/css/admin-payment-settings.css',
                array(),
                MONOOVA_VERSION
            );

            // Get current settings
            $current_settings = $this->get_current_settings();

            // Localize script with current settings
            wp_localize_script('monoova-admin-payment-settings', 'monoovaAdminSettings', $current_settings);
            // wp_localize_script('monoova-admin-payment-settings', 'monoovaAdminNonce', wp_create_nonce('monoova_save_settings'));
            wp_localize_script('monoova-admin-payment-settings', 'ajaxUrl', admin_url('admin-ajax.php'));
            wp_localize_script('monoova-admin-payment-settings', 'monoovaPluginUrl', MONOOVA_PLUGIN_URL);

            // Add webhook-related nonces
            wp_localize_script('monoova-admin-payment-settings', 'monoovaCheckWebhookSubscriptionsNonce', wp_create_nonce('monoova_check_webhook_subscriptions_nonce'));
            wp_localize_script('monoova-admin-payment-settings', 'monoovaSubscribeWebhookEventsNonce', wp_create_nonce('monoova_subscribe_webhook_events_nonce'));

            // Add automatcher nonce for PayID settings
            wp_localize_script('monoova-admin-payment-settings', 'monoovaGenerateAutomatcherNonce', wp_create_nonce('monoova_generate_automatcher_nonce'));
        }
    }

    /**
     * Get current settings for React interface
     */
    public function get_current_settings() {
        // Get settings from card, payid, and payto gateways
        $card_settings = get_option('woocommerce_monoova_card_settings', array());
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        $payto_settings = get_option('woocommerce_monoova_payto_settings', array());
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());

        return array_merge(
            array(
                // General settings from unified gateway
                'enabled' => (isset($this->enabled) && $this->enabled === 'yes') ? 'yes' : 'no',

                // Payment Methods Tab defaults
                'enable_card_payments' => isset($card_settings['enabled']) && $card_settings['enabled'] === 'yes' ? 'yes' : 'no',
                'enable_payid_payments' => isset($payid_settings['enabled']) && $payid_settings['enabled'] === 'yes' ? 'yes' : 'no',
                'enable_payto_payments' => isset($payto_settings['enabled']) && $payto_settings['enabled'] === 'yes' ? 'yes' : 'no',
                'enable_express_checkout' => isset($card_settings['enable_express_checkout']) && ($card_settings['enable_express_checkout'] === 'yes' || $card_settings['enable_express_checkout'] === true) ? 'yes' : 'no',
                'enable_payto_express_checkout' => isset($payto_settings['enable_payto_express_checkout']) && ($payto_settings['enable_payto_express_checkout'] === 'yes' || $payto_settings['enable_payto_express_checkout'] === true) ? 'yes' : 'no',
                'express_checkout_method_priority' => isset($unified_settings['express_checkout_method_priority']) ? $unified_settings['express_checkout_method_priority'] : 'card',

                // Card Settings Tab (including title and description)
                'card_title' => !empty($card_settings['title']) ? $card_settings['title'] : 'Credit / Debit Card',
                'card_description' => !empty($card_settings['description']) ? $card_settings['description'] : 'Accept payments via Mastercard, Visa, Apple pay and Google pay',
                'card_testmode' => isset($card_settings['testmode']) && ($card_settings['testmode'] === 'yes' || $card_settings['testmode'] === true) ? 'yes' : 'no',
                'card_debug' => isset($card_settings['debug']) && ($card_settings['debug'] === 'no' || $card_settings['debug'] === false) ? 'no' : 'yes',
                'capture' => isset($card_settings['capture']) && ($card_settings['capture'] === 'no' || $card_settings['capture'] === false) ? 'no' : 'yes',
                'saved_cards' => isset($card_settings['saved_cards']) && ($card_settings['saved_cards'] === 'no' || $card_settings['saved_cards'] === false) ? 'no' : 'yes',
                'apply_surcharge' => isset($card_settings['apply_surcharge']) && ($card_settings['apply_surcharge'] === 'yes' || $card_settings['apply_surcharge'] === true) ? 'yes' : 'no',
                'surcharge_amount' => isset($card_settings['surcharge_amount']) ? floatval($card_settings['surcharge_amount']) : 0.0,
                'enable_apple_pay' => isset($card_settings['enable_apple_pay']) && ($card_settings['enable_apple_pay'] === 'no' || $card_settings['enable_apple_pay'] === false) ? 'no' : 'yes',
                'enable_google_pay' => isset($card_settings['enable_google_pay']) && ($card_settings['enable_google_pay'] === 'no' || $card_settings['enable_google_pay'] === false) ? 'no' : 'yes',
                'order_button_text' => isset($card_settings['order_button_text']) ? $card_settings['order_button_text'] : 'Pay with Card',

                // Checkout UI Style Settings
                'checkout_ui_styles' => isset($card_settings['checkout_ui_styles']) ? $card_settings['checkout_ui_styles'] : array(
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
                ),

                // PayID Settings Tab (including title and description)
                'payid_title' => !empty($payid_settings['title']) ? $payid_settings['title'] : 'PayID / Bank Transfer',
                'payid_description' => !empty($payid_settings['description']) ? $payid_settings['description'] : 'Secure payments from your bank account using PayID or bank transfer',
                'payid_testmode' => isset($payid_settings['testmode']) && ($payid_settings['testmode'] === 'yes' || $payid_settings['testmode'] === true) ? 'yes' : 'no',
                'payid_debug' => isset($payid_settings['debug']) && ($payid_settings['debug'] === 'no' || $payid_settings['debug'] === false) ? 'no' : 'yes',
                'payment_instruction_method' => isset($payid_settings['payment_instruction_method']) ? $payid_settings['payment_instruction_method'] : 'generate_unique_details',
                'static_payid_name' => isset($payid_settings['static_payid_name']) ? $payid_settings['static_payid_name'] : '',
                'static_payid_value' => isset($payid_settings['static_payid_value']) ? $payid_settings['static_payid_value'] : '',
                'static_bank_account_name' => isset($payid_settings['static_bank_account_name']) ? $payid_settings['static_bank_account_name'] : '',
                'static_bsb' => isset($payid_settings['static_bsb']) ? $payid_settings['static_bsb'] : '',
                'static_account_number' => isset($payid_settings['static_account_number']) ? $payid_settings['static_account_number'] : '',
                'static_reference_format' => isset($payid_settings['static_reference_format']) ? $payid_settings['static_reference_format'] : 'Order {order_number}',
                'payment_types' => isset($payid_settings['payment_types']) ? $payid_settings['payment_types'] : array('payid', 'bank_transfer'),
                'expire_hours' => isset($payid_settings['expire_hours']) ? intval($payid_settings['expire_hours']) : 24,
                'account_name' => isset($payid_settings['static_bank_account_name']) ? $payid_settings['static_bank_account_name'] : get_bloginfo('name'),
                'instructions' => isset($payid_settings['instructions']) ? $payid_settings['instructions'] : '',
                'payid_show_reference_field' => isset($payid_settings['payid_show_reference_field']) && ($payid_settings['payid_show_reference_field'] === 'yes' || $payid_settings['payid_show_reference_field'] === true) ? 'yes' : 'no',

                // PayTo Settings Tab (including title and description)
                'payto_title' => !empty($payto_settings['title']) ? $payto_settings['title'] : 'PayTo',
                'payto_description' => !empty($payto_settings['description']) ? $payto_settings['description'] : 'Approve a payment agreement to complete the purchase.',
                'payto_testmode' => isset($payto_settings['testmode']) && ($payto_settings['testmode'] === 'yes' || $payto_settings['testmode'] === true) ? 'yes' : 'no',
                'payto_debug' => isset($payto_settings['debug']) && ($payto_settings['debug'] === 'no' || $payto_settings['debug'] === false) ? 'no' : 'yes',
                'payto_purpose' => isset($payto_settings['purpose']) ? $payto_settings['purpose'] : 'OTHR',
                'payto_agreement_expiry_days' => isset($payto_settings['agreement_expiry_days']) ? intval($payto_settings['agreement_expiry_days']) : 0,
                'payto_payee_type' => isset($payto_settings['payee_type']) ? $payto_settings['payee_type'] : 'ORGN',
                'payto_maximum_amount' => isset($payto_settings['maximum_amount']) ? floatval($payto_settings['maximum_amount']) : 1000,

                // Common settings from unified gateway settings
                'testmode' => $this->get_derived_testmode($unified_settings, $card_settings, $payid_settings, $payto_settings),
                'debug' => $unified_settings['debug'] ?? 'yes',
                'maccount_number' => $unified_settings['maccount_number'] ?? '',
                'test_api_key' => $unified_settings['test_api_key'] ?? '',
                'live_api_key' => $unified_settings['live_api_key'] ?? '',
                'monoova_payments_api_url_sandbox' => $unified_settings['monoova_payments_api_url_sandbox'] ?? 'https://api.m-pay.com.au',
                'monoova_payments_api_url_live' => $unified_settings['monoova_payments_api_url_live'] ?? 'https://api.mpay.com.au',
                'monoova_card_api_url_sandbox' => $unified_settings['monoova_card_api_url_sandbox'] ?? 'https://sand-api.monoova.com',
                'monoova_card_api_url_live' => $unified_settings['monoova_card_api_url_live'] ?? 'https://api.monoova.com',
            ),
            $unified_settings
        );
    }

    /**
     * Process payment - This gateway is for admin interface only
     */
    public function process_payment($order_id) {
        // This unified gateway is for admin settings only
        // Actual payments are processed by the individual card/payid gateways
        return array('result' => 'failure', 'redirect' => '');
    }

    /**
     * Check if gateway is available
     */
    public function is_available() {
        // This gateway is only for admin settings, not for customer checkout
        return false;
    }

    /**
     * Filter payment gateways to hide individual Card and PayID gateways from admin settings
     * This is the proper WordPress way to control gateway visibility in admin
     */
    public function filter_admin_payment_gateways_list($gateways) {
        // Only filter if user has WooCommerce management permissions and we're on payment settings page
        if (!current_user_can('manage_woocommerce')) {
            return $gateways;
        }

        // Check if we're on WooCommerce payment settings page
        global $pagenow;
        if ($pagenow !== 'admin.php') {
            return $gateways;
        }

        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return $gateways;
        }

        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return $gateways;
        }

        // Don't filter if we're specifically on a child gateway's settings page
        // (this allows the redirect to work)
        if (isset($_GET['section']) && ($_GET['section'] === 'monoova_card' || $_GET['section'] === 'monoova_payid' || $_GET['section'] === 'monoova_payto')) {
            return $gateways;
        }

        // Remove individual gateways from admin settings list
        foreach ($gateways as $key => $gateway_class) {
            if (is_string($gateway_class)) {
                if ($gateway_class === 'Monoova_Card_Gateway' || $gateway_class === 'Monoova_PayID_Gateway' || $gateway_class === 'Monoova_PayTo_Gateway') {
                    unset($gateways[$key]);
                }
            } elseif (is_object($gateway_class)) {
                if ($gateway_class instanceof Monoova_Card_Gateway || $gateway_class instanceof Monoova_PayID_Gateway || $gateway_class instanceof Monoova_PayTo_Gateway) {
                    unset($gateways[$key]);
                }
            }
        }

        return $gateways;
    }

    /**
     * Check if this unified gateway is enabled and controlling child gateways
     */
    public function is_controlling_child_gateways() {
        return isset($this->enabled) && $this->enabled === 'yes';
    }

    /**
     * Check if a specific child gateway should be available based on React settings
     */
    public function is_child_gateway_enabled($gateway_type) {
        if (!$this->is_controlling_child_gateways()) {
            return true; // If unified gateway is disabled, child gateways are controlled individually
        }

        // Get the current React-based settings
        $current_settings = $this->get_current_settings();

        // Check the enable setting from React component
        $enable_key = 'enable_' . $gateway_type . '_payments';

        // When unified gateway is controlling children, be strict about enabled state
        // Only show child gateways if explicitly enabled in React settings
        if (!is_array($current_settings)) {
            return false; // No settings loaded, hide child gateways
        }

        return isset($current_settings[$enable_key]) && $current_settings[$enable_key] === 'yes';
    }

    /**
     * Get instance of this gateway for checking from other gateways
     */
    public static function get_instance() {
        static $instance = null;
        if (null === $instance) {
            $instance = new self();
        }
        return $instance;
    }

    /**
     * Refresh testmode property to ensure it's always up-to-date
     */
    public function refresh_testmode_property() {
        $card_settings = get_option('woocommerce_monoova_card_settings', array());
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        $payto_settings = get_option('woocommerce_monoova_payto_settings', array());
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
        $derived_testmode = $this->get_derived_testmode($unified_settings, $card_settings, $payid_settings, $payto_settings);
        $this->testmode = 'yes' === $derived_testmode;
    }

    /**
     * Override get_option to return derived testmode value
     */
    public function get_option($key, $empty_value = null) {
        if ($key === 'testmode') {
            // Return derived testmode value
            $card_settings = get_option('woocommerce_monoova_card_settings', array());
            $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
            $payto_settings = get_option('woocommerce_monoova_payto_settings', array());
            $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
            return $this->get_derived_testmode($unified_settings, $card_settings, $payid_settings, $payto_settings);
        }

        return parent::get_option($key, $empty_value);
    }

    /**
     * Derive testmode setting from unified settings or child gateway settings
     */
    private function get_derived_testmode($unified_settings, $card_settings, $payid_settings, $payto_settings = array()) {
        // Ensure all parameters are arrays to prevent errors
        $unified_settings = is_array($unified_settings) ? $unified_settings : array();
        $card_settings = is_array($card_settings) ? $card_settings : array();
        $payid_settings = is_array($payid_settings) ? $payid_settings : array();
        $payto_settings = is_array($payto_settings) ? $payto_settings : array();

        // First check if unified settings has testmode
        if (isset($unified_settings['testmode'])) {
            return $unified_settings['testmode'];
        }

        // If card has testmode settings, use card's setting as primary
        if (isset($card_settings['testmode'])) {
            return $card_settings['testmode'] === 'yes' ? 'yes' : 'no';
        }

        // Fallback to payid testmode
        if (isset($payid_settings['testmode'])) {
            return $payid_settings['testmode'] === 'yes' ? 'yes' : 'no';
        }

        // Fallback to payto testmode
        if (isset($payto_settings['testmode'])) {
            return $payto_settings['testmode'] === 'yes' ? 'yes' : 'no';
        }

        // Default to 'yes' for development/testing
        return 'yes';
    }
}
