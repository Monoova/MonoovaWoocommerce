<?php

/**
 * Monoova Admin Settings Handler
 * 
 * Handles AJAX requests for saving unified payment gateway settings
 * 
 * @package Monoova_Payments_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova Admin Settings Handler Class
 */
class Monoova_Admin_Settings_Handler {

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
     * Constructor
     */
    public function __construct() {
        // Enable debug mode based on unified gateway settings
        $this->init_debug_mode();

        // Initialize logger if debug is enabled
        if ($this->debug) {
            $this->logger = wc_get_logger();
        }

        add_action('wp_ajax_monoova_save_payment_settings', array($this, 'save_payment_settings'));
        add_action('wp_ajax_monoova_generate_automatcher', array($this, 'generate_automatcher_account'));
        // check if all webhook subscriptions for Monoova (Card, PayTo, and Payments) is active
        add_action('wp_ajax_monoova_check_webhook_subscriptions_status', array($this, 'check_webhook_subscriptions_status'));
        // subscribe to all necessary webhook subscriptions for Monoova (Card, PayTo, and Payments)
        add_action('wp_ajax_monoova_subscribe_to_webhook_events', array($this, 'subscribe_to_webhook_events'));
    }

    public function check_webhook_subscriptions_status() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_check_webhook_subscriptions_nonce')) {
            $this->log('Check webhook subscriptions: Nonce verification failed', 'error');
            wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            $this->log('Check webhook subscriptions: User capabilities check failed', 'error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Get testmode parameter
        $is_testmode = isset($_POST['is_testmode']) && $_POST['is_testmode'] === 'true';
        $mode_label = $is_testmode ? 'Sandbox' : 'Live';

        $this->log('Checking webhook subscriptions status for ' . $mode_label . ' mode');

        try {
            // Create API instance based on testmode parameter
            $api = $this->create_api_instance($is_testmode);
            if (!$api) {
                throw new Exception(__('Monoova API client not available. Please check your configuration.', 'monoova-payments-for-woocommerce'));
            }

            $webhook_url = home_url('/?wc-api=monoova_webhook');
            $this->log('Expected webhook URL: ' . $webhook_url);

            // Check Card and PayTo subscriptions
            $card_payto_response = $api->get_all_card_and_payto_webhook_subscriptions();
            if (is_wp_error($card_payto_response)) {
                throw new Exception('Failed to get Card/PayTo subscriptions: ' . $card_payto_response->get_error_message());
            }

            $this->log('Card/PayTo subscriptions response: ' . wp_json_encode($card_payto_response), 'debug');

            $card_active = false;
            $payto_active = false;

            if (isset($card_payto_response['subscriptionDetails']) && is_array($card_payto_response['subscriptionDetails'])) {
                $subscriptions = $card_payto_response['subscriptionDetails'];

                // Check Card subscriptions
                $card_events = array('CreditCardPaymentNotification', 'CreditCardRefundNotification');
                $card_found = array();

                // Check PayTo subscriptions
                $payto_events = array('PaymentAgreementNotification', 'PaymentInstructionNotification');
                $payto_found = array();

                foreach ($subscriptions as $subscription) {
                    $event_name = $subscription['eventName'] ?? '';
                    $callback_url = $subscription['webHookDetail']['callBackUrl'] ?? '';
                    $is_active = $subscription['isActive'] ?? false;

                    // Check if this is a Card event
                    if (in_array($event_name, $card_events)) {
                        if ($callback_url === $webhook_url && $is_active) {
                            $card_found[] = $event_name;
                        }
                    }

                    // Check if this is a PayTo event
                    if (in_array($event_name, $payto_events)) {
                        if ($callback_url === $webhook_url && $is_active) {
                            $payto_found[] = $event_name;
                        }
                    }
                }

                $card_active = count($card_found) === count($card_events);
                $payto_active = count($payto_found) === count($payto_events);
            }

            // Check Payments subscriptions
            $payments_response = $api->get_all_payments_webhook_subscriptions();
            if (is_wp_error($payments_response)) {
                throw new Exception('Failed to get Payments subscriptions: ' . $payments_response->get_error_message());
            }

            $this->log('Payments subscriptions response: ' . wp_json_encode($payments_response), 'debug');

            $payments_active = false;
            $required_payment_events = array(
                'NppReturn',
                'NppPaymentStatus',
                'PayToReceivePayment',
                'NPPReceivePayment',
                'InboundDirectCredit',
                'NPPCreditRejections',
                'InboundDirectCreditRejections'
            );

            if (isset($payments_response['eventName']) && is_array($payments_response['eventName'])) {
                $payment_events = $payments_response['eventName'];
                $payments_found = array();

                foreach ($payment_events as $event) {
                    $event_name = $event['eventName'] ?? '';
                    $target_url = $event['targetUrl'] ?? '';
                    $status = $event['status'] ?? '';

                    if (in_array($event_name, $required_payment_events)) {
                        if ($target_url === $webhook_url && strtolower($status) === 'on') {
                            $payments_found[] = $event_name;
                        }
                    }
                }
                $this->log('Payments found: ' . wp_json_encode($payments_found), 'debug');
                $this->log('Required payments events: ' . wp_json_encode($required_payment_events), 'debug');
                $payments_active = count($payments_found) === count($required_payment_events);
            }

            $all_active = $card_active && $payto_active && $payments_active;

            $this->log(sprintf(
                'Webhook status check complete - Card: %s, PayTo: %s, Payments: %s, All Active: %s',
                $card_active ? 'active' : 'inactive',
                $payto_active ? 'active' : 'inactive',
                $payments_active ? 'active' : 'inactive',
                $all_active ? 'true' : 'false'
            ));

            wp_send_json_success(array(
                'all_active' => $all_active,
                'card_active' => $card_active,
                'payto_active' => $payto_active,
                'payments_active' => $payments_active,
                'message' => $all_active
                    ? __('All webhook subscriptions are active.', 'monoova-payments-for-woocommerce')
                    : __('Some webhook subscriptions are inactive.', 'monoova-payments-for-woocommerce')
            ));
        } catch (Exception $e) {
            $this->log('Check webhook subscriptions error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function subscribe_to_webhook_events() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_subscribe_webhook_events_nonce')) {
            $this->log('Subscribe webhook events: Nonce verification failed', 'error');
            wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            $this->log('Subscribe webhook events: User capabilities check failed', 'error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Get testmode parameter
        $is_testmode = isset($_POST['is_testmode']) && $_POST['is_testmode'] === 'true';
        $mode_label = $is_testmode ? 'Sandbox' : 'Live';

        $this->log('Starting webhook events subscription process for ' . $mode_label . ' mode');

        try {
            // Create API instance based on testmode parameter
            $api = $this->create_api_instance($is_testmode);
            if (!$api) {
                throw new Exception(__('Monoova API client not available. Please check your configuration.', 'monoova-payments-for-woocommerce'));
            }

            // Step 0: Get all existing Card and PayTo subscriptions
            $existing_subscriptions = $api->get_all_card_and_payto_webhook_subscriptions();
            if (is_wp_error($existing_subscriptions)) {
                throw new Exception('Failed to get existing subscriptions: ' . $existing_subscriptions->get_error_message());
            }

            $this->log('Existing subscriptions: ' . wp_json_encode($existing_subscriptions), 'debug');

            // Create a lookup map for existing subscriptions by eventName
            $existing_lookup = array();
            if (isset($existing_subscriptions['subscriptionDetails']) && is_array($existing_subscriptions['subscriptionDetails'])) {
                foreach ($existing_subscriptions['subscriptionDetails'] as $subscription) {
                    $event_name = $subscription['eventName'] ?? '';
                    if ($event_name) {
                        $existing_lookup[$event_name] = $subscription;
                    }
                }
            }

            // Step 1: Create/Update Card and PayTo subscriptions
            $card_payto_subscriptions = array(
                // Card subscriptions
                array(
                    'eventName' => 'CreditCardPaymentNotification',
                    'subscriptionName' => 'CreditCardPaymentStatusEvent'
                ),
                array(
                    'eventName' => 'CreditCardRefundNotification',
                    'subscriptionName' => 'CreditCardRefundStatusEvent'
                ),
                // PayTo subscriptions
                array(
                    'eventName' => 'PaymentAgreementNotification',
                    'subscriptionName' => 'PaymentAgreementStatusEvent'
                ),
                array(
                    'eventName' => 'PaymentInstructionNotification',
                    'subscriptionName' => 'PaymentInstructionStatusEvent'
                )
            );

            $card_payto_results = array();
            foreach ($card_payto_subscriptions as $subscription_data) {
                $event_request = (object) $subscription_data;

                $this->log('Processing Card/PayTo subscription for event: ' . $event_request->eventName);

                // Try to create the subscription
                $response = $api->create_subscription_for_card_payto_notification($event_request);

                if (is_wp_error($response)) {
                    $error_data = $response->get_error_data();
                    $error_code = $response->get_error_code();
                    $http_status = isset($error_data['status']) ? $error_data['status'] : null;

                    $this->log('Error response - Code: ' . $error_code . ', HTTP Status: ' . $http_status . ', Data: ' . wp_json_encode($error_data), 'debug');

                    // Check if it's a 400 error (subscription already exists)
                    if ($http_status === 400 || (isset($error_data['response']['code']) && $error_data['response']['code'] === 400)) {
                        $this->log('Subscription already exists for ' . $event_request->eventName . ', attempting update');

                        // Find the existing subscription ID
                        if (isset($existing_lookup[$event_request->eventName])) {
                            $subscription_id = $existing_lookup[$event_request->eventName]['subscriptionId'] ?? '';
                            if ($subscription_id) {
                                $update_response = $api->update_subscription_for_card_notification($subscription_id, $event_request);
                                if (is_wp_error($update_response)) {
                                    throw new Exception('Failed to update Card/PayTo subscription for ' . $event_request->eventName . ': ' . $update_response->get_error_message());
                                }
                                $card_payto_results[] = array('event' => $event_request->eventName, 'action' => 'updated');
                            } else {
                                throw new Exception('Could not find subscription ID for existing ' . $event_request->eventName . ' subscription');
                            }
                        } else {
                            throw new Exception('Subscription exists but not found in existing subscriptions list for ' . $event_request->eventName);
                        }
                    } else {
                        throw new Exception('Failed to create Card/PayTo subscription for ' . $event_request->eventName . ': ' . $response->get_error_message());
                    }
                } else {
                    $card_payto_results[] = array('event' => $event_request->eventName, 'action' => 'created');
                }
            }

            // Step 2: Create/Update Payments subscriptions
            $payment_events = array(
                'NppReturn',
                'NppPaymentStatus',
                'PayToReceivePayment',
                'NPPReceivePayment',
                'InboundDirectCredit',
                'NPPCreditRejections',
                'InboundDirectCreditRejections'
            );

            $payment_results = array();
            foreach ($payment_events as $event_name) {
                $event_request = (object) array('eventName' => $event_name);

                $this->log('Processing Payments subscription for event: ' . $event_name);

                // Try to create the subscription
                $response = $api->create_subscription_for_payments_notification($event_request);

                if (is_wp_error($response)) {
                    $error_data = $response->get_error_data();
                    $error_code = $response->get_error_code();
                    $http_status = isset($error_data['status']) ? $error_data['status'] : null;

                    $this->log('Payments error response - Code: ' . $error_code . ', HTTP Status: ' . $http_status . ', Data: ' . wp_json_encode($error_data), 'debug');

                    // Check if it's a 400 error (subscription already exists) or specifically "WebhookAlreadySubscribed"
                    $is_already_subscribed = ($http_status === 400 || (isset($error_data['response']['code']) && $error_data['response']['code'] === 400)) &&
                        (isset($error_data['response']['status']) && $error_data['response']['status'] === 'WebhookAlreadySubscribed');

                    if ($is_already_subscribed || ($http_status === 400 && isset($error_data['response']['id']))) {
                        $this->log('Subscription already exists for ' . $event_name . ', attempting update');

                        // For payments API, the error response should contain the ID
                        $response_body = $response->get_error_data();
                        $subscription_id = null;
                        $this->log('Raw Payments API error response body: ' . wp_json_encode($response_body), 'debug');

                        // The subscription ID should be in the 'response' field which contains the decoded JSON response
                        if (isset($response_body['response']['id'])) {
                            $subscription_id = $response_body['response']['id'];
                            $this->log('Found subscription ID in response.id: ' . $subscription_id, 'debug');
                        }

                        $this->log('Extracted subscription ID: ' . $subscription_id . ' for event: ' . $event_name, 'debug');

                        if ($subscription_id) {
                            $update_response = $api->update_subscription_for_payments_notification($subscription_id);
                            if (is_wp_error($update_response)) {
                                throw new Exception('Failed to update Payments subscription for ' . $event_name . ': ' . $update_response->get_error_message());
                            }
                            $payment_results[] = array('event' => $event_name, 'action' => 'updated');
                        } else {
                            // Fallback: try to get existing subscriptions and find the one for this event
                            $this->log('Subscription ID not found in error response, trying to fetch existing subscriptions for ' . $event_name, 'debug');
                            $existing_subscriptions = $api->get_all_payments_webhook_subscriptions();

                            if (!is_wp_error($existing_subscriptions) && isset($existing_subscriptions['subscriptions'])) {
                                foreach ($existing_subscriptions['subscriptions'] as $sub) {
                                    if (isset($sub['eventName']) && $sub['eventName'] === $event_name) {
                                        $subscription_id = $sub['id'];
                                        $this->log('Found existing subscription ID via lookup: ' . $subscription_id . ' for event: ' . $event_name, 'debug');
                                        break;
                                    }
                                }

                                if ($subscription_id) {
                                    $update_response = $api->update_subscription_for_payments_notification($subscription_id);
                                    if (is_wp_error($update_response)) {
                                        throw new Exception('Failed to update Payments subscription for ' . $event_name . ': ' . $update_response->get_error_message());
                                    }
                                    $payment_results[] = array('event' => $event_name, 'action' => 'updated');
                                } else {
                                    throw new Exception('Could not find existing subscription ID for ' . $event_name . ' even with fallback lookup');
                                }
                            } else {
                                throw new Exception('Could not find subscription ID in error response and failed to fetch existing subscriptions for ' . $event_name);
                            }
                        }
                    } else {
                        throw new Exception('Failed to create Payments subscription for ' . $event_name . ': ' . $response->get_error_message());
                    }
                } else {
                    $payment_results[] = array('event' => $event_name, 'action' => 'created');
                }
            }

            $this->log('Webhook subscription process completed successfully');
            $this->log('Card/PayTo results: ' . wp_json_encode($card_payto_results), 'debug');
            $this->log('Payment results: ' . wp_json_encode($payment_results), 'debug');

            wp_send_json_success(array(
                'message' => __('All webhook subscriptions have been successfully created/updated.', 'monoova-payments-for-woocommerce'),
                'card_payto_results' => $card_payto_results,
                'payment_results' => $payment_results
            ));
        } catch (Exception $e) {
            $this->log('Subscribe webhook events error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Create API instance based on testmode parameter
     *
     * @param bool $is_testmode Whether to use test mode
     * @return Monoova_API|null API instance or null if configuration is missing
     */
    private function create_api_instance($is_testmode) {
        // Get settings from unified gateway
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
        if (!$unified_settings || !is_array($unified_settings)) {
            $this->log('Unified settings not found or invalid', 'error');
            return null;
        }

        // Get API key based on testmode
        $api_key = $is_testmode ?
            ($unified_settings['test_api_key'] ?? '') : ($unified_settings['live_api_key'] ?? '');

        // Get API URLs based on testmode with defaults
        $payments_api_url = $is_testmode ?
            ($unified_settings['monoova_payments_api_url_sandbox'] ?? 'https://api.m-pay.com.au') : ($unified_settings['monoova_payments_api_url_live'] ?? 'https://api.mpay.com.au');

        $card_api_url = $is_testmode ?
            ($unified_settings['monoova_card_api_url_sandbox'] ?? 'https://sand-api.monoova.com') : ($unified_settings['monoova_card_api_url_live'] ?? 'https://api.monoova.com');

        $maccount_number = $unified_settings['maccount_number'] ?? '';

        // Validate required settings
        if (empty($api_key) || empty($payments_api_url) || empty($card_api_url) || empty($maccount_number)) {
            $this->log('Missing required API configuration. API Key: ' . (!empty($api_key) ? 'set' : 'missing') .
                ', Payments URL: ' . $payments_api_url .
                ', Card URL: ' . $card_api_url .
                ', mAccount: ' . $maccount_number, 'error');
            return null;
        }

        // Create and return API instance
        return new Monoova_API(
            $payments_api_url,
            $card_api_url,
            $api_key,
            $maccount_number,
            $is_testmode,
            true // debug enabled
        );
    }

    /**
     * Initialize debug mode from gateway settings
     */
    private function init_debug_mode() {
        // Check debug setting from unified gateway first
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
        if (isset($unified_settings['debug']) && $unified_settings['debug'] === 'yes') {
            $this->debug = true;
            return;
        }

        // Fallback to card gateway debug setting
        $card_settings = get_option('woocommerce_monoova_card_settings', array());
        if (isset($card_settings['debug']) && $card_settings['debug'] === 'yes') {
            $this->debug = true;
            return;
        }

        // Fallback to payid gateway debug setting
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        if (isset($payid_settings['debug']) && $payid_settings['debug'] === 'yes') {
            $this->debug = true;
        }
    }

    /**
     * Logging method - writes to separate log file for admin settings
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values: emergency, alert, critical, error, warning, notice, info, debug.
     */
    private function log($message, $level = 'info') {
        if ($this->debug && $this->logger) {
            $formatted_message = '[Admin Settings] ' . $message;
            $this->logger->log($level, $formatted_message, array('source' => 'monoova-admin-settings'));
        }
    }

    /**
     * Handle AJAX request to save payment settings
     */
    public function save_payment_settings() {
        // Verify nonce
        // if (!wp_verify_nonce($_POST['nonce'], 'monoova_save_settings')) {
        //     $this->log('Nonce verification failed', 'error');
        //     wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
        //     return;
        // }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            $this->log('User capabilities check failed', 'error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Get settings from request
        $settings = json_decode(stripslashes($_POST['settings']), true);
        $tab = sanitize_text_field($_POST['tab']);

        $this->log('Decoded settings: ' . print_r($settings, true), 'debug');

        if (!is_array($settings)) {
            wp_send_json_error(array('message' => __('Invalid settings data.', 'monoova-payments-for-woocommerce')));
            return;
        }

        try {
            // Save settings based on tab or save all if tab is 'all'
            switch ($tab) {
                case 'general_settings':
                    $this->log('Saving general settings');
                    $this->save_general_settings($settings);
                    break;
                case 'payment_methods':
                    $this->log('Saving payment methods settings');
                    $this->save_payment_methods_settings($settings);
                    break;
                case 'card_settings':
                    $this->log('Saving card settings');
                    $this->save_card_settings($settings);
                    break;
                case 'payid_settings':
                    $this->log('Saving PayID settings');
                    $this->save_payid_settings($settings);
                    break;
                case 'payto_settings':
                    $this->log('Saving PayTo settings');
                    $this->save_payto_settings($settings);
                    break;
                case 'all':
                default:
                    $this->log('Saving all settings');
                    $this->save_general_settings($settings);
                    $this->save_payment_methods_settings($settings);
                    $this->save_card_settings($settings);
                    $this->save_payid_settings($settings);
                    $this->save_payto_settings($settings);
                    break;
            }

            $this->log('Settings saved successfully for tab: ' . $tab);

            // Force refresh of gateway instances after saving
            $this->refresh_gateway_instances();

            wp_send_json_success(array('message' => __('Settings saved successfully!', 'monoova-payments-for-woocommerce')));
        } catch (Exception $e) {
            $this->log('Save error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Save payment methods settings (Tab 1)
     */
    private function save_payment_methods_settings($settings) {
        // Update card gateway enabled status
        $card_settings = get_option('woocommerce_monoova_card_settings', array());

        // Explicitly check for false to ensure 'no' is set for checkboxes that are unchecked
        $card_settings['enabled'] = isset($settings['enable_card_payments']) && $settings['enable_card_payments'] ? 'yes' : 'no';
        $card_settings['enable_express_checkout'] = isset($settings['enable_express_checkout']) && $settings['enable_express_checkout'] ? 'yes' : 'no';
        update_option('woocommerce_monoova_card_settings', $card_settings);

        // Update payid gateway enabled status
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        $payid_settings['enabled'] = isset($settings['enable_payid_payments']) && $settings['enable_payid_payments'] ? 'yes' : 'no';
        update_option('woocommerce_monoova_payid_settings', $payid_settings);

        // Update payto gateway enabled status
        $payto_settings = get_option('woocommerce_monoova_payto_settings', array());
        $payto_settings['enabled'] = isset($settings['enable_payto_payments']) && $settings['enable_payto_payments'] ? 'yes' : 'no';
        $payto_settings['enable_payto_express_checkout'] = isset($settings['enable_payto_express_checkout']) && $settings['enable_payto_express_checkout'] ? 'yes' : 'no';
        update_option('woocommerce_monoova_payto_settings', $payto_settings);

        // Save payment method settings to unified gateway as well
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());
        $unified_settings['enable_card_payments'] = isset($settings['enable_card_payments']) && $settings['enable_card_payments'] ? 'yes' : 'no';
        $unified_settings['enable_payid_payments'] = isset($settings['enable_payid_payments']) && $settings['enable_payid_payments'] ? 'yes' : 'no';
        $unified_settings['enable_payto_payments'] = isset($settings['enable_payto_payments']) && $settings['enable_payto_payments'] ? 'yes' : 'no';
        $unified_settings['enable_express_checkout'] = isset($settings['enable_express_checkout']) && $settings['enable_express_checkout'] ? 'yes' : 'no';
        $unified_settings['enable_payto_express_checkout'] = isset($settings['enable_payto_express_checkout']) && $settings['enable_payto_express_checkout'] ? 'yes' : 'no';
        $unified_settings['express_checkout_method_priority'] = isset($settings['express_checkout_method_priority']) ? sanitize_text_field($settings['express_checkout_method_priority']) : 'card';
        update_option('woocommerce_monoova_unified_settings', $unified_settings);
    }

    /**
     * Save general settings (General Settings Tab)
     */
    private function save_general_settings($settings) {
        // Update unified gateway settings (enabled and testmode settings)
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());

        // Save the enabled status for unified gateway
        if (isset($settings['enabled'])) {
            $unified_settings['enabled'] = $settings['enabled'] ? 'yes' : 'no';
        }

        // Save the testmode setting for unified gateway
        // if (isset($settings['testmode'])) {
        //     $unified_settings['testmode'] = $settings['testmode'] ? 'yes' : 'no';
        // }

        update_option('woocommerce_monoova_unified_settings', $unified_settings);

        // Save common settings to both card and payid gateways
        $this->save_common_settings_to_gateways($settings);
    }

    /**
     * Save card settings (Tab 2)
     */
    private function save_card_settings($settings) {
        $card_settings = get_option('woocommerce_monoova_card_settings', array());

        // Card-specific settings including title and description
        $card_fields = array(
            'title' => 'string',
            'description' => 'textarea',
            'testmode' => 'boolean',
            'debug' => 'boolean',
            'capture' => 'boolean',
            'saved_cards' => 'boolean',
            'apply_surcharge' => 'boolean',
            'surcharge_amount' => 'float',
            'enable_apple_pay' => 'boolean',
            'enable_google_pay' => 'boolean',
            'order_button_text' => 'string'
        );

        foreach ($card_fields as $field => $type) {
            // Map card_title to title and card_description to description
            $setting_key = $field;
            if ($field === 'title' && isset($settings['card_title'])) {
                $value = $settings['card_title'];
            } elseif ($field === 'description' && isset($settings['card_description'])) {
                $value = $settings['card_description'];
            } elseif ($field === 'testmode' && isset($settings['card_testmode'])) {
                $value = $settings['card_testmode'];
            } elseif ($field === 'debug' && isset($settings['card_debug'])) {
                $value = $settings['card_debug'];
            } elseif (isset($settings[$field])) {
                $value = $settings[$field];
            } else {
                continue;
            }

            switch ($type) {
                case 'boolean':
                    $card_settings[$field] = $value ? 'yes' : 'no';
                    break;
                case 'float':
                    $card_settings[$field] = (float) $value;
                    break;
                case 'textarea':
                    $card_settings[$field] = sanitize_textarea_field($value);
                    break;
                case 'string':
                default:
                    $card_settings[$field] = sanitize_text_field($value);
                    break;
            }
        }

        // Handle checkout_ui_styles nested object
        if (isset($settings['checkout_ui_styles']) && is_array($settings['checkout_ui_styles'])) {
            $card_settings['checkout_ui_styles'] = $this->sanitize_checkout_ui_styles($settings['checkout_ui_styles']);
        }

        update_option('woocommerce_monoova_card_settings', $card_settings);
    }

    /**
     * Save PayID settings (Tab 3)
     */
    private function save_payid_settings($settings) {
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());

        // PayID-specific settings including title and description
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());

        // PayID-specific settings including title and description
        $payid_fields = array(
            'title' => 'string',
            'description' => 'textarea',
            'testmode' => 'boolean',
            'debug' => 'boolean',
            'static_bank_account_name' => 'string',
            'static_bsb' => 'string',
            'static_account_number' => 'string',
            'expire_hours' => 'integer',
            'account_name' => 'string',
            'instructions' => 'textarea',
            'payid_show_reference_field' => 'boolean',
        );

        foreach ($payid_fields as $field => $type) {
            // Map payid_title to title and payid_description to description
            $setting_key = $field;
            if ($field === 'title' && isset($settings['payid_title'])) {
                $value = $settings['payid_title'];
            } elseif ($field === 'description' && isset($settings['payid_description'])) {
                $value = $settings['payid_description'];
            } elseif ($field === 'testmode' && isset($settings['payid_testmode'])) {
                $value = $settings['payid_testmode'];
            } elseif ($field === 'debug' && isset($settings['payid_debug'])) {
                $value = $settings['payid_debug'];
            } elseif (isset($settings[$field])) {
                $value = $settings[$field];
            } else {
                continue;
            }

            switch ($type) {
                case 'boolean':
                    $payid_settings[$field] = $value ? 'yes' : 'no';
                    break;
                case 'integer':
                    $payid_settings[$field] = intval($value);
                    break;
                case 'textarea':
                    $payid_settings[$field] = sanitize_textarea_field($value);
                    break;
                case 'string':
                default:
                    $payid_settings[$field] = sanitize_text_field($value);
                    break;
            }
        }

        // Handle payment_types array
        if (isset($settings['payment_types']) && is_array($settings['payment_types'])) {
            $payid_settings['payment_types'] = array_map('sanitize_text_field', $settings['payment_types']);
        }

        // Handle payid_checkout_ui_styles nested object
        if (isset($settings['payid_checkout_ui_styles']) && is_array($settings['payid_checkout_ui_styles'])) {
            $payid_settings['payid_checkout_ui_styles'] = $this->sanitize_payid_checkout_ui_styles($settings['payid_checkout_ui_styles']);
        }

        update_option('woocommerce_monoova_payid_settings', $payid_settings);
    }

    /**
     * Save PayTo settings (PayTo Settings Tab)
     */
    private function save_payto_settings($settings) {
        $payto_settings = get_option('woocommerce_monoova_payto_settings', array());

        // PayTo-specific settings including title and description
        $payto_fields = array(
            'title' => 'string',
            'description' => 'textarea',
            'testmode' => 'boolean',
            'debug' => 'boolean',
            'payto_purpose' => 'string',
            'payto_agreement_expiry_days' => 'integer',
            'payto_payee_type' => 'string',
            'payto_maximum_amount' => 'float',
        );

        foreach ($payto_fields as $field => $type) {
            // Map payto_title to title and payto_description to description
            $setting_key = $field;
            if ($field === 'title' && isset($settings['payto_title'])) {
                $value = $settings['payto_title'];
            } elseif ($field === 'description' && isset($settings['payto_description'])) {
                $value = $settings['payto_description'];
            } elseif ($field === 'testmode' && isset($settings['payto_testmode'])) {
                $value = $settings['payto_testmode'];
            } elseif ($field === 'debug' && isset($settings['payto_debug'])) {
                $value = $settings['payto_debug'];
            } elseif ($field === 'payto_purpose' && isset($settings['payto_purpose'])) {
                $value = $settings['payto_purpose'];
                $setting_key = 'purpose'; // Map to the correct key expected by the gateway
            } elseif ($field === 'payto_agreement_expiry_days' && isset($settings['payto_agreement_expiry_days'])) {
                $value = $settings['payto_agreement_expiry_days'];
                $setting_key = 'agreement_expiry_days'; // Map to the correct key expected by the gateway
            } elseif ($field === 'payto_payee_type' && isset($settings['payto_payee_type'])) {
                $value = $settings['payto_payee_type'];
                $setting_key = 'payee_type'; // Map to the correct key expected by the gateway
            } elseif ($field === 'payto_maximum_amount' && isset($settings['payto_maximum_amount'])) {
                $value = $settings['payto_maximum_amount'];
                $setting_key = 'maximum_amount'; // Map to the correct key expected by the gateway
            } elseif (isset($settings[$field])) {
                $value = $settings[$field];
            } else {
                continue;
            }

            switch ($type) {
                case 'boolean':
                    $payto_settings[$setting_key] = $value ? 'yes' : 'no';
                    break;
                case 'textarea':
                    $payto_settings[$setting_key] = sanitize_textarea_field($value);
                    break;
                case 'integer':
                    $payto_settings[$setting_key] = absint($value);
                    break;
                case 'float':
                    $payto_settings[$setting_key] = floatval($value);
                    break;
                case 'string':
                default:
                    $payto_settings[$setting_key] = sanitize_text_field($value);
                    break;
            }
        }

        // Handle payto_checkout_ui_styles nested object
        if (isset($settings['payto_checkout_ui_styles']) && is_array($settings['payto_checkout_ui_styles'])) {
            $payto_settings['payto_checkout_ui_styles'] = $this->sanitize_payto_checkout_ui_styles($settings['payto_checkout_ui_styles']);
        }

        update_option('woocommerce_monoova_payto_settings', $payto_settings);
        $this->log('PayTo settings updated: ' . print_r($payto_settings, true));
    }

    /**
     * Save common settings shared between gateways
     */
    private function save_common_settings_to_gateways($settings) {
        // Save common settings to both gateways
        $common_fields = array(
            'maccount_number' => 'string',
            'test_api_key' => 'string',
            'live_api_key' => 'string',
            'monoova_payments_api_url_sandbox' => 'string',
            'monoova_payments_api_url_live' => 'string',
            'monoova_card_api_url_sandbox' => 'string',
            'monoova_card_api_url_live' => 'string'
        );

        // Also handle testmode as a common boolean setting
        $common_boolean_fields = array(
            'testmode' => 'boolean'
        );

        $card_settings = get_option('woocommerce_monoova_card_settings', array());
        $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
        $unified_settings = get_option('woocommerce_monoova_unified_settings', array());

        // Handle string fields
        foreach ($common_fields as $field => $type) {
            if (isset($settings[$field])) {
                $value = sanitize_text_field($settings[$field]);

                $card_settings[$field] = $value;
                $payid_settings[$field] = $value;
                $unified_settings[$field] = $value;
            }
        }

        // Handle boolean fields
        foreach ($common_boolean_fields as $field => $type) {
            if ($field === 'testmode') {
                // Skip unified testmode - each gateway will handle its own testmode independently
                continue;
            }

            $value = isset($settings[$field]) && $settings[$field] ? 'yes' : 'no';

            $card_settings[$field] = $value;
            $payid_settings[$field] = $value;
            $unified_settings[$field] = $value;
        }

        update_option('woocommerce_monoova_card_settings', $card_settings);
        update_option('woocommerce_monoova_payid_settings', $payid_settings);
        update_option('woocommerce_monoova_unified_settings', $unified_settings);

        // Add debug logging
        // if ($this->debug) {
        //     $this->log('Common settings saved to all gateways');
        //     $this->log('Card settings: ' . print_r($card_settings, true));
        //     $this->log('PayID settings: ' . print_r($payid_settings, true));
        //     $this->log('Unified settings: ' . print_r($unified_settings, true));
        // }

        // Force refresh of gateway instances to pick up new settings
        $this->refresh_gateway_instances();
    }

    /**
     * Sanitize checkout UI styles nested object
     */
    private function sanitize_checkout_ui_styles($styles) {
        $sanitized = array();

        // Define the expected structure
        $expected_structure = array(
            'input_label' => array(
                'font_family' => 'string',
                'font_weight' => 'string',
                'font_size' => 'string',
                'color' => 'string'
            ),
            'input' => array(
                'font_family' => 'string',
                'font_weight' => 'string',
                'font_size' => 'string',
                'background_color' => 'string',
                'border_color' => 'string',
                'border_radius' => 'string',
                'text_color' => 'string'
            ),
            'submit_button' => array(
                'font_family' => 'string',
                'font_size' => 'string',
                'background' => 'string',
                'border_radius' => 'string',
                'border_color' => 'string',
                'font_weight' => 'string',
                'text_color' => 'string'
            )
        );

        foreach ($expected_structure as $section => $fields) {
            if (isset($styles[$section]) && is_array($styles[$section])) {
                $sanitized[$section] = array();
                foreach ($fields as $field => $type) {
                    if (isset($styles[$section][$field])) {
                        $sanitized[$section][$field] = sanitize_text_field($styles[$section][$field]);
                    }
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize PayID checkout UI styles nested object
     */
    private function sanitize_payid_checkout_ui_styles($styles) {
        $sanitized = array();

        // Define the expected structure for PayID UI styles
        $expected_structure = array(
            'scan_pay' => array(
                'pay_label' => array(
                    'font_size' => 'string',
                    'font_weight' => 'string',
                    'color' => 'string'
                ),
                'amount' => array(
                    'font_size' => 'string',
                    'font_weight' => 'string',
                    'color' => 'string'
                )
            )
        );

        foreach ($expected_structure as $section => $subsections) {
            if (isset($styles[$section]) && is_array($styles[$section])) {
                $sanitized[$section] = array();
                foreach ($subsections as $subsection => $fields) {
                    if (isset($styles[$section][$subsection]) && is_array($styles[$section][$subsection])) {
                        $sanitized[$section][$subsection] = array();
                        foreach ($fields as $field => $type) {
                            if (isset($styles[$section][$subsection][$field])) {
                                $sanitized[$section][$subsection][$field] = sanitize_text_field($styles[$section][$subsection][$field]);
                            }
                        }
                    }
                }
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize PayTo checkout UI styles nested object
     */
    private function sanitize_payto_checkout_ui_styles($styles) {
        $sanitized = array();

        // Define the expected structure for PayTo UI styles
        $expected_structure = array(
            'scan_pay' => array(
                'pay_label' => array(
                    'font_size' => 'string',
                    'font_weight' => 'string',
                    'color' => 'string'
                ),
                'amount' => array(
                    'font_size' => 'string',
                    'font_weight' => 'string',
                    'color' => 'string'
                )
            ),
            'submit_button' => array(
                'font_family' => 'string',
                'font_size' => 'string',
                'background' => 'string',
                'border_radius' => 'string',
                'border_color' => 'string',
                'font_weight' => 'string',
                'text_color' => 'string'
            )
        );

        foreach ($expected_structure as $section => $subsections_or_fields) {
            if (isset($styles[$section]) && is_array($styles[$section])) {
                $sanitized[$section] = array();

                // Check if this is a nested structure (like scan_pay) or direct fields (like submit_button)
                if ($section === 'scan_pay') {
                    // Handle nested structure
                    foreach ($subsections_or_fields as $subsection => $fields) {
                        if (isset($styles[$section][$subsection]) && is_array($styles[$section][$subsection])) {
                            $sanitized[$section][$subsection] = array();
                            foreach ($fields as $field => $type) {
                                if (isset($styles[$section][$subsection][$field])) {
                                    $sanitized[$section][$subsection][$field] = sanitize_text_field($styles[$section][$subsection][$field]);
                                }
                            }
                        }
                    }
                } else {
                    // Handle direct fields (like submit_button)
                    foreach ($subsections_or_fields as $field => $type) {
                        if (isset($styles[$section][$field])) {
                            $sanitized[$section][$field] = sanitize_text_field($styles[$section][$field]);
                        }
                    }
                }
            }
        }

        return $sanitized;
    }

    /**
     * Force refresh of gateway instances to pick up new settings
     */
    private function refresh_gateway_instances() {
        // Clear any cached gateway instances
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('woocommerce_payment_gateways', 'woocommerce');
        }

        // Force WooCommerce to reload payment gateways
        if (class_exists('WC_Payment_Gateways')) {
            WC_Payment_Gateways::instance()->init();
        }
    }

    /**
     * Handle AJAX request to generate a store-wide Automatcher account.
     */
    public function generate_automatcher_account() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_generate_automatcher_nonce')) {
            $this->log('Generate Automatcher: Nonce verification failed', 'error');
            wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            $this->log('Generate Automatcher: User capabilities check failed', 'error');
            wp_send_json_error(array('message' => __('Insufficient permissions.', 'monoova-payments-for-woocommerce')));
            return;
        }

        $this->log('Attempting to generate a new store-wide Automatcher account.');

        try {
            // We need an API client. We can get it from one of the gateways.
            $payid_gateway = new Monoova_PayID_Gateway();
            $api = $payid_gateway->get_api();

            // Get the account name from saved settings, fallback to site name
            $payid_settings = get_option('woocommerce_monoova_payid_settings', array());
            $bank_account_name = !empty($payid_settings['account_name']) ? sanitize_text_field($payid_settings['account_name']) : get_bloginfo('name');

            // Create a unique reference for this one-time account creation
            $client_unique_id = 'WC' . time();

            $payload = array(
                "clientUniqueId" => $client_unique_id,
                "bankAccountName" => $bank_account_name,
                "isActive" => true, // Request it to be active
            );

            $this->log('Payload for /receivables/v1/create (Automatcher): ' . wp_json_encode($payload), 'debug');
            $response = $api->create_receivables_account($payload);

            if (is_wp_error($response) || !isset($response['status']) || strtolower($response['status']) !== 'ok') {
                $error_msg = is_wp_error($response) ? $response->get_error_message() : ($response['statusDescription'] ?? 'Failed to create Automatcher account.');
                throw new Exception($error_msg);
            }

            $this->log('Successfully created new Automatcher account via API: ' . wp_json_encode($response), 'info');

            $new_bsb = $response['bsb'] ?? null;
            $new_account_number = $response['bankAccountNumber'] ?? null;

            if (!$new_bsb || !$new_account_number) {
                throw new Exception(__('API response was successful but did not contain BSB or Account Number.', 'monoova-payments-for-woocommerce'));
            }

            // Save the new details to the PayID settings
            $payid_settings['static_bank_account_name'] = $response['bankAccountName'] ?? $bank_account_name;
            $payid_settings['static_bsb'] = $new_bsb;
            $payid_settings['static_account_number'] = $new_account_number;
            update_option('woocommerce_monoova_payid_settings', $payid_settings);

            wp_send_json_success(array(
                'message' => __('New Automatcher account created and saved successfully! Please note it may take up to 5 minutes to become fully active for receiving payments.', 'monoova-payments-for-woocommerce'),
                'bsb' => $new_bsb,
                'accountNumber' => $new_account_number,
                'accountName' => $payid_settings['static_bank_account_name'],
            ));
        } catch (Exception $e) {
            $this->log('Generate Automatcher error: ' . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
}

// Initialize the handler after gateways are registered
add_action('woocommerce_payment_gateways', function() {
    // Initialize on a later hook to ensure gateways are registered
    add_action('init', function() {
        new Monoova_Admin_Settings_Handler();
    }, 20); // Priority 20 to ensure it runs after gateway registration
});
