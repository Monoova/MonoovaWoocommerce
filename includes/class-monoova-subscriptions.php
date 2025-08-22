<?php
/**
 * Monoova Subscriptions Integration
 *
 * Handles integration with WooCommerce Subscriptions plugin
 *
 * @package Monoova_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include WooCommerce Subscriptions functions if they don't exist
if (!function_exists('wcs_get_subscriptions_for_renewal_order')) {
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/wcs-order-functions.php')) {
        include_once(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/wcs-order-functions.php');
    }
}

if (!function_exists('wcs_get_subscriptions_for_payment_token')) {
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/wcs-user-functions.php')) {
        include_once(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/wcs-user-functions.php');
    }
}

if (!function_exists('wcs_get_users_subscriptions')) {
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/wcs-user-functions.php')) {
        include_once(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/wcs-user-functions.php');
    }
}

if (!class_exists('WC_Subscriptions_Change_Payment_Gateway')) {
    if (file_exists(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/class-wc-subscriptions-change-payment-gateway.php')) {
        include_once(WP_PLUGIN_DIR . '/woocommerce-subscriptions/includes/class-wc-subscriptions-change-payment-gateway.php');
    }
}

/**
 * WC_Monoova_Subscriptions class
 */
class WC_Monoova_Subscriptions {
    /**
     * API instance
     *
     * @var Monoova_API
     */
    protected $api;

    /**
     * Constructor
     */
    public function __construct() {
        // Only hook in if WooCommerce Subscriptions is active
        if (!class_exists('WC_Subscriptions')) {
            return;
        }

        // Initialize API if a gateway instance is available
        // Use a safer approach to get gateways
        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            $gateway = $gateways['monoova_card'] ?? null;
            if ($gateway && !empty($gateway->api)) {
                $this->api = $gateway->api;
            }
        }

        // Add hooks for subscription integration
        $this->add_hooks();
    }

    /**
     * Add hooks for subscription integration
     */
    private function add_hooks() {
        // Process scheduled subscription payments for Card gateway
        add_action('woocommerce_scheduled_subscription_payment_monoova_card', array($this, 'process_scheduled_payment'), 10, 2);

        // Process scheduled subscription payments for PayTo gateway
        add_action('woocommerce_scheduled_subscription_payment_monoova_payto', array($this, 'process_scheduled_payto_payment'), 10, 2);

        // Cancel subscription when payment method is deleted
        add_action('wc_monoova_payment_method_deleted', array($this, 'handle_payment_method_deleted'), 10, 2);

        // Display subscription payment method update message
        add_action('woocommerce_thankyou_monoova_card', array($this, 'display_update_subscription_message'), 10, 1);
        add_action('woocommerce_thankyou_monoova_payto', array($this, 'display_update_subscription_message'), 10, 1);

        // Update subscription payment method
        add_action('woocommerce_subscription_payment_method_updated', array($this, 'handle_subscription_payment_method_updated'), 10, 3);
        add_action('woocommerce_subscription_payment_method_updated_to_monoova_card', array($this, 'handle_payment_method_updated_to_monoova'), 10, 2);
        // Note: PayTo payment method change hooks are not clearly documented in current WooCommerce Subscriptions
        // PayTo payment method changes are handled through the standard checkout process
    }

    /**
     * Process a scheduled subscription payment
     *
     * @param float $amount Recurring amount
     * @param WC_Order $renewal_order The order to process
     * @return void
     */
    public function process_scheduled_payment($amount, $renewal_order) {
        try {
            // Get gateway instance
            $gateway = wc_get_payment_gateway_by_order($renewal_order);

            if (!$gateway || !($gateway instanceof Monoova_Card_Gateway)) {
                throw new Exception(__('Payment gateway not found.', 'monoova-payments-for-woocommerce'));
            }

            // Get subscription
            $subscriptions = wcs_get_subscriptions_for_renewal_order($renewal_order);
            $subscription = reset($subscriptions);

            if (!$subscription) {
                throw new Exception(__('Subscription not found.', 'monoova-payments-for-woocommerce'));
            }

            // Get the payment method
            $token_id = $this->get_payment_token_id($subscription);

            if (empty($token_id)) {
                throw new Exception(__('No payment token found for subscription.', 'monoova-payments-for-woocommerce'));
            }

            $token = WC_Payment_Tokens::get($token_id);

            if (!$token || $token->get_gateway_id() !== 'monoova_card') {
                throw new Exception(__('Invalid payment token.', 'monoova-payments-for-woocommerce'));
            }

            // Get customer ID
            $customer_id = $this->get_customer_id($subscription);

            if (empty($customer_id)) {
                throw new Exception(__('No customer ID found.', 'monoova-payments-for-woocommerce'));
            }

            // Create payment intent
            $intent_args = array(
                'amount' => $this->format_amount($amount, $renewal_order->get_currency()),
                'currency' => strtolower($renewal_order->get_currency()),
                'description' => sprintf(
                    /* translators: %1$s: Site name, %2$s: Subscription ID */
                    __('%1$s - Subscription %2$s', 'monoova-payments-for-woocommerce'),
                    wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
                    $subscription->get_order_number()
                ),
                'customer' => $customer_id,
                'payment_method' => $token->get_token(),
                'confirm' => true,
                'off_session' => true,
                'metadata' => array(
                    'order_id' => $renewal_order->get_id(),
                    'subscription_id' => $subscription->get_id(),
                    'site_url' => home_url('/'),
                    'is_renewal' => 'yes',
                ),
            );

            // Process the payment intent
            $response = $gateway->api->create_payment_intent($intent_args);

            if (is_wp_error($response)) {
                $this->handle_payment_failure($renewal_order, $response->get_error_message());
                return;
            }

            // Check for payment requiring action (unlikely for off-session)
            if (isset($response['status']) && $response['status'] === 'requires_action') {
                $this->handle_payment_failure($renewal_order, __('Card requires authentication. Please complete payment manually.', 'monoova-payments-for-woocommerce'));
                return;
            }

            // Process successful payment
            if (isset($response['status']) && ($response['status'] === 'succeeded' || $response['status'] === 'requires_capture')) {
                $this->handle_payment_success($renewal_order, $response);
            } else {
                $this->handle_payment_failure($renewal_order, __('Payment processing failed.', 'monoova-payments-for-woocommerce'));
            }

        } catch (Exception $e) {
            $this->handle_payment_failure($renewal_order, $e->getMessage());
        }
    }

    /**
     * Handle successful subscription payment
     *
     * @param WC_Order $renewal_order
     * @param array $response
     */
    private function handle_payment_success($renewal_order, $response) {
        // Save payment intent ID
        $renewal_order->update_meta_data('_monoova_payment_id', $response['id']);
        $renewal_order->update_meta_data('_monoova_payment_method_id', $response['payment_method'] ?? '');
        $renewal_order->update_meta_data('_monoova_payment_amount', $response['amount'] / 100);
        $renewal_order->update_meta_data('_monoova_payment_status', $response['status']);

        // Mark payment complete
        $renewal_order->payment_complete($response['id']);
        
        // Add order note
        $renewal_order->add_order_note(
            sprintf(__('Monoova subscription payment successful. Payment ID: %s', 'monoova-payments-for-woocommerce'), $response['id'])
        );

        $renewal_order->save();
    }

    /**
     * Handle failed subscription payment
     *
     * @param WC_Order $renewal_order
     * @param string $error_message
     */
    private function handle_payment_failure($renewal_order, $error_message) {
        // Add order note
        $renewal_order->add_order_note(
            sprintf(__('Monoova subscription payment failed. Error: %s', 'monoova-payments-for-woocommerce'), $error_message)
        );

        // Mark payment as failed
        $renewal_order->update_status('failed');
    }

    /**
     * Get the payment token ID from a subscription
     *
     * @param WC_Subscription $subscription
     * @return string|null
     */
    private function get_payment_token_id($subscription) {
        return $subscription->get_meta('_monoova_payment_token_id');
    }

    /**
     * Get customer ID from a subscription
     *
     * @param WC_Subscription $subscription
     * @return string|null
     */
    private function get_customer_id($subscription) {
        return $subscription->get_meta('_monoova_customer_id');
    }

    /**
     * Process a scheduled PayTo subscription payment
     *
     * @param float $amount Recurring amount
     * @param WC_Order $renewal_order The order to process
     * @return void
     */
    public function process_scheduled_payto_payment($amount, $renewal_order) {
        try {
            // Get PayTo gateway instance
            $gateway = wc_get_payment_gateway_by_order($renewal_order);

            if (!$gateway || !($gateway instanceof Monoova_PayTo_Gateway)) {
                throw new Exception(__('PayTo payment gateway not found.', 'monoova-payments-for-woocommerce'));
            }

            // Get subscription
            $subscriptions = wcs_get_subscriptions_for_renewal_order($renewal_order);
            $subscription = reset($subscriptions);

            if (!$subscription) {
                throw new Exception(__('Subscription not found.', 'monoova-payments-for-woocommerce'));
            }

            // Get the PayTo agreement UID from the subscription
            $agreement_uid = $subscription->get_meta('_monoova_payto_agreement_uid', true);
            if (empty($agreement_uid)) {
                throw new Exception(__('No PayTo agreement found for subscription.', 'monoova-payments-for-woocommerce'));
            }

            // Create payment initiation for the renewal
            $result = $gateway->create_payment_initiation($renewal_order->get_id(), $agreement_uid);

            if (is_wp_error($result)) {
                $this->handle_payto_payment_failure($renewal_order, $result->get_error_message());
                return;
            }

            // Mark the renewal order as pending payment
            $renewal_order->update_status('pending', __('PayTo payment initiation created for subscription renewal.', 'monoova-payments-for-woocommerce'));

            // Add order note
            $renewal_order->add_order_note(
                sprintf(__('PayTo subscription payment initiated. Payment UID: %s', 'monoova-payments-for-woocommerce'), $result['paymentInitiationUID'] ?? '')
            );

        } catch (Exception $e) {
            $this->handle_payto_payment_failure($renewal_order, $e->getMessage());
        }
    }

    /**
     * Handle failed PayTo subscription payment
     *
     * @param WC_Order $renewal_order
     * @param string $error_message
     */
    private function handle_payto_payment_failure($renewal_order, $error_message) {
        // Add order note
        $renewal_order->add_order_note(
            sprintf(__('PayTo subscription payment failed. Error: %s', 'monoova-payments-for-woocommerce'), $error_message)
        );

        // Mark payment as failed
        $renewal_order->update_status('failed');

        // Use WooCommerce Subscriptions API to handle the failed payment
        if (function_exists('WC_Subscriptions_Manager') && method_exists('WC_Subscriptions_Manager', 'process_subscription_payment_failure_on_order')) {
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
        }
    }

    /**
     * Handle deleted payment method
     *
     * @param string $token_id
     * @param WC_Payment_Token $token
     */
    public function handle_payment_method_deleted($token_id, $token) {
        // Get subscriptions using this payment method
        $subscriptions = wcs_get_subscriptions_for_payment_token($token_id);

        foreach ($subscriptions as $subscription) {
            // Add note to subscription
            $subscription->add_order_note(
                sprintf(__('Payment method deleted: %s ending in %s', 'monoova-payments-for-woocommerce'), $token->get_card_type(), $token->get_last4())
            );

            // Update payment method to empty
            WC_Subscriptions_Change_Payment_Gateway::update_payment_method($subscription, '');

            // Maybe put subscription on hold
            if ($subscription->has_status('active')) {
                $subscription->update_status('on-hold', __('Subscription payment method was deleted.', 'monoova-payments-for-woocommerce'));
            }
        }
    }

    /**
     * Display message to update subscriptions when payment method changes
     * 
     * @param int $order_id
     */
    public function display_update_subscription_message($order_id) {
        // Only for logged in users
        if (!is_user_logged_in()) {
            return;
        }

        // Check if there are subscriptions with different payment methods
        $subscriptions = wcs_get_users_subscriptions(get_current_user_id());
        $has_other_payment_subscriptions = false;

        foreach ($subscriptions as $subscription) {
            if ($subscription->get_payment_method() !== 'monoova_card' && $subscription->has_status(array('active', 'on-hold'))) {
                $has_other_payment_subscriptions = true;
                break;
            }
        }

        if ($has_other_payment_subscriptions) {
            wc_add_notice(
                sprintf(
                    __('Would you like to use this payment method for your subscriptions? <a href="%s">Update subscriptions</a>', 'monoova-payments-for-woocommerce'),
                    esc_url(wc_get_account_endpoint_url('subscriptions'))
                ),
                'notice'
            );
        }
    }

    /**
     * Handle subscription payment method updated
     *
     * @param WC_Subscription $subscription
     * @param string $old_payment_method
     * @param string $new_payment_method
     */
    public function handle_subscription_payment_method_updated($subscription, $old_payment_method, $new_payment_method) {
        if ($old_payment_method === 'monoova_card' && $new_payment_method !== 'monoova_card') {
            // Subscription was changed away from Monoova
            // You might want to cancel the payment agreement
            $subscription->delete_meta_data('_monoova_payment_token_id');
            $subscription->delete_meta_data('_monoova_customer_id');
            $subscription->save();
        }
    }

    /**
     * Handle subscription payment method updated to Monoova Card
     *
     * @param WC_Subscription $subscription
     * @param array $data
     */
    public function handle_payment_method_updated_to_monoova($subscription, $data) {
        if (!empty($data['payment_token'])) {
            // Get token
            $token = WC_Payment_Tokens::get($data['payment_token']);

            if ($token && $token->get_gateway_id() === 'monoova_card') {
                // Save token to subscription
                $subscription->update_meta_data('_monoova_payment_token_id', $token->get_id());

                // Save customer ID
                $customer_id = get_user_meta($token->get_user_id(), '_monoova_customer_id', true);
                if ($customer_id) {
                    $subscription->update_meta_data('_monoova_customer_id', $customer_id);
                }

                $subscription->save();
            }
        }
    }



    /**
     * Format amount for Monoova API
     *
     * @param float $amount
     * @param string $currency
     * @return int
     */
    protected function format_amount($amount, $currency) {
        // Monoova expects amounts in the smallest currency unit
        return (int) round($amount * 100);
    }
}

// Initialize class after gateways are registered
add_action('woocommerce_payment_gateways', function() {
    // Initialize on a later hook to ensure gateways are registered
    add_action('init', function() {
        new WC_Monoova_Subscriptions();
    }, 20); // Priority 20 to ensure it runs after gateway registration
});