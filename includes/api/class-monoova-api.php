<?php

/**
 * Monoova API Client
 *
 * @package Monoova_Payments_For_WooCommerce
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova_API Class
 */
class Monoova_API {

    /**
     * Monoova Payments API base URL.
     * @var string
     */
    private $payments_api_url;

    /**
     * Monoova Card API base URL.
     * @var string
     */
    private $card_api_url;

    /**
     * API Key (for Payments API Basic Auth and Card API OAuth client credentials).
     * @var string
     */
    private $api_key;

    /**
     * Monoova mAccount number.
     * @var string
     */
    private $maccount_number;

    /**
     * Test mode.
     * @var bool
     */
    private $testmode;

    /**
     * Debug mode.
     * @var bool
     */
    private $debug;

    /**
     * Logger instance.
     * @var WC_Logger|null
     */
    private $logger;

    /**
     * Card API OAuth Token.
     * @var string|null
     */
    private $card_api_token = null;

    /**
     * Transient key for Card API token.
     */
    const CARD_API_TOKEN_TRANSIENT_KEY = 'monoova_card_api_token';

    /**
     * Constructor.
     *
     * @param string $payments_api_url Base URL for the Monoova Payments API.
     * @param string $card_api_url Base URL for the Monoova Card API.
     * @param string $api_key API Key for authentication.
     * @param string $maccount_number Monoova mAccount number.
     * @param bool   $testmode Whether to enable test mode.
     * @param bool   $debug Whether to enable debug logging.
     */
    public function __construct($payments_api_url, $card_api_url, $api_key, $maccount_number, $testmode = false, $debug = false) {
        $this->payments_api_url = trailingslashit($payments_api_url);
        $this->card_api_url     = trailingslashit($card_api_url);
        $this->api_key          = $api_key;
        $this->maccount_number  = $maccount_number;
        $this->testmode         = $testmode;
        $this->debug            = $debug;

        if ($this->debug) {
            $this->logger = wc_get_logger();
        }
    }

    /**
     * Create a subscription for a card notification.
     *
     * @param object $event_request { subscriptionName: string, eventName: string }
     * The final generated payload before sending to Monoova API request should have the following structure:
     * {
        *     "subscriptionName": "PayToTest",
        *     "eventName": "PaymentAgreementNotification",
        *     "webHookDetail": {
        *         "callBackUrl": "http://your-callback-url.com",
        *         "securityToken": ""
        *     },
        *     "isActive": true
        * }
     *
     * response 400 should be like:
     *   {
     *      "errors": [
     *          {
     *              "errorCode": "NOS_DUP_SUB_CRT_ERROR",
     *              "errorMessage": "duplicate record already exists or subscriptionId not provided"
     *          }
     *      ],
     *      "traceId": "bd7b1d56-e80d-4260-8066-a89642c47a77"
     *  }
     */
    public function create_subscription_for_card_payto_notification($event_request) {
        $this->log('Creating subscription for card notification directly with Card API. Event Name: ' . $event_request->eventName);
        $payload = array(
            'subscriptionName' => $event_request->subscriptionName,
            'eventName' => $event_request->eventName,
            'webHookDetail' => array(
                // callbackUrl should be same as the sample: https://kittendifferentb9aeecdf5d-omuxa-studio.wp.build/?wc-api=monoova_webhook
                'callBackUrl' => home_url('/?wc-api=monoova_webhook'),
                // securityToken should be empty string
                'securityToken' => '',
            ),
            'isActive' => true,
        );
        $this->log('Payload for create_subscription_for_card_notification: ' . wp_json_encode($payload), 'debug');
        $this->log('Creating subscription for card notification directly with Card API.');
        return $this->request('au/core/notification-v1/Subscription', $payload, 'POST', 'card');
    }

    /**
     * Update a subscription for a card notification.
     *
     * @param string $subscription_id The subscription ID.
     * @param object $event_request { subscriptionName: string, eventName: string }
     * The final generated payload before sending to Monoova API request should have the following structure:
     * {
        *     "subscriptionName": "PayToTest",
        *     "eventName": "PaymentAgreementNotification",
        *     "webHookDetail": {
        *         "callBackUrl": "http://your-callback-url.com",
        *         "securityToken": ""
        *     },
        *     "isActive": true
        * }
     */
    public function update_subscription_for_card_notification($subscription_id, $event_request) {
        // log subscription_id and event_request->eventName
        $this->log('Updating subscription for card notification directly with Card API. Subscription ID: ' . $subscription_id . ' Event Name: ' . $event_request->eventName);
        $payload = array(
            'subscriptionName' => $event_request->subscriptionName,
            'eventName' => $event_request->eventName,
            'webHookDetail' => array(
                // callbackUrl should be same as the sample: https://kittendifferentb9aeecdf5d-omuxa-studio.wp.build/?wc-api=monoova_webhook
                'callBackUrl' => home_url('/?wc-api=monoova_webhook'),
                // securityToken should be empty string
                'securityToken' => '',
            ),
            'isActive' => true,
        );
        $this->log('Payload for update_subscription_for_card_notification: ' . wp_json_encode($payload), 'debug');
        $this->log('Updating subscription for card notification directly with Card API.');
        return $this->request('au/core/notification-v1/Subscription/' . $subscription_id, $payload, 'PUT', 'card');
    }

    /**
     * Create a subscription for a payments notification.
     *
     * @param object $event_request { eventName: string }
     * The final generated payload before sending to Monoova API request should have the following structure:
     * {
        *     "eventName": "NPPReceivePayment",
        *     "targetUrl": "http://your-callback-url.com",
        *     "subscriptionStatus": "On",
        *     "securityToken": ""
        * }
     *
     * {
    
     * response 400 should be like: (based on id in response, we can use it for API update subscription)
     *   {
     *       "durationMs": 54,
     *       "status": "WebhookAlreadySubscribed",
     *       "statusDescription": "Event is already subscribed. Please use /update API if you want to make any updates to your existing subscription",
     *       "dateTime": null,
     *       "eventName": "NPPReceivePayment",
     *       "id": 3675,
     *       "targetUrl": "http://ec2-54-206-140-90.ap-southeast-2.compute.amazonaws.com/?wc-api=monoova_webhook"
     *   }
     */
    public function create_subscription_for_payments_notification($event_request) {
        $payload = array(
            'eventName' => $event_request->eventName,
            'targetUrl' => home_url('/?wc-api=monoova_webhook'),
            'subscriptionStatus' => 'On',
            'securityToken' => '',
        );
        $this->log('Payload for create_subscription_for_payments_notification: ' . wp_json_encode($payload), 'debug');
        $this->log('Creating subscription for payments notification directly with Payments API.');
        return $this->request('subscriptions/v1/create', $payload, 'POST', 'payments');
    }

    /**
     * Update a subscription for a payments notification.
     *
     * @param string $subscription_id The subscription ID.
     * @param object $event_request { eventName: string }
     * The final generated payload before sending to Monoova API request should have the following structure:
     * {
        *     "id": 1,
        *     "targetUrl": "http://your-callback-url.com",
        *     "subscriptionStatus": "On",
        *     "securityToken": ""
        * }
     *
     */
    public function update_subscription_for_payments_notification($subscription_id) {
        $payload = array(
            'id' => $subscription_id,
            'targetUrl' => home_url('/?wc-api=monoova_webhook'),
            'subscriptionStatus' => 'On',
            'securityToken' => '',
        );
        $this->log('Payload for update_subscription_for_payments_notification: ' . wp_json_encode($payload), 'debug');
        $this->log('Updating subscription for payments notification directly with Payments API.');
        return $this->request('subscriptions/v1/update', $payload, 'POST', 'payments');
    }


    /**
     * Get all webhook subscriptions for Card and PayTo.
     *
     * @return array|WP_Error Subscriptions or error.
     * 
     * response should be like:
     *   {
     *       "traceId": "5b5d2d6e-4972-4be4-ab95-8a373b9d90c5",
     *       "subscriptionDetails": [
     *           {
     *           "subscriptionName": "PaytoTest",
     *           "eventName": "PaymentAgreementNotification",
     *           "subscriptionId": "734d8a9d-7ac6-44a7-9e88-867fb59f54e9",
     *           "notificationType": "string",
     *           "webHookDetail": {
     *               "callBackUrl": "http://demo62eacbbe3c14.mockable.io/pagnotification"
     *           },
     *           "isActive": true
     *           }
     *       ]
     *   }
     */
    public function get_all_card_and_payto_webhook_subscriptions() {
        $this->log('Getting all webhook subscriptions directly with Payments API.');
        return $this->request('au/core/notification-v1/Subscription', array(), 'GET', 'card');
    }

    /**
     * Get all webhook subscriptions for Payments API.
     *
     * @return array|WP_Error Subscriptions or error.
     * 
     * response should be like:
     *   {
     *       "durationMs": 20,
     *       "status": "Ok",
     *       "statusDescription": "Operation completed successfully",
     *       "eventname": [
     *           {
     *           "eventName": "NPPReceivePayment",
     *           "id": 1,
     *           "targetUrl": "http://www.abc1.com.au",
     *           "status": "On",
     *           "LastUpdated": "11/14/2019 3:41:05 PM",
     *           "createdDate": "11/14/2019 3:41:05 PM"
     *           }
     *       ]
     *   }
     */
    public function get_all_payments_webhook_subscriptions() {
        $this->log('Getting all webhook subscriptions directly with Payments API.');
        return $this->request('subscriptions/v1/list', array(), 'GET', 'payments');
    }

    /**
     * Get webhook subscriptions by Subscription ID for Card and PayTo.
     *
     * @return array|WP_Error Subscriptions or error.
     * 
     * response should be like:
     *   {
     *       "traceId": "5b5d2d6e-4972-4be4-ab95-8a373b9d90c5",
     *       "subscriptionDetails": [
     *           {
     *           "subscriptionName": "PaytoTest",
     *           "eventName": "PaymentAgreementNotification",
     *           "subscriptionId": "734d8a9d-7ac6-44a7-9e88-867fb59f54e9",
     *           "notificationType": "string",
     *           "webHookDetail": {
     *               "callBackUrl": "http://demo62eacbbe3c14.mockable.io/pagnotification"
     *           },
     *           "isActive": true
     *           }
     *       ]
     *   }
     */
    public function get_card_payto_webhook_subscription_by_Id($subscription_id) {
        $this->log('Getting webhook subscription by ID directly with Card API.');
        return $this->request('au/core/notification-v1/Subscription/' . $subscription_id, array(), 'GET', 'card');
    }


    /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level Log level.
     */
    private function log($message, $level = 'debug') {
        if ($this->debug && $this->logger) {
            $this->logger->log($level, '[Monoova API] ' . $message, array('source' => 'monoova-api'));
        }
    }

    /**
     * Get Card API OAuth Token.
     * Fetches a new token if expired or not set, using client_credentials grant type.
     *
     * @return string|WP_Error OAuth token or WP_Error on failure.
     */
    private function get_card_api_token() {
        if ($this->card_api_token) {
            return $this->card_api_token;
        }

        $token_transient = get_transient(self::CARD_API_TOKEN_TRANSIENT_KEY);
        if ($token_transient !== false) {
            $this->card_api_token = $token_transient;
            $this->log('Retrieved Card API token from transient.');
            return $this->card_api_token;
        }

        $this->log('Requesting new Card API OAuth token.');
        $token_url = $this->card_api_url . 'au/security/oauth-v1/Token';

        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($this->maccount_number . ':' . $this->api_key),
            'Content-Type'  => 'application/json',
        );



        $response = wp_remote_post($token_url, array(
            'method'    => 'POST',
            'headers'   => $headers,
            // no body needed
            'body'      => '',
            'timeout'   => 45,
        ));

        if (is_wp_error($response)) {
            $this->log('Error fetching Card API token: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $token_data = json_decode($response_body, true);

        if ($response_code === 200 && !empty($token_data['token'])) {
            $this->card_api_token = $token_data['token'];
            $expires_in = 3600; // Assume token expires in 1 hour
            set_transient(self::CARD_API_TOKEN_TRANSIENT_KEY, $this->card_api_token, $expires_in);
            $this->log('Successfully fetched and stored new Card API token. Expires in: ' . $expires_in . 's');
            return $this->card_api_token;
        } else {
            $this->log('Failed to fetch Card API token. Code: ' . $response_code . '. Body: ' . $response_body, 'error');
            return new WP_Error('token_error', __('Failed to obtain Card API access token.', 'monoova-payments-for-woocommerce'), array('status' => $response_code, 'response' => $response_body));
        }
    }

    /**
     * Make a request to the Monoova API.
     *
     * @param string $endpoint API endpoint path.
     * @param array  $data Request data.
     * @param string $method HTTP method (GET, POST, PUT, DELETE).
     * @param string $api_type 'payments' or 'card' to determine base URL and auth.
     * @return array|WP_Error API response or WP_Error on failure.
     */
    private function request($endpoint, $data = array(), $method = 'POST', $api_type = 'payments') {
        if (empty($this->api_key)) {
            $this->log('API Key is not configured.', 'error');
            return new WP_Error('api_key_missing', __('API Key is not configured.', 'monoova-payments-for-woocommerce'));
        }

        $base_url = ($api_type === 'card') ? $this->card_api_url : $this->payments_api_url;
        $url = $base_url . $endpoint;
        $this->log(sprintf('Requesting %s %s', $method, $url));
        if (!empty($data) && $method !== 'GET') {
            $this->log('Request data: ' . wp_json_encode($data));
        }

        $headers = array(
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        );

        if ($api_type === 'card') {
            if (strpos($endpoint, 'au/security/oauth-v1/Token') === false) {
                $token = $this->get_card_api_token();
                if (is_wp_error($token)) {
                    return $token;
                }
                $headers['Authorization'] = 'Bearer ' . $token;
            }
        } else {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->api_key . ':');
        }

        $request_args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 45,
        );

        if (!empty($data)) {
            if ('GET' === $method) {
                $url = add_query_arg($data, $url);
            } else {
                $request_args['body'] = wp_json_encode($data);
            }
        }

        $response = wp_remote_request($url, $request_args);

        if (is_wp_error($response)) {
            $this->log('WP_Error during request to ' . $url . ': ' . $response->get_error_message(), 'error');
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $this->log('Response Code: ' . $response_code);
        $this->log('Response Body: ' . $response_body);

        $decoded_body = json_decode($response_body, true);

        if ($response_code >= 200 && $response_code < 300) {
            return $decoded_body;
        } else {
            $error_message = __('An error occurred while communicating with Monoova.', 'monoova-payments-for-woocommerce');
            if (isset($decoded_body['errors'])) {
                $error_messages = array();
                foreach ($decoded_body['errors'] as $error) {
                    $error_messages[] = sprintf('Error Code: %s, Message: %s', $error['errorCode'], $error['errorMessage']);
                }
                $error_message = implode(' | ', $error_messages);
            } elseif (isset($decoded_body['errorDescription'])) {
                $error_message = $decoded_body['errorDescription'];
            } elseif (isset($decoded_body['title'])) {
                $error_message = $decoded_body['title'] . (isset($decoded_body['detail']) ? ': ' . $decoded_body['detail'] : '');
            } elseif (isset($decoded_body['message'])) {
                $error_message = $decoded_body['message'];
            } elseif (isset($decoded_body['statusDescription']) && isset($decoded_body['status'])) {
                $error_message = sprintf('eventName: %s, Status: %s, Message: %s', $decoded_body['eventName'], $decoded_body['status'], $decoded_body['statusDescription']);
            }
            
            $this->log('API Error: ' . $error_message . ' (Status: ' . $response_code . ')', 'error');
            return new WP_Error('api_error', $error_message, array('status' => $response_code, 'response' => $decoded_body));
        }
    }

    /**
     * Get financial transactions by date range.
     *
     * @param string $start_date Start date (YYYY-MM-DD).
     * @param string $end_date End date (YYYY-MM-DD).
     * @return array|WP_Error Transactions or error.
     */
    public function get_financial_transactions_by_date($start_date, $end_date) {
        $this->log(sprintf('Getting financial transactions from %s to %s directly from Payments API.', $start_date, $end_date));
        $query_params = array('startDate' => $start_date, 'endDate' => $end_date);
        return $this->request('/transactions', $query_params, 'GET', 'payments');
    }

    /**
     * Create a receivables account.
     *
     * @param array $payload Receivables account creation details.
     * @return array|WP_Error Account response or error.
     */
    public function create_receivables_account($payload) {
        $this->log('Creating receivables account - mapping to direct Payments API.');
        return $this->request('receivables/v1/create', $payload, 'POST', 'payments');
    }

    /**
     * Register a PayID.
     *
     * @param array $payload PayID registration details.
     * @return array|WP_Error Registration response or error.
     */
    public function register_payid($payload) {
        $this->log('Registering PayID - mapping to direct Payments API.');
        return $this->request('receivables/v1/payid/registerpayid', $payload, 'POST', 'payments');
    }

    /**
     * Creates a Reconciliation Rule in Monoova.
     * This pre-registers an expected payment against a static account.
     *
     * @param array $payload The rule creation payload.
     * @return array|WP_Error The API response or an error.
     */
    public function create_reconciliation_rule($payload) {
        $this->log('Creating reconciliation rule.');
        return $this->request('receivables/v1/reconciliationrules/create', $payload, 'POST', 'payments');
    }

    /**
     * Cancels a Reconciliation Rule in Monoova.
     *
     * @param array $payload The rule cancellation payload, typically containing the 'reconciliationRuleReference'.
     * @return array|WP_Error The API response or an error.
     */
    public function cancel_reconciliation_rule($payload) {
        $this->log('Cancelling reconciliation rule.');
        return $this->request('receivables/v1/reconciliationrules/cancel', $payload, 'PATCH', 'payments');
    }

    /**
     * Create a payment webhook subscription.
     *
     * @param array $subscription_details Subscription details.
     * @return array|WP_Error Subscription response or error.
     */
    public function create_payment_webhook_subscription($subscription_details) {
        $this->log('Creating payment webhook subscription directly with Monoova Payments API.');
        return $this->request('webhooks/subscriptions', $subscription_details, 'POST', 'payments');
    }

    /**
     * Update a payment webhook subscription.
     *
     * @param string $subscription_id The subscription ID.
     * @param array $subscription_details Updated subscription details.
     * @return array|WP_Error Update response or error.
     */
    public function update_payment_webhook_subscription($subscription_id, $subscription_details) {
        $this->log(sprintf('Updating payment webhook subscription %s directly with Monoova Payments API.', $subscription_id));
        return $this->request(sprintf('webhooks/subscriptions/%s', $subscription_id), $subscription_details, 'PUT', 'payments');
    }

    /**
     * Create a card transaction token (for Primer integration).
     *
     * @param array $payload The token creation payload.
     * @return array|WP_Error The token response or error.
     */
    public function create_card_transaction_token($payload) {
        $this->log('Creating card transaction token directly with Card API.');
        return $this->request('au/card/ccm/CreditCardTransaction/token', $payload, 'POST', 'card');
    }

    /**
     * Process a card payment with a token.
     *
     * @param array $payload The payment processing payload.
     * @return array|WP_Error The payment response or error.
     */
    public function process_card_payment_with_token($payload) {
        $this->log('Processing card payment with token directly with Card API.');
        return $this->request('au/card/ccm/CreditCardTransaction/process', $payload, 'POST', 'card');
    }

    /**
     * Request a refund from the Monoova API.
     *
     * @param array $body The refund request body.
     * @return array|WP_Error The API response on success, or a WP_Error on failure.
     */
    public function request_refund($body) {
        $this->log('Requesting card refund with Card API.');
        return $this->request('au/card/ccm/CreditCardTransactionAsync/refund', $body, 'POST', 'card');
    }

    /**
     * Process a refund for a receivables transaction (PayID/Bank Transfer).
     * Uses /receivables/v2/refund endpoint.
     *
     * @param array $payload Refund details.
     * @return array|WP_Error Refund response or error.
     */
    public function process_receivables_refund($payload) {
        $this->log('Processing receivables refund with Payments API.');
        return $this->request('receivables/v2/refund', $payload, 'POST', 'payments');
    }

    /**
     * Get the status of a card transaction.
     *
     * @param string $client_transaction_ref The client transaction reference.
     * @return array|WP_Error The transaction status or error.
     */
    public function get_card_transaction_status($client_transaction_ref) {
        $this->log('Getting card transaction status for reference: ' . $client_transaction_ref);
        return $this->request('au/card/ccm/CreditCardTransaction/' . $client_transaction_ref, array(), 'GET', 'card');
    }

    /* ===== PayTo API Methods ===== */

    /**
     * Create a PayTo payment agreement.
     *
     * @param array $payload The payment agreement creation payload.
     * @return array|WP_Error The API response or error.
     */
    public function create_payto_agreement($payload) {
        $this->log('Creating PayTo payment agreement.');
        return $this->request('au/payto/pam-v1/PaymentAgreement', $payload, 'POST', 'card');
    }

    /**
     * Get PayTo payment agreement details.
     *
     * @param string $payment_agreement_uid The payment agreement UID.
     * @return array|WP_Error The agreement details or error.
     */
    /* 
        Response should be like:
        {
  "traceId": "43075658-ed9a-4ce0-84ba-2f6524a16676",
  "paymentAgreementDetails": {
    "paymentAgreementUID": "BCORP1662344139",
    "paymentAgreementStatus": "Paused",
    "statusReasonCode": "R006",
    "statusReasonDescription": "Account is now closed",
    "mmsId": "d6765e4ff2eb1e7f83d72c1f8d3e2a07",
    "payeeDetails": {
      "payeeType": null,
      "payeeLinkedBsb": 802950,
      "payeeLinkedAccount": 10109010,
      "payeeLinkedPayId": null,
      "payeeLinkedPayIdType": null,
      "payeeAccountName": "BCORP",
      "ultimatePayee": "BCORP"
    },
    "payerDetails": {
      "payerType": "ORGN",
      "linkedBsb": 802950,
      "linkedAccount": 10109010,
      "linkedPayId": null,
      "linkedPayIdType": null,
      "payer": "WidgetCo",
      "ultimatePayer": "WidgetCo",
      "payerPartyReference": "Payer1662333659"
    },
    "paymentTerms": {
      "numberOfTransactionsPermitted": 100,
      "frequency": "WEEK",
      "amount": null,
      "maximumAmount": 100,
      "agreementType": "VARI"
    },
    "paymentDetails": {
      "automaticRenewal": false,
      "description": "payroll pag",
      "shortDescription": "PayToTest_1662333659",
      "purpose": "MORT",
      "respondByTime": "2022-10-07T14:35:27-10",
      "startDate": "2022-09-05",
      "endDate": "2023-08-24"
    },
    "pendingActions": [
      {
        "actionId": "45df1d4abc914455a0e377051cb39fd7",
        "actionType": "Create",
        "bilateral": true,
        "status": "PendingApproval"
      }
    ]
  }
}
    */
    public function get_payto_agreement($payment_agreement_uid) {
        $this->log('Getting PayTo payment agreement: ' . $payment_agreement_uid);
        return $this->request('au/payto/pam-v1/paymentAgreement/' . $payment_agreement_uid, array(), 'GET', 'card');
    }

    /**
     * Update PayTo payment agreement status (suspend, resume, cancel).
     *
     * @param string $payment_agreement_uid The payment agreement UID.
     * @param array $payload The status update payload.
     * @return array|WP_Error The API response or error.
     */
    public function update_payto_agreement_status($payment_agreement_uid, $payload) {
        $this->log('Updating PayTo payment agreement status: ' . $payment_agreement_uid);
        return $this->request('au/payto/pam-v1/paymentagreement/' . $payment_agreement_uid . '/status', $payload, 'PATCH', 'card');
    }

    /**
     * Create a PayTo payment initiation.
     *
     * @param array $payload The payment initiation payload.
     * @return array|WP_Error The API response or error.
     */
    /* 
        the API can return 400 bad request with response like:
        {
            "errors": [
                {
                    "errorCode": "PAS_COUNT_LIMIT_EXCEEDED",
                    "errorMessage": "Count of payments 4 exceeds configured limits 3 for the specified payment agreement."
                },
                {
                    "errorCode": "PAS_MAX_AMT_EXCEEDED",
                    "errorMessage": "The amount 1100.00 exceeds the allowed maximumAmount of the payment agreement 1000.00."
                }
            ],
            "traceId": "f51a80a4-e4f2-4be1-bfda-9a1281a71154"
        }
    */
    public function create_payto_payment($payload) {
        $this->log('Creating PayTo payment initiation.');
        return $this->request('au/payto/pas-v1/paymentInstruction', $payload, 'POST', 'card');
    }

    /**
     * Get PayTo payment initiation details.
     *
     * @param string $payment_initiation_uid The payment initiation UID.
     * @return array|WP_Error The payment details or error.
     */
    public function get_payto_payment($payment_initiation_uid) {
        $this->log('Getting PayTo payment initiation: ' . $payment_initiation_uid);
        return $this->request('au/payto/pas-v1/paymentinstruction/' . $payment_initiation_uid, array(), 'GET', 'card');
    }

    /**
     * Create a PayTo payment agreement (async).
     *
     * @param array $payload The payment agreement creation payload.
     * @return array|WP_Error The API response or error.
     */
    public function create_payto_agreement_async($payload) {
        $this->log('Creating PayTo payment agreement (async).');
        return $this->request('au/payto/pam-v1/PaymentAgreementAsync', $payload, 'POST', 'card');
    }

    /**
     * Create a PayTo payment initiation (async).
     *
     * @param array $payload The payment initiation payload.
     * @return array|WP_Error The API response or error.
     */
    public function create_payto_payment_async($payload) {
        $this->log('Creating PayTo payment initiation (async).');
        return $this->request('au/payto/pas-v1/PaymentInstructionAsync', $payload, 'POST', 'card');
    }

    /**
     * Get async request status for PayTo agreement.
     *
     * @param string $unique_request_id The unique request ID.
     * @return array|WP_Error The request status or error.
     */
    public function get_payto_agreement_async_status($unique_request_id) {
        $this->log('Getting PayTo agreement async request status: ' . $unique_request_id);
        return $this->request('au/payto/pam-v1/PaymentAgreementAsync/Request/' . $unique_request_id, array(), 'GET', 'card');
    }

    /**
     * Get async request status for PayTo payment.
     *
     * @param string $unique_request_id The unique request ID.
     * @return array|WP_Error The request status or error.
     */
    public function get_payto_payment_async_status($unique_request_id) {
        $this->log('Getting PayTo payment async request status: ' . $unique_request_id);
        return $this->request('au/payto/pas-v1/PaymentInstructionAsync/Request/' . $unique_request_id, array(), 'GET', 'card');
    }
}
