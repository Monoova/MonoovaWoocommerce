<?php

/**
 * Monoova Card Payment Gateway
 *
 * Provides a Credit Card Payment Gateway for Monoova.
 *
 * @package Monoova_Payments_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova Card Gateway Class
 */
class Monoova_Card_Gateway extends Monoova_Gateway {
    /**
     * Whether to capture payments immediately
     *
     * @var bool
     */
    protected $capture;

    /**
     * Whether to allow saved cards
     *
     * @var bool
     */
    protected $saved_cards;
    /**
     * Whether to enable to apply surcharge for card payments
     *
     * @var bool
     */
    protected $apply_surcharge;
    /**
     * surcharge amount
     *
     * @var float
     */
    protected $surcharge_amount;

    /**
     * Whether Apple Pay is enabled
     *
     * @var bool
     */
    protected $enable_apple_pay;

    /**
     * Whether Google Pay is enabled
     *
     * @var bool
     */
    protected $enable_google_pay;

    /**
     * Whether express checkout is enabled
     *
     * @var bool
     */
    protected $enable_express_checkout;

    /**
     * Express checkout button color
     *
     * @var string
     */
    protected $express_button_color;

    /**
     * Express checkout button height
     *
     * @var int
     */
    protected $express_button_height;

    /**
     * Express checkout button border radius
     *
     * @var int
     */
    protected $express_button_border_radius;



    /**
     * Gateway ID
     *
     * @var string
     */
    public $id = 'monoova_card';

    /**
     * Gateway title
     *
     * @var string
     */
    public $method_title = 'Credit / Debit card';

    /**
     * Gateway description
     *
     * @var string
     */
    public $method_description = 'Accept payments via Mastercard, Visa, Apple pay and Google pay';

    /**
     * Supported features
     *
     * @var array
     */
    public $supports = array(
        'products',
        'refunds',
        'tokenization',
        'subscriptions',
        'subscription_cancellation',
        'subscription_suspension',
        'subscription_reactivation',
        'subscription_amount_changes',
        'subscription_date_changes',
        'subscription_payment_method_change',
        'subscription_payment_method_change_customer',
        'subscription_payment_method_change_admin',
        'multiple_subscriptions'
    );

    /**
     * Constructor for the gateway
     */
    public function __construct() {
        $this->icon               = apply_filters('woocommerce_monoova_card_icon', MONOOVA_PLUGIN_URL . 'assets/images/monoova.png');
        $this->has_fields         = true;

        // Call parent constructor first
        parent::__construct();

        // Initialize properties from Unified Gateway settings
        $this->init_card_settings();

        // Actions
        // add_action('wp_ajax_monoova_create_session_token', array($this, 'ajax_create_session_token'));
        // add_action('wp_ajax_nopriv_monoova_create_session_token', array($this, 'ajax_create_session_token'));

        // Express checkout AJAX handlers
        add_action('wp_ajax_monoova_express_checkout', array($this, 'ajax_express_checkout'));
        add_action('wp_ajax_nopriv_monoova_express_checkout', array($this, 'ajax_express_checkout'));
        add_action('wp_ajax_monoova_complete_express_checkout', array($this, 'ajax_complete_express_checkout'));
        add_action('wp_ajax_nopriv_monoova_complete_express_checkout', array($this, 'ajax_complete_express_checkout'));
        
        // Hide this gateway from admin settings and redirect to unified gateway
        add_action('admin_init', array($this, 'maybe_redirect_to_unified_gateway'));
    }

    /**
     * Initialize card-specific settings from Unified Gateway
     */
    private function init_card_settings() {
        $unified_settings = $this->get_unified_gateway_settings();

        if (!$unified_settings) {
            // Fallback to default values if unified settings not available
            $this->capture = true;
            $this->saved_cards = true;
            $this->apply_surcharge = false;
            $this->surcharge_amount = 0.0;
            $this->enable_apple_pay = true;
            $this->enable_google_pay = true;
            $this->enable_express_checkout = false;
            $this->express_button_color = 'primary';
            $this->express_button_height = 48;
            $this->express_button_border_radius = 4;
            $this->order_button_text = __('Pay with Card', 'monoova-payments-for-woocommerce');
            $this->debug = true; // Default to debug enabled
            $this->testmode = true; // Default to test mode
            return;
        }

        // Initialize properties from unified settings with defaults
        $this->capture = 'yes' === ($unified_settings['capture'] ?? 'yes');
        $this->saved_cards = 'yes' === ($unified_settings['saved_cards'] ?? 'yes');
        $this->apply_surcharge = 'yes' === ($unified_settings['apply_surcharge'] ?? 'no');
        $this->surcharge_amount = (float) ($unified_settings['surcharge_amount'] ?? 0.0);
        $this->enable_apple_pay = 'yes' === ($unified_settings['enable_apple_pay'] ?? 'yes');
        $this->enable_google_pay = 'yes' === ($unified_settings['enable_google_pay'] ?? 'yes');
        $this->enable_express_checkout = 'yes' === ($unified_settings['enable_express_checkout'] ?? 'no');
        $this->express_button_color = $unified_settings['express_button_color'] ?? 'primary';
        $this->express_button_height = (int) ($unified_settings['express_button_height'] ?? 48);
        $this->express_button_border_radius = (int) ($unified_settings['express_button_border_radius'] ?? 4);
        $this->order_button_text = $unified_settings['order_button_text'] ?? __('Pay with Card', 'monoova-payments-for-woocommerce');
        $this->debug = 'yes' === ($unified_settings['debug'] ?? 'yes');
        $this->testmode = 'yes' === ($unified_settings['testmode'] ?? 'yes');
    }

    /**
     * Get the title for the payment method.
     *
     * @return string Payment method title.
     */
    public function get_title() {
        // Use the title from settings if available, otherwise fallback to method_title
        $title_from_settings = $this->get_option('title');
        return !empty($title_from_settings) ? $title_from_settings : $this->method_title;
    }

    /**
     * Get the order button text with proper translation.
     *
     * @return string Order button text.
     */
    public function get_order_button_text() {
        return $this->order_button_text ?: __('Pay with Card', 'monoova-payments-for-woocommerce');
    }

    /**
     * Get icons formatted for blocks
     *
     * @return array
     */
    public function get_icons_for_blocks() {
        $icons = array();

        // Add card type icons
        $card_types = array(
            'visa' => 'Visa',
            'mastercard' => 'Mastercard'
        );

        foreach ($card_types as $type => $name) {
            $icon_url = MONOOVA_PLUGIN_URL . 'assets/images/' . $type . '.png';
            if (file_exists(MONOOVA_PLUGIN_DIR . 'assets/images/' . $type . '.png')) {
                $icons[] = array(
                    'id' => $type,
                    'src' => $icon_url,
                    'alt' => $name
                );
            }
        }

        // Fallback to generic cards icon
        if (empty($icons)) {
            $icons[] = array(
                'id' => 'cards',
                'src' => MONOOVA_PLUGIN_URL . 'assets/images/cards.png',
                'alt' => 'Credit Cards'
            );
        }

        return $icons;
    }



    /**
     * Enqueue payment scripts.
     */
    public function payment_scripts() {
        if (!is_checkout() || !$this->is_available()) {
            return;
        }

        // Add custom styles for the checkout container
        wp_add_inline_style('monoova-card', '
            #checkout-container {
                display: none;
                overflow: hidden;
                margin: 0 auto;
                max-width: 500px;
                padding: 32px;

                background: white;
                border-radius: 8px;
                box-shadow: 0px 2px 20px rgba(0, 0, 0, 0.1);
            }
            .monoova-payment-methods {
                margin-bottom: 20px;
            }
            .monoova-payment-method-option {
                margin-bottom: 10px;
            }
            .monoova-payment-method-option input[type="radio"] {
                margin-right: 8px;
            }
            .monoova-payment-method-option label {
                display: inline-block;
                vertical-align: middle;
            }
        ');

        // Get settings from unified gateway
        $unified_settings = $this->get_unified_gateway_settings();
        $testmode = 'yes' === ($unified_settings['testmode'] ?? 'no');

        // Localize script
        wp_localize_script('monoova-monoova-card', 'monoovaCheckout', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'create_session_nonce' => wp_create_nonce('monoova-card-session-nonce'),
            'is_test' => $testmode ? '1' : '0',
            'enable_apple_pay' => $this->enable_apple_pay ? '1' : '0',
            'enable_google_pay' => $this->enable_google_pay ? '1' : '0',
            'currency' => get_woocommerce_currency(),
            'merchant_id' => $unified_settings['merchant_id'] ?? '',
            'merchant_name' => get_bloginfo('name'),
            'i18n' => array(
                'processing' => __('Processing payment...', 'monoova-payments-for-woocommerce'),
                'error' => __('An error occurred while processing your payment. Please try again.', 'monoova-payments-for-woocommerce'),
                'payment_failed' => __('Your payment could not be processed. Please try again later.', 'monoova-payments-for-woocommerce'),
                'payment_success' => __('Payment successful! Redirecting to order confirmation...', 'monoova-payments-for-woocommerce')
            )
        ));

        wp_enqueue_style(
            'monoova-payments',
            MONOOVA_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            MONOOVA_VERSION
        );
    }

    /**
     * Display monnify payment icon.
     */
    public function get_icon() {

        $icon = '<img src="' . esc_url(MONOOVA_PLUGIN_URL . 'assets/images/monoova.png') . '" alt="Monoova Card Payment Options" style="height: 20px;" />';

        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    /**
     * Display payment fields on checkout
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }

        // Payment method selection container
        echo '<div class="monoova-payment-methods">';
        echo '<h3>' . __('Monoova Payment Methods', 'monoova-payments-for-woocommerce') . '</h3>';

        // Card type selection
        echo '<div class="monoova-card-types">';
        echo '<label style="display:block; margin-bottom:8px; font-weight:600;">' . __('Select Card Type:', 'monoova-payments-for-woocommerce') . '</label>';

        echo '<div class="monoova-radio-group">';
        // Visa option
        echo '<label class="monoova-radio-label">';
        echo '<input type="radio" id="monoova_payment_method_visa" name="monoova_payment_method" value="visa" checked="checked" />';
        echo '<span class="monoova-custom-radio"></span> ';
        echo '<img src="' . esc_url(MONOOVA_PLUGIN_URL . 'assets/images/visa.png') . '" alt="Visa" class="monoova-card-icon" />';
        echo __('Visa', 'monoova-payments-for-woocommerce');
        echo '</label>';

        // Mastercard option
        echo '<label class="monoova-radio-label">';
        echo '<input type="radio" id="monoova_payment_method_mastercard" name="monoova_payment_method" value="mastercard" />';
        echo '<span class="monoova-custom-radio"></span> ';
        echo '<img src="' . esc_url(MONOOVA_PLUGIN_URL . 'assets/images/mastercard.png') . '" alt="Mastercard" class="monoova-card-icon" />';
        echo __('Mastercard', 'monoova-payments-for-woocommerce');
        echo '</label>';
        echo '</div>';
        echo '</div>';

        // Add some CSS to style the payment fields
        echo '<style>
        .monoova-radio-group {
            display: flex;
            gap: 24px;
            margin-bottom: 10px;
        }
        .monoova-radio-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 1rem;
            position: relative;
            padding-left: 28px;
            user-select: none;
        }
        .monoova-radio-label input[type="radio"] {
            opacity: 0;
            position: absolute;
            left: 0;
            top: 50%;
            width: 20px;
            height: 20px;
            margin: 0;
            transform: translateY(-50%);
            z-index: 2;
            cursor: pointer;
        }
        .monoova-custom-radio {
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            height: 20px;
            width: 20px;
            background: #fff;
            border: 2px solid #2271b1;
            border-radius: 50%;
            box-sizing: border-box;
            z-index: 1;
        }
        .monoova-radio-label input[type="radio"]:checked + .monoova-custom-radio {
            background: #2271b1;
            box-shadow: 0 0 0 2px #2271b1 inset;
        }
        .monoova-radio-label input[type="radio"]:focus + .monoova-custom-radio {
            outline: 2px solid #005fcc;
        }
        .monoova-card-icon {
            width: 32px;
            height: 20px;
            margin: 0 8px 0 8px;
            vertical-align: middle;
            display: inline-block;
        }
        </style>';
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array Result of payment processing.
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $this->log("=== Starting payment processing for order $order_id (Redirect Flow) ===");
        $this->log("POST data: " . print_r($_POST, true));

        if (!$order) {
            $this->log("Order $order_id not found", 'error');
            wc_add_notice(__('Order not found.', 'monoova-payments-for-woocommerce'), 'error');
            return array(
                'result' => 'fail',
                'redirect' => '',
            );
        }

        try {
            // Get payment method from POST data
            $payment_method_selected = isset($_POST['monoova_payment_method']) ? sanitize_text_field($_POST['monoova_payment_method']) : '';

            // if Selected card type is empty, set it to 'visa' by default
            if (empty($payment_method_selected)) {
                $payment_method_selected = 'visa'; // Default to Visa if not specified
            }
            if (empty($payment_method_selected) || !in_array($payment_method_selected, array('visa', 'mastercard'))) {
                $this->log("Invalid card type: " . $payment_method_selected, 'error');
                throw new Exception(__('Please select a valid card type.', 'monoova-payments-for-woocommerce'));
            }

            // Save the selected card type to order meta so it's available for ajax_get_client_token
            $order->update_meta_data('_monoova_selected_card_type', $payment_method_selected);

            // Set order status to on-hold
            $order->update_status('on-hold', __('Awaiting payment via Primer redirection.', 'monoova-payments-for-woocommerce'));
            $order->save();

            // Create a nonce for the redirect page to verify later
            $redirect_nonce = wp_create_nonce('card_payment_redirect_nonce_' . $order_id);

            // Construct redirect URL to trigger our custom handler
            // User wants: /checkout/order-pay/{id}?card_payment_sdk=1&m_nonce={redirect_none}
            $base_redirect_url = $order->get_checkout_payment_url(true); // Gets /checkout/order-pay/ID/?pay_for_order=true&key=ORDER_KEY

            $redirect_url = add_query_arg(array(
                'card_payment_sdk' => '1', // Our trigger query var
                'order_id' => $order_id, // Explicitly add order_id to the query string
                'm_nonce' => $redirect_nonce
            ), $base_redirect_url);

            $this->log("Order $order_id set to on-hold. Redirecting to custom handler URL: " . $redirect_url);

            return array(
                'result' => 'success',
                'redirect' => $redirect_url,
            );
        } catch (Exception $e) {
            $this->log("Payment processing error for order #{$order_id}: " . $e->getMessage(), 'error');
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'fail',
                'redirect' => '',
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Save payment token for the user.
     *
     * @param int $user_id User ID.
     * @param array $payment_method_details Details from API response containing token info.
     */
    protected function save_payment_token($user_id, $payment_method_details) {
        if (empty($user_id) || empty($payment_method_details['storableToken'])) {
            return;
        }

        $token = new WC_Payment_Token_CC();
        $token->set_token($payment_method_details['storableToken']); // This is the Monoova storable token
        $token->set_gateway_id($this->id);
        $token->set_card_type(strtolower($payment_method_details['brand'] ?? 'unknown'));
        $token->set_last4($payment_method_details['last4'] ?? '');
        $token->set_expiry_month($payment_method_details['expiryMonth'] ?? '');
        $token->set_expiry_year($payment_method_details['expiryYear'] ?? '');
        $token->set_user_id($user_id);

        if ($token->save()) {
            $this->log("Saved Monoova payment token {$token->get_id()} for user $user_id.");
        } else {
            $this->log("Failed to save Monoova payment token for user $user_id.", 'error');
        }
    }

    /**
     * Process refund for an order
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            $this->log("Refund error: Could not retrieve order object for order ID: $order_id", 'error');
            return new WP_Error('monoova_refund_error', __('Invalid order ID for refund.', 'monoova-payments-for-woocommerce'));
        }

        $transaction_id = $order->get_transaction_id();

        if (empty($transaction_id)) {
            $this->log("Refund error: No Monoova transaction ID found for order #{$order_id} to refund.", 'error');
            return new WP_Error('monoova_refund_error', __('No Monoova transaction ID found for this order, cannot process refund.', 'monoova-payments-for-woocommerce'));
        }

        if (is_null($amount) || $amount <= 0) {
            $this->log("Refund error: Invalid refund amount ($amount) for order #{$order_id}.", 'error');
            return new WP_Error('monoova_refund_error', __('Invalid refund amount.', 'monoova-payments-for-woocommerce'));
        }

        try {
            $reason = !empty($reason) ? $reason : 'Refund for order ' . $order->get_order_number();
            $refund_transaction_id = 'REF-' . $order_id . '-' . uniqid();

            $body = array(
                'clientParentTransactionUniqueReference' => $transaction_id,
                'clientTransactionUniqueReference'       => $refund_transaction_id,
                'refundAmount'                           => (float) $amount,
                'refundReason'                           => $reason,
            );

            $response = $this->get_api()->request_refund($body);

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $order->add_order_note(
                sprintf(
                    __('Monoova refund request for %s was accepted. Async Request ID: %s | Transaction ID: %s', 'monoova-payments-for-woocommerce'),
                    wc_price($amount, array('currency' => $order->get_currency())),
                    esc_html($response['uniqueRequestId']),
                    esc_html($refund_transaction_id),
                )
            );

            return true;
        } catch (Exception $e) {
            $order->add_order_note(sprintf(__('Monoova Refund Failed: %s', 'monoova-payments-for-woocommerce'), $e->getMessage()));

            // Re-throw the exception to ensure WooCommerce's AJAX handler catches it,
            // stops the spinner, and displays the error to the user.
            throw $e;
        }
    }

    /**
     * Prepare session token payload for API request
     *
     * @param WC_Order $order The order object
     * @param string $payment_method The selected payment method (e.g., 'visa', 'mastercard')
     * @param string $maccount_number The mAccount number
     * @param array $billing_address_fallback Optional billing address data from frontend (for autofilled forms)
     * @return array The payload for the session token request
     */
    protected function _prepare_session_token_payload($order, $payment_method, $maccount_number, $billing_address_fallback = null) {
        $this->log("Preparing session token payload for Order ID: " . $order->get_id());
        $this->log("Boolean properties debug - saved_cards: " . var_export($this->saved_cards, true) .
            " (type: " . gettype($this->saved_cards) . "), capture: " . var_export($this->capture, true) .
            " (type: " . gettype($this->capture) . "), apply_surcharge: " . var_export($this->apply_surcharge, true) .
            " (type: " . gettype($this->apply_surcharge) . ")");

        // Generate unique reference for client transaction for token creation
        $client_transaction_ref = 'wc_transaction_' . $order->get_id() . '_' . time();
        $client_payment_token_ref = 'wc_client_token_' . $order->get_id() . '_' . time();

        // Determine capture mode
        $capture_mode = $this->get_option('capture', 'yes');
        $should_capture = ($capture_mode === 'yes') ? true : false;

        // Get billing data from order first, then use fallback if order data is empty
        $billing_first_name = $order->get_billing_first_name();
        $billing_last_name = $order->get_billing_last_name();
        $billing_email = $order->get_billing_email();
        $billing_address_1 = $order->get_billing_address_1();
        $billing_address_2 = $order->get_billing_address_2();
        $billing_city = $order->get_billing_city();
        $billing_state = $order->get_billing_state();
        $billing_postcode = $order->get_billing_postcode();
        $billing_country = $order->get_billing_country();

        // Use fallback data if order data is empty (for autofilled forms)
        if ($billing_address_fallback && is_array($billing_address_fallback)) {
            $this->log("Using billing address fallback data for missing order fields");
            
            if (empty($billing_first_name) && !empty($billing_address_fallback['first_name'])) {
                $billing_first_name = $billing_address_fallback['first_name'];
                $this->log("Using fallback first_name: " . $billing_first_name);
            }
            
            if (empty($billing_last_name) && !empty($billing_address_fallback['last_name'])) {
                $billing_last_name = $billing_address_fallback['last_name'];
                $this->log("Using fallback last_name: " . $billing_last_name);
            }
            
            if (empty($billing_email) && !empty($billing_address_fallback['email'])) {
                $billing_email = $billing_address_fallback['email'];
                $this->log("Using fallback email: " . $billing_email);
            }
            
            if (empty($billing_address_1) && !empty($billing_address_fallback['address_1'])) {
                $billing_address_1 = $billing_address_fallback['address_1'];
                $this->log("Using fallback address_1: " . $billing_address_1);
            }
            
            if (empty($billing_address_2) && !empty($billing_address_fallback['address_2'])) {
                $billing_address_2 = $billing_address_fallback['address_2'];
                $this->log("Using fallback address_2: " . $billing_address_2);
            }
            
            if (empty($billing_city) && !empty($billing_address_fallback['city'])) {
                $billing_city = $billing_address_fallback['city'];
                $this->log("Using fallback city: " . $billing_city);
            }
            
            if (empty($billing_state) && !empty($billing_address_fallback['state'])) {
                $billing_state = $billing_address_fallback['state'];
                $this->log("Using fallback state: " . $billing_state);
            }
            
            if (empty($billing_postcode) && !empty($billing_address_fallback['postcode'])) {
                $billing_postcode = $billing_address_fallback['postcode'];
                $this->log("Using fallback postcode: " . $billing_postcode);
            }
            
            if (empty($billing_country) && !empty($billing_address_fallback['country'])) {
                $billing_country = $billing_address_fallback['country'];
                $this->log("Using fallback country: " . $billing_country);
            }
        }

        $country_code = !empty($billing_country) ? strtoupper($billing_country) : 'AU';
        $this->log("Final billing country code: " . $country_code);
        $state_code = !empty($billing_state) ? $this->get_state_code($billing_state, $country_code) : 'NSW';
        $this->log("Final billing state code: " . $state_code);

        // Log final billing data being used
        $this->log("Final billing data - firstName: '$billing_first_name', lastName: '$billing_last_name', email: '$billing_email', address1: '$billing_address_1', city: '$billing_city', state: '$billing_state', postcode: '$billing_postcode', country: '$billing_country'");

        $payload = array(
            'clientTransactionUniqueReference' => $client_transaction_ref,
            'customer' => array(
                'billingAddress' => array(
                    'firstName' => substr($billing_first_name, 0, 60),
                    'lastName'  => substr($billing_last_name, 0, 60),
                    'street'    => array_filter(array(substr($billing_address_1, 0, 60), substr($billing_address_2, 0, 60))),
                    'suburb'    => substr($billing_city, 0, 14),
                    'state'     => substr($state_code, 0, 3),
                    'postalCode' => substr(empty($billing_postcode) ? '2060' : $billing_postcode, 0, 14),
                    'countryCode' => $country_code,
                ),
                'emailAddress' => $billing_email,
                'customerId'   => $this->get_customer_id_for_order($order),
            ),
            'paymentDetails' => array(
                // e.g., 'Visa', 'Mastercard' (uppercase first letter)
                'cardType' => ucfirst($payment_method),
                'saveOnSuccess' => (bool) $this->saved_cards,
                'clientPaymentTokenUniqueReference' => $client_payment_token_ref,
                'capturePayment' => (bool) $should_capture,
                'applySurcharge' => $should_capture ? (bool) $this->apply_surcharge : false,
                'description' => sprintf(__('Client Token for WooCommerce Order %s', 'monoova-payments-for-woocommerce'), $order->get_order_number()),
            ),
            'amount' => array(
                'currencyAmount' => !$should_capture ? 0 : (float) $order->get_total(),
            ),
        );

        // if surcharge is enabled, add surchargeAmount to the payload
        if ($this->apply_surcharge && isset($this->surcharge_amount) && $should_capture) {
            $payload['amount']['surchargeAmount'] = (float)$this->surcharge_amount;
        }

        $this->log("Prepared session token payload with proper boolean display: " . var_export($payload, true));
        return $payload;
    }



    /**
     * AJAX handler to get client session token for Primer
     */
    public function ajax_get_client_token() {
        // Validate nonce - this nonce is from the main checkout page, not the redirect page yet
        // The redirect page will use a different nonce verification if it calls this directly.
        // We might need a new AJAX action specifically for the redirect page.
        // Let's assume this is now called by the new redirect page.

        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field($_POST['_wpnonce']) : '';
        // Check if this is called from the checkout page or the redirect page
        $is_in_checkout = isset($_POST['is_in_checkout']) && $_POST['is_in_checkout'] === 'true';

        $this->log("AJAX Get Client Token request received for order ID: $order_id");

        if (!$order_id || !wp_verify_nonce($nonce, 'card_payment_redirect_nonce_' . $order_id) && !$is_in_checkout) {
            $this->log("Invalid nonce or order ID. Nonce received: $nonce, Expected action: card_payment_redirect_nonce_$order_id", 'error');
            wp_send_json_error(array('message' => __('Invalid request or session expired. Please try again.', 'monoova-payments-for-woocommerce')), 403);
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("Order $order_id not found for client token generation.", 'error');
            wp_send_json_error(array('message' => __('Order not found.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Retrieve stored card type from order
        $payment_method = isset($_POST['card_type']) ? $_POST['card_type'] : $order->get_meta('_monoova_selected_card_type');

        if (empty($payment_method)) {
            $this->log("Missing card type in order meta for order $order_id.", 'error');
            wp_send_json_error(array('message' => __('Order data incomplete for payment.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Get billing address from POST data as fallback (for autofilled forms)
        $billing_address_fallback = null;
        if (isset($_POST['billingAddress'])) {
            $billing_address_fallback = json_decode(stripslashes($_POST['billingAddress']), true);
            $this->log("Billing address fallback data received: " . print_r($billing_address_fallback, true));
        }

        $this->log("Preparing client token payload for order $order_id. Card Type: $payment_method");

        // Ensure _prepare_session_token_payload method exists and is correctly defined.
        // If this method is causing a linter error, it needs to be implemented or corrected.
        // For now, assuming it's available and correct as per original structure.
        if (!method_exists($this, '_prepare_session_token_payload')) {
            $this->log("CRITICAL: _prepare_session_token_payload method does not exist in " . __CLASS__, 'error');
            wp_send_json_error(array('message' => __('Internal server error: Payment preparation method missing.', 'monoova-payments-for-woocommerce')));
            return;
        }
        $payload = $this->_prepare_session_token_payload($order, $payment_method, $this->get_option('maccount_number'), $billing_address_fallback);
        $response = $this->get_api()->create_card_transaction_token($payload);

        if (is_wp_error($response)) {
            $this->log("API Error getting client token for order $order_id: " . $response->get_error_message(), 'error');
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $this->log("API Response for client token (Order $order_id): " . print_r($response, true));

        if (isset($response['success']) && $response['success'] === false) {
            // Handle cases where serverless might return success:false with a message
            $error_message = isset($response['message']) ? $response['message'] : __('Failed to create client token due to an unknown error.', 'monoova-payments-for-woocommerce');
            if (isset($response['data']) && is_string($response['data'])) { // Sometimes error details are in data
                $error_message .= ' Details: ' . $response['data'];
            }
            $this->log("Failed to create client token for order $order_id: " . $error_message, 'error');
            wp_send_json_error(array('message' => $error_message));
            return;
        }

        if (!empty($response['clientToken']) && !empty($response['clientTransactionUniqueReference'])) { // Expiry is optional from API perspective
            $this->log("Client token generated successfully for order $order_id.");
            // Store client transaction reference on the order if not already there or if it needs updating
            $order->update_meta_data('_monoova_client_transaction_ref', $response['clientTransactionUniqueReference']);
            $order->save_meta_data();

            // if is_in_checkout is true, we are in the checkout page and not the redirect page
            // then we need to set order status to on-hold
            if ($is_in_checkout) {
                $order->update_status('on-hold', __('Awaiting payment via Monoova card SDK loaded.', 'monoova-payments-for-woocommerce'));
                $order->save();
            }

            wp_send_json_success(array(
                'clientToken' => $response['clientToken'],
                'clientTransactionUniqueReference' => $response['clientTransactionUniqueReference'],
                'clientTokenExpirationDate' => $response['clientTokenExpirationDate'] ?? null
            ));
        } else {
            $this->log("Client token response missing required fields for order $order_id. Response: " . print_r($response, true), 'error');
            $error_message = __('Failed to create payment session. Required data missing from response.', 'monoova-payments-for-woocommerce');
            if (isset($response['errors'])) { // 
                // errors is an array of error objects { "errorCode": "xxx", "errorMessage": "xxx" }
                // This is a common pattern in API responses, but check your actual API documentation for exact structure.
                $error_message .= ' ' . implode(', ', array_map(function ($error) {
                    return $error['errorMessage'] ?? '';
                }, $response['errors']));
            }
        }
    }

    /**
     * AJAX handler to complete the checkout after Primer processes the payment.
     * Called by primer-payment-handler.js from the redirect page.
     */
    public function ajax_complete_checkout() {
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field($_POST['_wpnonce']) : '';
        $primer_payment_id = isset($_POST['primer_payment_id']) ? sanitize_text_field($_POST['primer_payment_id']) : '';
        $client_transaction_ref = isset($_POST['client_transaction_ref']) ? sanitize_text_field($_POST['client_transaction_ref']) : '';
        $this->log("AJAX Complete Checkout request for order ID: $order_id. Primer Payment ID: $primer_payment_id, Client Ref: $client_transaction_ref");

        if (!$order_id || !$nonce || !wp_verify_nonce($nonce, 'card_sdk_complete_payment_nonce_' . $order_id)) {
            $this->log("Invalid nonce or order ID for complete_checkout. Nonce: $nonce, Expected: card_sdk_complete_payment_nonce_$order_id", 'error');
            wp_send_json_error(array('message' => __('Invalid request or session expired. Please try again.', 'monoova-payments-for-woocommerce')), 403);
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log("Order $order_id not found for complete_checkout.", 'error');
            wp_send_json_error(array('message' => __('Order not found.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Ensure the order is still in a state that expects payment completion (e.g., on-hold)
        if (!$order->has_status('on-hold')) {
            $this->log("Order $order_id is not in 'on-hold' status. Current status: " . $order->get_status(), 'warning');
            if ($order->is_paid()) {
                wp_send_json_success(array('redirect_url' => $this->get_return_url($order)));
                return;
            }
            wp_send_json_error(array('message' => __('Order is not awaiting payment completion. Current status: ', 'monoova-payments-for-woocommerce') . $order->get_status()));
            return;
        }

        if (empty($primer_payment_id) || empty($client_transaction_ref)) {
            $this->log("Missing Primer Payment ID or Client Transaction Reference in complete_checkout for order $order_id.", 'error');
            // $order object is available here, so adding a note is fine.
            $order->add_order_note(__('Payment failed: Missing critical payment identifiers from the redirect page.', 'monoova-payments-for-woocommerce'));
            wp_send_json_error(array('message' => __('Payment information is incomplete. Cannot verify transaction.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Server-side verification of the transaction status
        $this->log("Verifying transaction server-side for order $order_id, client_transaction_ref: $client_transaction_ref");
        $verification_response = $this->get_api()->get_card_transaction_status($client_transaction_ref);

        if (is_wp_error($verification_response)) {
            $error_message = $verification_response->get_error_message();
            $this->log("API Error verifying transaction status for order $order_id (Client Ref: $client_transaction_ref): $error_message", 'error');
            // $order object is available here
            $order->add_order_note(sprintf(__('Payment verification API error: %s', 'monoova-payments-for-woocommerce'), $error_message));
            wp_send_json_error(array('message' => __('Could not verify payment status with the payment processor. Please contact support.', 'monoova-payments-for-woocommerce')));
            return;
        }

        $this->log("Server-side verification response for order $order_id (Client Ref: $client_transaction_ref): " . print_r($verification_response, true));

        // Extract status from the actual API response structure.
        // This depends on the structure of response from `get_card_transaction_status`
        // Assuming it returns something like: { "success": true, "data": { "status": "SETTLED", "transactionId": "txn_xxx", ... } }
        // Or { "status": "Succeeded", "transactionId": "txn_xxx", ... } if not nested under 'data' by your serverless layer

        $verified_status = null;
        $verified_transaction_id = null;

        if (isset($verification_response['creditCardTransactionDetails']) && isset($verification_response['creditCardTransactionDetails']['status'])) {
            $verified_status = strtolower($verification_response['creditCardTransactionDetails']['status']);
            $verified_transaction_id = $verification_response['creditCardTransactionDetails']['clientTransactionUniqueReference'] ?? $primer_payment_id; // Use API's ID if available
        } else {
            $this->log("Unexpected verification response structure for order $order_id (Client Ref: $client_transaction_ref).", 'error');
            // $order object is available here
            $order->add_order_note(__('Payment verification returned an unexpected response structure.', 'monoova-payments-for-woocommerce'));
            wp_send_json_error(array('message' => __('Payment verification failed due to an unexpected response. Please contact support.', 'monoova-payments-for-woocommerce')));
            return;
        }

        $this->log("Order $order_id - Verified Server-Side Status: $verified_status, Verified Transaction ID: $verified_transaction_id");


        if ($verified_status === 'pending' || $verified_status === 'pending settlement' || $verified_status === 'settlement complete' || $verified_status === 'authorized') {
            // $order object is available here
            $order->payment_complete($verified_transaction_id);
            $order->add_order_note(
                sprintf(
                    __('Monoova payment via Primer verified and completed. Status: %s. Transaction ID: %s. Client Ref: %s. Primer ID: %s', 'monoova-payments-for-woocommerce'),
                    esc_html($verified_status),
                    esc_html($verified_transaction_id),
                    esc_html($client_transaction_ref),
                    esc_html($primer_payment_id) // Log Primer's ID for reference
                )
            );
            $this->log("Order $order_id payment completed successfully after server-side verification. Verified ID: $verified_transaction_id");

            // Check if card should be saved (if Primer provides such info and tokenization is part of this flow)
            // For now, we assume Primer handles tokenization if configured, and this flow primarily processes one-time payments.
            // If $verification_response['data'] (or similar) contained storable token info from Monoova/Primer, you'd call $this->save_payment_token() here.

            // Empty cart
            WC()->cart->empty_cart();

            wp_send_json_success(array(
                'redirect_url' => $this->get_return_url($order)
            ));
        } else {
            // Payment failed or status not recognized as success
            // $order object is available here
            $failure_message = sprintf(
                __('Monoova payment failed after server verification. Status: %s. Transaction ID: %s. Client Ref: %s. Primer ID: %s. API Response: %s', 'monoova-payments-for-woocommerce'),
                esc_html($verified_status),
                esc_html($verified_transaction_id ?? 'N/A'),
                esc_html($client_transaction_ref),
                esc_html($primer_payment_id),
                esc_html(wp_json_encode($verification_response))
            );
            $order->update_status('failed', $failure_message);
            $this->log("Order $order_id payment failed or status unclear after server verification. Verified Status: $verified_status. Details: " . print_r($verification_response, true), 'error');
            wp_send_json_error(array(
                'message' => __('Payment was not successful after verification. Please try again or contact support.', 'monoova-payments-for-woocommerce')
            ));
        }
    }

    /**
     * Handle AJAX request for express checkout
    
    
    
     * Creates an order with 'on-hold' status and returns client token data following the same flow as normal checkout
     *
     * @since 1.0.0
     */
    public function ajax_express_checkout() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'monoova_express_checkout_nonce')) {
                wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Check if express checkout is enabled
            if (!$this->enable_express_checkout) {
                wp_send_json_error(array('message' => __('Express checkout is not enabled.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Check if user is logged in
            if (!is_user_logged_in()) {
                wp_send_json_error(array('message' => __('You must be logged in to use express checkout.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Get cart data
            if (empty(WC()->cart) || WC()->cart->is_empty()) {
                wp_send_json_error(array('message' => __('Cart is empty.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Get express checkout data from POST
            // $billing_address = $_POST['billingAddress'] ?? array();
            // $shipping_address = $_POST['shippingAddress'] ?? array();
            // need to parse json data from POST billingAddress and shippingAddress
            $billing_address = isset($_POST['billingAddress']) ? json_decode(stripslashes($_POST['billingAddress']), true) : array();
            $shipping_address = isset($_POST['shippingAddress']) ? json_decode(stripslashes($_POST['shippingAddress']), true) : array();
            $selected_shipping_option = sanitize_text_field($_POST['shippingOption'] ?? '');
            $payment_method_selected = sanitize_text_field($_POST['cardType'] ?? 'visa'); // Default to visa

            $this->log("Express checkout request received. Billing Address: " . print_r($billing_address, true) . ", Shipping Address: " . print_r($shipping_address, true) . ", Selected Shipping Option: $selected_shipping_option, Card Type: $payment_method_selected");
            // Set customer addresses if provided
            if (!empty($billing_address)) {
                $this->log("Setting billing address for express checkout: " . print_r($billing_address, true));
                $this->set_customer_address('billing', $billing_address);
            }

            if (!empty($shipping_address)) {
                $this->log("Setting shipping address for express checkout: " . print_r($shipping_address, true));
                $this->set_customer_address('shipping', $shipping_address);
            }

            // Set selected shipping method
            if (!empty($selected_shipping_option) && WC()->cart->needs_shipping()) {
                $this->log("Setting selected shipping method for express checkout: $selected_shipping_option");
                $this->set_shipping_method($selected_shipping_option);
            }

            // Calculate totals with new shipping
            WC()->cart->calculate_totals();

            // Get the order_id sent from the JavaScript fetch call
            $is_checkout_page = isset($_POST['isCheckoutPage']) && $_POST['isCheckoutPage'] === 'true';
            $order_id = isset($_POST['orderId']) ? intval($_POST['orderId']) : 0;

            $order = null;

            // If a valid order_id was passed from the Checkout page, use it.
            if ($order_id > 0 && $is_checkout_page) {
                $this->log("Express checkout received existing order ID: " . $order_id);
                $order = wc_get_order($order_id);
            }

            // If no valid order was found or passed (e.g., from the Cart page), create a new one.
            if (!$order) {
                $this->log("No existing order found or provided. Creating a new order for express checkout.");
                $order = wc_create_order();
                if (is_wp_error($order)) {
                    wp_send_json_error(array('message' => __('Failed to create order.', 'monoova-payments-for-woocommerce')));
                    return;
                }
                // Set customer ID if user is logged in for express checkout
                $current_user_id = get_current_user_id();
                if ($current_user_id > 0) {
                    $order->set_customer_id($current_user_id);
                    // Store customer ID in session for persistence during payment flow
                    WC()->session->set('monoova_express_customer_id', $current_user_id);
                    $this->log("Express checkout: Set customer ID {$current_user_id} for order {$order->get_id()}");
                } else {
                    // Store guest flag in session
                    WC()->session->set('monoova_express_customer_id', 'guest');
                    $this->log("Express checkout: Guest customer for order {$order->get_id()}");
                }

                // Set billing address on order if provided
                if (!empty($billing_address)) {
                    $this->log("Setting billing address on order: " . print_r($billing_address, true));
                    $this->log("billing address first name: " . ($billing_address['first_name']));
                    $this->log("billing address last name: " . ($billing_address['last_name'] ?? ''));
                    $this->log("billing address email: " . ($billing_address['email'] ?? ''));
                    $order->set_billing_first_name($billing_address['first_name'] ?? '');
                    $order->set_billing_last_name($billing_address['last_name'] ?? '');
                    $order->set_billing_company($billing_address['company'] ?? '');
                    $order->set_billing_address_1($billing_address['address_1'] ?? '');
                    $order->set_billing_address_2($billing_address['address_2'] ?? '');
                    $order->set_billing_city($billing_address['city'] ?? '');
                    $order->set_billing_state($billing_address['state'] ?? '');
                    $order->set_billing_postcode($billing_address['postcode'] ?? '');
                    $order->set_billing_country($billing_address['country'] ?? '');
                    $order->set_billing_email($billing_address['email'] ?? '');
                    $order->set_billing_phone($billing_address['phone'] ?? '');
                }

                // Set shipping address on order if provided
                if (!empty($shipping_address)) {
                    $this->log("Setting shipping address on order: " . print_r($shipping_address, true));
                    $order->set_shipping_first_name($shipping_address['first_name'] ?? '');
                    $order->set_shipping_last_name($shipping_address['last_name'] ?? '');
                    $order->set_shipping_company($shipping_address['company'] ?? '');
                    $order->set_shipping_address_1($shipping_address['address_1'] ?? '');
                    $order->set_shipping_address_2($shipping_address['address_2'] ?? '');
                    $order->set_shipping_city($shipping_address['city'] ?? '');
                    $order->set_shipping_state($shipping_address['state'] ?? '');
                    $order->set_shipping_postcode($shipping_address['postcode'] ?? '');
                    $order->set_shipping_country($shipping_address['country'] ?? '');
                    $order->set_shipping_phone($shipping_address['phone'] ?? '');
                } else if (!empty($billing_address)) {
                    // If no shipping address provided, copy billing address to shipping
                    $order->set_shipping_first_name($billing_address['first_name'] ?? '');
                    $order->set_shipping_last_name($billing_address['last_name'] ?? '');
                    $order->set_shipping_company($billing_address['company'] ?? '');
                    $order->set_shipping_address_1($billing_address['address_1'] ?? '');
                    $order->set_shipping_address_2($billing_address['address_2'] ?? '');
                    $order->set_shipping_city($billing_address['city'] ?? '');
                    $order->set_shipping_state($billing_address['state'] ?? '');
                    $order->set_shipping_postcode($billing_address['postcode'] ?? '');
                    $order->set_shipping_country($billing_address['country'] ?? '');
                    $order->set_shipping_phone($billing_address['phone'] ?? '');
                }

                // set order email
                $customer = WC()->customer;
                if (!empty($billing_address['email'])) {
                    $this->log("Setting billing email from POST data: " . $billing_address['email']);
                    $order->set_billing_email($billing_address['email']);
                } else if (!empty($customer) && method_exists($customer, 'get_email') && !empty($customer->get_email())) {
                    // Fallback to customer object email if available
                    $this->log("Setting billing email from customer object: " . $customer->get_email());
                    $order->set_billing_email($customer->get_email());
                }
                // Add cart items to order
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $order->add_product($cart_item['data'], $cart_item['quantity'], $cart_item);
                }

                // Add shipping
                if (!empty($selected_shipping_option) && WC()->cart->needs_shipping()) {
                    foreach (WC()->shipping->get_packages() as $package_id => $package) {
                        if (!empty($package['rates'][$selected_shipping_option])) {
                            $order->add_shipping($package['rates'][$selected_shipping_option]);
                            break;
                        }
                    }
                }

                $this->log("Order billing first name: " . print_r($order->get_billing_first_name(), true));

                // Set payment method
                $order->set_payment_method($this->id);
                $order->set_payment_method_title($this->title);

                // Calculate order totals
                $order->calculate_totals();
            }
            if (is_wp_error($order)) {
                wp_send_json_error(array('message' => __('Failed to create order.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Save the selected card type to order meta (same as normal checkout)
            $order->update_meta_data('_monoova_selected_card_type', $payment_method_selected);
            $order->update_meta_data('_monoova_express_checkout', 'yes');

            // Set order status to on-hold (same as normal checkout)
            $order->update_status('on-hold', __('Express checkout order created, awaiting payment via Primer.', 'monoova-payments-for-woocommerce'));
            $order->save();

            $this->log("Express checkout order {$order->get_id()} created with on-hold status. Card Type: $payment_method_selected");

            // Generate client token using the same logic as ajax_get_client_token()
            if (!method_exists($this, '_prepare_session_token_payload')) {
                $this->log("CRITICAL: _prepare_session_token_payload method does not exist in " . __CLASS__, 'error');
                wp_send_json_error(array('message' => __('Internal server error: Payment preparation method missing.', 'monoova-payments-for-woocommerce')));
                return;
            }

            $payload = $this->_prepare_session_token_payload($order, $payment_method_selected, $this->get_option('maccount_number'), $billing_address);
            $response = $this->get_api()->create_card_transaction_token($payload);

            if (is_wp_error($response)) {
                $this->log("API Error getting client token for express checkout order {$order->get_id()}: " . $response->get_error_message(), 'error');
                wp_send_json_error(array('message' => $response->get_error_message()));
                return;
            }

            $this->log("API Response for express checkout client token (Order {$order->get_id()}): " . print_r($response, true));

            if (isset($response['success']) && $response['success'] === false) {
                $error_message = isset($response['message']) ? $response['message'] : __('Failed to create client token due to an unknown error.', 'monoova-payments-for-woocommerce');
                if (isset($response['data']) && is_string($response['data'])) {
                    $error_message .= ' Details: ' . $response['data'];
                }
                $this->log("Failed to create client token for express checkout order {$order->get_id()}: " . $error_message, 'error');
                wp_send_json_error(array('message' => $error_message));
                return;
            }

            if (!empty($response['clientToken']) && !empty($response['clientTransactionUniqueReference'])) {
                $this->log("Client token generated successfully for express checkout order {$order->get_id()}.");

                // Store client transaction reference on the order
                $order->update_meta_data('_monoova_client_transaction_ref', $response['clientTransactionUniqueReference']);
                $order->save_meta_data();

                wp_send_json_success(array(
                    'orderId' => $order->get_id(),
                    'clientToken' => $response['clientToken'],
                    'clientTransactionUniqueReference' => $response['clientTransactionUniqueReference'],
                    'expiryTime' => $response['expiryTime'] ?? null,
                    'total' => array(
                        'amount' => $order->get_total() * 100, // Convert to cents
                        'currency' => $order->get_currency(),
                        'displayAmount' => wc_price($order->get_total())
                    )
                ));
            } else {
                $this->log("Invalid token response structure for express checkout order {$order->get_id()}.", 'error');
                wp_send_json_error(array('message' => __('Failed to initialize payment. Please try again.', 'monoova-payments-for-woocommerce')));
            }
        } catch (Exception $e) {
            $this->log('Express checkout AJAX error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => __('An error occurred while processing express checkout.', 'monoova-payments-for-woocommerce')
            ));
        }
    }

    /**
     * Complete express checkout payment
     * Processes the payment verification and completion following the same flow as normal checkout
     *
     * @since 1.0.0
     */
    public function ajax_complete_express_checkout() {
        try {
            // Check if this is called from the checkout page or the redirect page
            $is_in_checkout = isset($_POST['is_in_checkout']) && $_POST['is_in_checkout'] === 'true';
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'], 'monoova_express_checkout_nonce')) {
                wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Check if express checkout is enabled
            if (!$this->enable_express_checkout) {
                wp_send_json_error(array('message' => __('Express checkout is not enabled.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Check if user is logged in
            // if user is not logged in, we need to check if user is in checkout page because this function can be called from the checkout page after integrating Card SDK into checkout page
            if (!is_user_logged_in() && !$is_in_checkout) {
                wp_send_json_error(array('message' => __('You must be logged in to use express checkout.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Get payment data
            $order_id = isset($_POST['orderId']) ? intval($_POST['orderId']) : 0;
            $primer_payment_id = sanitize_text_field($_POST['primerPaymentId'] ?? '');
            $client_transaction_ref = sanitize_text_field($_POST['clientRef'] ?? '');

            $this->log("AJAX Complete Express Checkout request for order ID: $order_id. Primer Payment ID: $primer_payment_id, Client Ref: $client_transaction_ref");

            if (!$order_id) {
                wp_send_json_error(array('message' => __('Order ID is required.', 'monoova-payments-for-woocommerce')));
                return;
            }

            $order = wc_get_order($order_id);
            if (!$order) {
                $this->log("Order $order_id not found for complete_express_checkout.", 'error');
                wp_send_json_error(array('message' => __('Order not found.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Ensure the order is still in a state that expects payment completion (e.g., on-hold, pending)
            if (!$order->has_status(array('on-hold', 'pending'))) {
                $this->log("Express checkout order $order_id is not in 'on-hold' or 'pending' status. Current status: " . $order->get_status(), 'warning');
                if ($order->is_paid()) {
                    wp_send_json_success(array('redirect_url' => $this->get_return_url($order)));
                    return;
                }
                wp_send_json_error(array('message' => __('Order is not awaiting payment completion. Current status: ', 'monoova-payments-for-woocommerce') . $order->get_status()));
                return;
            }

            if (empty($primer_payment_id) || empty($client_transaction_ref)) {
                $this->log("Missing Primer Payment ID or Client Transaction Reference in complete_express_checkout for order $order_id.", 'error');
                $order->add_order_note(__('Express checkout payment failed: Missing critical payment identifiers.', 'monoova-payments-for-woocommerce'));
                wp_send_json_error(array('message' => __('Payment information is incomplete. Cannot verify transaction.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Server-side verification of the transaction status (same as normal checkout)
            $this->log("Verifying express checkout transaction server-side for order $order_id, client_transaction_ref: $client_transaction_ref");
            $verification_response = $this->get_api()->get_card_transaction_status($client_transaction_ref);

            if (is_wp_error($verification_response)) {
                $error_message = $verification_response->get_error_message();
                $this->log("API Error verifying express checkout transaction status for order $order_id (Client Ref: $client_transaction_ref): $error_message", 'error');
                $order->add_order_note(sprintf(__('Express checkout payment verification API error: %s', 'monoova-payments-for-woocommerce'), $error_message));
                wp_send_json_error(array('message' => __('Could not verify payment status with the payment processor. Please contact support.', 'monoova-payments-for-woocommerce')));
                return;
            }

            $this->log("Express checkout server-side verification response for order $order_id (Client Ref: $client_transaction_ref): " . print_r($verification_response, true));

            // Extract status from the API response (same logic as normal checkout)
            $verified_status = null;
            $verified_transaction_id = null;

            if (isset($verification_response['creditCardTransactionDetails']) && isset($verification_response['creditCardTransactionDetails']['status'])) {
                $verified_status = strtolower($verification_response['creditCardTransactionDetails']['status']);
                $verified_transaction_id = $verification_response['creditCardTransactionDetails']['clientTransactionUniqueReference'] ?? $primer_payment_id;
            } else {
                $this->log("Unexpected express checkout verification response structure for order $order_id (Client Ref: $client_transaction_ref).", 'error');
                $order->add_order_note(__('Express checkout payment verification returned an unexpected response structure.', 'monoova-payments-for-woocommerce'));
                wp_send_json_error(array('message' => __('Payment verification failed due to an unexpected response. Please contact support.', 'monoova-payments-for-woocommerce')));
                return;
            }

            $this->log("Express checkout order $order_id - Verified Server-Side Status: $verified_status, Verified Transaction ID: $verified_transaction_id");

            if ($verified_status === 'pending' || $verified_status === 'pending settlement' || $verified_status === 'settlement complete' || $verified_status === 'authorized') {
                // Complete payment (same as normal checkout)
                $order->payment_complete($verified_transaction_id);
                $order->add_order_note(
                    sprintf(
                        __('Monoova express checkout payment verified and completed. Status: %s. Transaction ID: %s. Client Ref: %s. Primer ID: %s', 'monoova-payments-for-woocommerce'),
                        esc_html($verified_status),
                        esc_html($verified_transaction_id),
                        esc_html($client_transaction_ref),
                        esc_html($primer_payment_id)
                    )
                );
                $this->log("Express checkout order $order_id payment completed successfully after server-side verification. Verified ID: $verified_transaction_id");

                // Empty cart (same as normal checkout)
                WC()->cart->empty_cart();

                wp_send_json_success(array(
                    'redirect_url' => $this->get_return_url($order)
                ));
            } else {
                // Payment failed (same handling as normal checkout)
                $failure_message = sprintf(
                    __('Monoova express checkout payment failed after server verification. Status: %s. Transaction ID: %s. Client Ref: %s. Primer ID: %s. API Response: %s', 'monoova-payments-for-woocommerce'),
                    esc_html($verified_status),
                    esc_html($verified_transaction_id ?? 'N/A'),
                    esc_html($client_transaction_ref),
                    esc_html($primer_payment_id),
                    esc_html(wp_json_encode($verification_response))
                );
                $order->update_status('failed', $failure_message);
                $this->log("Express checkout order $order_id payment failed or status unclear after server verification. Verified Status: $verified_status. Details: " . print_r($verification_response, true), 'error');
                wp_send_json_error(array(
                    'message' => __('Payment was not successful after verification. Please try again or contact support.', 'monoova-payments-for-woocommerce')
                ));
            }
        } catch (Exception $e) {
            $this->log('Complete express checkout error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array(
                'message' => __('An error occurred while completing express checkout.', 'monoova-payments-for-woocommerce')
            ));
        }
    }

    /**
     * Set customer address from express checkout data
     *
     * @param string $type Either 'billing' or 'shipping'
     * @param array $address Address data from express checkout
     * @since 1.0.0
     */
    private function set_customer_address($type, $address) {
        $customer = WC()->customer;

        if ($type === 'billing') {
            $customer->set_billing_first_name($address['first_name'] ?? '');
            $customer->set_billing_last_name($address['last_name'] ?? '');
            $customer->set_billing_company($address['company'] ?? '');
            $customer->set_billing_address_1($address['address_1'] ?? '');
            $customer->set_billing_address_2($address['address_2'] ?? '');
            $customer->set_billing_city($address['city'] ?? '');
            $customer->set_billing_state($address['state'] ?? '');
            $customer->set_billing_postcode($address['postcode'] ?? '');
            $customer->set_billing_country($address['country'] ?? '');
            $customer->set_billing_email($address['email'] ?? '');
            $customer->set_billing_phone($address['phone'] ?? '');
        } else {
            $customer->set_shipping_first_name($address['first_name'] ?? '');
            $customer->set_shipping_last_name($address['last_name'] ?? '');
            $customer->set_shipping_company($address['company'] ?? '');
            $customer->set_shipping_address_1($address['address_1'] ?? '');
            $customer->set_shipping_address_2($address['address_2'] ?? '');
            $customer->set_shipping_city($address['city'] ?? '');
            $customer->set_shipping_state($address['state'] ?? '');
            $customer->set_shipping_postcode($address['postcode'] ?? '');
            $customer->set_shipping_country($address['country'] ?? '');
        }
        $customer->save();
    }

    /**
     * Gets the correct state code for a given state and country.
     * It handles cases where the full state name (e.g., 'New South Wales') or the code (e.g., 'NSW') is provided.
     *
     * @param string $state_input The state name or code from WooCommerce.
     * @param string $country_code The 2-letter country code (e.g., 'AU').
     * @return string The abbreviated state code, truncated to 3 characters as a fallback.
     */
    private function get_state_code($state_input, $country_code) {
        if (empty($state_input) || empty($country_code)) {
            return '';
        }

        // Fetch the list of states for the given country from WooCommerce.
        $states = WC()->countries->get_states($country_code);

        if (is_array($states) && !empty($states)) {
            $state_input_upper = strtoupper($state_input);

            // Case 1: The input is already a valid state code (e.g., 'NSW').
            if (array_key_exists($state_input_upper, $states)) {
                return $state_input_upper;
            }

            // Case 2: The input is the full state name (e.g., 'New South Wales').
            // We flip the array and search for the full name to get its corresponding code.
            $flipped_states = array_change_key_case(array_flip($states), CASE_UPPER);
            if (isset($flipped_states[$state_input_upper])) {
                return $flipped_states[$state_input_upper];
            }
        }

        // Fallback: If no match is found, return the original input, uppercased, and truncated to the API limit.
        return substr(strtoupper($state_input), 0, 3);
    }

    /**
     * Set shipping method for express checkout
     *
     * @param string $method_id Shipping method ID
     * @since 1.0.0
     */
    private function set_shipping_method($method_id) {
        $chosen_methods = array($method_id);
        WC()->session->set('chosen_shipping_methods', $chosen_methods);
    }

    /**
     * Get customer ID for order with proper authentication and fallback handling
     *
     * @param WC_Order $order The order object
     * @return string Customer ID for use in payment processing
     * @since 1.0.0
     */
    private function get_customer_id_for_order($order) {
        // First, try to get the customer ID from the order
        $order_customer_id = $order->get_customer_id();

        // If order has a valid customer ID (not 0), use it
        if (!empty($order_customer_id) && $order_customer_id > 0) {
            $formatted_customer_id = 'wcMonoovaPluginCustomer' . $order_customer_id;
            $this->log("Using order customer ID: {$formatted_customer_id} for order {$order->get_id()}");
            return $formatted_customer_id;
        }

        // Check if a user is currently logged in
        $current_user_id = get_current_user_id();
        if ($current_user_id > 0) {
            // Update the order with the current user ID if it wasn't set
            $order->set_customer_id($current_user_id);
            $order->save();
            $formatted_customer_id = 'wcMonoovaPluginCustomer' . $current_user_id;
            $this->log("Updated order {$order->get_id()} with current user ID: {$formatted_customer_id}");
            return $formatted_customer_id;
        }

        // Check if user is logged in via WooCommerce session
        if (WC()->customer && WC()->customer->get_id() > 0) {
            $wc_customer_id = WC()->customer->get_id();
            // Update the order with the WooCommerce customer ID
            $order->set_customer_id($wc_customer_id);
            $order->save();
            $formatted_customer_id = 'wcMonoovaPluginCustomer' . $wc_customer_id;
            $this->log("Updated order {$order->get_id()} with WC customer ID: {$formatted_customer_id}");
            return $formatted_customer_id;
        }

        // For express checkout, check if customer data was provided in session
        $express_customer_id = WC()->session ? WC()->session->get('monoova_express_customer_id') : null;
        if (!empty($express_customer_id) && $express_customer_id !== 'guest') {
            $formatted_customer_id = 'wcMonoovaPluginCustomer' . $express_customer_id;
            $this->log("Using express checkout customer ID: {$formatted_customer_id} for order {$order->get_id()}");
            return $formatted_customer_id;
        }

        // Try to find existing customer by email for authenticated context
        $billing_email = $order->get_billing_email();
        if (!empty($billing_email) && is_user_logged_in()) {
            $user = get_user_by('email', $billing_email);
            if ($user && $user->ID > 0) {
                // Update the order with the found user ID
                $order->set_customer_id($user->ID);
                $order->save();
                $formatted_customer_id = 'wcMonoovaPluginCustomer' . $user->ID;
                $this->log("Found and assigned user ID {$formatted_customer_id} for order {$order->get_id()} based on email {$billing_email}");
                return $formatted_customer_id;
            }
        }

        // Last resort: generate a guest customer ID
        // This ensures consistency across payment attempts for the same order
        $guest_customer_id = 'guest_' . $order->get_id() . '_' . time();
        $this->log("Using guest customer ID: {$guest_customer_id} for order {$order->get_id()}");
        return $guest_customer_id;
    }

    /**
     * Redirect to unified gateway if user tries to access individual gateway settings
     */
    public function maybe_redirect_to_unified_gateway() {
        // Check if we're on this gateway's settings page
        if (!current_user_can('manage_woocommerce') || !isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'checkout') {
            return;
        }
        
        if (!isset($_GET['section']) || $_GET['section'] !== $this->id) {
            return;
        }
        
        // Redirect to unified gateway settings
        $redirect_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_unified');
        wp_redirect($redirect_url);
        exit;
    }

    /**
     * Override admin options to show redirect message instead of settings
     */
    public function admin_options() {
        ?>
        <div class="notice notice-info">
            <p>
                <strong><?php _e('Card payment settings have been moved', 'monoova-payments-for-woocommerce'); ?></strong>
            </p>
            <p>
                <?php printf(
                    __('To configure card payment settings, please go to %s.', 'monoova-payments-for-woocommerce'),
                    '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_unified') . '">' . __('Monoova Payments settings', 'monoova-payments-for-woocommerce') . '</a>'
                ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Check if payment method should be available at checkout
     * Respects unified gateway control
     */
    public function is_available() {
        // Check if controlled by unified gateway
        if (class_exists('Monoova_Unified_Gateway')) {
            $unified_gateway = Monoova_Unified_Gateway::get_instance();
            if ($unified_gateway && $unified_gateway->is_controlling_child_gateways()) {
                // If unified gateway is controlling, check if this gateway is enabled in unified settings
                // and use unified gateway's availability logic
                if (!$unified_gateway->is_child_gateway_enabled('card')) {
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
            ($unified_settings['monoova_payments_api_url_sandbox'] ?? 'https://api.m-pay.com.au') :
            ($unified_settings['monoova_payments_api_url_live'] ?? 'https://api.mpay.com.au');

        $card_api_url = $is_testmode ?
            ($unified_settings['monoova_card_api_url_sandbox'] ?? 'https://sand-api.monoova.com') :
            ($unified_settings['monoova_card_api_url_live'] ?? 'https://api.monoova.com');

        if (empty($payments_api_url) || empty($card_api_url)) {
            if ($this->debug) {
                $this->log('Gateway not available: API URLs not configured for current mode in unified gateway.', 'warning');
            }
            return false;
        }

        return true;
    }
}
