<?php

/**
 * Monoova PayID Gateway
 * 
 * Handles PayID payment processing for WooCommerce orders.
 * This gateway allows customers to pay using PayID, a simple way to make payments
 * using a unique identifier (like an email or phone number), or via direct bank transfer.
 * 
 * @package Monoova_Payments_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova PayID Payment Gateway
 */
class Monoova_PayID_Gateway extends Monoova_Gateway {

    /**
     * Gateway ID
     *
     * @var string
     */
    public $id = 'monoova_payid';

    /**
     * Gateway title
     *
     * @var string
     */
    public $method_title = 'PayID / Bank Transfer'; // Updated for clarity

    /**
     * Gateway description
     *
     * @var string
     */
    public $method_description = 'Accept payments via PayID or Direct Bank Transfer'; // Updated

    /**
     * Supported features
     *
     * @var array
     */
    public $supports = array(
        'products',
        'refunds',
        // Tokenization and subscriptions are less common for pure PayID/Bank Transfer
        // but can be kept if your specific Monoova setup supports linking these.
        // For now, focusing on core payment and refund.
    );

    public $debug = true; // Default debug mode
    public $testmode = true; // Default to test mode

    /**
     * Constructor for the gateway
     */
    public function __construct() {
        $this->icon               = apply_filters('woocommerce_monoova_payid_icon', MONOOVA_PLUGIN_URL . 'assets/images/monoova.png');
        $this->has_fields         = false; // PayID typically shows instructions after order placement, not input fields at checkout.

        // Call parent constructor (Monoova_Gateway::__construct)
        parent::__construct(); // This initializes $this->api

        // Initialize PayID-specific settings from Unified Gateway
        $this->init_payid_settings();

        add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        add_action('wp_ajax_monoova_check_payment_status', array($this, 'ajax_check_payment_status'));
        add_action('wp_ajax_nopriv_monoova_check_payment_status', array($this, 'ajax_check_payment_status'));

        add_action('wp_ajax_monoova_generate_payment_instructions_in_blocked_checkout', array($this, 'ajax_generate_payment_instructions_in_blocked_checkout'));
        add_action('wp_ajax_nopriv_monoova_generate_payment_instructions_in_blocked_checkout', array($this, 'ajax_generate_payment_instructions_in_blocked_checkout'));

        add_action('wp_ajax_monoova_reset_payment_status', array($this, 'ajax_reset_payment_status'));
        add_action('wp_ajax_nopriv_monoova_reset_payment_status', array($this, 'ajax_reset_payment_status'));

        add_action('admin_init', array($this, 'maybe_redirect_to_unified_gateway'));

        // Add actions for our scheduled reconciliation task
        //add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        //add_action('monoova_reconciliation_cron_hook', array($this, 'run_scheduled_reconciliation'));

        //if (!wp_next_scheduled('monoova_reconciliation_cron_hook')) {
        //    wp_schedule_event(time(), 'every_fifteen_minutes', 'monoova_reconciliation_cron_hook');
        //}
    }

    /**
     * Initialize PayID-specific settings from Unified Gateway
     */
    private function init_payid_settings() {
        $unified_settings = $this->get_unified_gateway_settings();

        if (!$unified_settings) {
            // Fallback to default values if unified settings not available
            $this->order_button_text = __('Proceed to Payment Instructions', 'monoova-payments-for-woocommerce');
            $this->debug = true; // Default to debug enabled
            $this->testmode = true; // Default to test mode
            return;
        }

        // Initialize properties from unified settings with defaults
        $this->order_button_text = $unified_settings['payid_order_button_text'] ?? __('Proceed to Payment Instructions', 'monoova-payments-for-woocommerce');
        $this->debug = 'yes' === ($unified_settings['debug'] ?? 'yes');
        $this->testmode = 'yes' === ($unified_settings['testmode'] ?? 'yes');
    }

    /**
     * Redirect to unified gateway if user tries to access individual gateway settings
     */
    public function maybe_redirect_to_unified_gateway() {
        if (!current_user_can('manage_woocommerce') || !isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return;
        }
        if (!isset($_GET['section']) || $_GET['section'] !== $this->id) {
            return;
        }
        $redirect_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_unified');
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Get the title for the payment method.
     */
    public function get_title() {
        // Title is managed by Monoova_Unified_Gateway settings if active
        if (class_exists('Monoova_Unified_Gateway')) {
            $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
            if (isset($unified_settings['enable_payid_payments']) && $unified_settings['enable_payid_payments'] === 'yes' && !empty($unified_settings['payid_title'])) {
                return $unified_settings['payid_title'];
            }
        }
        // Fallback to own settings or default
        $title = $this->get_option('title');
        return !empty($title) ? $title : $this->method_title;
    }

    /**
     * Get the description for the payment method.
     */
    public function get_icon() {
        $icon = '<img src="' . esc_url(MONOOVA_PLUGIN_URL . 'assets/images/payid-logo.svg') . '" alt="Monoova PayID Payment Options" style="height: 20px;" />';
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    /**
     * Get the order button text with proper translation.
     *
     * @return string Order button text.
     */
    public function get_order_button_text() {
        return $this->order_button_text ?: __('Proceed to Payment Instructions', 'monoova-payments-for-woocommerce');
    }

    /**
     * Get icons formatted for blocks and frontend display
     */
    public function get_icons_for_blocks() {
        $icons = array();
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
        $payment_types = isset($unified_settings['payment_types']) ? $unified_settings['payment_types'] : array('payid', 'bank_transfer');

        if (in_array('payid', $payment_types)) {
            $icons[] = array(
                'id' => 'payid',
                'src' => MONOOVA_PLUGIN_URL . 'assets/images/payid-logo.png',
                'alt' => __('PayID', 'monoova-payments-for-woocommerce')
            );
        }
        if (in_array('bank_transfer', $payment_types)) {
            $icons[] = array(
                'id' => 'bank-transfer',
                'src' => MONOOVA_PLUGIN_URL . 'assets/images/bank-transfer.png',
                'alt' => __('Bank Transfer', 'monoova-payments-for-woocommerce')
            );
        }

        if (empty($icons)) { // Fallback
            $icons[] = array(
                'id' => 'default-bank',
                'src' => MONOOVA_PLUGIN_URL . 'assets/images/bank-transfer.png',
                'alt' => __('Bank Payment', 'monoova-payments-for-woocommerce')
            );
        }
        return $icons;
    }

    /**
     * Process the payment using the Static Automatcher with Dynamic PayID strategy.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $this->log("Processing payment for order #{$order_id} by creating a reconciliation rule.");

        try {
            // Generate reconciliation rules and get instruction arguments.
            $this->generate_reconciliation_rules_in_blocked_checkout($order, true);

            // The rest of the process is handled within the generate_reconciliation_rules_in_blocked_checkout method,
            // including updating order status and meta.

            wc_reduce_stock_levels($order_id);
            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            $this->log("Payment processing exception for order #{$order_id}: " . $e->getMessage(), 'error');
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * Output for the order received page.
     */
    public function thankyou_page($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== $this->id) {
            return;
        }

        $unified_settings = $this->get_unified_gateway_settings();
        $args = $this->prepare_payment_instructions_args($order, $unified_settings);

        // Pass details to the template.
        wc_get_template(
            'checkout/monoova-payid-instructions.php',
            $args,
            '',
            MONOOVA_PLUGIN_DIR . 'templates/'
        );
    }

    /**
     * Display payment fields on checkout page.
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        if ($this->testmode) {
            echo '<div class="monoova-test-mode-notice" style="background-color: #fff8e1; border-left: 4px solid #ffc107; padding: 10px; margin-bottom: 15px;">';
            echo '<p style="margin:0;">' . esc_html__('TEST MODE: This payment method is in test mode. No real payments will be processed.', 'monoova-payments-for-woocommerce') . '</p>';
            echo '</div>';
        }
    }

    /**
     * Process a refund.
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log("PayID Refund error: Could not retrieve order object for order ID: $order_id", 'error');
            return new WP_Error('monoova_payid_refund_error', __('Invalid order ID for refund.', 'monoova-payments-for-woocommerce'));
        }

        $this->log("Attempting refund for order #{$order_id}. Amount: {$amount}. Reason: {$reason}.", 'info');

        // The original transaction ID for the payment might be stored if the API provided one.
        $original_txn_id = $order->get_meta('_monoova_transaction_id', true);
        $payment_status = $order->get_meta('_monoova_payment_status', true);

        if ($payment_status !== 'completed') {
            $this->log("PayID Refund error: Order #{$order_id} is not in a refundable state (current status: {$payment_status}).", 'error');
            return new WP_Error('monoova_payid_refund_error', __('Order is not eligible for refund.', 'monoova-payments-for-woocommerce'));
        }

        if (empty($original_txn_id)) {
            $this->log("PayID Refund error: No original transaction reference found for order #{$order_id}.", 'error');
            return new WP_Error('monoova_payid_refund_error', __('Original transaction reference not found for refund.', 'monoova-payments-for-woocommerce'));
        }

        $payload = array(
            'refundAmount' => $this->format_amount($amount, $order->get_currency()),
            "originalTransactionId" => $original_txn_id, // Use the original transaction ID or client unique ID
            'description' => !empty($reason) ? $reason : sprintf(__('Refund#%s', 'monoova-payments-for-woocommerce'), $order->get_order_number()),
            'uniqueReference' => 'REF' . $order_id . 'T' . time(), // Unique ID for this refund transaction
        );

        $this->log("Refund payload for order #{$order_id}: " . wp_json_encode($payload), 'debug');
        $response = $this->get_api()->process_receivables_refund($payload);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->log("PayID Refund API error for order #{$order_id}: " . $error_message, 'error');
            $order->add_order_note(sprintf(__('Monoova refund failed. Error: %s', 'monoova-payments-for-woocommerce'), $error_message));
            return $response;
        }

        $this->log("PayID Refund API response for order #{$order_id}: " . wp_json_encode($response), 'debug');

        if (isset($response['status']) && strtolower($response['status']) === 'ok') {
            $refund_id = $response['transactionId'];
            $order->add_order_note(
                sprintf(
                    __('Monoova refund processed. Amount: %s. Refund ID: %s. Status: %s', 'monoova-payments-for-woocommerce'),
                    wc_price($amount, array('currency' => $order->get_currency())),
                    esc_html($refund_id),
                    esc_html($response['status'])
                )
            );
            // Store refund transaction ID if needed
            $order->update_meta_data('_monoova_refund_id_' . time(), $refund_id);
            $order->update_meta_data('_monoova_refund_status_' . time(), $response['status']);
            return true;
        } else {
            $failure_reason = isset($response['statusDescription']) ? $response['statusDescription'] : (isset($response['message']) ? $response['message'] : __('Unknown error', 'monoova-payments-for-woocommerce'));
            $this->log("PayID Refund failed for order #{$order_id}. Reason: " . $failure_reason, 'error');
            $order->add_order_note(sprintf(__('Monoova refund request failed. Reason: %s', 'monoova-payments-for-woocommerce'), $failure_reason));
            return new WP_Error('monoova_payid_refund_failed', $failure_reason);
        }
    }

    /**
     * Get API key based on current mode (test/live)
     */
    public function get_api_key() {
        // Get settings from unified gateway
        $unified_settings = $this->get_unified_gateway_settings();
        if (!$unified_settings) {
            return '';
        }

        // Return appropriate API key based on testmode
        $is_testmode = isset($unified_settings['testmode']) && $unified_settings['testmode'] === 'yes';
        return $is_testmode ? ($unified_settings['test_api_key'] ?? '') : ($unified_settings['live_api_key'] ?? '');
    }

    /**
     * Load frontend scripts for PayID payment method
     */
    public function payment_scripts() {
        if (!is_checkout() && !is_add_payment_method_page() && !isset($_GET['pay_for_order']) && !is_wc_endpoint_url('order-received')) {
            return;
        }

        if ('no' === $this->enabled) {
            return;
        }

        // Enqueue common frontend CSS
        wp_enqueue_style(
            'monoova-frontend-styles',
            MONOOVA_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            MONOOVA_VERSION
        );

        // Enqueue QR Code generation library
        wp_enqueue_script(
            'monoova-qrcode',
            'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
            array(),
            MONOOVA_VERSION,
            true
        );

        wp_enqueue_script(
            'monoova-payid-thankyou',
            MONOOVA_PLUGIN_URL . 'assets/js/build/monoova-payid-thankyou.js',
            array('jquery', 'monoova-qrcode'),
            MONOOVA_VERSION,
            true
        );
    }

    /**
     * Admin options - managed by unified gateway.
     */
    public function admin_options() {
?>
        <div class="notice notice-info inline">
            <p>
                <strong><?php esc_html_e('PayID / Bank Transfer Settings', 'monoova-payments-for-woocommerce'); ?></strong><br>
                <?php
                printf(
                    esc_html__('These settings are managed within the %1$sMonoova Payments unified settings page%2$s.', 'monoova-payments-for-woocommerce'),
                    '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_unified')) . '">',
                    '</a>'
                );
                ?>
            </p>
        </div>
<?php
    }

    /**
     * Check if payment method should be available at checkout.
     */
    public function is_available() {
        // Check if controlled by unified gateway
        if (class_exists('Monoova_Unified_Gateway')) {
            $unified_gateway = Monoova_Unified_Gateway::get_instance();
            if ($unified_gateway && $unified_gateway->is_controlling_child_gateways()) {
                // If unified gateway is controlling, check if this gateway is enabled in unified settings
                // and use unified gateway's availability logic
                if (!$unified_gateway->is_child_gateway_enabled('payid')) {
                    return false;
                }

                // Use unified gateway's settings for availability check
                return $this->is_available_with_unified_settings($unified_gateway);
            }
        }

        // If unified gateway is not controlling, use standard availability check
        if (!parent::is_available()) {
            return false;
        }

        return true;
    }

    /**
     * Check availability using unified gateway settings
     */
    private function is_available_with_unified_settings($unified_gateway) {
        // Check basic WooCommerce availability (enabled status, etc.)
        if ($this->enabled !== 'yes') {
            return false;
        }

        // Get settings from unified gateway
        $unified_settings = $unified_gateway->get_current_settings();

        // Check if required settings are configured in unified gateway
        if (empty($unified_settings['maccount_number'])) {
            if ($this->debug) {
                $this->log('Gateway not available: mAccount number not configured in unified gateway.', 'warning');
            }
            return false;
        }

        // Check API key based on unified gateway's testmode
        $is_testmode = isset($unified_settings['testmode']) && $unified_settings['testmode'] === 'yes';
        $api_key = $is_testmode ? $unified_settings['test_api_key'] : $unified_settings['live_api_key'];

        if (empty($api_key)) {
            if ($this->debug) {
                $this->log('Gateway not available: API key not configured for current mode in unified gateway.', 'warning');
            }
            return false;
        }

        // Check API URLs based on unified gateway's testmode with default values
        $payments_api_url = $is_testmode ?
            ($unified_settings['monoova_payments_api_url_sandbox'] ?? 'https://api.m-pay.com.au') : ($unified_settings['monoova_payments_api_url_live'] ?? 'https://api.mpay.com.au');

        $card_api_url = $is_testmode ?
            ($unified_settings['monoova_card_api_url_sandbox'] ?? 'https://sand-api.monoova.com') : ($unified_settings['monoova_card_api_url_live'] ?? 'https://api.monoova.com');

        if (empty($payments_api_url) || empty($card_api_url)) {
            if ($this->debug) {
                $this->log('Gateway not available: API URLs not configured for current mode in unified gateway.', 'warning');
            }
            return false;
        }

        return true;
    }


    /**
     * Add custom cron schedules.
     * @param array $schedules
     * @return array
     */
    public function add_cron_schedules($schedules) {
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 900, // 15 minutes in seconds
            'display'  => esc_html__('Every 15 Minutes'),
        );
        return $schedules;
    }

    /**
     * Generates the payload string for a PayID QR code.
     * Format: payid://payid-value?amount=123.45&ref=reference-text
     */
    private function generate_payid_qr_payload($payid, $amount, $reference) {
        if (empty($payid)) {
            return '';
        }
        $payload = 'payid://' . trim($payid);
        $payload .= '?amount=' . urlencode(number_format($amount, 2, '.', ''));
        $payload .= '&ref=' . urlencode($reference);
        return $payload;
    }

    /**
     * AJAX handler to replace "process_payment" method for generating payment instructions using for PayID block-based checkout 
     *
     * This method generates the necessary payment instructions for the PayID block-based checkout process.
     */
    public function ajax_generate_payment_instructions_in_blocked_checkout() {
        check_ajax_referer('monoova_generate_instructions', 'nonce');

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['status' => 'error', 'message' => 'Order not found.']);
            return;
        }

        $force_regeneration = isset($_POST['regenerate']) && $_POST['regenerate'] === 'true';

        try {
            // Generate payment instructions
            $instructionsResponse = $this->generate_reconciliation_rules_in_blocked_checkout($order, false, $force_regeneration);
            wp_send_json_success($instructionsResponse);
        } catch (Exception $e) {
            wp_send_json_error(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }

    /**
     * Retrieves a reusable PayID, or generates a new one if the old one has expired or doesn't exist.
     * We now use one PayID for up to 10 uses to reduce API calls of generating PayID and improve performance.
     *
     * @return string The PayID to be used for the transaction.
     * @throws Exception If a new PayID cannot be generated.
     */
    private function get_or_generate_reusable_payid() {
        $this->log("Attempting to retrieve or generate a reusable PayID.", 'info');
        $max_uses = 10; // Define the maximum number of uses for a PayID

        $current_payid = get_option('monoova_reusable_payid');
        $current_use_count = (int) get_option('monoova_reusable_payid_order_count', 0);

        if (!empty($current_payid) && $current_use_count < $max_uses) {
            $this->log("Found valid reusable PayID: {$current_payid} with use count: {$current_use_count}. Reusing it.", 'info');
            return $current_payid;
        }

        $this->log("No valid reusable PayID found or limit reached ({$current_use_count}/{$max_uses}). Generating a new one.", 'info');

        $unified_settings = $this->get_unified_gateway_settings();
        $static_bsb = $unified_settings['static_bsb'] ?? '';
        $static_account_number = $unified_settings['static_account_number'] ?? '';
        $static_account_name = $unified_settings['static_bank_account_name'] ?? get_bloginfo('name');

        if (empty($static_bsb) || empty($static_account_number)) {
            $this->log('Configuration error for generating new PayID: Static BSB/Account Number not set.', 'error');
            throw new Exception(__('Payment gateway is not configured correctly.', 'monoova-payments-for-woocommerce'));
        }

        $payload = [
            "bankAccountNumber" => $static_account_number,
            "bsb"               => $static_bsb,
            "PayIDName"         => substr($static_account_name, 0, 255),
        ];

        $this->log("Payload for Register New Reusable PayID: " . wp_json_encode($payload), 'debug');
        $response = $this->get_api()->register_payid($payload);

        if (is_wp_error($response) || !isset($response['status']) || strtolower($response['status']) !== 'ok') {
            $error_msg = is_wp_error($response) ? $response->get_error_message() : ($response['statusDescription'] ?? 'Failed to register new PayID.');
            $this->log("API error registering new reusable PayID: " . $error_msg, 'error');
            throw new Exception(__('Could not generate new payment details. Please contact support.', 'monoova-payments-for-woocommerce'));
        }

        $new_payid = $response['PayIdDetails']['PayId'] ?? '';
        if (empty($new_payid)) {
            $this->log("New PayID value not found in API response.", 'error');
            throw new Exception(__('Could not retrieve new PayID from the gateway.', 'monoova-payments-for-woocommerce'));
        }

        update_option('monoova_reusable_payid', $new_payid);
        update_option('monoova_reusable_payid_order_count', 0);
        $this->log("Successfully generated and stored new reusable PayID: {$new_payid}", 'info');

        return $new_payid;
    }

    /**
     * Generates PayID + Bank Transfer Reconciliation rules for the order using a reusable PayID.
     *
     * @param WC_Order $order The order object.
     * @param bool $update_order_status Whether to update the order status to 'on-hold'.
     * @return array The payment instructions.
     * @throws Exception
     */
    private function generate_reconciliation_rules_in_blocked_checkout($order, $update_order_status = false, $force_regeneration = false) {
        // IMPORTANT: Check if instructions have already been generated to prevent duplicates on page refresh to avoid unnecessary API calls.
        $existing_ref = $order->get_meta('_monoova_payid_bank_transfer_reconciliation_reference', true);
        $expiry_timestamp = $order->get_meta('_monoova_payid_bank_transfer_payment_expires_at', true);

        // Check if a reference exists AND it has not expired.
        // An empty expiry timestamp means it doesn't expire.
        $is_expired = !empty($expiry_timestamp) && $expiry_timestamp < current_time('timestamp');
        if (!$force_regeneration && !empty($existing_ref) && !$is_expired) {
            $this->log("Reconciliation rules already exist for order #{$order->get_id()}. Skipping generation.", 'info');
            $unified_settings = $this->get_unified_gateway_settings();
            return $this->prepare_payment_instructions_args($order, $unified_settings);
        }

        if ($is_expired) {
            $this->log("Existing reconciliation rules for order #{$order->get_id()} have expired. Generating new ones.", 'info');
        }

        if ($force_regeneration) {
            $this->log("Forcing regeneration of reconciliation rules for order #{$order->get_id()}.", 'info');
            // Clear old meta keys to ensure a clean slate for regeneration.
            $order->delete_meta_data('_monoova_payid_bank_transfer_reconciliation_reference');
            $order->delete_meta_data('_monoova_payid_value');
            $order->delete_meta_data('_monoova_payid_static_bank_account_name');
            $order->delete_meta_data('_monoova_payid_static_bsb');
            $order->delete_meta_data('_monoova_payid_static_account_number');
            $order->delete_meta_data('_monoova_payid_bank_transfer_payment_expires_at');
        }

        $unified_settings = $this->get_unified_gateway_settings();
        $order_id = $order->get_id();

        $static_bsb = $unified_settings['static_bsb'] ?? '';
        $static_account_number = $unified_settings['static_account_number'] ?? '';

        if (empty($static_bsb) || empty($static_account_number)) {
            $this->log('Configuration error: Store Automatcher account (Static BSB/Account Number) is not configured.', 'error');
            throw new Exception(__('Payment gateway is not configured correctly. Please ask the store administrator to generate a store-wide Automatcher account from the PayID settings tab.', 'monoova-payments-for-woocommerce'));
        }

        $reusable_payid_value = $this->get_or_generate_reusable_payid();
        $reconciliation_ref = apply_filters('monoova_reconciliation_reference', 'WC' . $order->get_id() . 'T' . time(), $order);

        $payload = [
            'reconciliationRuleReference' => $reconciliation_ref,
            'amount'                      => (float) $order->get_total(),
        ];

        $expire_hours = (int) ($unified_settings['expire_hours'] ?? 24);
        if ($expire_hours > 0) {
            $expiry_datetime = new DateTime('now', new DateTimeZone('Australia/Sydney'));
            $expiry_datetime->add(new DateInterval("PT{$expire_hours}H"));
            $payload['expiryDateTime'] = $expiry_datetime->format('Y-m-d\TH:i:s');
        }

        $payload['payId'] = $reusable_payid_value;
        $payload['reconciliationRuleReference'] = $reconciliation_ref . 'P';
        $this->log("Payload for Create Reconciliation Rule | PayID (Order #{$order_id}): " . wp_json_encode($payload), 'debug');
        $response_payid_rule = $this->get_api()->create_reconciliation_rule($payload);

        if (is_wp_error($response_payid_rule) || !isset($response_payid_rule['status']) || strtolower($response_payid_rule['status']) !== 'ok') {
            $error_msg = is_wp_error($response_payid_rule) ? $response_payid_rule->get_error_message() : ($response_payid_rule['statusDescription'] ?? 'Failed to create reconciliation rule.');
            $this->log("API error creating reconciliation rule (PayID) for order #{$order_id}: " . $error_msg, 'error');
            throw new Exception(__('Could not generate payment details. Please try again or contact support.', 'monoova-payments-for-woocommerce'));
        }

        unset($payload['payId']);
        $payload['bsb'] = $static_bsb;
        $payload['accountNumber'] = $static_account_number;
        $payload['reconciliationRuleReference'] = $reconciliation_ref . 'B';
        $this->log("Payload for Create Reconciliation Rule | Bank Transfer (Order #{$order_id}): " . wp_json_encode($payload), 'debug');
        $response_bank_rule = $this->get_api()->create_reconciliation_rule($payload);

        if (is_wp_error($response_bank_rule) || !isset($response_bank_rule['status']) || strtolower($response_bank_rule['status']) !== 'ok') {
            $error_msg = is_wp_error($response_bank_rule) ? $response_bank_rule->get_error_message() : ($response_bank_rule['statusDescription'] ?? 'Failed to create reconciliation rule.');
            $this->log("API error creating reconciliation rule (Bank Transfer) for order #{$order_id}: " . $error_msg, 'error');
            throw new Exception(__('Could not generate all required payment details. Please try again or contact support.', 'monoova-payments-for-woocommerce'));
        }

        // Only increment the PayID usage counter if this is the first time we are generating rules for this order.
        if (empty($existing_ref)) {
            $current_use_count = (int) get_option('monoova_reusable_payid_order_count', 0);
            update_option('monoova_reusable_payid_order_count', $current_use_count + 1);
            $this->log("Successfully used reusable PayID. New use count is " . ($current_use_count + 1), 'info');
        }

        $this->log("Successfully created reconciliation rule (PayID/Bank Transfer) for order #{$order_id}. Reference: {$reconciliation_ref}", 'info');
        $order->update_meta_data('_monoova_payid_bank_transfer_payment_instruction_method', 'reconciliation_rule');
        $order->update_meta_data('_monoova_payid_bank_transfer_reconciliation_reference', $reconciliation_ref);
        $order->update_meta_data('_monoova_payid_value', $reusable_payid_value);
        $order->update_meta_data('_monoova_payid_static_bank_account_name', $unified_settings['static_bank_account_name'] ?? get_bloginfo('name'));
        $order->update_meta_data('_monoova_payid_static_bsb', $static_bsb);
        $order->update_meta_data('_monoova_payid_static_account_number', $static_account_number);
        if (isset($payload['expiryDateTime'])) {
            $this->log("Setting expiry date for reconciliation rule: " . $payload['expiryDateTime'], 'debug');
            $order->update_meta_data('_monoova_payid_bank_transfer_payment_expires_at', $expiry_datetime->getTimestamp());
        }

        if ($update_order_status) {
            // update order status to 'on-hold' if making payment from Order received (thankyou) page 
            $order->update_status('on-hold', __('Awaiting payment via PayID / Bank Transfer.', 'monoova-payments-for-woocommerce'));
        }
        else {
            // set order status to 'pending' if making payment from checkout page
            $order->update_status('pending', __('Awaiting payment via PayID / Bank Transfer.', 'monoova-payments-for-woocommerce'));
            $this->log("Order #{$order_id} status updated to 'pending' for PayID / Bank Transfer payment.", 'info');
        }
        

        $order->save();
        $this->log("Reconciliation rules generated successfully for order #{$order_id}.", 'info');

        return $this->prepare_payment_instructions_args($order, $unified_settings);
    }

    private function prepare_payment_instructions_args($order, $unified_settings) {
        $generated_payid_value = $order->get_meta('_monoova_payid_value', true);
        $reconciliation_ref = $order->get_meta('_monoova_payid_bank_transfer_reconciliation_reference', true);
        $expiry_timestamp = $order->get_meta('_monoova_payid_bank_transfer_payment_expires_at', true);
        $this->log("Get Expiry timestamp for reconciliation rule: " . $expiry_timestamp, 'debug');
        $details = [
            'payid_value'           => $generated_payid_value,
            'reference'             => $reconciliation_ref,
            'bank_account_name'     => $unified_settings['static_bank_account_name'] ?? get_bloginfo('name'),
            'bank_bsb'              => $unified_settings['static_bsb'] ?? '',
            'bank_account_number'   => $unified_settings['static_account_number'] ?? '',
            'amount_to_pay_formatted' => $order->get_formatted_order_total(),
            'order_key'             => $order->get_order_key(), // Add order key for status polling
        ];

        // Generate the QR code payload with the static PayID and unique reference.
        // $details['qr_code_payload'] = $this->generate_payid_qr_payload(
        //     $details['payid_value'],
        //     $order->get_total(),
        //     $details['reference'] . 'P' // Append 'P' for PayID reference
        // );

        $payment_types_to_show = $unified_settings['payment_types'] ?? ['payid', 'bank_transfer'];
        $show_reference_field = ($unified_settings['payid_show_reference_field'] ?? 'yes') === 'yes';

        return [
            'order'   => $order,
            'details' => $details,
            'status_check_nonce' => wp_create_nonce('monoova_check_status_' . $order->get_id()),
            'payment_types_to_show' => $payment_types_to_show,
            'show_reference_field' => $show_reference_field,
            'expiry_timestamp' => (int) $expiry_timestamp,
            'instructions' => $unified_settings['instructions'] ?? '',
            'view_order_url' => $this->get_return_url($order),
        ];
    }




    /**
     * AJAX handler to check the payment status of an order.
     *
     * This is called by JavaScript on the thank you page to poll for updates.
     */
    public function ajax_check_payment_status() {
        check_ajax_referer('monoova_check_status_' . intval($_POST['order_id']), 'nonce');

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['status' => 'error', 'message' => 'Order not found.']);
            return;
        }

        $order_key = isset($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        if (!current_user_can('manage_woocommerce') && !$order->key_is_valid($order_key)) {
            wp_send_json_error(['status' => 'error', 'message' => 'Permission denied.']);
            return;
        }

        $response_data = ['status' => 'pending'];
        if ($order->is_paid()) {
            $response_data['status'] = 'paid';
        } elseif ($order->has_status('failed')) {
            $response_data['status'] = 'failed';
            $reason = $order->get_meta('_monoova_cancellation_reason', true);
            if (!empty($reason)) {
                $response_data['reason'] = esc_html($reason);
            } else {
                $response_data['reason'] = __('Unexpected payment issue', 'monoova-payments-for-woocommerce');
            }
        } elseif ($order->has_status('cancelled')) {
            $response_data['status'] = 'cancelled';
        }

        wp_send_json_success($response_data);
    }

    /**
     * Runs the scheduled reconciliation task.
     * This is the fallback method to catch payments missed by webhooks.
     */
    public function run_scheduled_reconciliation() {
        $this->log('Running scheduled reconciliation task.', 'info');

        if (!$this->api) {
            $this->log('Scheduled Reconciliation: API client not initialized. Aborting.', 'error');
            return;
        }

        // Get all 'on-hold' orders that used this payment method
        $orders_to_check = wc_get_orders(array(
            'status'         => 'on-hold',
            'payment_method' => $this->id,
            'limit'          => -1, // Check all on-hold orders
        ));

        if (empty($orders_to_check)) {
            $this->log('Scheduled Reconciliation: No on-hold orders to check.', 'debug');
            return;
        }

        // Fetch transactions from the last 24 hours (or a more appropriate window)
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = date('Y-m-d');

        $transactions = $this->get_api()->get_financial_transactions_by_date($start_date, $end_date);

        if (is_wp_error($transactions) || empty($transactions)) {
            $this->log('Scheduled Reconciliation: Could not fetch transactions from Monoova or none found.', 'warning');
            return;
        }

        // Create a lookup array of transactions by their reference
        $transactions_by_ref = array();
        foreach ($transactions as $transaction) {
            if (isset($transaction['clientUniqueId'])) {
                $transactions_by_ref[$transaction['clientUniqueId']] = $transaction;
            }
        }

        foreach ($orders_to_check as $order) {
            $order_reference = $order->get_meta('_monoova_payment_reference', true);

            if (isset($transactions_by_ref[$order_reference])) {
                $transaction = $transactions_by_ref[$order_reference];
                $amount_paid = (float) $transaction['amount'];
                $order_total = (float) $order->get_total();

                if ($amount_paid >= $order_total) {
                    $this->log('Scheduled Reconciliation: Found matching transaction for order #' . $order->get_id(), 'info');
                    $order->add_order_note(sprintf(__('Monoova payment confirmed via scheduled reconciliation. Transaction reference: %s', 'monoova-payments-for-woocommerce'), $order_reference));
                    $order->payment_complete();
                }
            }
        }
        $this->log('Scheduled reconciliation task finished.', 'info');
    }

    /**
     * AJAX handler to reset payment status from failed back to pending
     * 
     * This allows customers to retry payment after a failure by resetting
     * the order status and enabling polling for payment status updates.
     */
    public function ajax_reset_payment_status() {
        check_ajax_referer('monoova_reset_payment_status', 'nonce');

        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => 'Order not found.']);
            return;
        }

        // Validate order key for security
        $order_key = isset($_POST['order_key']) ? wc_clean(wp_unslash($_POST['order_key'])) : '';
        if (!current_user_can('manage_woocommerce') && !$order->key_is_valid($order_key)) {
            wp_send_json_error(['message' => 'Permission denied.']);
            return;
        }

        // Check if order is in a failed status
        if (!$order->has_status(['failed', 'cancelled'])) {
            wp_send_json_error(['message' => 'Order is not in a failed or cancelled status.']);
            return;
        }

        try {
            // Reset order status to pending payment
            $order->update_status('pending', __('Payment status reset by customer for retry.', 'monoova-payments-for-woocommerce'));
            
            // Clear any failure reason meta
            $order->delete_meta_data('_monoova_cancellation_reason');
            $order->delete_meta_data('_monoova_payment_status');
            
            // Save the order
            $order->save();

            $this->log("Payment status reset for order #{$order_id} from failed/cancelled to pending. Waiting for customer to pay again.", 'info');

            wp_send_json_success([
                'message' => __('Payment status has been reset. You can now retry the payment.', 'monoova-payments-for-woocommerce'),
                'status' => 'pending'
            ]);

        } catch (Exception $e) {
            $this->log("Error resetting payment status for order #{$order_id}: " . $e->getMessage(), 'error');
            wp_send_json_error(['message' => __('Failed to reset payment status. Please try again.', 'monoova-payments-for-woocommerce')]);
        }
    }
}
