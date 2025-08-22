<?php

/**
 * Monoova Webhook Handler
 *
 * This class handles incoming webhooks from Monoova's Card and Payment APIs.
 * It processes various payment events (success, failure, expiration) and updates
 * WooCommerce orders accordingly. It also handles subscription-related events
 * when WooCommerce Subscriptions is active.
 *
 * Key responsibilities:
 * - Validates webhook signatures for security
 * - Processes payment status updates
 * - Updates order statuses and adds order notes
 * - Handles subscription payment events
 * - Manages refunds and payment dishonors
 *
 * @package Monoova_Payments_For_WooCommerce
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Webhook Handler Class
 */
class Monoova_Webhook_Handler {
    /**
     * Card Gateway instance
     *
     * @var Monoova_Card_Gateway
     */
    protected $card_gateway;

    /**
     * PayID Gateway instance
     *
     * @var Monoova_PayID_Gateway
     */
    protected $payid_gateway;

    /**
     * Webhook secret from settings
     *
     * @var string
     */
    protected $webhook_secret;

    /**
     * Constructor
     * 
     * Initializes the webhook handler by:
     * 1. Getting instances of both Card and PayID gateways
     * 2. Setting up the webhook secret from gateway settings
     */
    public function __construct() {
        // Get gateway instances for API and settings
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->card_gateway = $gateways['monoova_card'] ?? null;
        $this->payid_gateway = $gateways['monoova_payid'] ?? null;

        // only use webhook signature from Card gateway
        if (!$this->card_gateway) {
            $this->log('Monoova Card Gateway not found. Webhook handler will not function properly.', 'error');
            return;
        }
        if (!$this->card_gateway->is_available()) {
            $this->log('Monoova Card Gateway is not enabled. Webhook handler will not function properly.', 'error');
            return;
        }
        $this->webhook_secret = $this->card_gateway->get_option('webhook_signature_public_key');
    }

    /**
     * Register webhook hooks
     * 
     * Registers the webhook endpoint with WooCommerce's API system.
     * we are using plain permalinks for WordPress, so we use the woocommerce_api action.
     * The endpoint will be available at https://kittendifferentb9aeecdf5d-omuxa-studio.wp.build/?wc-api=monoova_webhook
     */
    public function register_hooks() {
        add_action('woocommerce_api_monoova_webhook', array($this, 'process_webhook'));
    }

    /**
     * Determine if the webhook is from Card API based on payload structure.
     *
     * @param array $data Decoded JSON payload.
     * @return bool True if it's likely a Card API webhook.
     */
    protected function is_monoova_card_webhook_from_data($data) {
        // has field 'sourceEventName' and sourceEventName starts with 'CreditCard'
        return isset($data['sourceEventName']) && strpos($data['sourceEventName'], 'CreditCard') === 0;
    }

    /**
     * Determine if the webhook is from PayTo based on payload structure.
     *
     * @param array $data Decoded JSON payload.
     * @return bool True if it's likely a PayTo webhook.
     */
    protected function is_monoova_payto_webhook_from_data($data) {
        // has field 'paymentInitiationDetail' or 'paymentInitiationDetail'
        return isset($data['paymentInitiationDetail']) || isset($data['paymentInitiationDetails']) || isset($data['paymentAgreementDetails']);
    }

    /**
     * Log message with different levels
     */
    private function log($message, $level = 'info', $context = array()) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();

            // Add timestamp and webhook context
            $formatted_message = '[Webhook Handler] ' . $message;

            // Add context to the log source
            $log_context = array_merge(array('source' => 'monoova-webhook'), $context);

            switch ($level) {
                case 'error':
                    $logger->error($formatted_message, $log_context);
                    break;
                case 'warning':
                    $logger->warning($formatted_message, $log_context);
                    break;
                case 'debug':
                    $logger->debug($formatted_message, $log_context);
                    break;
                case 'info':
                default:
                    $logger->info($formatted_message, $log_context);
                    break;
            }
        }
    }

    /**
     * Log detailed webhook information
     */
    private function log_webhook_details($event_data, $signature = '') {
        $details = array(
            'timestamp' => current_time('mysql'),
            'signature' => $signature,
            'event_type' => isset($event_data['sourceEventName']) ? $event_data['sourceEventName'] : ($event_data['eventType'] ?? 'unknown'),
            'webhook_type' => $this->is_monoova_card_webhook_from_data($event_data)
                ? 'Card API'
                : ($this->is_monoova_payto_webhook_from_data($event_data) ? 'PayTo API' : 'Payment API'),
            'payload_size' => strlen(json_encode($event_data)),
            'payload' => $event_data
        );

        $this->log('Webhook Details: ' . json_encode($details, JSON_PRETTY_PRINT), 'debug', array('webhook_details' => $details));
    }

    /**
     * Check if WooCommerce Subscriptions is active and required functions exist
     */
    private function is_subscriptions_active() {
        return function_exists('wcs_order_contains_subscription')
            && function_exists('wcs_get_subscriptions_for_order');
    }

    /**
     * Process webhook
     * 
     * Main entry point for webhook processing. This method:
     * 1. Validates the incoming webhook payload and signature
     * 2. Decodes the JSON payload
     * 3. Routes the event to appropriate handler based on event type
     * 4. Handles common payment events (success, failure, expiration)
     * 5. Returns appropriate HTTP responses
     */
    public function process_webhook() {
        $payload = file_get_contents('php://input');
        //$signature = isset($_SERVER['HTTP_X_MONOOVA_SIGNATURE']) ? $_SERVER['HTTP_X_MONOOVA_SIGNATURE'] : '';
        // Monoova uses 'Verification-Signature' instead
        $headers = getallheaders();
        $signature = isset($headers['Verification-Signature']) ? $headers['Verification-Signature'] : '';
        $this->log('Processing webhook with signature: ' . $signature);

        if (empty($payload)) {
            $this->log('Missing payload', 'error');
            status_header(400);
            exit('Missing payload');
        }

        // Decode JSON payload first
        $event = json_decode($payload, true);
        if (!$event) {
            $this->log('Invalid JSON payload', 'error');
            status_header(400);
            exit('Invalid payload');
        }

        // Log detailed webhook information for debugging
        $this->log_webhook_details($event, $signature);

        // Log the incoming webhook for debugging
        $event_type = isset($event['sourceEventName']) ? $event['sourceEventName'] : ($event['eventType'] ?? 'unknown');
        $this->log('Received webhook event: ' . $event_type, 'info');

        // Verify webhook signature if signature is provided
        if (!empty($signature)) {

            $this->log('Webhook signature verified successfully', 'info');
        } else {
            $this->log('Invalid webhook signature', 'error');
            status_header(401);
            exit('Invalid signature');
        }

        try {
            if ($this->is_monoova_card_webhook_from_data($event)) {
                // Handle Card API webhook
                $this->log('Routing to Card API webhook handler', 'debug');
                $this->process_card_api_webhook($event);
            } elseif ($this->is_monoova_payto_webhook_from_data($event)) {
                // Handle PayTo API webhook
                $this->log('Routing to PayTo API webhook handler', 'debug');
                $this->process_payto_api_webhook($event);
            } else {
                // Handle Payment API webhook (PayID, Direct Credit, etc.)
                $this->log('Routing to Payment API webhook handler', 'debug');
                $this->process_payment_api_webhook($event);
            }
        } catch (Exception $e) {
            $this->log('Error processing webhook: ' . $e->getMessage(), 'error');
            status_header(500);
            exit('Error processing webhook');
        }
    }

    /**
     * Handle subscription payment if applicable
     * 
     * Processes subscription payments by:
     * 1. Checking if subscriptions are active
     * 2. Finding related subscriptions for the order
     * 3. Updating subscription payment status
     * 4. Adding subscription payment notes
     *
     * @param WC_Order $order The order object
     * @param string $payment_id The payment ID
     * @param string $status The payment status ('completed', 'failed', 'expired')
     */
    private function handle_subscription_payment($order, $payment_id, $status = 'completed') {
        if (!$this->is_subscriptions_active()) {
            return;
        }

        if (!function_exists('wcs_order_contains_subscription') || ! ($order)) {
            return;
        }

        if (!function_exists('wcs_get_subscriptions_for_order')) {
            $this->log('WooCommerce Subscriptions function wcs_get_subscriptions_for_order not available');
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order);
        if (empty($subscriptions)) {
            $this->log('No subscriptions found for order: ' . $order->get_id());
            return;
        }

        foreach ($subscriptions as $subscription) {
            switch ($status) {
                case 'completed':
                    $subscription->payment_complete($payment_id);
                    $subscription->add_order_note(
                        sprintf(
                            __('Subscription payment completed via Monoova. Payment ID: %s', 'monoova-payments-for-woocommerce'),
                            $payment_id
                        )
                    );
                    break;
                case 'failed':
                    $subscription->update_status('on-hold', __('Payment failed via Monoova.', 'monoova-payments-for-woocommerce'));
                    $subscription->add_order_note(
                        sprintf(
                            __('Subscription payment failed via Monoova. Payment ID: %s', 'monoova-payments-for-woocommerce'),
                            $payment_id
                        )
                    );
                    break;
                case 'expired':
                    $subscription->update_status('cancelled', __('Payment expired via Monoova.', 'monoova-payments-for-woocommerce'));
                    $subscription->add_order_note(
                        sprintf(
                            __('Subscription payment expired via Monoova. Payment ID: %s', 'monoova-payments-for-woocommerce'),
                            $payment_id
                        )
                    );
                    break;
            }
        }
    }

    /**
     * Handle failed payment
     * 
     * Processes a failed payment by:
     * 1. Finding the associated order
     * 2. Marking the order as failed
     * 3. Updating payment status and adding failure details
     * 4. Handling subscription status updates if applicable
     *
     * @param array $event The webhook event data
     */
    private function handle_payment_failed($event) {
        $payment_id = $event['data']['payment_id'];
        $order = $this->get_order_by_payment_id($payment_id);

        if (!$order) {
            $this->log('Order not found for failed payment: ' . $payment_id);
            status_header(404);
            exit('Order not found');
        }

        // Update order status and meta
        $order->update_status('failed', __('Payment failed via Monoova.', 'monoova-payments-for-woocommerce'));
        $order->update_meta_data('_monoova_payment_status', 'failed');
        $order->add_order_note(
            sprintf(
                __('Monoova payment failed. Payment ID: %s. Reason: %s', 'monoova-payments-for-woocommerce'),
                $payment_id,
                $event['data']['failure_reason'] ?? 'Unknown'
            )
        );
        $order->save();

        // Handle subscription if present
        if ($this->is_subscriptions_active()) {
            if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                if (function_exists('wcs_get_subscriptions_for_order')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    if (!empty($subscriptions)) {
                        foreach ($subscriptions as $subscription) {
                            $subscription->update_status('on-hold', __('Payment failed via Monoova.', 'monoova-payments-for-woocommerce'));
                            $subscription->add_order_note(
                                sprintf(
                                    __('Subscription payment failed via Monoova. Payment ID: %s. Reason: %s', 'monoova-payments-for-woocommerce'),
                                    $payment_id,
                                    $event['data']['failure_reason'] ?? 'Unknown'
                                )
                            );
                        }
                    }
                } else {
                    $this->log('WooCommerce Subscriptions function wcs_get_subscriptions_for_order not available');
                }
            }
        }

        status_header(200);
        exit('Payment failure processed');
    }

    /**
     * Handle expired payment
     * 
     * Processes an expired payment by:
     * 1. Finding the associated order
     * 2. Cancelling the order if still pending
     * 3. Updating payment status and adding expiration note
     * 4. Handling subscription cancellations if applicable
     *
     * @param array $event The webhook event data
     */
    private function handle_payment_expired($event) {
        $payment_id = $event['data']['payment_id'];
        $order = $this->get_order_by_payment_id($payment_id);

        if (!$order) {
            $this->log('Order not found for expired payment: ' . $payment_id);
            status_header(404);
            exit('Order not found');
        }

        // Only process if order is still pending
        if ($order->get_status() === 'pending' || $order->get_status() === 'on-hold') {
            $order->update_status('cancelled', __('Payment expired via Monoova.', 'monoova-payments-for-woocommerce'));
            $order->update_meta_data('_monoova_payment_status', 'expired');
            $order->add_order_note(
                sprintf(
                    __('Monoova payment expired. Payment ID: %s', 'monoova-payments-for-woocommerce'),
                    $payment_id
                )
            );
            $order->save();
        }
        // Handle subscription if present
        if ($this->is_subscriptions_active()) {
            if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {
                if (function_exists('wcs_get_subscriptions_for_order')) {
                    $subscriptions = wcs_get_subscriptions_for_order($order);
                    if (!empty($subscriptions)) {
                        foreach ($subscriptions as $subscription) {
                            $subscription->update_status('cancelled', __('Payment expired via Monoova.', 'monoova-payments-for-woocommerce'));
                            $subscription->add_order_note(
                                sprintf(
                                    __('Subscription payment expired via Monoova. Payment ID: %s', 'monoova-payments-for-woocommerce'),
                                    $payment_id
                                )
                            );
                        }
                    }
                } else {
                    $this->log('WooCommerce Subscriptions function wcs_get_subscriptions_for_order not available');
                }
            }
        }

        status_header(200);
        exit('Payment expiration processed');
    }

    /**
     * Get order by payment ID
     *
     * @param string $payment_id
     * @return WC_Order|null
     */
    private function get_order_by_payment_id($payment_id) {
        $orders = wc_get_orders(array(
            'meta_key' => '_monoova_payment_id',
            'meta_value' => $payment_id,
            'limit' => 1
        ));

        return !empty($orders) ? reset($orders) : null;
    }

    /**
     * Get order by the lodgement reference stored in meta.
     *
     * @param string $reference The lodgement reference.
     * @return WC_Order|false The order object or false if not found.
     */
    private function get_order_by_lodgement_reference($reference) {
        // Trim trailing 'B' or 'P' from reference to handle reconciliation variations.
        if (in_array(substr($reference, -1), ['B', 'P'])) {
            $reference = substr($reference, 0, -1);
        }
        $orders = wc_get_orders(array(
            'limit'      => 1,
            'meta_key'   => '_monoova_payid_bank_transfer_reconciliation_reference',
            'meta_value' => $reference,
        ));
        return !empty($orders) ? reset($orders) : false;
    }
    /**
     * Process Card API webhook
     * 
     * Handles different types of Card API webhook events:
     * - CreditCardPaymentUpdated: Payment transaction status updates
     * - CreditCardRefundUpdated: Refund transaction status updates
     * - AsyncJobCompleted: Async job completion notifications
     *
     * @param array $event The webhook event data
     */
    private function process_card_api_webhook($event) {
        $event_type = $event['sourceEventName'] ?? 'unknown';
        $this->log('Processing Card API webhook: ' . $event_type, 'info');

        try {
            switch ($event_type) {
                case 'CreditCardPaymentUpdated':
                    $this->handle_credit_card_payment_updated($event);
                    break;

                case 'CreditCardRefundUpdated':
                    $this->handle_credit_card_refund_notification($event);
                    break;

                case 'AsyncJobCompleted':
                    $this->handle_async_job_completed($event);
                    break;

                default:
                    $this->log('Unhandled Card API webhook event type: ' . $event_type, 'warning');
                    status_header(200);
                    exit('Unhandled Card API event type processed');
            }
        } catch (Exception $e) {
            $this->log('Error processing Card API webhook: ' . $e->getMessage(), 'error');
            status_header(500);
            exit('Error processing webhook');
        }
    }

    /**
     * Process PayTo API webhook
     * 
     * Handles different types of PayTo API webhook events:
     * - PaymentAgreementStatusUpdated: PayTo agreement status updates
     * - PaymentInstructionStatusUpdated: PayTo payment instruction status updates
     *
     * @param array $event The webhook event data
     */
    private function process_payto_api_webhook($event) {
        $event_type = $event['sourceEventName'] ?? 'unknown';
        $this->log('Processing PayTo API webhook: ' . $event_type, 'info');

        try {
            switch ($event_type) {
                /* 
                Possible Values PaymentAgreementCreated, PaymentAgreementActive, PaymentAgreementCancelled, PaymentAgreementAmended, PaymentAgreementStatusAmended, PaymentAgreementActionCancelled, PaymentAgreementActionExpired (since 1.03), PaymentAgreementActionDeclined (since 1.03), PaymentAgreementActionCreated (since 1.06)
                */
                case 'PaymentAgreementCreated':
                case 'PaymentAgreementActive':
                case 'PaymentAgreementCancelled':
                case 'PaymentAgreementAmended':
                case 'PaymentAgreementStatusAmended':
                case 'PaymentAgreementActionCancelled':
                case 'PaymentAgreementActionExpired':
                case 'PaymentAgreementActionDeclined':
                case 'PaymentAgreementActionCreated':
                    $this->handle_payto_agreement_status_updated($event);
                    break;

                case 'PaymentInstructionStatusUpdated':
                    $this->handle_payto_payment_status_updated($event);
                    break;

                default:
                    $this->log('Unhandled PayTo API webhook event type: ' . $event_type, 'warning');
                    status_header(200);
                    exit('Unhandled PayTo API event type processed');
            }
        } catch (Exception $e) {
            $this->log('Error processing PayTo API webhook: ' . $e->getMessage(), 'error');
            status_header(500);
            exit('Error processing webhook');
        }
    }

    /**
     * Handle CreditCardPaymentUpdated webhook event
     * 
     * Processes payment transaction updates with paymentDetails array.
     * This maintains the current logic for processing payment status updates.
     *
     * @param array $event The webhook event data
     */
    private function handle_credit_card_payment_updated($event) {
        $this->log('Processing CreditCardPaymentUpdated event', 'info');

        $payment_details_list = $event['paymentDetails'] ?? [];
        if (empty($payment_details_list)) {
            $this->log('No payment details found in CreditCardPaymentUpdated webhook event', 'error');
            status_header(400);
            exit('No payment details found');
        }

        $this->log('Found ' . count($payment_details_list) . ' payment details to process', 'info');

        // Process each payment detail in the array
        $processed_count = 0;
        $success_count = 0;
        $error_count = 0;

        foreach ($payment_details_list as $index => $payment_object) {
            $processed_count++;
            $this->log("Processing payment detail #$index: " . json_encode($payment_object), 'debug');

            try {
                // Extract order ID from clientTransactionUniqueReference
                $client_ref = $payment_object['clientTransactionUniqueReference'] ?? '';
                $order_id = $this->extract_order_id_from_client_reference($client_ref);

                if (!$order_id) {
                    $this->log("Could not extract order ID from client reference: $client_ref", 'error');
                    $error_count++;
                    continue;
                }

                $this->log("Extracted order ID: $order_id from client reference: $client_ref", 'info');

                // Get the order
                $order = wc_get_order($order_id);
                if (!$order) {
                    $this->log("Order $order_id not found", 'error');
                    $error_count++;
                    continue;
                }

                // Get payment status
                $status = $payment_object['status'] ?? '';
                if (empty($status)) {
                    $this->log("No status found in payment object for order $order_id", 'error');
                    $error_count++;
                    continue;
                }

                $this->log("Processing order $order_id with status: $status", 'info');

                // Process based on the payment status
                $this->process_card_payment_status($order, $payment_object, $status);
                $success_count++;
            } catch (Exception $e) {
                $error_count++;
                $this->log("Error processing payment detail #$index: " . $e->getMessage(), 'error');
            }
        }

        $this->log("CreditCardPaymentUpdated processing complete. Processed: $processed_count, Success: $success_count, Errors: $error_count", 'info');

        if ($success_count > 0) {
            status_header(200);
            exit("Successfully processed $success_count of $processed_count payment details");
        } else {
            status_header(400);
            exit("Failed to process any payment details");
        }
    }

    /**
     * Handle CreditCardRefundUpdated webhook event
     * 
     * Processes refund transaction updates with refundDetails array.
     * Structure:
     * {
     *   "sourceEventName": "CreditCardRefundUpdated",
     *   "refundDetails": [
     *     {
     *       "transactionId": "123456",
     *       "clientTransactionUniqueReference": "clientRefundId",
     *       "amount": "100.00",
     *       "currencyCode": "AUD",
     *       "status": "Settlement Complete"
     *     }
     *   ]
     * }
     *
     * @param array $event The webhook event data
     */
    private function handle_credit_card_refund_updated_($event) {
        $this->log('Processing CreditCardRefundUpdated event', 'info');

        // TODO: Implement refund processing logic
        // Expected structure: $event['refundDetails'] array with refund transaction details

        $refund_details_list = $event['refundDetails'] ?? [];
        $this->log('Found ' . count($refund_details_list) . ' refund details to process', 'info');

        // TODO: Process each refund detail similar to payment processing
        // TODO: Extract order ID from clientTransactionUniqueReference
        // TODO: Update order notes with refund information
        // TODO: Handle refund status updates

        $this->log('CreditCardRefundUpdated processing not yet implemented', 'warning');
        status_header(200);
        exit('CreditCardRefundUpdated event received but not yet implemented');
    }

    /**
     * Handle AsyncJobCompleted webhook event
     * 
     * Processes async job completion notifications.
     * Structure:
     * {
     *   "sourceEventName": "AsyncJobCompleted",
     *   "uniqueRequestId": "b78e1829-dd2e-4119-a678-af8abccf9129",
     *   "status": "Failed",
     *   "entityType": "CreditCardPayment",
     *   "actionType": "Create",
     *   "errorCode": "PAM_UNEXPECTED_ERROR",
     *   "errorMessage": "Internal server error"
     * }
     *
     * @param array $event The webhook event data
     */
    private function handle_async_job_completed($event) {
        $this->log('Processing AsyncJobCompleted event', 'info');

        // TODO: Implement async job completion processing logic
        // Expected fields: uniqueRequestId, status, entityType, actionType, location, errorCode, errorMessage

        $unique_request_id = $event['uniqueRequestId'] ?? '';
        $status = $event['status'] ?? '';
        $entity_type = $event['entityType'] ?? '';
        $action_type = $event['actionType'] ?? '';

        $this->log("AsyncJobCompleted: RequestID: $unique_request_id, Status: $status, Entity: $entity_type, Action: $action_type", 'info');

        // TODO: Map uniqueRequestId to order or payment
        // TODO: Handle successful job completion
        // TODO: Handle failed job completion with error details
        // TODO: Update order status and notes based on job completion

        $this->log('AsyncJobCompleted processing not yet implemented', 'warning');
        status_header(200);
        exit('AsyncJobCompleted event received but not yet implemented');
    }

    /**
     * Handle PayTo payment agreement status updated webhook
     *
     * @param array $event The webhook event data
     */
    private function handle_payto_agreement_status_updated($event) {
        $this->log('Processing PaymentAgreementStatusUpdated event', 'info');
        // structure: 
        /* 
            {
                "eventId": "9f0f390c-6173-4977-abd0-9f41218a62a3",
                "sourceEventName": "PaymentAgreementCancelled",
                "eventTimestamp": "2022-08-31T07:12:35.7560202Z",
                "paymentAgreementDetails": {
                    "paymentAgreementUID": "BCORP123456",
                    "mmsId": "e57577f82e841bf3b0edbacfdc775ca0",
                    "paymentAgreementStatus": "Cancelled",
                    "payeeDetails": {
                        "payeeType": "ORGN",
                        "payeeLinkedBsb": 802950,
                        "payeeLinkedAccount": 10109010,
                        "payeeLinkedPayID": "abc@gmail.com",
                        "payeeLinkedPayIdType": "EMAIL",
                        "payeeAccountName": "PayCo",
                        "ultimatePayee": "PayCo"
                    },
                    "payerDetails": {
                        "payerType": "ORGN",
                        "linkedBsb": 802950,
                        "linkedAccount": 987654331,
                        "payer": "WidgetCo",
                        "ultimatePayer": "WidgetCo",
                        "payerPartyReference": "Payer4321"
                    },
                    "paymentTerms": {
                        "numberOfTransactionsPermitted": 100,
                        "frequency": "WEEK",
                        "maximumAmount": 100,
                        "agreementType": "VARI"
                    },
                    "paymentDetails": {
                        "automaticRenewal": true,
                        "description": "payroll pag",
                        "shortDescription": "PayToTest123445",
                        "purpose": "MORI",
                        "respondByTime": "03/22/2024 15:45:30",
                        "startDate": "2022-10-05",
                        "endDate": "2023-08-24"
                    }
                },
                "action": {
                    "agreementStatusChangeReasonCode": "M002",
                    "agreementStatusChangeReasonDescription": "Reason description, manually entered, or mapped from agreementStatusChangeReasonCode.",
                    "actionIdentification": "dccbd8bb810618e1891e9fde2379345e",
                    "actionType": "Amend",
                    "actionStatus": "Expired"
                }
            }
        */
        // Extract required fields from the event
        $source_event_name = $event['sourceEventName'] ?? 'unknown';
        $this->log("Source event name: $source_event_name", 'debug');
        // paymentAgreementDetails is an object 
        $payment_agreement_details = $event['paymentAgreementDetails'] ?? [];
        $payment_agreement_uid = $payment_agreement_details['paymentAgreementUID'] ?? '';
        $agreement_status = $payment_agreement_details['paymentAgreementStatus'] ?? '';
        $agreement_mms_id = $payment_agreement_details['mmsId'] ?? '';

        if (empty($payment_agreement_uid) || empty($agreement_status)) {
            $this->log('Missing required PayTo agreement data in webhook', 'error');
            status_header(400);
            exit('Missing required PayTo agreement data');
        }

        $this->log("PayTo agreement status updated: UID: $payment_agreement_uid, Status: $agreement_status, MMS ID: $agreement_mms_id", 'info');

        // Find latest order by agreement UID 
        $orders = wc_get_orders([
            'meta_key' => '_monoova_payto_agreement_uid',
            'meta_value' => $payment_agreement_uid,
            'meta_compare' => '=',
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (empty($orders)) {
            $this->log("No order found for PayTo agreement UID: $payment_agreement_uid", 'error');
            status_header(200);
            exit('No order found for agreement');
        }

        $order = $orders[0];
        $order_id = $order->get_id();
        $this->log("Found order #{$order_id} for PayTo agreement UID: $payment_agreement_uid", 'info');

        // Update agreement status
        $order->update_meta_data('_monoova_payto_agreement_status', $agreement_status);

        // Update mandate status in database
        $mandate_manager = new Monoova_PayTo_Mandate_Manager();
        $mandate_manager->update_mandate_status($payment_agreement_uid, $agreement_status);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('PayTo agreement status updated to: %s', 'monoova-payments-for-woocommerce'),
                $agreement_status
            )
        );

        // Handle different agreement statuses
        // Must be one of Created, Active, Paused, Cancelled, Rejected, Expired
        switch (strtolower($agreement_status)) {
            case 'created':
                $this->log("PayTo agreement created for order #{$order_id}", 'info');
                $order->add_order_note(__('[PayTo Webhook] PayTo agreement created. Awaiting customer authorization.', 'monoova-payments-for-woocommerce'));
                break;
            case 'active':
                $this->log("PayTo agreement active for order #{$order_id}", 'info');
                $this->log("Starting to create payment initiation for order #{$order_id}", 'info');
                // Try to create payment initiation
                $payto_gateway = new Monoova_PayTo_Gateway();
                $result = $payto_gateway->create_payment_initiation($order_id, $payment_agreement_uid);

                if (is_wp_error($result)) {
                    $this->log("Failed to create payment initiation for order #{$order_id}: " . $result->get_error_message(), 'error');
                    $order->add_order_note(__('[PayTo Webhook] PayTo agreement authorized but payment initiation failed. Manual intervention required.', 'monoova-payments-for-woocommerce'));
                } else {
                    $order->add_order_note(__('[PayTo Webhook] PayTo agreement authorized and payment initiation created.', 'monoova-payments-for-woocommerce'));
                }
                break;
            case 'paused':
                $this->log("PayTo agreement paused for order #{$order_id}", 'info');
                $order->update_status('pending', __('PayTo payment agreement paused. Awaiting customer action. Change order status back to pending.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(__('[PayTo Webhook] PayTo payment agreement paused. Awaiting customer action. Change order status back to pending.', 'monoova-payments-for-woocommerce'));
                break;

            case 'cancelled':
                $this->log("PayTo agreement cancelled for order #{$order_id}", 'info');
                $order->update_status('pending', __('PayTo payment agreement cancelled by customer. Change order status back to pending.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(__('[PayTo Webhook] PayTo payment agreement cancelled by customer. Change order status back to pending', 'monoova-payments-for-woocommerce'));
                break;

            case 'rejected':
                $this->log("PayTo agreement rejected for order #{$order_id}", 'info');
                $order->update_status('failed', __('PayTo payment agreement rejected by customer. Change order status to failed.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(__('[PayTo Webhook] PayTo payment agreement rejected by customer. Change order status to failed.', 'monoova-payments-for-woocommerce'));
                break;

            case 'expired':
                $this->log("PayTo agreement expired for order #{$order_id}", 'info');
                $order->update_status('pending', __('PayTo payment agreement expired. Change order status back to pending.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(__('[PayTo Webhook] PayTo payment agreement expired. Change order status back to pending.', 'monoova-payments-for-woocommerce'));
                break;

            default:
                $this->log("Unknown PayTo agreement status: $agreement_status for order #{$order_id}", 'error');
                break;
        }

        $order->save();

        status_header(200);
        exit('PayTo agreement status updated successfully');
    }

    /**
     * Handle PayTo payment instruction status updated webhook
     *
     * @param array $event The webhook event data
     */
    private function handle_payto_payment_status_updated($event) {
        $this->log('Processing PaymentInstructionStatusUpdated event', 'info');
        // structure:
        /* 
            {
                "eventId": "7C1C78DB-BD64-416A-96C4-661C89602FDC",
                "sourceEventName": "PaymentInstructionStatusUpdated",
                "eventTimestamp": "2023-06-05T04:43:05.9904048Z",
                "paymentInitiationDetail": {
                    "paymentAgreementUID": "MONPAG1683781294",
                    "paymentInitiationUID": "PIU-1685940180",
                    "nppInstructionId": "PI123456789",
                    "paymentInitiationStatus": "ACSC",
                    "paymentInitiationStatusDescription": "Accepted & Settled",
                    "mmsId": "4fdbeebadff71da0b5ccd9d25427c3df"
                },
                "payeeDetails": {
                    "payeeType": "Organisation",
                    "payeeLinkedBSB": "",
                    "payeeLinkedAccount": "",
                    "payeeLinkedPayID": "sample@monoova.com",
                    "payeeLinkedPayIdType": "Email",
                    "payeeAccountName": "ABC Corp1",
                    "ultimatePayee": "ABC Corp1"
                },
                "paymentDetails": {
                    "amount": 10,
                    "isLastPayment": false,
                    "lodgementReference": "Monoova PayTo LVP Demo Testing"
                }
            }
        */

        // Extract required fields from the event
        $source_event_name = $event['sourceEventName'] ?? 'unknown';
        $this->log("Source event name: $source_event_name", 'debug');
        // paymentInitiationDetail is an object
        $payment_initiation_detail = $event['paymentInitiationDetail'] ?? [];
        $payment_initiation_uid = $payment_initiation_detail['paymentInitiationUID'] ?? '';
        $payment_agreement_uid = $payment_initiation_detail['paymentAgreementUID'] ?? '';
        $payment_initiation_status = $payment_initiation_detail['paymentInitiationStatus'] ?? '';
        $npp_instruction_id = $payment_initiation_detail['nppInstructionId'] ?? '';
        $payment_lodgement_reference = $event['paymentDetails']['lodgementReference'] ?? '';
        $mms_id = $payment_initiation_detail['mmsId'] ?? '';

        if (empty($payment_initiation_uid) || empty($payment_initiation_status)) {
            $this->log('Missing required PayTo payment data in webhook', 'error');
            status_header(400);
            exit('Missing required PayTo payment data');
        }

        $this->log("PayTo payment status updated: UID: $payment_initiation_uid, Agreement UID: $payment_agreement_uid, Status: $payment_initiation_status, NPP Instruction ID: $npp_instruction_id, Lodgement Reference: $payment_lodgement_reference, MMS ID: $mms_id", 'info');

        // Find order by payment UID or agreement UID
        $orders = wc_get_orders([
            'meta_key' => '_monoova_payto_payment_uid',
            'meta_value' => $payment_initiation_uid,
            'meta_compare' => '=',
            'limit' => 1,
        ]);

        // If not found by payment UID, try latest order by agreement UID
        if (empty($orders) && !empty($payment_agreement_uid)) {
            $orders = wc_get_orders([
                'meta_key' => '_monoova_payto_agreement_uid',
                'meta_value' => $payment_agreement_uid,
                'meta_compare' => '=',
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
        }

        if (empty($orders)) {
            $this->log("No order found for PayTo payment UID: $payment_initiation_uid", 'error');
            status_header(200);
            exit('No order found for payment');
        }

        $order = $orders[0];
        $order_id = $order->get_id();
        $this->log("Found order #{$order_id} for PayTo payment UID: $payment_initiation_uid", 'info');

        // Update payment status
        $order->update_meta_data('_monoova_payto_payment_status', $payment_initiation_status);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('PayTo payment status updated to: %s', 'monoova-payments-for-woocommerce'),
                $payment_initiation_status
            )
        );

        // Handle different payment statuses
        // Must be one of ACCP (Accepted Customer Profile)
        // ACSP (Accepted Settlement in Process)
        // INPR (In Process)
        // RECV (Received)
        // SAFD (Participant Down)
        // SENT (Sent To Payer)
        // UNDV (Undelivered)
        // ACSC (Accepted Settlement Completed)
        // RJCT (Rejected)
        switch (strtolower($payment_initiation_status)) {
            case 'acsc': // Accepted Settlement Completed
                $this->log("PayTo payment accepted and settled for order #{$order_id}", 'info');

                // Mark order as paid
                $order->payment_complete();
                $order->add_order_note(
                    sprintf(
                        __('PayTo payment accepted and settled successfully. Payment UID: %s', 'monoova-payments-for-woocommerce'),
                        $payment_initiation_uid
                    )
                );

                // Handle subscription payments if applicable
                $this->handle_subscription_payment($order, $payment_initiation_uid, 'completed');
                break;
            case 'rjct': // Rejected
                $this->log("PayTo payment rejected for order #{$order_id}", 'info');
                $order->update_status('failed', __('PayTo payment was rejected.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(
                    sprintf(
                        __('PayTo payment was rejected. Payment UID: %s', 'monoova-payments-for-woocommerce'),
                        $payment_initiation_uid
                    )
                );
                // Handle subscription payments if applicable
                $this->handle_subscription_payment($order, $payment_initiation_uid, 'failed');
                break;
            case 'inpr':
                $this->log("PayTo payment in progress for order #{$order_id}", 'info');
                $order->update_status('pending', __('PayTo payment is in progress.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(
                    sprintf(
                        __('PayTo payment is in progress. Payment UID: %s', 'monoova-payments-for-woocommerce'),
                        $payment_initiation_uid
                    )
                );
                break;

            case 'acsp': // Accepted Settlement Pending
                $this->log("PayTo payment accepted and pending for order #{$order_id}", 'info');
                // mark order as processing
                $order->update_status('processing', __('PayTo payment is accepted and setlement is pending.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(
                    sprintf(
                        __('PayTo payment is accepted and settlement is pending. Payment UID: %s', 'monoova-payments-for-woocommerce'),
                        $payment_initiation_uid
                    )
                );
                $order->payment_complete();
                $this->handle_subscription_payment($order, $payment_initiation_uid, 'completed');
                break;

            case 'recv': // Received
                $this->log("PayTo payment received for order #{$order_id}", 'info');
                $order->update_status('processing', __('PayTo payment has been received.', 'monoova-payments-for-woocommerce'));
                $order->add_order_note(
                    sprintf(
                        __('PayTo payment has been received. Payment UID: %s', 'monoova-payments-for-woocommerce'),
                        $payment_initiation_uid
                    )
                );
                $order->payment_complete();
                $this->handle_subscription_payment($order, $payment_initiation_uid, 'completed');
                break;
        }

        $order->save();

        status_header(200);
        exit('PayTo payment status updated successfully');
    }

    /**
     * Process Payment API webhook
     *
     * @param array $event The webhook event data
     */
    private function process_payment_api_webhook($event) {
        $this->log('Processing Payment API webhook');

        if (isset($event['ReconciliationRuleReference'])) {
            $this->handle_payment_api_event_NPPReceivePayment($event);
        } elseif (isset($event['LodgementRef']) && isset($event['PayIdType']) && isset($event['Status'])) {
            $this->handle_payment_api_event_NppPaymentStatus($event);
        } elseif (isset($event['ReturnTransactionId']) && isset($event['ReturnDate']) && isset($event['ReturnReason'])) {
            $this->handle_payment_api_event_NppReturn($event);
        } elseif (isset($event['sourceAccountName']) && $event['transactionType'] === "NPP" && isset($event['remitterName'])) {
            $this->handle_payment_api_event_NPPCreditRejections($event);
        } elseif (isset($event['DirectCreditDetails'])) {
            $this->handle_payment_api_event_InboundDirectCredit($event);
        } elseif (isset($event[0]['sourceAccountName']) && $event[0]['transactionType'] === "DE" && isset($event[0]['remitterName'])) {
            $this->handle_payment_api_event_InboundDirectCreditRejections($event);
        } else {
            $this->log('Unhandled Payment API webhook event type: ' . json_encode($event), 'warning');
            status_header(200);
            exit('Unhandled Payment API event type processed');
        }
    }

    /**
     * Handle Card API payment success
     * 
     * Processes successful payments from the Card API by:
     * 1. Finding the order using metadata or transaction ID
     * 2. Marking the order as paid
     * 3. Adding payment details to order notes
     * 4. Handling subscription payments if applicable
     *
     * @param array $payment_object The payment data from the webhook
     * @param array $full_event_data The complete webhook event data
     */
    protected function handle_card_event_payment_succeeded($payment_object, $full_event_data) {
        $logger = wc_get_logger();
        $logger->info('Handling Card API payment.succeeded event.', array('source' => 'monoova-webhook', 'payment_object' => $payment_object));

        $order_id = $payment_object['metadata']['order_id'] ?? null;
        $client_ref = $payment_object['clientTransactionUniqueReference'] ?? null;

        if (!$order_id) {
            $logger->error('Could not determine order ID from Card API payment.succeeded webhook.', array('source' => 'monoova-webhook', 'payment_object' => $payment_object));
            status_header(404);
            exit('Order not found');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->error(sprintf('Order %s not found for Card API payment.succeeded webhook.', $order_id), array('source' => 'monoova-webhook'));
            status_header(404);
            exit('Order not found');
        }

        if ($order->is_paid()) {
            $logger->info(sprintf('Order %s already marked as paid. Skipping update.', $order_id), array('source' => 'monoova-webhook'));
            status_header(200);
            exit('Payment already processed');
        }

        $payment_id = $payment_object['id'];
        $order->payment_complete($payment_id);
        $order->update_meta_data('_monoova_payment_status', 'completed');
        $order->add_order_note(
            sprintf(
                __('Monoova payment successful (Card API). Transaction ID: %s. Amount: %s %s.', 'monoova-payments-for-woocommerce'),
                $payment_id,
                wc_format_decimal($payment_object['amount'] / 100, wc_get_price_decimals()),
                strtoupper($payment_object['currency'])
            )
        );
        $order->save();

        // Handle subscription payments
        $this->handle_subscription_payment($order, $payment_id, 'completed');

        status_header(200);
        exit('Payment processed successfully');
    }

    /**
     * Handle Card API payment failure
     * 
     * Processes failed payments from the Card API by:
     * 1. Finding the order using metadata or transaction ID
     * 2. Marking the order as failed
     * 3. Adding failure details to order notes
     * 4. Handling subscription status updates if applicable
     *
     * @param array $payment_object The payment data from the webhook
     * @param array $full_event_data The complete webhook event data
     */
    protected function handle_card_event_payment_failed($payment_object, $full_event_data) {
        $logger = wc_get_logger();
        $logger->info('Handling Card API payment.failed event.', array('source' => 'monoova-webhook', 'payment_object' => $payment_object));

        $order_id = $payment_object['metadata']['order_id'] ?? null;
        $client_ref = $payment_object['clientTransactionUniqueReference'] ?? null;

        if (!$order_id) {
            $logger->error('Could not determine order ID from Card API payment.failed webhook.', array('source' => 'monoova-webhook', 'payment_object' => $payment_object));
            status_header(404);
            exit('Order not found');
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->error(sprintf('Order %s not found for Card API payment.failed webhook.', $order_id), array('source' => 'monoova-webhook'));
            status_header(404);
            exit('Order not found');
        }

        // Only update if the order is not already failed or completed
        if (!in_array($order->get_status(), array('failed', 'completed', 'processing'))) {
            $failure_reason = $payment_object['failure_reason'] ?? $payment_object['status_reason'] ?? 'Unknown';
            $payment_id = $payment_object['id'];

            $order->update_status('failed', __('Payment failed via Monoova Card API.', 'monoova-payments-for-woocommerce'));
            $order->update_meta_data('_monoova_payment_status', 'failed');
            $order->add_order_note(
                sprintf(
                    __('Monoova payment failed (Card API). Transaction ID: %s. Reason: %s. Amount: %s %s.', 'monoova-payments-for-woocommerce'),
                    $payment_id,
                    $failure_reason,
                    wc_format_decimal($payment_object['amount'] / 100, wc_get_price_decimals()),
                    strtoupper($payment_object['currency'])
                )
            );
            $order->save();

            // Handle subscription payments
            $this->handle_subscription_payment($order, $payment_id, 'failed');
        }

        status_header(200);
        exit('Payment failure processed');
    }

    protected function handle_card_event_payment_completed($payment_object, $full_event_data) {
        $this->handle_card_event_payment_succeeded($payment_object, $full_event_data);
    }

    /**
     * Handle Card API refund
     * 
     * Processes refunds from the Card API by:
     * 1. Finding the original order
     * 2. Adding refund details to order notes
     * 3. Recording refund amount and transaction ID
     *
     * @param array $refund_object The refund data from the webhook
     */
    protected function handle_card_event_refund_processed($refund_object, $full_event_data) {
        $logger = wc_get_logger();
        $logger->info('Handling Card API refund.processed event.', array('source' => 'monoova-webhook', 'refund_object' => $refund_object));

        $order_id = $refund_object['metadata']['order_id'] ?? null;
        $client_ref = $refund_object['clientTransactionUniqueReference'] ?? null;

        if (!$order_id) {
            $logger->error('Could not determine order ID from Card API refund.processed webhook.', array('source' => 'monoova-webhook', 'refund_object' => $refund_object));
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            $logger->error(sprintf('Order %s not found for Card API refund.processed webhook.', $order_id), array('source' => 'monoova-webhook'));
            return;
        }

        $refund_amount = wc_format_decimal($refund_object['amount'] / 100, wc_get_price_decimals());
        $currency = strtoupper($refund_object['currency']);
        $refund_id = $refund_object['id'];

        $order->add_order_note(
            sprintf(
                __('Monoova refund processed (Card API). Refund ID: %s. Amount: %s %s.', 'monoova-payments-for-woocommerce'),
                $refund_id,
                $refund_amount,
                $currency
            )
        );

        status_header(200);
        exit('Refund processed successfully');
    }

    /**
     * Handle PayID/NPP payment
     * 
     * Processes PayID and NPP (New Payments Platform) payments by:
     * 1. Finding the order using PayID or Reconciliation Rule Reference
     * 2. Marking the order as paid
     * 3. Recording transaction details
     * 4. Adding payment confirmation to order notes
     * 5. Handling subscription payments if applicable
     *
     * @param array $event_data The webhook event data
     */
    protected function handle_payment_api_event_NPPReceivePayment($event_data) {
        $logger = wc_get_logger();
        $logger->info('Handling Payment API NPPReceivePayment event.', array('source' => 'monoova-webhook', 'event_data' => $event_data));

        $transaction_id = $event_data['TransactionId'] ?? null;
        $amount_received = $event_data['Amount'] ?? null;
        $payid = $event_data['PayId'] ?? null;
        $reconciliationRuleRef = $event_data['ReconciliationRuleReference'] ?? null;

        if (!$transaction_id || !$amount_received || !$payid || !$reconciliationRuleRef) {
            $logger->error('Missing required fields in Payment API NPPReceivePayment webhook.', array('source' => 'monoova-webhook', 'event_data' => $event_data));
            status_header(400);
            exit('Missing required fields');
        }

        // try to find by reconciliation reference
        // notice that we cannot find order by payID or bank account, because now a PayID can reuse for up to 10 orders
        $order = $this->get_order_by_lodgement_reference($reconciliationRuleRef);

        if (!$order) {
            $logger->error('Could not determine order from Payment API NPPReceivePayment webhook.', array('source' => 'monoova-webhook', 'event_data' => $event_data));
            status_header(200);
            exit('Order not found');
        }

        // log order details here
        $logger->info(sprintf('Found order %s for NPPReceivePayment with PayID: %s and Reconciliation Rule Reference: %s', $order->get_id(), $payid, $reconciliationRuleRef), array('source' => 'monoova-webhook'));

        $this->process_successful_payment($order, $amount_received, $transaction_id, "NPP");

        status_header(200);
        exit('Payment processed successfully');
    }

    /**
     * Extract order ID from client transaction reference
     * Expected format: 'wc_transaction_{order_id}_{timestamp}'
     * 
     * @param string $client_ref The client transaction unique reference
     * @return int|null The order ID or null if not found
     */
    private function extract_order_id_from_client_reference($client_ref) {
        if (empty($client_ref)) {
            return null;
        }

        $this->log("Extracting order ID from client reference: $client_ref", 'debug');

        // Expected format: 'wc_transaction_{order_id}_{timestamp}' or 'WC_TRANSACTION_{order_id}_{timestamp}'
        if (preg_match('/^wc_transaction_(\d+)_\d+$/', $client_ref, $matches) || preg_match('/^WC_TRANSACTION_(\d+)_\d+$/', $client_ref, $matches)) {
            $order_id = intval($matches[1]);
            $this->log("Successfully extracted order ID: $order_id", 'debug');
            return $order_id;
        }

        // Fallback: try to extract any numeric value that looks like an order ID
        if (preg_match('/(\d+)/', $client_ref, $matches)) {
            $order_id = intval($matches[1]);
            $this->log("Fallback extraction found order ID: $order_id", 'warning');
            return $order_id;
        }

        $this->log("Could not extract order ID from client reference: $client_ref", 'error');
        return null;
    }

    /**
     * Process card payment status and update WooCommerce order accordingly
     * 
     * @param WC_Order $order The WooCommerce order
     * @param array $payment_object The payment object from webhook
     * @param string $status The Monoova payment status
     */
    private function process_card_payment_status($order, $payment_object, $status) {
        $order_id = $order->get_id();
        $transaction_id = $payment_object['clientTransactionUniqueReference'] ?? '';
        $amount = $payment_object['amount'] ?? '';
        $currency = $payment_object['currencyCode'] ?? 'AUD';

        $this->log("Processing status '$status' for order $order_id with transaction ID: $transaction_id", 'info');

        // Map Monoova status to WooCommerce order status and actions
        switch (strtolower($status)) {
            case 'settlement complete':
                $this->handle_settlement_complete($order, $payment_object);
                break;

            case 'failed':
            case 'declined':
            case 'cancelled':
                $this->handle_payment_failure($order, $payment_object, $status);
                break;

            case 'pending':
            case 'authorized':
            case 'settling':
                $this->handle_interim_status($order, $payment_object, $status);
                break;

            default:
                $this->log("Unknown payment status '$status' for order $order_id", 'warning');
                $this->add_order_note($order, "Monoova webhook received unknown status: $status. Transaction ID: $transaction_id");
        }
    }

    /**
     * Handle settlement complete status
     * 
     * @param WC_Order $order The WooCommerce order
     * @param array $payment_object The payment object from webhook
     */
    private function handle_settlement_complete($order, $payment_object) {
        $order_id = $order->get_id();
        $transaction_id = $payment_object['clientTransactionUniqueReference'] ?? '';
        $amount = $payment_object['amount'] ?? '';
        $currency = $payment_object['currencyCode'] ?? 'AUD';
        $settlement_date = $payment_object['settlementDateTime'] ?? '';

        // Check if order is already paid
        if ($order->is_paid()) {
            $this->log("Order $order_id is already marked as paid. Skipping settlement processing.", 'info');
            $this->add_order_note($order, "Monoova settlement complete notification received (already paid). Transaction ID: $transaction_id");
            return;
        }

        // Mark payment as complete
        $order->payment_complete($transaction_id);
        $order->update_meta_data('_monoova_payment_status', 'settlement_complete');
        $order->update_meta_data('_monoova_transaction_id', $transaction_id);
        $order->update_meta_data('_monoova_settlement_date', $settlement_date);

        // Add detailed order note
        $note = sprintf(
            __('Monoova payment settlement complete. Transaction ID: %s. Amount: %s %s. Settlement Date: %s', 'monoova-payments-for-woocommerce'),
            $transaction_id,
            $amount,
            $currency,
            $settlement_date
        );
        $this->add_order_note($order, $note);

        $order->save();

        // Handle subscription payments if applicable
        $this->handle_subscription_payment($order, $transaction_id, 'completed');

        $this->log("Order $order_id marked as payment complete. Transaction ID: $transaction_id", 'info');
    }

    /**
     * Handle payment failure statuses (Failed, Declined, Cancelled)
     * 
     * @param WC_Order $order The WooCommerce order
     * @param array $payment_object The payment object from webhook
     * @param string $status The failure status
     */
    private function handle_payment_failure($order, $payment_object, $status) {
        $order_id = $order->get_id();
        $transaction_id = $payment_object['clientTransactionUniqueReference'] ?? '';
        $amount = $payment_object['amount'] ?? '';
        $currency = $payment_object['currencyCode'] ?? 'AUD';

        // Only update if order is not already completed or processing
        if (in_array($order->get_status(), array('completed', 'processing'))) {
            $this->log("Order $order_id is already completed/processing. Skipping failure processing.", 'warning');
            $this->add_order_note($order, "Monoova payment $status notification received (order already processed). Transaction ID: $transaction_id");
            return;
        }

        // Update order status to failed
        $order->update_status('failed', sprintf(__('Payment %s via Monoova Card API.', 'monoova-payments-for-woocommerce'), strtolower($status)));
        $order->update_meta_data('_monoova_payment_status', strtolower(str_replace(' ', '_', $status)));
        $order->update_meta_data('_monoova_transaction_id', $transaction_id);

        // Add detailed order note
        $note = sprintf(
            __('Monoova payment %s. Transaction ID: %s. Amount: %s %s.', 'monoova-payments-for-woocommerce'),
            strtolower($status),
            $transaction_id,
            $amount,
            $currency
        );
        $this->add_order_note($order, $note);

        $order->save();

        // Handle subscription failures if applicable
        $this->handle_subscription_payment($order, $transaction_id, 'failed');

        $this->log("Order $order_id marked as failed due to payment $status. Transaction ID: $transaction_id", 'info');
    }

    /**
     * Handle interim statuses (Pending, Authorized, Settling)
     * 
     * @param WC_Order $order The WooCommerce order
     * @param array $payment_object The payment object from webhook
     * @param string $status The interim status
     */
    private function handle_interim_status($order, $payment_object, $status) {
        $order_id = $order->get_id();
        $transaction_id = $payment_object['clientTransactionUniqueReference'] ?? '';
        $amount = $payment_object['amount'] ?? '';
        $currency = $payment_object['currencyCode'] ?? 'AUD';

        // Update order meta but don't change order status for interim statuses
        $order->update_meta_data('_monoova_payment_status', strtolower(str_replace(' ', '_', $status)));
        $order->update_meta_data('_monoova_transaction_id', $transaction_id);

        // Add order note for tracking
        $note = sprintf(
            __('Monoova payment status update: %s. Transaction ID: %s. Amount: %s %s.', 'monoova-payments-for-woocommerce'),
            $status,
            $transaction_id,
            $amount,
            $currency
        );
        $this->add_order_note($order, $note);

        $order->save();

        $this->log("Order $order_id updated with interim status: $status. Transaction ID: $transaction_id", 'info');
    }

    /**
     * Add a note to the order with consistent formatting
     * 
     * @param WC_Order $order The WooCommerce order
     * @param string $note The note to add
     */
    private function add_order_note($order, $note) {
        $formatted_note = '[Monoova Webhook] ' . $note;
        $order->add_order_note($formatted_note);
        $this->log("Added order note to order {$order->get_id()}: $formatted_note", 'debug');
    }


    /**
     * Handles the 'CreditCardRefundNotification' webhook from Monoova.
     *
     * @param array $data The webhook payload data.
     */
    public function handle_credit_card_refund_notification($data) {

        foreach ($data['refundDetails'] as $refund) {
            if (empty($refund['clientTransactionUniqueReference'])) {
                $this->log('Webhook Error: clientTransactionUniqueReference missing in CreditCardRefundNotification.');
                return;
            }

            $refund_ref = $refund['clientTransactionUniqueReference'];

            // Assumes the refund reference format is 'REF-{order_id}-RANDOMID'.
            $parts = explode('-', $refund_ref);
            if (count($parts) < 2 || !is_numeric($parts[1])) {
                $this->log('Webhook Error: Could not parse order ID from refund reference: ' . $refund_ref);
                return;
            }

            $order = wc_get_order($parts[1]);
            if (! $order) {
                $this->log('Webhook Error: Order not found for refund reference: ' . $refund_ref);
                return;
            }

            $note = sprintf(
                __('Monoova refund notification received. Status: %s. Amount: %s. | Monoova Transaction ID: %s', 'monoova-payments-for-woocommerce'),
                esc_html($refund['status']),
                wc_price($refund['amount']),
                esc_html($refund['transactionId'])
            );

            $order->add_order_note($note);
            $order->save();
        }
    }

    /**
     * Handles 'NppPaymentStatus' event from Payment API.
     *
     * This webhook is used for PayID payments made to a Automatcher account.
     * It confirms the status of a payment initiated by a customer.
     *
     * @param array $event_data The full event data
     */
    protected function handle_payment_api_event_NppPaymentStatus($event_data) {
        $this->log('Handling Payment API NppPaymentStatus event.', 'info', array('event_data' => $event_data));

        // Extract key information from the payload
        $reference = $event_data['UniqueReference'] ?? null;
        $status = strtolower($event_data['Status'] ?? 'unknown');
        $transaction_id = $event_data['TransactionId'] ?? null;
        $reason = $event_data['RejectionReasonDescription'] ?? 'Unknown';
        $amount_paid = isset($event_data['Amount']) ? (float) $event_data['Amount'] : 0;

        if (!$reference) {
            $this->log('UniqueReference missing in NppPaymentStatus webhook.', 'error', array('event_data' => $event_data));
            status_header(400);
            exit('Missing Reference');
        }

        $order = $this->get_order_by_lodgement_reference($reference);

        if (!$order) {
            $this->log('Could not determine order from NppPaymentStatus webhook. Reference: ' . $reference, 'error', array('event_data' => $event_data));
            status_header(404);
            exit('Order not found');
        }

        $this->log(sprintf('Found order %s for NppPaymentStatus webhook.', $order->get_id()), 'info');

        // Process based on status
        if ($status === 'payment successful') {
            $this->process_successful_payment($order, $amount_paid, $transaction_id, 'PayID/NPP');
        } elseif ($status === 'rejected') {
            $this->process_failed_payment($order, $reason, $transaction_id, 'PayID/NPP', $event_data);
        } else {
            $this->log('Unhandled NppPaymentStatus: ' . $status, 'warning', array('event_data' => $event_data));
        }

        status_header(200);
        exit('NppPaymentStatus processed successfully');
    }

    /**
     * Handles 'NppReturn' event from Payment API.
     * This occurs when a previously successful NPP payment is returned.
     *
     * @param array $event_data The full event data
     */
    protected function handle_payment_api_event_NppReturn($event_data) {
        $this->log('Handling Payment API NppReturn event.', 'info', array('event_data' => $event_data));

        $original_transaction_id = $event_data['TransactionId'] ?? null;
        $return_transaction_id = $event_data['ReturnTransactionId'] ?? null;
        $reason = $event_data['ReturnReason'] ?? 'Unknown';
        $amount = $event_data['Amount'] ?? 0;

        if (!$original_transaction_id) {
            $this->log('Original TransactionId missing in NppReturn webhook.', 'error', array('event_data' => $event_data));
            status_header(400);
            exit('Missing Original TransactionId');
        }

        $order = $this->get_order_by_transaction_id($original_transaction_id);

        if (!$order) {
            $this->log(sprintf('Could not find order with Original Transaction ID %s from NppReturn webhook.', $original_transaction_id), 'error');
            status_header(404);
            exit('Order not found');
        }

        // Mark the order as failed and add a note.
        $order->update_status('failed', __('NPP Payment Returned.', 'monoova-payments-for-woocommerce'));
        $order->add_order_note(
            sprintf(
                __('NPP payment was returned. Amount: %s. Reason: %s. Original Transaction ID: %s. Return Transaction ID: %s.', 'monoova-payments-for-woocommerce'),
                wc_price($amount),
                esc_html($reason),
                esc_html($original_transaction_id),
                esc_html($return_transaction_id)
            )
        );
        $order->update_meta_data('_monoova_payment_status', 'returned');
        $order->save();

        $this->log(sprintf('Order %s status updated to failed due to NppReturn.', $order->get_id()), 'info');

        status_header(200);
        exit('NppReturn processed successfully');
    }

    /**
     * Handles 'InboundDirectCredit' event from Payment API.
     *
     * @param array $event_data The webhook event data
     */
    protected function handle_payment_api_event_InboundDirectCredit($event_data) {
        $this->log('Handling Payment API InboundDirectCredit event.', 'info', array('event_data' => $event_data));

        foreach ($event_data['DirectCreditDetails'] as $detail) {
            $this->log('Processing Direct Credit Detail: ' . json_encode($detail), 'debug');

            $transaction_id = $detail['TransactionId'] ?? null;
            $amount_paid = $detail['Amount'] ?? 0;
            $lodgement_ref = $detail['LodgementRef'] ?? null;

            if (!$lodgement_ref) {
                $this->log('lodgementReference missing in InboundDirectCredit record.', 'error', array('detail' => $detail));
                status_header(400);
                exit('Missing lodgement reference');
            }

            $order = $this->get_order_by_lodgement_reference($lodgement_ref);

            if (!$order) {
                $this->log('Could not determine order from InboundDirectCredit webhook. Reference: ' . $lodgement_ref, 'error', array('event_data' => $event_data));
                status_header(404);
                exit('Order not found');
            }

            $this->process_successful_payment($order, $amount_paid, $transaction_id, 'Direct Credit');
        }

        status_header(200);
        exit('InboundDirectCredit processed successfully');
    }

    /**
     * Handles 'DirectEntryDishonour' event from Payment API.
     *
     * @param array $event_data The full event data
     */
    protected function handle_payment_api_event_DirectEntryDishonour($event_data) {
        $this->log('Handling Payment API DirectEntryDishonour event.', 'info', array('event_data' => $event_data));

        $original_transaction_id = $event_data['originalTransactionId'] ?? null;
        $reason = $event_data['dishonourReason'] ?? 'Unknown';

        if (!$original_transaction_id) {
            $this->log('Original transaction ID missing in DirectEntryDishonour webhook.', 'error', array('event_data' => $event_data));
            status_header(400);
            exit('Missing transaction ID');
        }

        $order = $this->get_order_by_transaction_id($original_transaction_id);

        if (!$order) {
            $this->log(sprintf('Could not find order with Original Transaction ID %s from DirectEntryDishonour webhook.', $original_transaction_id), 'error');
            status_header(404);
            exit('Order not found');
        }

        $this->process_failed_payment($order, "Payment Dishonoured: " . $reason, $original_transaction_id, 'Direct Entry', $event_data);
        status_header(200);
        exit('Dishonour processed successfully');
    }

    /**
     * Handles 'InboundDirectCreditRejections' event from Payment API.
     *
     * @param array $event The full event data, which contains an array of rejections.
     */
    protected function handle_payment_api_event_InboundDirectCreditRejections($event) {
        $this->log('Handling Payment API InboundDirectCreditRejections event.', 'info', array('event_data' => $event));

        $rejections = $event ?? [];
        if (empty($rejections)) {
            $this->log('InboundDirectCreditRejections error: No rejections found in webhook payload.', 'error');
            status_header(400);
            exit('No rejections found in payload');
        }

        $processed_count = 0;
        foreach ($rejections as $rejection) {
            $reference = $rejection['lodgementRef'] ?? null;
            $reason = $rejection['reason'] ?? 'Unknown reason';

            if (!$reference) {
                $this->log('Skipping rejection due to missing lodgementRef.', 'warning', ['rejection' => $rejection]);
                continue;
            }

            $order = $this->get_order_by_lodgement_reference($reference);

            if ($order) {
                $this->process_failed_payment($order, "Direct Credit Rejected: " . $reason, null, 'Direct Credit', $rejection);
                $processed_count++;
            } else {
                $this->log('Could not find order for rejection with lodgementRef: ' . $reference, 'warning');
            }
        }

        status_header(200);
        exit("Processed {$processed_count} direct credit rejection(s).");
    }

    /**
     * Handles 'NPPCreditRejections' event from Payment API.
     *
     * @param array $event The full event data, which contains an array of rejections.
     */
    protected function handle_payment_api_event_NPPCreditRejections($event) {
        $this->log('Handling Payment API NPPCreditRejections event.', 'info', array('event_data' => $event));

        $reference = $event['lodgementRef'] ?? null;
        $reason = $event['reason'] ?? 'Unknown reason';
        $amount_paid = isset($event['amount']) ? (float) $event['amount'] : 0;

        if (!$reference) {
            $this->log('Skipping NPP rejection due to missing lodgementRef.', 'warning', ['rejection' => $event]);
            status_header(400);
            exit('Missing lodgement reference in NPP rejection webhook');
        }

        $order = $this->get_order_by_lodgement_reference($reference);

        if (!$order) {
            $this->log('Could not find order for NPP rejection with lodgementRef: ' . $reference, 'warning');
            status_header(200);
            exit("Order not found for NPP rejection.");
        }

        // Check if this is an overpayment or underpayment scenario
        if ($reason === 'Unmatched Reconciliation Rule Receivable' && $amount_paid > 0) {
            $order_total = (float) $order->get_total();

            if ($amount_paid > $order_total + 0.01) { // Allow a small tolerance for floating point issues
                // Overpayment scenario
                $overpayment_amount = $amount_paid - $order_total;
                $this->log(sprintf('Detected overpayment for order %s. Amount paid: %s, Order total: %s, Overpayment: %s', $order->get_id(), $amount_paid, $order_total, $overpayment_amount), 'info');
                $this->process_failed_payment($order, $reason, null, 'PayID/NPP', $event);
            } elseif ($amount_paid < $order_total) {
                $underpayment_amount = $order_total - $amount_paid;
                $this->log(sprintf('Detected underpayment for order %s. Amount paid: %s, Order total: %s, Underpayment: %s', $order->get_id(), $amount_paid, $order_total, $underpayment_amount), 'info');
                $this->process_failed_payment($order, $reason, null, 'PayID/NPP', $event);
            } else {
                // This shouldn't happen for exact payments, but log it
                $this->log(sprintf('Exact payment amount rejected for order %s. Amount: %s', $order->get_id(), $amount_paid), 'warning');
                $this->process_failed_payment($order, "NPP Credit Rejected: " . $reason, null, 'PayID/NPP', $event);
            }
        } else {
            // Handle other rejection reasons as failures
            $this->process_failed_payment($order, "NPP Credit Rejected: " . $reason, null, 'PayID/NPP', $event);
        }

        status_header(200);
        exit("Processed NPP rejection.");
    }

    /**
     * Centralized logic to process a successful payment.
     * This method should only be called for exact payments, as over/underpayments 
     * for PayID/NPP are handled via NPPCreditRejections webhook.
     *
     * @param WC_Order $order
     * @param float $amount_paid
     * @param string $transaction_id
     * @param string $payment_method_type (e.g., 'PayID/NPP', 'Direct Credit')
     */
    private function process_successful_payment($order, $amount_paid, $transaction_id, $payment_method_type) {
        $order_id = $order->get_id();
        $order_status = $order->get_status();

        $this->log(sprintf('Processing successful payment for order %s. Current status: %s', $order_id, $order_status), 'info');

        if ($order->is_paid()) {
            $this->log(sprintf('Order %s already marked as paid. Skipping update for %s.', $order_id, $payment_method_type), 'info');
            $order->add_order_note(
                sprintf(
                    __('[Monoova] Received a duplicate successful payment notification for an already paid order. Type: %s, Transaction ID: %s, Amount: %s.', 'monoova-payments-for-woocommerce'),
                    $payment_method_type,
                    esc_html($transaction_id),
                    wc_price($amount_paid)
                )
            );
            $order->save();
            return;
        }

        // For successful payments, we expect the amount to match the order total
        // Over/underpayments for PayID/NPP should be handled via NPPCreditRejections webhook
        $order->add_order_note(
            sprintf(
                __('Monoova payment successful (%s). Transaction ID: %s. Amount: %s.', 'monoova-payments-for-woocommerce'),
                $payment_method_type,
                esc_html($transaction_id),
                wc_price($amount_paid)
            )
        );

        $this->log(sprintf('Added payment success note to order %s', $order_id), 'info');

        // Update payment method for PayID payments

        if ($this->payid_gateway) {
            $payment_method_id = $this->payid_gateway->id;
            $payment_method_title = $this->payid_gateway->get_method_title();

            $order->set_payment_method($payment_method_id);
            $order->set_payment_method_title($payment_method_title);

            $this->log(sprintf('Updated payment method to "%s" (ID: %s) for order %s', $payment_method_title, $payment_method_id, $order_id), 'info');
        } else {
            $this->log(sprintf('PayID gateway not available, using fallback payment method title for order %s', $order_id), 'warning');
            $order->set_payment_method_title('PayID/Bank Transfer');
        }

        $order->payment_complete();
        $this->log(sprintf('Marked order %s as payment complete with transaction ID: %s', $order_id, $transaction_id), 'info');

        $order->update_meta_data('_monoova_transaction_id', $transaction_id);
        $order->update_meta_data('_monoova_payment_status', 'completed');
        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();
        $order->save();

        $this->handle_subscription_payment($order, $transaction_id, 'completed');
    }

    /**
     * Centralized logic to process a failed payment.
     *
     * @param WC_Order $order
     * @param string $reason
     * @param string|null $transaction_id
     * @param string $payment_method_type
     */
    private function process_failed_payment($order, $reason, $transaction_id, $payment_method_type, $response) {
        $order_id = $order->get_id();
        $order_status = $order->get_status();

        $this->log(sprintf('Processing failed payment for order %s. Current status: %s', $order_id, $order_status), 'info');

        if ($order->is_paid()) {
            $this->log(sprintf('Order %s is already paid. Skipping failure update.', $order_id), 'info');
            return;
        }
        if ($order->has_status('failed')) {
            $this->log(sprintf('Order %s is already marked as failed. Skipping update.', $order_id), 'info');
            $order->add_order_note(sprintf(__('Received a duplicate failed payment notification for an already failed order. Type: %s, Transaction ID: %s, Reason: %s, Status: %s', 'monoova-payments-for-woocommerce'), $payment_method_type, esc_html($transaction_id), esc_html($reason), $response['status'] ?? $response['Status'] ?? 'unknown'));
            $order->save();
            status_header(200);
            exit('Order already failed');
        }

        $order->update_status('failed', sprintf(__('Payment failed via Monoova (%s).', 'monoova-payments-for-woocommerce'), $payment_method_type));
        $order->add_order_note(
            sprintf(
                __('Monoova payment failed (%s). Amount: %s. Reason: %s. Transaction ID: %s. Status: %s', 'monoova-payments-for-woocommerce'),
                $payment_method_type,
                $response['amount'] ?? $response['Amount'] ?? 'N/A',
                esc_html($reason),
                esc_html($transaction_id ?? 'N/A'),
                $response['status'] ?? $response['Status'] ?? 'unknown'
            )
        );

        $this->log(sprintf('Added payment failure note to order %s', $order_id), 'info');

        $order->update_meta_data('_monoova_payment_status', 'failed');
        $order->update_meta_data('_monoova_cancellation_reason', $reason);
        if ($transaction_id) {
            $order->update_meta_data('_monoova_transaction_id', $transaction_id);
        }
        $order->save();

        $this->handle_subscription_payment($order, $transaction_id, 'failed');
    }

    /**
     * Get order by a transaction ID stored in meta.
     *
     * @param string $transaction_id
     * @return WC_Order|false
     */
    private function get_order_by_transaction_id($transaction_id) {
        $orders = wc_get_orders(array(
            'limit'      => 1,
            'meta_key'   => '_monoova_transaction_id',
            'meta_value' => $transaction_id,
        ));
        if (!empty($orders)) {
            return reset($orders);
        }
        // Fallback to standard WooCommerce transaction ID meta
        $orders = wc_get_orders(array(
            'limit'      => 1,
            'meta_key'   => '_transaction_id',
            'meta_value' => $transaction_id,
        ));
        return !empty($orders) ? reset($orders) : false;
    }
}
