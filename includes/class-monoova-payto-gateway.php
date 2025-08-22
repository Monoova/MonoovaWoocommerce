<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova PayTo Payment Gateway.
 *
 * @class       Monoova_PayTo_Gateway
 * @extends     Monoova_Gateway
 * @version     1.0.2
 * @package     Monoova_Payments_For_WooCommerce/Classes/Payment
 * @author      Monoova
 */
class Monoova_PayTo_Gateway extends Monoova_Gateway {
    /**
     * Gateway ID
     *
     * @var string
     */
    public $id = 'monoova_payto';

    /**
     * Gateway title
     *
     * @var string
     */
    public $method_title = 'PayTo';

    /**
     * Gateway description
     *
     * @var string
     */
    public $method_description = 'Accept payments via PayTo. Customers can approve a payment agreement to complete the purchase.';

    /**
     * Is test mode active?
     *
     * @var bool
     */
    public $testmode;

    /**
     * Is debug mode active?
     *
     * @var bool
     */
    public $debug;

    /**
     * Purpose code for PayTo agreements
     *
     * @var string
     */
    public $purpose;

    /**
     * Agreement expiry days
     *
     * @var string
     */
    public $agreement_expiry_days;

    /**
     * Payee type for PayTo agreements
     *
     * @var string
     */
    public $payee_type;

    /**
     * Maximum amount for PayTo agreements
     *
     * @var float
     */
    public $maximum_amount;

    /**
     * PayTo mandate manager instance
     *
     * @var Monoova_PayTo_Mandate_Manager
     */
    private $mandate_manager;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'monoova_payto';
        $this->icon               = apply_filters('woocommerce_monoova_payto_icon', MONOOVA_PLUGIN_URL . 'assets/images/payto-logo.svg');
        $this->has_fields         = true;
        $this->method_title       = __('PayTo', 'monoova-payments-for-woocommerce');
        $this->method_description = __('Accept payments via PayTo. Customers can approve a payment agreement to complete the purchase.', 'monoova-payments-for-woocommerce');

        // Add subscription support - following official WooCommerce Subscriptions integration guide
        $this->supports = array(
            'products',
            'subscriptions',
            'subscription_cancellation',
            'subscription_suspension',
            'subscription_reactivation',
            'subscription_amount_changes',
            'subscription_date_changes',
            'subscription_payment_method_change',
            'subscription_payment_method_change_customer',
            'subscription_payment_method_change_admin',
            'multiple_subscriptions',
        );

        parent::__construct();

        $this->init_form_fields();
        $this->init_settings();
        $this->init_payto_settings();

        // Initialize mandate manager
        $this->mandate_manager = new Monoova_PayTo_Mandate_Manager();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

        // Register AJAX handlers
        add_action('wp_ajax_get_payto_agreement_payment_initiation_status', array($this, 'ajax_get_payto_agreement_payment_initiation_status'));
        add_action('wp_ajax_nopriv_get_payto_agreement_payment_initiation_status', array($this, 'ajax_get_payto_agreement_payment_initiation_status'));

        // Add action for creating PayTo agreement
        add_action('wp_ajax_monoova_create_payto_agreement', array($this, 'ajax_create_payto_agreement'));
        add_action('wp_ajax_nopriv_monoova_create_payto_agreement', array($this, 'ajax_create_payto_agreement'));

        // Add action for processing payment with existing mandate in the checkout page
        add_action('wp_ajax_monoova_process_payment_with_existing_mandate', array($this, 'ajax_process_payment_with_existing_mandate'));

        // Add action for checking express checkout availability  
        add_action('wp_ajax_monoova_check_express_checkout', array($this, 'ajax_check_express_checkout'));

        // Add action for checking PayTo mandate (express checkout)
        add_action('wp_ajax_monoova_payto_check_mandate', array($this, 'ajax_check_mandate'));

        // Add action for processing PayTo express payment
        add_action('wp_ajax_monoova_payto_express_payment', array($this, 'ajax_express_payment'));

        // Add action for resetting payment agreement when limits exceeded
        add_action('wp_ajax_monoova_payto_reset_agreement', array($this, 'ajax_reset_agreement'));
        add_action('wp_ajax_nopriv_monoova_payto_reset_agreement', array($this, 'ajax_reset_agreement'));

        // Add subscription hooks
        $this->add_subscription_hooks();
    }

    /**
     * Add subscription-related hooks following Stripe's implementation pattern
     */
    private function add_subscription_hooks() {
        // Only add hooks if WooCommerce Subscriptions is active
        if (!class_exists('WC_Subscriptions')) {
            return;
        }

        // Step 3: Process scheduled subscription payments (following Stripe pattern)
        add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'scheduled_subscription_payment'), 10, 2);

        // Step 5: Handle payment method changes (following Stripe pattern)
        add_action('woocommerce_subscription_failing_payment_method_updated_' . $this->id, array($this, 'update_failing_payment_method'), 10, 2);

        // Handle subscription status changes - these hooks are triggered for all gateways
        // We need to check if the subscription uses our gateway before acting
        add_action('woocommerce_subscription_status_cancelled', array($this, 'handle_subscription_cancelled'), 10, 1);
        add_action('woocommerce_subscription_status_on-hold', array($this, 'handle_subscription_suspended'), 10, 1);
        add_action('woocommerce_subscription_status_active', array($this, 'handle_subscription_activated'), 10, 1);
    }

    // ========================================
    // Subscription Utility Methods (following Stripe's pattern)
    // ========================================

    /**
     * Is $order_id a subscription? (following Stripe's has_subscription method)
     *
     * @param  int $order_id
     * @return boolean
     */
    public function has_subscription($order_id) {
        return (
            function_exists('wcs_order_contains_subscription')
            && function_exists('wcs_is_subscription')
            && function_exists('wcs_order_contains_renewal')
            && (wcs_order_contains_subscription($order_id) || wcs_is_subscription($order_id) || wcs_order_contains_renewal($order_id))
        );
    }

    /**
     * Returns whether this user is changing the payment method for a subscription.
     * (following Stripe's is_changing_payment_method_for_subscription method)
     *
     * @return bool
     */
    public function is_changing_payment_method_for_subscription() {
        if (isset($_GET['change_payment_method']) && function_exists('wcs_is_subscription')) { // phpcs:ignore WordPress.Security.NonceVerification
            return wcs_is_subscription(wc_clean(wp_unslash($_GET['change_payment_method']))); // phpcs:ignore WordPress.Security.NonceVerification
        }
        return false;
    }

    /**
     * Check if we should change subscription payment method (following Stripe's pattern)
     *
     * @param int $order_id
     * @return bool
     */
    public function maybe_change_subscription_payment_method($order_id) {
        return (
            class_exists('WC_Subscriptions') &&
            $this->has_subscription($order_id) &&
            $this->is_changing_payment_method_for_subscription()
        );
    }

    /**
     * Process subscription payment method change (following Stripe's pattern)
     *
     * @param int $order_id
     * @return array|null
     */
    public function process_change_subscription_payment_method($order_id) {
        try {
            $subscription_order = wc_get_order($order_id);

            if (!$subscription_order) {
                throw new Exception(__('Subscription not found.', 'monoova-payments-for-woocommerce'));
            }

            $this->log("Processing payment method change for subscription #{$order_id}");

            // Create new PayTo agreement for the subscription
            $this->create_new_payment_agreement($subscription_order);

            $this->log("Successfully updated payment method for subscription #{$order_id}");

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url($subscription_order),
            );

        } catch (Exception $e) {
            $this->log("Failed to change subscription payment method: " . $e->getMessage(), 'error');
            wc_add_notice($e->getMessage(), 'error');
            return array(
                'result' => 'failure'
            );
        }
    }

    /**
     * Initialize PayTo-specific settings from Unified Gateway
     */
    private function init_payto_settings() {
        $unified_settings = $this->get_unified_gateway_settings();

        if (!$unified_settings) {
            // Fallback to default values if unified settings not available
            $this->testmode = 'yes' === $this->get_option('testmode', 'yes');
            $this->debug = 'yes' === $this->get_option('debug', 'yes');
            $this->purpose = $this->get_option('purpose', 'OTHR');
            $this->agreement_expiry_days = $this->get_option('agreement_expiry_days', '');
            $this->payee_type = $this->get_option('payee_type', 'ORGN');
            $this->maximum_amount = $this->get_option('maximum_amount', '10000');
            return;
        }

        // Initialize properties from unified settings with defaults
        $this->testmode = 'yes' === ($unified_settings['testmode'] ?? 'yes');
        $this->debug = 'yes' === ($unified_settings['debug'] ?? 'yes');
        $this->purpose = $unified_settings['payto_purpose'] ?? 'OTHR';
        $this->agreement_expiry_days = $unified_settings['payto_agreement_expiry_days'] ?? '';
        $this->payee_type = $unified_settings['payto_payee_type'] ?? 'ORGN';
        $this->maximum_amount = $unified_settings['payto_maximum_amount'] ?? '10000';
    }

    /**
     * Get the title for the payment method.
     *
     * @return string Payment method title.
     */
    public function get_title() {
        // Check if controlled by unified gateway first
        if (class_exists('Monoova_Unified_Gateway')) {
            $unified_gateway = Monoova_Unified_Gateway::get_instance();
            if ($unified_gateway && $unified_gateway->is_controlling_child_gateways()) {
                $unified_settings = $unified_gateway->get_current_settings();
                if (!empty($unified_settings['payto_title'])) {
                    return $unified_settings['payto_title'];
                }
            }
        }

        // Fallback to individual gateway settings
        $title_from_settings = $this->get_option('title');
        return !empty($title_from_settings) ? $title_from_settings : $this->method_title;
    }

    /**
     * Get the description for the payment method.
     *
     * @return string Payment method description.
     */
    public function get_description() {
        // Check if controlled by unified gateway first
        if (class_exists('Monoova_Unified_Gateway')) {
            $unified_gateway = Monoova_Unified_Gateway::get_instance();
            if ($unified_gateway && $unified_gateway->is_controlling_child_gateways()) {
                $unified_settings = $unified_gateway->get_current_settings();
                if (!empty($unified_settings['payto_description'])) {
                    return $unified_settings['payto_description'];
                }
            }
        }

        // Fallback to individual gateway settings
        $description_from_settings = $this->get_option('description');
        return !empty($description_from_settings) ? $description_from_settings : $this->description;
    }

    /**
     * Check if gateway is available for use.
     *
     * @return bool
     */
    public function is_available() {
        // Check if controlled by unified gateway
        if (class_exists('Monoova_Unified_Gateway')) {
            $unified_gateway = Monoova_Unified_Gateway::get_instance();
            if ($unified_gateway && $unified_gateway->is_controlling_child_gateways()) {
                // Check if this gateway is enabled in unified settings
                if (!$unified_gateway->is_child_gateway_enabled('payto')) {
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
        // Get settings from unified gateway first
        $unified_settings = $unified_gateway->get_current_settings();

        // Check if PayTo is explicitly enabled in unified settings
        if (empty($unified_settings['enable_payto_payments']) || $unified_settings['enable_payto_payments'] !== 'yes') {
            return false;
        }

        // Now check basic availability (enabled status, etc.)
        if ($this->enabled !== 'yes') {
            return false;
        }

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
     * Get the payment method icon.
     *
     * @return string
     */
    public function get_icon() {
        $icon = '<img src="' . esc_url(MONOOVA_PLUGIN_URL . 'assets/images/payto-logo.svg') . '" alt="Monoova PayTo Payment" style="height: 20px;" />';
        return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
    }

    /**
     * Initialise Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __('Enable/Disable', 'monoova-payments-for-woocommerce'),
                'type'    => 'checkbox',
                'label'   => __('Enable Monoova PayTo', 'monoova-payments-for-woocommerce'),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __('Title', 'monoova-payments-for-woocommerce'),
                'type'        => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'monoova-payments-for-woocommerce'),
                'default'     => __('PayTo', 'monoova-payments-for-woocommerce'),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __('Description', 'monoova-payments-for-woocommerce'),
                'type'        => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'monoova-payments-for-woocommerce'),
                'default'     => __('Set up PayTo directly from your bank using BSB and Account Number or PayID.', 'monoova-payments-for-woocommerce'),
            ),
            'testmode' => array(
                'title'       => __('Test mode', 'monoova-payments-for-woocommerce'),
                'label'       => __('Enable Test Mode', 'monoova-payments-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'monoova-payments-for-woocommerce'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'debug' => array(
                'title'       => __('Debug mode', 'monoova-payments-for-woocommerce'),
                'label'       => __('Enable Debug Mode', 'monoova-payments-for-woocommerce'),
                'type'        => 'checkbox',
                'description' => __('Enable debug logging for PayTo transactions. Check logs under WooCommerce > Status > Logs.', 'monoova-payments-for-woocommerce'),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'purpose' => array(
                'title'       => __('Purpose Code', 'monoova-payments-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Purpose code for PayTo agreements as per ISO 20022 standards.', 'monoova-payments-for-woocommerce'),
                'default'     => 'OTHR',
                'desc_tip'    => true,
                'options'     => array(
                    'MORT' => __('MORT - Mortgage', 'monoova-payments-for-woocommerce'),
                    'UTIL' => __('UTIL - Utilities', 'monoova-payments-for-woocommerce'),
                    'LOAN' => __('LOAN - Loan', 'monoova-payments-for-woocommerce'),
                    'DEPD' => __('DEPD - Deposit', 'monoova-payments-for-woocommerce'),
                    'GAMP' => __('GAMP - Gaming/Gambling', 'monoova-payments-for-woocommerce'),
                    'RETL' => __('RETL - Retail', 'monoova-payments-for-woocommerce'),
                    'SALA' => __('SALA - Salary Payment', 'monoova-payments-for-woocommerce'),
                    'PERS' => __('PERS - Personal', 'monoova-payments-for-woocommerce'),
                    'GOVT' => __('GOVT - Government', 'monoova-payments-for-woocommerce'),
                    'PENS' => __('PENS - Pension', 'monoova-payments-for-woocommerce'),
                    'TAXS' => __('TAXS - Tax Payment', 'monoova-payments-for-woocommerce'),
                    'OTHR' => __('OTHR - Other', 'monoova-payments-for-woocommerce'),
                ),
            ),
            'agreement_expiry_days' => array(
                'title'       => __('Agreement Expiry (Days)', 'monoova-payments-for-woocommerce'),
                'type'        => 'number',
                'description' => __('Number of days from creation after which the PayTo agreement will expire. Leave empty for no expiry.', 'monoova-payments-for-woocommerce'),
                'default'     => '',
                'desc_tip'    => true,
                'custom_attributes' => array(
                    'min' => '1',
                    'max' => '365',
                    'step' => '1',
                ),
            ),
            'payee_type' => array(
                'title'       => __('Payee Type', 'monoova-payments-for-woocommerce'),
                'type'        => 'select',
                'description' => __('Type of payee for PayTo agreements. ORGN for organizations/businesses, PERS for individuals.', 'monoova-payments-for-woocommerce'),
                'default'     => 'ORGN',
                'desc_tip'    => true,
                'options'     => array(
                    'ORGN' => __('ORGN - Organization', 'monoova-payments-for-woocommerce'),
                    'PERS' => __('PERS - Person', 'monoova-payments-for-woocommerce'),
                ),
            ),
        );
    }

    /**
     * Output the payment form fields
     */
    public function payment_fields() {
        if ($this->description) {
            echo '<p>' . wp_kses_post($this->description) . '</p>';
        }

        echo '<div class="monoova-payto-form">';

        // Check for existing active mandate for express checkout
        $this->display_express_checkout_option();
        echo '<div class="payto-payment-method-selection">';
        echo '<p class="form-row form-row-wide">';
        echo '<label>' . esc_html__('Pay with', 'monoova-payments-for-woocommerce') . '</label>';
        echo '<input type="radio" id="payto_payment_method_payid" name="payto_payment_method" value="payid" checked>';
        echo '<label for="payto_payment_method_payid" style="margin-left: 10px; margin-right: 20px;">' . esc_html__('PayID', 'monoova-payments-for-woocommerce') . '</label>';
        echo '<input type="radio" id="payto_payment_method_bsb" name="payto_payment_method" value="bsb_account">';
        echo '<label for="payto_payment_method_bsb" style="margin-left: 10px;">' . esc_html__('BSB and account number', 'monoova-payments-for-woocommerce') . '</label>';
        echo '</p>';
        echo '</div>';

        // PayID Fields
        echo '<div id="payto_payid_fields" class="payto-fields-group">';
        echo '<h4>' . esc_html__('Enter your PayID details', 'monoova-payments-for-woocommerce') . '</h4>';

        // PayID Type Selection
        echo '<p class="form-row form-row-wide">';
        echo '<label for="payto_payid_type">' . esc_html__('PayID Type', 'monoova-payments-for-woocommerce') . ' <span class="required">*</span></label>';
        echo '<select id="payto_payid_type" name="payto_payid_type" class="select">';
        echo '<option value="PhoneNumber">' . esc_html__('Mobile Number', 'monoova-payments-for-woocommerce') . '</option>';
        echo '<option value="Email">' . esc_html__('Email Address', 'monoova-payments-for-woocommerce') . '</option>';
        echo '<option value="ABN">' . esc_html__('ABN (Australian Business Number)', 'monoova-payments-for-woocommerce') . '</option>';
        echo '<option value="ACN">' . esc_html__('ACN (Australian Company Number)', 'monoova-payments-for-woocommerce') . '</option>';
        echo '<option value="OrganisationId">' . esc_html__('Organisation ID', 'monoova-payments-for-woocommerce') . '</option>';
        echo '</select>';
        echo '</p>';

        // PayID Value Input
        echo '<p class="form-row form-row-wide">';
        echo '<label for="payto_payid_value" id="payto_payid_value_label">' . esc_html__('Mobile Number', 'monoova-payments-for-woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="tel" id="payto_payid_value" name="payto_payid_value" class="input-text" placeholder="0412345678 or +61412345678" />';
        echo '<small class="form-text text-muted" id="payto_payid_help">' . esc_html__('Enter your mobile number with or without country code', 'monoova-payments-for-woocommerce') . '</small>';
        echo '</p>';
        echo '</div>';

        // BSB and Account Fields
        echo '<div id="payto_bsb_fields" class="payto-fields-group" style="display: none;">';
        echo '<h4>' . esc_html__('Enter your bank details', 'monoova-payments-for-woocommerce') . '</h4>';
        echo '<p class="form-row form-row-wide">';
        echo '<label for="payto_account_name">' . esc_html__('Name associated with bank account', 'monoova-payments-for-woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="text" id="payto_account_name" name="payto_account_name" class="input-text" placeholder="Enter your name" />';
        echo '</p>';
        echo '<p class="form-row form-row-first">';
        echo '<label for="payto_bsb">' . esc_html__('BSB', 'monoova-payments-for-woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="text" id="payto_bsb" name="payto_bsb" class="input-text" placeholder="Enter BSB" />';
        echo '</p>';
        echo '<p class="form-row form-row-last">';
        echo '<label for="payto_account_number">' . esc_html__('Account Number', 'monoova-payments-for-woocommerce') . ' <span class="required">*</span></label>';
        echo '<input type="text" id="payto_account_number" name="payto_account_number" class="input-text" placeholder="Enter your account number" />';
        echo '</p>';
        echo '<div class="clear"></div>';
        echo '</div>';

        echo '</div>';

        // Add JavaScript for form switching
        $this->add_payment_form_scripts();
    }

    /**
     * Add JavaScript for payment form interaction
     */
    private function add_payment_form_scripts() {
?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                function togglePayToFields() {
                    var selectedMethod = $('input[name="payto_payment_method"]:checked').val();
                    if (selectedMethod === 'payid') {
                        $('#payto_payid_fields').show();
                        $('#payto_bsb_fields').hide();
                    } else {
                        $('#payto_payid_fields').hide();
                        $('#payto_bsb_fields').show();
                    }
                }

                function updatePayIdField() {
                    var payidType = $('#payto_payid_type').val();
                    var $input = $('#payto_payid_value');
                    var $label = $('#payto_payid_value_label');
                    var $help = $('#payto_payid_help');

                    // Clear current value
                    $input.val('');

                    // Update field based on PayID type
                    switch (payidType) {
                        case 'PhoneNumber':
                            $label.html('<?php echo esc_js(__('Mobile Number', 'monoova-payments-for-woocommerce')); ?> <span class="required">*</span>');
                            $input.attr('type', 'tel');
                            $input.attr('placeholder', '0412345678 or +61412345678');
                            $help.text('<?php echo esc_js(__('Enter your mobile number with or without country code', 'monoova-payments-for-woocommerce')); ?>');
                            break;
                        case 'Email':
                            $label.html('<?php echo esc_js(__('Email Address', 'monoova-payments-for-woocommerce')); ?> <span class="required">*</span>');
                            $input.attr('type', 'email');
                            $input.attr('placeholder', 'example@email.com');
                            $help.text('<?php echo esc_js(__('Use the email address registered as your PayID', 'monoova-payments-for-woocommerce')); ?>');
                            break;
                        case 'ABN':
                            $label.html('<?php echo esc_js(__('ABN', 'monoova-payments-for-woocommerce')); ?> <span class="required">*</span>');
                            $input.attr('type', 'text');
                            $input.attr('placeholder', '12345678901');
                            $help.text('<?php echo esc_js(__('Enter your 11-digit ABN', 'monoova-payments-for-woocommerce')); ?>');
                            break;
                        case 'ACN':
                            $label.html('<?php echo esc_js(__('ACN', 'monoova-payments-for-woocommerce')); ?> <span class="required">*</span>');
                            $input.attr('type', 'text');
                            $input.attr('placeholder', '123456789');
                            $help.text('<?php echo esc_js(__('Enter your 9-digit ACN', 'monoova-payments-for-woocommerce')); ?>');
                            break;
                        case 'OrganisationId':
                            $label.html('<?php echo esc_js(__('Organisation ID', 'monoova-payments-for-woocommerce')); ?> <span class="required">*</span>');
                            $input.attr('type', 'text');
                            $input.attr('placeholder', '<?php echo esc_js(__('Enter Organisation ID', 'monoova-payments-for-woocommerce')); ?>');
                            $help.text('<?php echo esc_js(__('Enter your organisation identifier', 'monoova-payments-for-woocommerce')); ?>');
                            break;
                    }
                }

                // Format mobile number input
                function formatMobileNumber(value) {
                    // Remove all non-digits
                    var numbers = value.replace(/\D/g, '');

                    // Handle different input patterns
                    if (numbers.indexOf('61') === 0) {
                        // Already has country code
                        return '+61' + numbers.slice(2);
                    } else if (numbers.indexOf('0') === 0) {
                        // Australian format starting with 0
                        return numbers;
                    } else if (numbers.length <= 9 && numbers.indexOf('0') !== 0) {
                        // Could be without leading 0, add it
                        return '0' + numbers;
                    }

                    return value;
                }

                // Initial setup
                togglePayToFields();
                updatePayIdField();

                // Event handlers
                $('input[name="payto_payment_method"]').change(function() {
                    togglePayToFields();
                });

                $('#payto_payid_type').change(function() {
                    updatePayIdField();
                });

                // Format mobile number on input (only for phone number type)
                $('#payto_payid_value').on('input', function() {
                    var payidType = $('#payto_payid_type').val();
                    if (payidType === 'PhoneNumber') {
                        var formatted = formatMobileNumber($(this).val());
                        $(this).val(formatted);
                    }
                });
            });
        </script>
        <style>
            .monoova-payto-form {
                padding: 15px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background-color: #f9f9f9;
                margin: 10px 0;
            }

            .payto-payment-method-selection label {
                display: inline;
                margin: 0;
                font-weight: normal;
            }

            .payto-fields-group {
                margin-top: 15px;
                padding: 10px;
                background-color: #fff;
                border-radius: 3px;
            }

            .payto-fields-group h4 {
                margin-top: 0;
                margin-bottom: 10px;
                color: #333;
            }

            .form-text.text-muted {
                color: #666;
                font-size: 12px;
                margin-top: 5px;
                display: block;
            }

            .payto-fields-group select {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 3px;
                background-color: #fff;
            }
        </style>
<?php
    }

    /**
     * Validate payment form fields
     */
    public function validate_fields() {
        $payment_method = sanitize_text_field($_POST['payto_payment_method'] ?? '');

        if ($payment_method === 'payid') {
            $payid_type = sanitize_text_field($_POST['payto_payid_type'] ?? '');
            $payid_value = sanitize_text_field($_POST['payto_payid_value'] ?? '');

            if (empty($payid_type)) {
                wc_add_notice(__('PayID type is required.', 'monoova-payments-for-woocommerce'), 'error');
                return false;
            }

            if (empty($payid_value)) {
                wc_add_notice(__('PayID is required.', 'monoova-payments-for-woocommerce'), 'error');
                return false;
            }

            // Validate based on PayID type
            switch ($payid_type) {
                case 'PhoneNumber':
                    // Remove spaces and normalize for validation
                    $normalized_phone = preg_replace('/\s+/', '', $payid_value);
                    if (!preg_match('/^(\+61-?[4-5]\d{8}|0[4-5]\d{8}|\d{9})$/', $normalized_phone)) {
                        wc_add_notice(__('Please enter a valid Australian mobile number (e.g., 0422020901 or +61422020901).', 'monoova-payments-for-woocommerce'), 'error');
                        return false;
                    }
                    break;
                case 'Email':
                    if (!filter_var($payid_value, FILTER_VALIDATE_EMAIL)) {
                        wc_add_notice(__('Please enter a valid email address.', 'monoova-payments-for-woocommerce'), 'error');
                        return false;
                    }
                    break;
                default:
                    wc_add_notice(__('Invalid PayID type selected.', 'monoova-payments-for-woocommerce'), 'error');
                    return false;
            }
        } elseif ($payment_method === 'bsb_account') {
            $account_name = sanitize_text_field($_POST['payto_account_name'] ?? '');
            $bsb = sanitize_text_field($_POST['payto_bsb'] ?? '');
            $account_number = sanitize_text_field($_POST['payto_account_number'] ?? '');

            if (empty($account_name)) {
                wc_add_notice(__('Account name is required.', 'monoova-payments-for-woocommerce'), 'error');
                return false;
            }
            if (empty($bsb)) {
                wc_add_notice(__('BSB is required.', 'monoova-payments-for-woocommerce'), 'error');
                return false;
            }
            if (empty($account_number)) {
                wc_add_notice(__('Account number is required.', 'monoova-payments-for-woocommerce'), 'error');
                return false;
            }
            // Basic BSB validation (6 digits, can have dash)
            if (!preg_match('/^\d{3}-?\d{3}$/', $bsb)) {
                wc_add_notice(__('Please enter a valid BSB (6 digits).', 'monoova-payments-for-woocommerce'), 'error');
                return false;
            }
        } else {
            wc_add_notice(__('Please select a payment method.', 'monoova-payments-for-woocommerce'), 'error');
            return false;
        }

        return true;
    }
    /**
     * Process the payment and return the result.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $this->log("Processing PayTo payment for order #{$order_id}.");

        // Step 2: Process Subscription Sign-ups - following Stripe's implementation pattern
        $is_subscription = $this->has_subscription($order_id);
        $is_payment_method_change = $this->maybe_change_subscription_payment_method($order_id);

        $this->log("Order type - Subscription: " . ($is_subscription ? 'yes' : 'no') . ", Payment method change: " . ($is_payment_method_change ? 'yes' : 'no'));

        $api = $this->get_api();
        if (!$api) {
            $this->log('Error: Monoova API client not initialized.', 'error');
            wc_add_notice(__('Payment gateway error. Please contact support.', 'monoova-payments-for-woocommerce'), 'error');
            return ['result' => 'failure'];
        }

        // Step 5: Handle Recurring Payment Method Changes (following Stripe pattern)
        // When customer changes payment method on existing subscription, process with $0 total
        if ($is_payment_method_change) {
            return $this->process_change_subscription_payment_method($order_id);
        }

        // Check if user wants to use existing mandate for express checkout
        $use_existing_mandate = !empty($_POST['payto_use_existing_mandate']);

        // Also check for express checkout from blocks
        $is_express_checkout = !empty($_POST['monoova_payto_express']) ||
            !empty($_POST['payment_method_data']['monoova_payto_express']);
        $payment_agreement_uid = sanitize_text_field($_POST['payment_method_data']['payment_agreement_uid'] ?? $_POST['payment_agreement_uid'] ?? '');

        if ($use_existing_mandate || ($is_express_checkout && $payment_agreement_uid)) {
            $customer_id = $this->get_customer_id_for_order($order, true); // true = for mandate storage
            $billing_email = $order->get_billing_email();

            $existing_mandate = null;

            if (!empty($payment_agreement_uid)) {
                // Get mandate by UID for express checkout
                $existing_mandate = $this->mandate_manager->get_mandate_by_uid($payment_agreement_uid);

                // Verify mandate belongs to current user
                if ($existing_mandate && $existing_mandate->user_id != $customer_id) {
                    $this->log("Security: Attempt to use mandate belonging to different user");
                    $existing_mandate = null;
                }
            } else {
                // Get active mandate for current user (legacy express checkout)
                if ($customer_id > 0) {
                    $existing_mandate = $this->mandate_manager->get_active_mandate_for_user($customer_id, $billing_email);
                }
            }

            if ($existing_mandate && $this->should_use_existing_mandate($order, $existing_mandate)) {
                $this->log("Using existing mandate for express checkout: {$existing_mandate->payment_agreement_uid}");

                // For subscription orders, store mandate info for future renewals
                if ($is_subscription) {
                    $this->store_subscription_mandate_info($order, $existing_mandate);
                }
                return $this->process_payment_with_existing_mandate($order, $existing_mandate);
            } else {
                if (!empty($payment_agreement_uid)) {
                    $this->log("Specified mandate not suitable or not found, creating new agreement");
                } else {
                    $this->log("Existing mandate not suitable, creating new agreement");
                }
            }
        }

        $this->log("Creating new PayTo payment agreement for order #{$order_id}.");

        // Create new payment agreement
        $this->create_new_payment_agreement($order);

        // For subscription orders, store additional metadata for future renewals
        if ($is_subscription) {
            $this->store_subscription_metadata($order);
        }

        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    /**
     * Get customer payment details from POST data
     */
    private function get_customer_payment_details($payment_method) {
        if ($payment_method === 'payid') {
            $payid_type = sanitize_text_field($_POST['payto_payid_type'] ?? '');
            $payid_value = sanitize_text_field($_POST['payto_payid_value'] ?? '');

            if (empty($payid_type) || empty($payid_value)) {
                return false;
            }

            // Format PayID value based on type
            switch ($payid_type) {
                case 'PhoneNumber':
                    // Ensure mobile number is in correct format for API: +61-422020901
                    // Remove all non-numeric characters first
                    $cleaned_number = preg_replace('/[^0-9]/', '', $payid_value);

                    // Handle different input formats
                    if (strpos($cleaned_number, '61') === 0 && strlen($cleaned_number) === 11) {
                        // Already has 61 prefix, format as +61-XXXXXXXXX
                        $payid_value = '+61-' . substr($cleaned_number, 2);
                    } elseif (strpos($cleaned_number, '0') === 0 && strlen($cleaned_number) === 10) {
                        // Australian format starting with 0, convert to +61-XXXXXXXXX
                        $payid_value = '+61-' . substr($cleaned_number, 1);
                    } elseif (strlen($cleaned_number) === 9) {
                        // 9 digits without prefix, add +61-
                        $payid_value = '+61-' . $cleaned_number;
                    } else {
                        // Use as-is if already has + or other format
                        if (strpos($payid_value, '+') !== 0) {
                            // If no + sign, try to format as Australian number
                            $payid_value = '+61-' . ltrim($cleaned_number, '0');
                        } elseif (strpos($payid_value, '+61') === 0 && strpos($payid_value, '-') === false) {
                            // If already has +61 but no dash, add the dash
                            $payid_value = '+61-' . substr($payid_value, 3);
                        }
                    }
                    break;
                case 'ABN':
                    // Remove spaces and non-digits from ABN
                    $payid_value = preg_replace('/[^0-9]/', '', $payid_value);
                    break;
                case 'ACN':
                    // Remove spaces and non-digits from ACN
                    $payid_value = preg_replace('/[^0-9]/', '', $payid_value);
                    break;
                case 'Email':
                case 'OrganisationId':
                    // No formatting needed
                    break;
            }

            return [
                'method' => 'payid',
                'payid_type' => $payid_type,
                'payid' => $payid_value,
            ];
        } elseif ($payment_method === 'bsb_account') {
            $account_name = sanitize_text_field($_POST['payto_account_name'] ?? '');
            $bsb = sanitize_text_field($_POST['payto_bsb'] ?? '');
            $account_number = sanitize_text_field($_POST['payto_account_number'] ?? '');

            if (empty($account_name) || empty($bsb) || empty($account_number)) {
                return false;
            }

            // Format BSB (remove spaces and add dash if needed)
            $bsb = preg_replace('/[^0-9]/', '', $bsb);
            if (strlen($bsb) === 6) {
                $bsb = substr($bsb, 0, 3) . '-' . substr($bsb, 3);
            }

            return [
                'method' => 'bsb_account',
                'account_name' => $account_name,
                'bsb' => $bsb,
                'account_number' => $account_number,
            ];
        }

        return false;
    }

    /**
     * Load scripts.
     */
    public function payment_scripts() {
        // We need scripts on checkout/pay page.
        if (!is_checkout() && !isset($_GET['pay_for_order'])) {
            return;
        }

        if (!$this->is_available()) {
            return;
        }

        // Localize script with AJAX parameters for status checking
        wp_localize_script('jquery', 'monoova_payto_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'status_nonce' => wp_create_nonce('monoova_payto_status_nonce'),
        ));
    }

    /**
     * Create a payment initiation for an authorized PayTo agreement.
     *
     * @param int $order_id The order ID.
     * @param string $payment_agreement_uid The payment agreement UID.
     * @return array|WP_Error The payment response or error.
     */
    public function create_payment_initiation($order_id, $payment_agreement_uid = null) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('invalid_order', __('Invalid order.', 'monoova-payments-for-woocommerce'));
        }

        // Get agreement UID from parameter or order meta
        if (empty($payment_agreement_uid)) {
            $payment_agreement_uid = $order->get_meta('_monoova_payto_agreement_uid', true);
        }

        if (empty($payment_agreement_uid)) {
            return new WP_Error('missing_agreement', __('Payment agreement UID not found.', 'monoova-payments-for-woocommerce'));
        }
        $api = $this->get_api();
        if (!$api) {
            return new WP_Error('api_error', __('API client not initialized.', 'monoova-payments-for-woocommerce'));
        }

        // Generate unique payment reference
        $payment_reference = apply_filters(
            'monoova_payto_payment_reference',
            'WC-PAYTO-PAYMENT_INIT-' . $order->get_id() . '-' . time(),
            $order
        );

        // Ensure payment reference doesn't exceed API limits
        $payment_reference = $this->truncate_field($payment_reference, 35, 'payment_reference');

        // Prepare payment initiation payload
        $raw_payment_payload = [
            'paymentInitiationUID' => $payment_reference,
            'paymentAgreementUID' => $payment_agreement_uid,
            'paymentDetails' => [
                'amount' => $order->get_total(),
                'lodgementReference' => sprintf(
                    __('Payment for order #%s', 'monoova-payments-for-woocommerce'),
                    $order->get_order_number()
                ),
            ],
        ];

        // Validate and truncate all string fields according to API limits
        $payment_payload = $this->prepare_payto_payment_payload($raw_payment_payload);

        $this->log("Creating PayTo payment initiation for order #{$order_id}. Payload: " . wp_json_encode($payment_payload), 'debug');

        // Create the payment initiation
        $response = $api->create_payto_payment($payment_payload);

        // Store payment initiation UID in order meta even if it fails
        $order->update_meta_data('_monoova_payto_payment_uid', $payment_reference);
        $order->save();

        if (is_wp_error($response)) {
            $this->log("Error creating PayTo payment initiation for order #{$order_id}: " . $response->get_error_message(), 'error');

            // Check if this is an API error with response data that might contain agreement limit errors
            $error_data = $response->get_error_data();
            if (isset($error_data['response']['paymentInitiationStatus'])) {
                $order->update_meta_data('_monoova_payto_payment_status', $error_data['response']['paymentInitiationStatus']);
                // add notice $error_data['response']['statusReasonDescription']
                $order->add_order_note($error_data['response']['statusReasonDescription']);
                $order->save();
            }   

            if (isset($error_data['response']['errors']) && is_array($error_data['response']['errors'])) {
                $agreement_limit_errors = array();
                foreach ($error_data['response']['errors'] as $error) {
                    if (isset($error['errorCode'])) {
                        $error_code = $error['errorCode'];
                        $error_message = $error['errorMessage'] ?? '';

                        // Check for payment agreement limit errors
                        if (in_array($error_code, array('PAS_COUNT_LIMIT_EXCEEDED', 'PAS_MAX_AMT_EXCEEDED'))) {
                            $agreement_limit_errors[] = array(
                                'code' => $error_code,
                                'message' => $error_message
                            );
                        }
                    }
                }

                // If we have agreement limit errors, return a special error type
                if (!empty($agreement_limit_errors)) {
                    $this->log("PayTo agreement limit exceeded for order #{$order_id}: " . wp_json_encode($agreement_limit_errors), 'warning');

                    return new WP_Error(
                        'payto_agreement_limit_exceeded',
                        __('Payment agreement limits exceeded. Please create a new payment agreement.', 'monoova-payments-for-woocommerce'),
                        array(
                            'errors' => $agreement_limit_errors,
                            'agreement_uid' => $payment_agreement_uid,
                            'requires_new_agreement' => true
                        )
                    );
                }
            }

            // Return the original API error if it's not an agreement limit error
            return $response;
        }

        $this->log("PayTo payment initiation response for order #{$order_id}: " . wp_json_encode($response), 'debug');

        // Save payment initiation details
        $payment_initiation_uid = $response['paymentInitiationUID'] ?? '';
        $payment_status = $response['paymentInitiationStatus'] ?? '';

        if (!empty($payment_initiation_uid)) {
            $order->update_meta_data('_monoova_payto_payment_uid', $payment_initiation_uid);
        }

        if (!empty($payment_status)) {
            $order->update_meta_data('_monoova_payto_payment_status', $payment_status);
        }

        $order->update_meta_data('_monoova_payto_payment_reference', $payment_reference);
        $order->save();

        $this->log("PayTo payment initiation created successfully for order #{$order_id}. Payment UID: {$payment_initiation_uid}", 'info');

        // Increment paid transaction count for the mandate
        if (!empty($payment_agreement_uid)) {
            $this->mandate_manager->increment_paid_transactions($payment_agreement_uid);
        }

        return $response;
    }

    /**
     * Remove payment agreement from order and database
     * Used when payment agreement limits are exceeded and user needs to create a new one
     *
     * @param int $order_id The order ID
     * @param string $agreement_uid Optional agreement UID to remove specifically
     * @return bool True on success, false on failure
     */
    public function remove_payment_agreement($order_id, $agreement_uid = null) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        // Get agreement UID from parameter or order meta
        if (empty($agreement_uid)) {
            $agreement_uid = $order->get_meta('_monoova_payto_agreement_uid', true);
        }

        $this->log("Removing payment agreement for order #{$order_id}, agreement UID: {$agreement_uid}");

        // Remove from order meta data
        $order->delete_meta_data('_monoova_payto_agreement_uid');
        $order->delete_meta_data('_monoova_payto_agreement_reference');
        $order->delete_meta_data('_monoova_payto_agreement_status');
        $order->delete_meta_data('_monoova_payto_customer_payment_method');
        $order->delete_meta_data('_monoova_payto_customer_payment_details');
        $order->delete_meta_data('_monoova_payto_mandate_uid');
        $order->delete_meta_data('_monoova_payto_expires_at');
        $order->delete_meta_data('_monoova_payto_payment_uid');
        $order->delete_meta_data('_monoova_payto_payment_status');
        $order->delete_meta_data('_monoova_payto_payment_reference');
        $order->save();

        // Remove from database if agreement UID is available
        if (!empty($agreement_uid)) {
            $mandate = $this->mandate_manager->get_mandate_by_uid($agreement_uid);
            if ($mandate) {
                // Update status to cancelled instead of deleting to maintain audit trail
                $this->mandate_manager->update_mandate_status($agreement_uid, 'Cancelled');
                $this->log("Cancelled PayTo mandate in database: {$agreement_uid}");
            }
        }

        $order->add_order_note(__('PayTo payment agreement removed due to limit exceeded. Customer can create a new agreement.', 'monoova-payments-for-woocommerce'));

        $this->log("Successfully removed payment agreement for order #{$order_id}");
        return true;
    }

    /**
     * Check PayTo agreement status and create payment if authorized.
     *
     * @param int $order_id The order ID.
     * @return bool True if payment was initiated, false otherwise.
     */
    public function check_and_process_authorized_agreement($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $agreement_uid = $order->get_meta('_monoova_payto_agreement_uid', true);
        if (empty($agreement_uid)) {
            return false;
        }

        $api = $this->get_api();
        if (!$api) {
            $this->log("API client not initialized when checking agreement status for order #{$order_id}.", 'error');
            return false;
        }

        // Get current agreement status from API
        $response = $api->get_payto_agreement($agreement_uid);
        if (is_wp_error($response)) {
            $this->log("Error getting PayTo agreement status for order #{$order_id}: " . $response->get_error_message(), 'error');
            return false;
        }

        $agreement_status = $response['agreementStatus'] ?? '';
        $this->log("PayTo agreement status for order #{$order_id}: {$agreement_status}", 'debug');

        // Update the stored status
        $order->update_meta_data('_monoova_payto_agreement_status', $agreement_status);
        $order->save();

        // If agreement is authorized, create payment initiation
        if (strtolower($agreement_status) === 'authorized') {
            $payment_uid = $order->get_meta('_monoova_payto_payment_uid', true);

            // Only create payment if not already created
            if (empty($payment_uid)) {
                $result = $this->create_payment_initiation($order_id, $agreement_uid);

                if (!is_wp_error($result)) {
                    $order->add_order_note(__('PayTo agreement authorized. Payment initiation created.', 'monoova-payments-for-woocommerce'));
                    return true;
                } else {
                    $this->log("Failed to create payment initiation for authorized agreement (order #{$order_id}): " . $result->get_error_message(), 'error');
                    $order->add_order_note(__('PayTo agreement authorized but payment initiation failed. Manual intervention may be required.', 'monoova-payments-for-woocommerce'));
                }
            }
        }

        return false;
    }

    /**
     * Truncate string fields according to Monoova PayTo API field length limits
     *
     * Field length limits based on Monoova PayTo API specification:
     * - paymentAgreementUID: 35 characters
     * - paymentInitiationUID: 35 characters
     * - payeeLinkedBsb: 7 characters (XXX-XXX format)
     * - payeeLinkedAccount: 20 characters
     * - payeeAccountName: 140 characters
     * - ultimatePayee: 140 characters
     * - payer: 140 characters
     * - ultimatePayer: 140 characters
     * - payerPartyReference: 35 characters
     * - linkedPayId: 256 characters
     * - linkedBsb: 7 characters (XXX-XXX format)
     * - linkedAccount: 28 characters
     * - description: 140 characters
     * - shortDescription: 35 characters
     * - lodgementReference: 280 characters
     *
     * @param string $value The string value to truncate
     * @param int $max_length Maximum allowed length
     * @param string $field_name Field name for logging (optional)
     * @return string Truncated string
     */
    private function truncate_field($value, $max_length, $field_name = '') {
        if (empty($value) || !is_string($value)) {
            return $value;
        }

        $original_length = strlen($value);
        $truncated_value = substr($value, 0, $max_length);

        if ($original_length > $max_length && !empty($field_name)) {
            $this->log("Truncated field '{$field_name}' from {$original_length} to {$max_length} characters: '{$value}' -> '{$truncated_value}'", 'debug');
        }

        return $truncated_value;
    }

    /**
     * Prepare and validate PayTo agreement payload with proper field length truncation
     *
     * @param array $raw_payload Raw payload array
     * @return array Validated and truncated payload
     */
    private function prepare_payto_agreement_payload($raw_payload) {
        // Apply field length limits according to Monoova PayTo API specification
        $payload = $raw_payload;

        // PayTo Agreement UID - typically up to 128 characters
        if (isset($payload['paymentAgreementUID'])) {
            $payload['paymentAgreementUID'] = $this->truncate_field($payload['paymentAgreementUID'], 35, 'paymentAgreementUID');
        }

        // Payee Details
        if (isset($payload['payeeDetails'])) {
            // BSB - 7 characters (already formatted as XXX-XXX)
            if (isset($payload['payeeDetails']['payeeLinkedBsb'])) {
                $payload['payeeDetails']['payeeLinkedBsb'] = $this->truncate_field($payload['payeeDetails']['payeeLinkedBsb'], 7, 'payeeLinkedBsb');
            }

            // Account number - typically up to 28 characters
            if (isset($payload['payeeDetails']['payeeLinkedAccount'])) {
                $payload['payeeDetails']['payeeLinkedAccount'] = $this->truncate_field($payload['payeeDetails']['payeeLinkedAccount'], 28, 'payeeLinkedAccount');
            }

            // Account name - up to 140 characters
            if (isset($payload['payeeDetails']['payeeAccountName'])) {
                $payload['payeeDetails']['payeeAccountName'] = $this->truncate_field($payload['payeeDetails']['payeeAccountName'], 140, 'payeeAccountName');
            }

            // Ultimate payee - up to 140 characters
            if (isset($payload['payeeDetails']['ultimatePayee'])) {
                $payload['payeeDetails']['ultimatePayee'] = $this->truncate_field($payload['payeeDetails']['ultimatePayee'], 140, 'ultimatePayee');
            }
        }

        // Payer Details
        if (isset($payload['payerDetails'])) {
            // Payer name - up to 140 characters
            if (isset($payload['payerDetails']['payer'])) {
                $payload['payerDetails']['payer'] = $this->truncate_field($payload['payerDetails']['payer'], 140, 'payer');
            }

            // Ultimate payer - up to 140 characters
            if (isset($payload['payerDetails']['ultimatePayer'])) {
                $payload['payerDetails']['ultimatePayer'] = $this->truncate_field($payload['payerDetails']['ultimatePayer'], 140, 'ultimatePayer');
            }

            // Payer party reference - up to 35 characters
            if (isset($payload['payerDetails']['payerPartyReference'])) {
                $payload['payerDetails']['payerPartyReference'] = $this->truncate_field($payload['payerDetails']['payerPartyReference'], 35, 'payerPartyReference');
            }

            // PayID - up to 256 characters
            if (isset($payload['payerDetails']['linkedPayId'])) {
                $payload['payerDetails']['linkedPayId'] = $this->truncate_field($payload['payerDetails']['linkedPayId'], 256, 'linkedPayId');
            }

            // BSB - 6 characters (already formatted as XXX-XXX)
            if (isset($payload['payerDetails']['linkedBsb'])) {
                $payload['payerDetails']['linkedBsb'] = $this->truncate_field($payload['payerDetails']['linkedBsb'], 7, 'linkedBsb');
            }

            // Account number - typically up to 28 characters
            if (isset($payload['payerDetails']['linkedAccount'])) {
                $payload['payerDetails']['linkedAccount'] = $this->truncate_field($payload['payerDetails']['linkedAccount'], 28, 'linkedAccount');
            }
        }

        // Payment Details
        if (isset($payload['paymentDetails'])) {
            // Description - up to 140 characters
            if (isset($payload['paymentDetails']['description'])) {
                $payload['paymentDetails']['description'] = $this->truncate_field($payload['paymentDetails']['description'], 140, 'description');
            }

            // Short description - up to 35 characters
            if (isset($payload['paymentDetails']['shortDescription'])) {
                $payload['paymentDetails']['shortDescription'] = $this->truncate_field($payload['paymentDetails']['shortDescription'], 35, 'shortDescription');
            }
        }

        return $payload;
    }

    /**
     * Prepare and validate PayTo payment initiation payload with proper field length truncation
     *
     * @param array $raw_payload Raw payload array
     * @return array Validated and truncated payload
     */
    private function prepare_payto_payment_payload($raw_payload) {
        // Apply field length limits according to Monoova PayTo API specification
        $payload = $raw_payload;

        // Payment Initiation UID - typically up to 128 characters
        if (isset($payload['paymentInitiationUID'])) {
            $payload['paymentInitiationUID'] = $this->truncate_field($payload['paymentInitiationUID'], 35, 'paymentInitiationUID');
        }

        // Payment Agreement UID - typically up to 35 characters
        if (isset($payload['paymentAgreementUID'])) {
            $payload['paymentAgreementUID'] = $this->truncate_field($payload['paymentAgreementUID'], 35, 'paymentAgreementUID');
        }

        // Payment Details
        if (isset($payload['paymentDetails'])) {
            // Lodgement reference - up to 280 characters
            if (isset($payload['paymentDetails']['lodgementReference'])) {
                $payload['paymentDetails']['lodgementReference'] = $this->truncate_field($payload['paymentDetails']['lodgementReference'], 280, 'lodgementReference');
            }
        }

        return $payload;
    }

    /**
     * Check if we should use an existing mandate for this order
     *
     * @param WC_Order $order
     * @param object $mandate
     * @return bool
     */
    private function should_use_existing_mandate($order, $mandate) {
        // Check if order amount is within mandate maximum
        $order_total = floatval($order->get_total());
        $mandate_max = floatval($mandate->maximum_amount);

        if ($order_total > $mandate_max) {
            $this->log("Order total ($order_total) exceeds mandate maximum ($mandate_max)");
            return false;
        }

        // Check if mandate is still valid (not expired)
        if (!empty($mandate->expires_at)) {
            $expires_timestamp = strtotime($mandate->expires_at);
            if ($expires_timestamp && $expires_timestamp < time()) {
                $this->log("Mandate has expired: {$mandate->expires_at}");
                return false;
            }
        }

        // Check if mandate has available transactions
        if (!$this->mandate_manager->has_available_transactions($mandate->payment_agreement_uid)) {
            $usage = $this->mandate_manager->get_transaction_usage($mandate->payment_agreement_uid);
            $this->log("Mandate has no available transactions: {$usage['num_of_paid_transactions']}/{$usage['num_of_transactions_permitted']} used");
            return false;
        }

        // Additional validation can be added here (e.g., currency, merchant details)
        return true;
    }

    /**
     * Process payment using an existing active mandate
     *
     * @param WC_Order $order
     * @param object $mandate
     * @return array
     */
    private function process_payment_with_existing_mandate($order, $mandate) {
        $order_id = $order->get_id();
        $api = $this->get_api();
        try {
            // Check transaction limits before proceeding
            if (!$this->mandate_manager->has_available_transactions($mandate->payment_agreement_uid)) {
                $usage = $this->mandate_manager->get_transaction_usage($mandate->payment_agreement_uid);
                $this->log("Mandate transaction limit exceeded: {$usage['num_of_paid_transactions']}/{$usage['num_of_transactions_permitted']} used");

                return new WP_Error(
                    'payto_agreement_limit_exceeded',
                    __('Payment agreement transaction limit exceeded. Please create a new payment agreement.', 'monoova-payments-for-woocommerce'),
                    array(
                        'errors' => array(array(
                            'code' => 'PAS_COUNT_LIMIT_EXCEEDED',
                            'message' => sprintf(
                                __('Transaction limit exceeded: %d/%d transactions used', 'monoova-payments-for-woocommerce'),
                                $usage['num_of_paid_transactions'],
                                $usage['num_of_transactions_permitted']
                            )
                        )),
                        'agreement_uid' => $mandate->payment_agreement_uid,
                        'requires_new_agreement' => true
                    )
                );
            }

            // First, verify the mandate is still active with the API
            $api_response = $api->get_payto_agreement($mandate->payment_agreement_uid);

            if (is_wp_error($api_response)) {
                $this->log("Failed to verify mandate status: " . $api_response->get_error_message(), 'error');
                // Fall back to creating new mandate
                return $this->create_new_payment_agreement($order);
            }

            $api_status = strtolower($api_response['paymentAgreementDetails']['paymentAgreementStatus'] ?? '');

            if ($api_status !== 'active') {
                $this->log("Mandate is not active (status: $api_status)");
                // Update local status and create new mandate
                $this->mandate_manager->update_mandate_status($mandate->payment_agreement_uid, ucfirst($api_status));
                throw new Exception(__('Existing mandate is not active. Please try again.', 'monoova-payments-for-woocommerce'));
            }

            // Mandate is active, create payment initiation
            $this->log("Using existing active mandate for express checkout: {$mandate->payment_agreement_uid}");

            // Save mandate reference to order
            $order->update_meta_data('_monoova_payto_agreement_uid', $mandate->payment_agreement_uid);
            $order->update_meta_data('_monoova_payto_agreement_status', 'Active');
            $order->update_meta_data('_monoova_payto_mandate_uid', $mandate->payment_agreement_uid);
            $order->update_meta_data('_monoova_payto_express_checkout', true);

            // Create payment initiation immediately
            $payment_result = $this->create_payment_initiation($order_id, $mandate->payment_agreement_uid);

            if (is_wp_error($payment_result)) {
                $this->log("Failed to create payment initiation: " . $payment_result->get_error_message(), 'error');
                throw new Exception(__('Could not initiate payment with existing mandate. Please try again.', 'monoova-payments-for-woocommerce'));
            }

            // Update order status
            $order->update_status(
                'pending',
                sprintf(
                    __('PayTo payment initiated using existing mandate. Agreement UID: %s. Awaiting payment completion.', 'monoova-payments-for-woocommerce'),
                    $mandate->payment_agreement_uid
                )
            );

            $order->save();

            // Return success result for express checkout
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        } catch (Exception $e) {
            $this->log("Express checkout failed: " . $e->getMessage(), 'error');
            wc_add_notice($e->getMessage(), 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * Display express checkout option if user has active mandate
     * Only available for logged-in users
     */
    private function display_express_checkout_option() {
        // Express checkout only available for logged-in users
        if (!is_user_logged_in()) {
            return;
        }

        $current_user = wp_get_current_user();
        $existing_mandate = $this->mandate_manager->get_active_mandate_for_user($current_user->ID, $current_user->user_email);

        if (!$existing_mandate) {
            return;
        }

        $mandate_summary = $this->mandate_manager->get_mandate_summary($existing_mandate);

        echo '<div class="payto-express-checkout" style="margin-bottom: 20px; padding: 15px; background: #f0f8ff; border: 1px solid #0073aa; border-radius: 5px;">';
        echo '<h4 style="margin-top: 0; color: #0073aa;">' . esc_html__('Express Checkout Available', 'monoova-payments-for-woocommerce') . '</h4>';
        echo '<p>' . esc_html__('You have an active PayTo mandate that can be used for faster checkout.', 'monoova-payments-for-woocommerce') . '</p>';

        echo '<div class="mandate-details" style="background: white; padding: 10px; border-radius: 3px; margin: 10px 0;">';
        echo '<strong>' . esc_html__('Agreement ID:', 'monoova-payments-for-woocommerce') . '</strong> ' . esc_html(substr($mandate_summary['payment_agreement_uid'], -8)) . '<br>';
        echo '<strong>' . esc_html__('Maximum Amount:', 'monoova-payments-for-woocommerce') . '</strong> ' . wc_price($mandate_summary['maximum_amount']) . '<br>';
        echo '<strong>' . esc_html__('Status:', 'monoova-payments-for-woocommerce') . '</strong> ' . esc_html($mandate_summary['status']);
        echo '</div>';

        echo '<p class="form-row form-row-wide">';
        echo '<input type="checkbox" id="payto_use_existing_mandate" name="payto_use_existing_mandate" value="1" checked>';
        echo '<label for="payto_use_existing_mandate" style="margin-left: 10px;">' . esc_html__('Use existing PayTo mandate for express checkout', 'monoova-payments-for-woocommerce') . '</label>';
        echo '</p>';
        echo '</div>';

        // Add JavaScript to hide/show payment method selection
        echo '<script type="text/javascript">
            jQuery(document).ready(function($) {
                function togglePaymentMethodSelection() {
                    var useExisting = $("#payto_use_existing_mandate").is(":checked");
                    if (useExisting) {
                        $(".payto-payment-method-selection, .payto-fields-group").hide();
                    } else {
                        $(".payto-payment-method-selection, .payto-fields-group").show();
                    }
                }

                $("#payto_use_existing_mandate").change(togglePaymentMethodSelection);
                togglePaymentMethodSelection();
            });
        </script>';
    }

    /**
     * Create a new payment agreement (extracted from process_payment for reuse)
     *
     * @param WC_Order $order
     * @return array
     */
    private function create_new_payment_agreement($order) {
        $order_id = $order->get_id();

        try {
            $unified_settings = $this->get_unified_gateway_settings();

            // Check if this is a subscription order
            $is_subscription = $this->has_subscription($order_id);
            $subscription_frequency = null;

            if ($is_subscription) {
                $subscription_frequency = $this->get_subscription_frequency($order_id);
                $this->log("Creating PayTo agreement for subscription order #{$order_id} with frequency: {$subscription_frequency}");
            }

            // Get customer payment details from checkout form
            $payment_method = sanitize_text_field($_POST['payto_payment_method'] ?? 'payid');
            $customer_payment_details = $this->get_customer_payment_details($payment_method);

            if (!$customer_payment_details) {
                throw new Exception(__('Invalid payment details provided.', 'monoova-payments-for-woocommerce'));
            }

            // Get customer details
            $customer_id = $order->get_customer_id();
            $billing_email = $order->get_billing_email();

            // Generate unique agreement UID
            $agreement_uid = apply_filters(
                'monoova_payto_agreement_uid',
                'WC-PAYTO-' . $order->get_id() . '-' . time(),
                $order
            );

            // Ensure agreement UID doesn't exceed API limits
            $agreement_uid = $this->truncate_field($agreement_uid, 35, 'agreement_uid');

            // Get purpose code from settings (default to OTHR if not set)
            $purpose_code = $unified_settings['payto_purpose'] ?? 'OTHR';

            // Validate purpose code against API allowed values
            $allowed_purpose_codes = ['MORT', 'UTIL', 'LOAN', 'DEPD', 'GAMP', 'RETL', 'SALA', 'PERS', 'GOVT', 'PENS', 'TAXS', 'OTHR'];
            if (!in_array($purpose_code, $allowed_purpose_codes)) {
                $this->log("Invalid purpose code '{$purpose_code}'. Using default 'OTHR'.", 'warning');
                $purpose_code = 'OTHR';
            }

            // Calculate end date if configured
            $end_date = null;
            $end_date_obj = null;
            $expiry_days = (int) ($unified_settings['payto_agreement_expiry_days'] ?: 0);
            if ($expiry_days > 0) {
                $end_date_obj = new DateTime('now', new DateTimeZone('Australia/Sydney'));
                $end_date_obj->add(new DateInterval("P{$expiry_days}D"));
                $end_date = $end_date_obj->format('Y-m-d');
            }

            // Calculate respond by time (5 days default as per API)
            // should start of a date
            $respond_by_time = new DateTime('now', new DateTimeZone('UTC'));
            $respond_by_time->add(new DateInterval('P5D'));

            // Build payer details based on customer's payment method selection
            $api_customer_id = $this->get_customer_id_for_order($order, false); // false = for API usage (includes guest IDs)

            // Get customer name with fallbacks
            $first_name = $order->get_billing_first_name();
            $last_name = $order->get_billing_last_name();
            $full_name = trim($first_name . ' ' . $last_name);

            // If we don't have a name from billing, try other sources
            if (empty($full_name)) {
                if ($customer_payment_details['method'] === 'bsb_account' && !empty($customer_payment_details['account_name'])) {
                    $full_name = $customer_payment_details['account_name'];
                } elseif (is_user_logged_in()) {
                    $user = wp_get_current_user();
                    $full_name = $user->display_name ?: $user->user_login;
                } else {
                    // Fallback for guest users
                    $full_name = 'Guest Customer';
                }
            }

            $payer_details = [
                'payerType' => 'PERS', // Person
                'payer' => $this->truncate_field($full_name, 140, 'payer'),
                'ultimatePayer' => $this->truncate_field($full_name, 140, 'ultimatePayer'),
                'payerPartyReference' => $this->truncate_field('CUSTOMER-' . $api_customer_id, 35, 'payerPartyReference'),
            ];

            // Add customer's bank/PayID details to payer details
            if ($customer_payment_details['method'] === 'payid') {
                $payer_details['linkedPayId'] = $this->truncate_field($customer_payment_details['payid'], 256, 'linkedPayId');
                $payer_details['linkedPayIdType'] = $customer_payment_details['payid_type']; // Enum value, no truncation needed
            } else {
                $payer_details['linkedBsb'] = $this->truncate_field($customer_payment_details['bsb'], 7, 'linkedBsb');
                $payer_details['linkedAccount'] = $this->truncate_field($customer_payment_details['account_number'], 20, 'linkedAccount');
                // Override payer names with account name for BSB/Account payments
                $payer_details['payer'] = $this->truncate_field($customer_payment_details['account_name'], 140, 'payer');
                $payer_details['ultimatePayer'] = $this->truncate_field($customer_payment_details['account_name'], 140, 'ultimatePayer');
            }

            // Get maximum amount - use custom amount if provided via filter, otherwise use settings
            $max_amount = apply_filters('monoova_payto_max_amount_override', $unified_settings['payto_max_amount'] ?? 1000);

            // Also check if maximum amount is provided in form data (for AJAX requests)
            if (!empty($_POST['payto_maximum_amount'])) {
                $form_max_amount = floatval($_POST['payto_maximum_amount']);
                if ($form_max_amount > 0) {
                    $max_amount = $form_max_amount;
                }
            }

            // Prepare payment agreement payload according to Monoova PayTo API v1
            $raw_agreement_payload = [
                'paymentAgreementUID' => $agreement_uid,
                'payeeDetails' => [
                    'payeeType' => $this->payee_type ?: 'ORGN',
                    'payeeLinkedBsb' => $unified_settings['static_bsb'] ?? '',
                    'payeeLinkedAccount' => $unified_settings['static_account_number'] ?? '',
                    // payeeAccountName & ultimatePayee must be empty when payeeLinkedBsb is Monoova
                    //'payeeAccountName' => $unified_settings['static_bank_account_name'] ?? get_bloginfo('name'),
                    //'ultimatePayee' => get_bloginfo('name'),
                ],
                'payerDetails' => $payer_details,
                'paymentTerms' => [
                    // For subscriptions: 1 transaction per agreement (each renewal creates new agreement)
                    // For regular orders: 10 transactions per agreement (reuse mandate), temporarily set default to 10 as we can reuse the stored mandate for up to 10 orders
                    'numberOfTransactionsPermitted' => $is_subscription ? 1 : 10,
                    /*
                        Available frequencies accepted by Monoova PayTo API:
                        ADHO - Adhoc - Event takes place on request or as necessary.
                        DAIL - Daily - Event takes place every day.
                        FRTN - Fortnightly - Event takes place every two weeks.
                        INDA - IntraDay - Event takes place several times a day.
                        MIAN - Semi-Annual - Event takes place every six months or two times a year.
                        MNTH - Monthly - Event takes place every month or once a month.
                        QURT - Quarterly - Event takes place every three months or four times a year.
                        WEEK - Weekly - Event takes place every week or once a week.
                        YEAR - Annual - Event takes place every year or once a year.
                    */
                    'frequency' => $is_subscription && $subscription_frequency ? $subscription_frequency : 'ADHO',
                    'pointInTime' => null,
                    'amount' => null,
                    'maximumAmount' => $max_amount,
                    'agreementType' => 'VARI', // Variable amount
                ],
                'paymentDetails' => [
                    'automaticRenewal' => true,
                    'description' => sprintf(
                        __('Payment for order #%s from %s', 'monoova-payments-for-woocommerce'),
                        $order->get_order_number(),
                        get_bloginfo('name')
                    ),
                    'shortDescription' => sprintf('Order #%s', $order->get_order_number()),
                    'purpose' => $purpose_code,
                    'respondByTime' => $respond_by_time->format('Y-m-d\TH:i:s\Z'),
                    // Start date of the validity of the mandate. The mandate is valid as of 00:00:00.000 Australia Sydney time on this date
                    'startDate' => (new DateTime('now', new DateTimeZone('Australia/Sydney')))->format('Y-m-d'),
                ],
            ];

            // Validate and truncate all string fields according to API limits
            $agreement_payload = $this->prepare_payto_agreement_payload($raw_agreement_payload);

            // Add end date if configured
            // if ($end_date) {
            //     $agreement_payload['paymentDetails']['endDate'] = $end_date;
            // }

            // Validate required account details
            if (
                empty($agreement_payload['payeeDetails']['payeeLinkedBsb']) ||
                empty($agreement_payload['payeeDetails']['payeeLinkedAccount'])
            ) {
                $this->log('Configuration error: Payee account details (BSB/Account Number) not configured.', 'error');
                throw new Exception(__('Payment gateway is not configured correctly. Please contact support.', 'monoova-payments-for-woocommerce'));
            }

            $this->log("PayTo agreement payload for order #{$order_id}: " . wp_json_encode($agreement_payload), 'debug');

            $api = $this->get_api();
            if (!$api) {
                $this->log('API client not initialized when creating PayTo agreement for order #{$order_id}.', 'error');
                throw new Exception(__('Payment gateway is not configured correctly. Please contact support.', 'monoova-payments-for-woocommerce'));
            }

            // Create the payment agreement
            $response = $api->create_payto_agreement($agreement_payload);

            if (is_wp_error($response)) {
                $error_msg = $response->get_error_message();
                $this->log("API error creating PayTo agreement for order #{$order_id}: " . $error_msg, 'error');

                // Log the specific error data if available
                $error_data = $response->get_error_data();
                if ($error_data) {
                    $this->log("PayTo API error details: " . wp_json_encode($error_data), 'error');
                }

                throw new Exception(__('Could not create payment agreement. Please try again or contact support.', 'monoova-payments-for-woocommerce'));
            }

            $this->log("PayTo agreement API response for order #{$order_id}: " . wp_json_encode($response), 'debug');

            // Extract agreement details from response
            $payment_agreement_uid = $response['paymentAgreementUID'] ?? '';
            $agreement_status = $response['paymentAgreementStatus'] ?? '';

            if (empty($payment_agreement_uid)) {
                $this->log("Payment agreement UID not found in API response for order #{$order_id}.", 'error');
                throw new Exception(__('Could not retrieve payment agreement details. Please contact support.', 'monoova-payments-for-woocommerce'));
            }

            // Save agreement details to order meta
            $order->update_meta_data('_monoova_payto_agreement_uid', $payment_agreement_uid);
            $order->update_meta_data('_monoova_payto_agreement_reference', $agreement_uid);
            $order->update_meta_data('_monoova_payto_agreement_status', $agreement_status);
            $order->update_meta_data('_monoova_payto_customer_payment_method', $payment_method);
            $order->update_meta_data('_monoova_payto_customer_payment_details', $customer_payment_details);

            if (isset($end_date_obj)) {
                $order->update_meta_data('_monoova_payto_expires_at', $end_date_obj->getTimestamp());
            }

            // Store mandate in database for future reuse (only for logged-in users)
            // Guest users can still use PayTo but won't have express checkout capability
            $customer_id = $this->get_customer_id_for_order($order, true); // true = for mandate storage
            $billing_email = $order->get_billing_email();

            if ($customer_id > 0 && !empty($billing_email)) {
                $mandate_data = array(
                    'payment_agreement_uid' => $payment_agreement_uid,
                    'user_id' => $customer_id,
                    'customer_email' => $billing_email,
                    'agreement_type' => 'VARI',
                    'maximum_amount' => $max_amount, // Use the same max amount as the agreement
                    'status' => $agreement_status,
                    'payment_method' => 'PAYTO',
                    'automatic_renewal' => $is_subscription, // Enable automatic renewal for subscriptions
                    'num_of_transactions_permitted' => $is_subscription ? 1 : 10, // 1 for subscriptions, 10 for regular orders
                    'num_of_paid_transactions' => 0, // Start with 0 paid transactions
                    'expires_at' => isset($end_date_obj) ? $end_date_obj->getTimestamp() : null,
                );

                $mandate_uid = $this->mandate_manager->store_mandate($mandate_data);
                if ($mandate_uid) {
                    $order->update_meta_data('_monoova_payto_mandate_uid', $mandate_data['payment_agreement_uid']);
                    $this->log("Stored PayTo mandate (UID: {$mandate_data['payment_agreement_uid']}) for logged-in user on order #{$order_id}");
                } else {
                    $this->log("Failed to store PayTo mandate for order #{$order_id}", 'warning');
                }
            } else {
                $this->log("Skipping mandate storage for guest user on order #{$order_id} - express checkout not available for guests");
            }

            $order->save();

            $this->log("Successfully created PayTo agreement for order #{$order_id}. Agreement UID: {$payment_agreement_uid}", 'info');
        } catch (Exception $e) {
            $this->log("PayTo payment processing exception for order #{$order_id}: " . $e->getMessage(), 'error');
            throw $e; // Re-throw the exception for proper error handling
        }
    }

    /**
     * AJAX handler to get PayTo agreement and payment initiation status
     * It should use for API polling for payment status updates.
     */
    public function ajax_get_payto_agreement_payment_initiation_status() {
        // Verify nonce for security - use the same nonce as other PayTo actions
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_payto_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }

        // Get order ID from request
        $order_id = absint($_POST['order_id'] ?? 0);

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Invalid order ID'));
            return;
        }

        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }

        $response_data = array();

        // Get PayTo mandate status
        $payment_agreement_uid = $order->get_meta('_monoova_payto_agreement_uid', true);
        $order_mandate_status = $order->get_meta('_monoova_payto_agreement_status', true);

        $response_data['payment_agreement_uid'] = !empty($payment_agreement_uid) ? $payment_agreement_uid : null;

        // For logged-in users, check stored mandate status and compare with order metadata
        $customer_id = $this->get_customer_id_for_order($order, true); // true = for mandate storage
        if ($customer_id > 0 && !empty($payment_agreement_uid)) {
            // This is a logged-in user, check stored mandate
            $stored_mandate = $this->mandate_manager->get_mandate_by_uid($payment_agreement_uid);
            if ($stored_mandate) {
                $stored_mandate_status = $stored_mandate->status;

                // Compare stored mandate status with order metadata
                if (strtolower($stored_mandate_status) !== strtolower($order_mandate_status)) {
                    $this->log("Mandate status mismatch for order {$order_id}: stored='{$stored_mandate_status}', order_meta='{$order_mandate_status}'. Using stored mandate status.");
                    // Update order metadata to match stored mandate status
                    $order->update_meta_data('_monoova_payto_agreement_status', $stored_mandate_status);
                    $order->save_meta_data();
                }

                $response_data['mandate_status'] = !empty($stored_mandate_status) ? strtolower($stored_mandate_status) : null;

                // Check transaction limits for stored mandate
                if (!$this->mandate_manager->has_available_transactions($payment_agreement_uid)) {
                    $usage = $this->mandate_manager->get_transaction_usage($payment_agreement_uid);
                    $this->log("Mandate transaction limit exceeded during status check: {$usage['num_of_paid_transactions']}/{$usage['num_of_transactions_permitted']} used");

                    // Add error details to response
                    $response_data['error_details'] = array(
                        'errors' => array(array(
                            'code' => 'PAS_COUNT_LIMIT_EXCEEDED',
                            'message' => sprintf(
                                __('Transaction limit exceeded: %d/%d transactions used', 'monoova-payments-for-woocommerce'),
                                $usage['num_of_paid_transactions'],
                                $usage['num_of_transactions_permitted']
                            )
                        )),
                        'agreement_uid' => $payment_agreement_uid,
                        'requires_new_agreement' => true
                    );
                }
            } else {
                // Stored mandate not found, use order metadata
                $response_data['mandate_status'] = !empty($order_mandate_status) ? strtolower($order_mandate_status) : null;
            }
        } else {
            // Guest user or no payment agreement UID, use order metadata
            $response_data['mandate_status'] = !empty($order_mandate_status) ? strtolower($order_mandate_status) : null;
        }

        // Get PayTo payment initiation status
        $payment_status = $order->get_meta('_monoova_payto_payment_status', true);
        $response_data['payment_initiation_status'] = !empty($payment_status) ? strtolower($payment_status) : null;

        // Get payment UID if available
        $payment_uid = $order->get_meta('_monoova_payto_payment_uid', true);
        $response_data['payment_initiation_uid'] = !empty($payment_uid) ? $payment_uid : null;

        // Get order status for additional context
        $response_data['order_status'] = strtolower($order->get_status());
        $response_data['order_id'] = $order_id;
        $response_data['order_key'] = $order->get_order_key();
        $response_data['order_received_url'] = $this->get_return_url($order);

        // Add agreement_status as an alias for mandate_status for frontend consistency
        $response_data['agreement_status'] = $response_data['mandate_status'];

        // Log the status check
        $this->log("AJAX status check for order #{$order_id}: Mandate status: " . ($response_data['mandate_status'] ?: 'null') . ", Payment status: " . ($response_data['payment_initiation_status'] ?: 'null'));

        wp_send_json_success($response_data);
    }



    /**
     * Get customer ID for order with proper authentication and fallback handling
     *
     * @param WC_Order $order The order object
     * @param bool $for_mandate_storage Whether this is for mandate storage (returns 0 for guests) or API usage (returns guest ID)
     * @return int|string Customer ID for use in mandate storage (int for logged-in, 0 for guests) or guest string for API
     * @since 1.0.0
     */
    private function get_customer_id_for_order($order, $for_mandate_storage = true) {
        // First, try to get the customer ID from the order
        $order_customer_id = $order->get_customer_id();

        // If order has a valid customer ID (not 0), use it
        if (!empty($order_customer_id) && $order_customer_id > 0) {
            $this->log("Using order customer ID: {$order_customer_id} for order {$order->get_id()}");
            return $order_customer_id;
        }

        // Check if a user is currently logged in
        $current_user_id = get_current_user_id();
        if ($current_user_id > 0) {
            // Update the order with the current user ID if it wasn't set
            $order->set_customer_id($current_user_id);
            $order->save();
            $this->log("Updated order {$order->get_id()} with current user ID: {$current_user_id}");
            return $current_user_id;
        }

        // Check if user is logged in via WooCommerce session
        if (WC()->customer && WC()->customer->get_id() > 0) {
            $wc_customer_id = WC()->customer->get_id();
            // Update the order with the WooCommerce customer ID
            $order->set_customer_id($wc_customer_id);
            $order->save();
            $this->log("Updated order {$order->get_id()} with WC customer ID: {$wc_customer_id}");
            return $wc_customer_id;
        }

        // Try to find existing customer by email for authenticated context
        $billing_email = $order->get_billing_email();
        if (!empty($billing_email) && is_user_logged_in()) {
            $user = get_user_by('email', $billing_email);
            if ($user && $user->ID > 0) {
                // Update the order with the found user ID
                $order->set_customer_id($user->ID);
                $order->save();
                $this->log("Found and assigned user ID {$user->ID} for order {$order->get_id()} based on email {$billing_email}");
                return $user->ID;
            }
        }

        // Handle guest users differently based on usage
        if ($for_mandate_storage) {
            // For PayTo mandates, we don't store for guest users
            // Return 0 to indicate this is a guest user
            $this->log("Guest user detected for order {$order->get_id()} - no mandate storage");
            return 0;
        } else {
            // For API usage, generate a guest customer ID
            // This ensures consistency across payment attempts for the same order
            $guest_customer_id = 'guest_' . $order->get_id() . '_' . time();
            $this->log("Using guest customer ID: {$guest_customer_id} for order {$order->get_id()}");
            return $guest_customer_id;
        }
    }

    /**
     * AJAX handler to process payment with existing PayTo mandate (express checkout).
     */
    public function ajax_process_payment_with_existing_mandate() {
        // Verify nonce - use the nonce action from blocks integration
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_payto_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Express checkout is only available for logged-in users.'));
            return;
        }

        try {
            $order_id = intval($_POST['order_id'] ?? 0);
            $payment_agreement_uid = sanitize_text_field($_POST['payment_agreement_uid'] ?? '');

            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Failed to get order.', 'monoova-payments-for-woocommerce'));
            }

            // Set payment method
            $order->set_payment_method($this->id);
            $order->save();

            $existing_mandate = $this->mandate_manager->get_mandate_by_uid($payment_agreement_uid);

            if (!$existing_mandate || !$this->should_use_existing_mandate($order, $existing_mandate)) {
                throw new Exception(__('No suitable active mandate found.', 'monoova-payments-for-woocommerce'));
            }

            $result = $this->process_payment_with_existing_mandate($order, $existing_mandate);

            if ($result['result'] === 'success') {
                $this->log("Payment with existing mandate checkout processed successfully for order #{$order_id}");

                wp_send_json_success(array(
                    'message' => __('Payment processed with existing mandate.', 'monoova-payments-for-woocommerce'),
                    'order_id' => $order_id,
                    'order_key' => $order->get_order_key(),
                    'redirect' => $this->get_return_url($order)
                ));
            } else {
                throw new Exception(__('Payment failed with existing mandate.', 'monoova-payments-for-woocommerce'));
            }
        } catch (Exception $e) {
            $this->log("Express checkout failed: " . $e->getMessage(), 'error');

            // Enhanced error response with detailed information
            $error_response = array(
                'message' => $e->getMessage(),
                'error_code' => $e->getCode() ?: 'EXPRESS_CHECKOUT_ERROR',
                'error_type' => 'EXPRESS_CHECKOUT_FAILED',
                'context' => 'express_checkout'
            );

            // Add more specific error details based on error message
            if (strpos($e->getMessage(), 'cart is empty') !== false) {
                $error_response['error_code'] = 'EMPTY_CART';
                $error_response['error_type'] = 'VALIDATION_ERROR';
            } elseif (strpos($e->getMessage(), 'mandate') !== false) {
                $error_response['error_code'] = 'MANDATE_ERROR';
                $error_response['error_type'] = 'MANDATE_VALIDATION_ERROR';
            } elseif (strpos($e->getMessage(), 'order') !== false) {
                $error_response['error_code'] = 'ORDER_CREATION_ERROR';
                $error_response['error_type'] = 'ORDER_ERROR';
            }

            wp_send_json_error($error_response);
        }
    }

    /**
     * AJAX handler to check if express checkout is available for current user.
     */
    public function ajax_check_express_checkout() {
        // Verify nonce - use the nonce action from blocks integration
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_payto_nonce')) {
            wp_send_json_error(array('message' => 'Invalid security token'));
            return;
        }

        if (!is_user_logged_in()) {
            wp_send_json_success(array('available' => false));
            return;
        }

        try {
            $customer_id = get_current_user_id();
            $customer_email = wp_get_current_user()->user_email;

            $existing_mandate = $this->mandate_manager->get_active_mandate_for_user($customer_id, $customer_email);

            if ($existing_mandate) {
                $mandate_summary = $this->mandate_manager->get_mandate_summary($existing_mandate);

                wp_send_json_success(array(
                    'available' => true,
                    'mandate_info' => array(
                        'agreement_id' => $mandate_summary['payment_agreement_uid'] ?? '',
                        'maximum_amount' => $mandate_summary['maximum_amount'] ?? 0,
                        'status' => $mandate_summary['status'] ?? 'active'
                    )
                ));
            } else {
                wp_send_json_success(array('available' => false));
            }
        } catch (Exception $e) {
            $this->log("Express checkout availability check failed: " . $e->getMessage(), 'error');
            wp_send_json_success(array('available' => false));
        }
    }

    /**
     * AJAX handler for creating PayTo agreement
     */
    public function ajax_create_payto_agreement() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'monoova_payto_nonce')) {
            wp_send_json_error(array('message' => __('Security check failed.', 'monoova-payments-for-woocommerce')));
            return;
        }

        try {
            // Get payment method data from request
            $payment_method = sanitize_text_field($_POST['payto_payment_method'] ?? '');
            $payid_type = sanitize_text_field($_POST['payto_payid_type'] ?? '');
            $payid_value = sanitize_text_field($_POST['payto_payid_value'] ?? '');
            $account_name = sanitize_text_field($_POST['payto_account_name'] ?? '');
            $bsb = sanitize_text_field($_POST['payto_bsb'] ?? '');
            $account_number = sanitize_text_field($_POST['payto_account_number'] ?? '');
            $maximum_amount = floatval($_POST['payto_maximum_amount'] ?? 0);

            // Get billing information from checkout form if available
            $billing_data = array();
            $billing_fields = [
                'billing_first_name',
                'billing_last_name',
                'billing_email',
                'billing_phone',
                'billing_address_1',
                'billing_address_2',
                'billing_city',
                'billing_state',
                'billing_postcode',
                'billing_country'
            ];

            foreach ($billing_fields as $field) {
                if (isset($_POST[$field]) && !empty($_POST[$field])) {
                    $billing_data[$field] = sanitize_text_field($_POST[$field]);
                }
            }

            // Check if we're being called from checkout page with existing order
            $is_checkout_page = isset($_POST['isCheckoutPage']) && $_POST['isCheckoutPage'] === 'true';
            $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
            $this->log("AJAX Create PayTo Agreement request received for order ID: $order_id. Checkout page: $is_checkout_page");

            $order = null;

            // If a valid order_id was passed from the Checkout page, use it.
            if ($order_id > 0 && $is_checkout_page) {
                $this->log("PayTo agreement creation received existing order ID: " . $order_id);
                $order = wc_get_order($order_id);

                if (!$order) {
                    wp_send_json_error(array('message' => __('Invalid order ID provided.', 'monoova-payments-for-woocommerce')));
                    return;
                }
            }

            // If no valid order was found or passed (e.g., from the Cart page), create a new one.
            if (!$order) {
                $this->log("No existing order found or provided. Creating a new order for PayTo agreement creation.");

                // Create a temporary order from cart
                $cart = WC()->cart;
                if (!$cart || $cart->is_empty()) {
                    wp_send_json_error(array('message' => __('Cart is empty.', 'monoova-payments-for-woocommerce')));
                    return;
                }

                // Create order with customer data
                $order_data = array();
                if (is_user_logged_in()) {
                    $order_data['customer_id'] = get_current_user_id();
                }

                $order = wc_create_order($order_data);
                if (is_wp_error($order)) {
                    wp_send_json_error(array('message' => __('Failed to create order.', 'monoova-payments-for-woocommerce')));
                    return;
                }

                // Add cart items to order
                foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                    $order->add_product($cart_item['data'], $cart_item['quantity']);
                }

                // Calculate totals
                $order->calculate_totals();
            }

            // Set/update customer information from checkout form if available
            if (!empty($billing_data)) {
                foreach ($billing_data as $field => $value) {
                    $setter_method = 'set_' . $field;
                    if (method_exists($order, $setter_method)) {
                        $order->$setter_method($value);
                    }
                }
            }

            // Fallback to saved customer data for logged-in users if not provided in form
            if (is_user_logged_in() && empty($billing_data)) {
                $customer = new WC_Customer();

                // Only set if not already set from form data
                if (empty($order->get_billing_first_name()) && !empty($customer->get_billing_first_name())) {
                    $order->set_billing_first_name($customer->get_billing_first_name());
                }
                if (empty($order->get_billing_last_name()) && !empty($customer->get_billing_last_name())) {
                    $order->set_billing_last_name($customer->get_billing_last_name());
                }
                if (empty($order->get_billing_email()) && !empty($customer->get_billing_email())) {
                    $order->set_billing_email($customer->get_billing_email());
                }
                if (empty($order->get_billing_phone()) && !empty($customer->get_billing_phone())) {
                    $order->set_billing_phone($customer->get_billing_phone());
                }
                if (empty($order->get_billing_address_1()) && !empty($customer->get_billing_address_1())) {
                    $order->set_billing_address_1($customer->get_billing_address_1());
                }
                if (empty($order->get_billing_address_2()) && !empty($customer->get_billing_address_2())) {
                    $order->set_billing_address_2($customer->get_billing_address_2());
                }
                if (empty($order->get_billing_city()) && !empty($customer->get_billing_city())) {
                    $order->set_billing_city($customer->get_billing_city());
                }
                if (empty($order->get_billing_state()) && !empty($customer->get_billing_state())) {
                    $order->set_billing_state($customer->get_billing_state());
                }
                if (empty($order->get_billing_postcode()) && !empty($customer->get_billing_postcode())) {
                    $order->set_billing_postcode($customer->get_billing_postcode());
                }
                if (empty($order->get_billing_country()) && !empty($customer->get_billing_country())) {
                    $order->set_billing_country($customer->get_billing_country());
                }

                // Ensure we have at least an email and some name information
                if (empty($order->get_billing_email()) && !empty($customer->get_email())) {
                    $order->set_billing_email($customer->get_email());
                }

                // If no billing name is set, try to get from user account
                if (empty($order->get_billing_first_name()) && empty($order->get_billing_last_name())) {
                    $user = wp_get_current_user();
                    if ($user && !empty($user->display_name)) {
                        $name_parts = explode(' ', $user->display_name, 2);
                        $order->set_billing_first_name($name_parts[0]);
                        if (isset($name_parts[1])) {
                            $order->set_billing_last_name($name_parts[1]);
                        }
                    }
                }
            }

            // For PayID payments, we might be able to extract name from account_name for BSB payments
            if ($payment_method === 'bsb_account' && !empty($account_name)) {
                // If order doesn't have billing name, use the account name
                if (empty($order->get_billing_first_name()) && empty($order->get_billing_last_name())) {
                    $name_parts = explode(' ', $account_name, 2);
                    $order->set_billing_first_name($name_parts[0]);
                    if (isset($name_parts[1])) {
                        $order->set_billing_last_name($name_parts[1]);
                    } else {
                        $order->set_billing_last_name(''); // Ensure last name is set
                    }
                }
            }

            $this->log("Processing to update order #{$order_id} with payment method data before creating PayTo agreement");
            // Set payment method
            $order->set_payment_method($this->id);
            $order->set_payment_method_title($this->get_title());

            // Set payment method data (needed for create_new_payment_agreement method)
            $order->update_meta_data('payto_payment_method', $payment_method);
            if ($payment_method === 'payid') {
                $order->update_meta_data('payto_payid_type', $payid_type);
                $order->update_meta_data('payto_payid_value', $payid_value);
            } else {
                $order->update_meta_data('payto_account_name', $account_name);
                $order->update_meta_data('payto_bsb', $bsb);
                $order->update_meta_data('payto_account_number', $account_number);
            }
            $order->update_meta_data('payto_maximum_amount', $maximum_amount);
            $order->save();

            // Force creation of new agreement - deactivate any existing mandates for this user
            $customer_id = $this->get_customer_id_for_order($order, true); // true = for mandate storage
            $billing_email = $order->get_billing_email();

            if ($customer_id > 0) {
                // Deactivate all existing active mandates for this user
                $deactivated_count = $this->mandate_manager->deactivate_existing_mandates_for_user($customer_id, $billing_email);
                if ($deactivated_count > 0) {
                    $this->log("Deactivated $deactivated_count existing mandates for user $customer_id before creating new agreement");
                }
            }

            // Process payment (will create new agreement)
            // Set $_POST data for create_new_payment_agreement method
            $_POST['payto_payment_method'] = $payment_method;
            if ($payment_method === 'payid') {
                $_POST['payto_payid_type'] = $payid_type;
                $_POST['payto_payid_value'] = $payid_value;
            } else {
                $_POST['payto_account_name'] = $account_name;
                $_POST['payto_bsb'] = $bsb;
                $_POST['payto_account_number'] = $account_number;
            }
            $_POST['payto_maximum_amount'] = $maximum_amount;

            // Temporarily override the maximum amount setting for this payment
            add_filter('monoova_payto_max_amount_override', function ($default_amount) use ($maximum_amount) {
                return $maximum_amount;
            });

            $result = $this->process_payment($order->get_id());

            if ($result['result'] === 'success') {
                // Only empty the cart if we created a new order (cart page scenario)
                if (!$is_checkout_page) {
                    WC()->cart->empty_cart();
                }

                // Get additional order information for the response
                $payment_agreement_uid = $order->get_meta('_monoova_payto_agreement_uid', true);
                $mandate_uid = $order->get_meta('_monoova_payto_mandate_uid', true);

                wp_send_json_success(array(
                    'order_id' => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'redirect_url' => $result['redirect'],
                    'order_received_url' => $this->get_return_url($order),
                    'payment_agreement_uid' => $payment_agreement_uid,
                    'mandate_uid' => $mandate_uid,
                    'express_checkout' => false,
                    'mandate_used' => false,
                    'new_agreement_created' => true
                ));
            } else {
                wp_send_json_error(array('message' => __('Payment processing failed.', 'monoova-payments-for-woocommerce')));
            }
        } catch (Exception $e) {
            $this->log("PayTo agreement creation failed: " . $e->getMessage(), 'error');

            // Enhanced error response with detailed information
            $error_response = array(
                'message' => $e->getMessage(),
                'error_code' => $e->getCode() ?: 'AGREEMENT_CREATION_ERROR',
                'error_type' => 'AGREEMENT_CREATION_FAILED',
                'context' => 'agreement_creation'
            );

            // Add more specific error details based on error message
            if (strpos($e->getMessage(), 'cart') !== false || strpos($e->getMessage(), 'Cart') !== false) {
                $error_response['error_code'] = 'EMPTY_CART';
                $error_response['error_type'] = 'VALIDATION_ERROR';
            } elseif (strpos($e->getMessage(), 'order') !== false || strpos($e->getMessage(), 'Order') !== false) {
                $error_response['error_code'] = 'ORDER_CREATION_ERROR';
                $error_response['error_type'] = 'ORDER_ERROR';
            } elseif (strpos($e->getMessage(), 'mandate') !== false || strpos($e->getMessage(), 'Mandate') !== false) {
                $error_response['error_code'] = 'MANDATE_ERROR';
                $error_response['error_type'] = 'MANDATE_ERROR';
            } elseif (strpos($e->getMessage(), 'payment') !== false || strpos($e->getMessage(), 'Payment') !== false) {
                $error_response['error_code'] = 'PAYMENT_PROCESSING_ERROR';
                $error_response['error_type'] = 'PAYMENT_ERROR';
            } elseif (strpos($e->getMessage(), 'API') !== false || strpos($e->getMessage(), 'api') !== false) {
                $error_response['error_code'] = 'API_ERROR';
                $error_response['error_type'] = 'API_COMMUNICATION_ERROR';
            } elseif (strpos($e->getMessage(), 'validation') !== false || strpos($e->getMessage(), 'Validation') !== false) {
                $error_response['error_code'] = 'VALIDATION_ERROR';
                $error_response['error_type'] = 'FIELD_VALIDATION_ERROR';
            }

            // Add validation details if available
            if (isset($e->validation_errors) && !empty($e->validation_errors)) {
                $error_response['validation_errors'] = $e->validation_errors;
            }

            wp_send_json_error($error_response);
        }
    }

    /**
     * AJAX handler to check if user has active PayTo mandate
     */
    public function ajax_check_mandate() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_payto_check_mandate')) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('User not logged in.', 'monoova-payments-for-woocommerce')));
            return;
        }

        $current_user = wp_get_current_user();
        $user_id = $current_user->ID;
        $user_email = $current_user->user_email;

        try {
            // Check for active mandate
            $active_mandate = $this->mandate_manager->get_active_mandate_for_user($user_id, $user_email);

            if ($active_mandate) {
                $mandate_summary = $this->mandate_manager->get_mandate_summary($active_mandate);

                // For express checkout, also check if mandate should be used based on cart
                $should_use_mandate = true;
                $mandate_limit_exceeded = false;

                // Check if cart is available and not empty
                $cart = WC()->cart;
                if ($cart && !$cart->is_empty()) {
                    // Create a temporary order from cart to check mandate validity
                    $cart_total = floatval($cart->get_total(''));
                    $mandate_max = floatval($active_mandate->maximum_amount);

                    // Check amount limit
                    if ($cart_total > $mandate_max) {
                        $should_use_mandate = false;
                    }

                    // Check transaction limit
                    if (!$this->mandate_manager->has_available_transactions($active_mandate->payment_agreement_uid)) {
                        $should_use_mandate = false;
                        $mandate_limit_exceeded = true;
                    }

                    // Check expiration
                    if (!empty($active_mandate->expires_at)) {
                        $expires_timestamp = strtotime($active_mandate->expires_at);
                        if ($expires_timestamp && $expires_timestamp < time()) {
                            $should_use_mandate = false;
                        }
                    }
                }

                wp_send_json_success(array(
                    'mandate' => $mandate_summary,
                    'has_mandate' => true,
                    'should_use_mandate' => $should_use_mandate,
                    'mandate_limit_exceeded' => $mandate_limit_exceeded
                ));
            } else {
                wp_send_json_success(array(
                    'mandate' => null,
                    'has_mandate' => false,
                    'should_use_mandate' => false,
                    'mandate_limit_exceeded' => false
                ));
            }
        } catch (Exception $e) {
            $this->log("Error checking mandate: " . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => __('Error checking mandate.', 'monoova-payments-for-woocommerce')));
        }
    }

    /**
     * AJAX handler to process PayTo express payment
     */
    public function ajax_express_payment() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_payto_express_payment')) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('User not logged in.', 'monoova-payments-for-woocommerce')));
            return;
        }

        $payment_agreement_uid = sanitize_text_field($_POST['payment_agreement_uid'] ?? '');
        if (!$payment_agreement_uid) {
            wp_send_json_error(array('message' => __('Invalid payment agreement UID.', 'monoova-payments-for-woocommerce')));
            return;
        }

        try {
            // Get the mandate
            $mandate = $this->mandate_manager->get_mandate_by_uid($payment_agreement_uid);
            if (!$mandate) {
                wp_send_json_error(array('message' => __('Mandate not found.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Verify mandate belongs to current user
            $current_user = wp_get_current_user();
            if ($mandate->user_id != $current_user->ID) {
                wp_send_json_error(array('message' => __('Unauthorized mandate access.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Verify mandate is active and initiate payment for express checkout
            $api = $this->get_api();
            if (!$api) {
                wp_send_json_error(array('message' => __('Payment gateway is not configured correctly.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Verify the mandate is still active with the API
            $api_response = $api->get_payto_agreement($mandate->payment_agreement_uid);

            if (is_wp_error($api_response)) {
                $this->log("API error verifying mandate status: " . $api_response->get_error_message(), 'error');
                wp_send_json_error(array('message' => __('Unable to verify mandate status. Please try again.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Debug: Log the full API response
            $this->log("PayTo API response for mandate verification: " . wp_json_encode($api_response), 'debug');

            $api_status = strtolower($api_response['paymentAgreementDetails']['paymentAgreementStatus'] ?? '');
            // Check multiple possible status values for active mandates
            $valid_statuses = ['active', 'authorized', 'approved'];
            if (!in_array($api_status, $valid_statuses)) {
                $this->log("Mandate is not active (status: {$api_status}) for express checkout. Full API response structure: " . wp_json_encode(array_keys($api_response)), 'warning');

                // Fallback: If API check fails but stored mandate shows as active, use stored mandate
                if (strtolower($mandate->status) === 'active') {
                    $this->log("API status check failed, but stored mandate is active. Proceeding with stored mandate status.", 'info');
                } else {
                    wp_send_json_error(array('message' => __('Your PayTo mandate is not active. Please create a new payment agreement.', 'monoova-payments-for-woocommerce')));
                    return;
                }
            }

            // Check if cart is available and not empty
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) {
                wp_send_json_error(array('message' => __('Cart is empty.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Check if order amount is within mandate maximum
            $cart_total = floatval($cart->get_total(''));
            $mandate_max = floatval($mandate->maximum_amount);

            if ($cart_total > $mandate_max) {
                wp_send_json_error(array('message' => sprintf(__('Order total (%s) exceeds mandate maximum (%s). Please update your mandate or create a new one.', 'monoova-payments-for-woocommerce'), wc_price($cart_total), wc_price($mandate_max))));
                return;
            }

            // Create order from cart for express checkout
            $order_data = array('customer_id' => $current_user->ID);
            $order = wc_create_order($order_data);

            if (is_wp_error($order)) {
                wp_send_json_error(array('message' => __('Failed to create order for express checkout.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Add cart items to order
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                $order->add_product($cart_item['data'], $cart_item['quantity']);
            }

            // Set customer data from logged-in user
            $customer = new WC_Customer($current_user->ID);
            $order->set_billing_first_name($customer->get_billing_first_name());
            $order->set_billing_last_name($customer->get_billing_last_name());
            $order->set_billing_email($customer->get_billing_email());
            $order->set_billing_phone($customer->get_billing_phone());
            $order->set_billing_address_1($customer->get_billing_address_1());
            $order->set_billing_address_2($customer->get_billing_address_2());
            $order->set_billing_city($customer->get_billing_city());
            $order->set_billing_state($customer->get_billing_state());
            $order->set_billing_postcode($customer->get_billing_postcode());
            $order->set_billing_country($customer->get_billing_country());

            // Set payment method
            $order->set_payment_method($this->id);
            $order->set_payment_method_title($this->get_title());

            // Calculate totals and save order
            $order->calculate_totals();
            $order->save();

            // Process payment with existing mandate
            $payment_result = $this->process_payment_with_existing_mandate($order, $mandate);

            // Check if the result is a WP_Error (agreement limit exceeded)
            if (is_wp_error($payment_result)) {
                // Delete the order since payment failed
                wp_delete_post($order->get_id(), true);

                // Cast to WP_Error for proper type checking
                /** @var WP_Error $error_result */
                $error_result = $payment_result;
                $error_code = $error_result->get_error_code();
                $error_data = $error_result->get_error_data();

                // Handle agreement limit exceeded specifically
                if ($error_code === 'payto_agreement_limit_exceeded') {
                    wp_send_json_error(array(
                        'message' => $error_result->get_error_message(),
                        'error_code' => $error_code,
                        'agreement_uid' => $error_data['agreement_uid'] ?? '',
                        'requires_new_agreement' => $error_data['requires_new_agreement'] ?? false,
                        'errors' => $error_data['errors'] ?? array()
                    ));
                } else {
                    wp_send_json_error(array(
                        'message' => $error_result->get_error_message(),
                        'error_code' => $error_code
                    ));
                }
                return;
            }

            // Check if payment result is an array and successful
            if (is_array($payment_result) && $payment_result['result'] === 'success') {
                // Empty the cart since order was created successfully
                $cart->empty_cart();

                // Prepare success response for express checkout
                wp_send_json_success(array(
                    'payment_agreement_uid' => $mandate->payment_agreement_uid,
                    'order_id' => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                    'redirect_url' => $payment_result['redirect'],
                    'order_received_url' => $this->get_return_url($order),
                    'express_checkout_ready' => true,
                    'message' => __('Express checkout completed successfully.', 'monoova-payments-for-woocommerce')
                ));
            } else {
                // Delete the order if payment failed
                wp_delete_post($order->get_id(), true);
                wp_send_json_error(array('message' => __('Express checkout payment failed. Please try again.', 'monoova-payments-for-woocommerce')));
            }
        } catch (Exception $e) {
            $this->log("Error processing express payment: " . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => __('Error processing express payment.', 'monoova-payments-for-woocommerce')));
        }
    }

    /**
     * Format phone number to Australian standard format
     *
     * @param string $phone The raw phone number
     * @return string|null Formatted phone number or null if invalid
     */
    private function format_phone_number($phone) {
        if (empty($phone)) {
            return null;
        }

        // Remove all non-numeric characters
        $cleaned_number = preg_replace('/[^0-9]/', '', $phone);

        // Handle different Australian phone number formats
        if (strlen($cleaned_number) === 10 && strpos($cleaned_number, '0') === 0) {
            // Australian format starting with 0 (e.g., 0412345678)
            return '+61-' . substr($cleaned_number, 1);
        } elseif (strlen($cleaned_number) === 9) {
            // 9 digits without prefix (e.g., 412345678)
            return '+61-' . $cleaned_number;
        } elseif (strpos($cleaned_number, '61') === 0 && strlen($cleaned_number) === 11) {
            // Already has 61 prefix (e.g., 61412345678)
            return '+61-' . substr($cleaned_number, 2);
        } elseif (strpos($phone, '+61') === 0) {
            // Already formatted with +61, just ensure dash is present
            if (strpos($phone, '-') === false) {
                return '+61-' . substr($phone, 3);
            } else {
                return $phone; // Already properly formatted
            }
        } else {
            // For international numbers or landlines, return as-is with basic cleaning
            if (strlen($cleaned_number) >= 8) {
                // Assume it's a valid number, add + if missing
                return strpos($phone, '+') === 0 ? $phone : '+' . $cleaned_number;
            }
        }

        // Return null for invalid numbers
        return null;
    }

    /**
     * AJAX handler for resetting payment agreement when limits are exceeded
     */
    public function ajax_reset_agreement() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'monoova_payto_nonce')) {
            wp_send_json_error(array('message' => __('Invalid security token.', 'monoova-payments-for-woocommerce')));
            return;
        }

        // Check if user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('User not logged in.', 'monoova-payments-for-woocommerce')));
            return;
        }

        $order_id = sanitize_text_field($_POST['order_id'] ?? '');
        $customer_email = sanitize_email($_POST['customer_email'] ?? '');

        if (!$order_id) {
            wp_send_json_error(array('message' => __('Invalid order ID.', 'monoova-payments-for-woocommerce')));
            return;
        }

        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                wp_send_json_error(array('message' => __('Order not found.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Verify order belongs to current user
            $current_user = wp_get_current_user();
            if ($order->get_customer_id() != $current_user->ID) {
                wp_send_json_error(array('message' => __('Unauthorized order access.', 'monoova-payments-for-woocommerce')));
                return;
            }

            // Clear any existing PayTo agreement data from the order
            $order->delete_meta_data('_monoova_payto_agreement_uid');
            $order->delete_meta_data('_monoova_payto_agreement_status');
            $order->delete_meta_data('_monoova_payto_mandate_uid');
            $order->delete_meta_data('_monoova_payto_express_checkout');
            $order->save();

            // Log the reset action
            $this->log("PayTo agreement reset for order {$order_id} due to agreement limits exceeded", 'info');

            wp_send_json_success(array(
                'message' => __('Payment agreement has been reset. You can now create a new agreement with updated limits.', 'monoova-payments-for-woocommerce'),
                'order_id' => $order_id,
                'reset_completed' => true
            ));
        } catch (Exception $e) {
            $this->log("Error resetting PayTo agreement: " . $e->getMessage(), 'error');
            wp_send_json_error(array('message' => __('Failed to reset payment agreement. Please try again.', 'monoova-payments-for-woocommerce')));
        }
    }
     
    /**
     * Process subscription payment method change
     *
     * @param int $order_id
     * @return array
     */
    public function process_subscription_payment_method_change($order_id) {
        $this->log("Processing subscription payment method change for order #{$order_id}");

        // For payment method changes, we need to create a new mandate with $0 total
        // The subscription will be updated with the new mandate details
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['result' => 'failure'];
        }

        // Set order total to 0 for payment method change
        $original_total = $order->get_total();
        $order->set_total(0);

        // Create new payment agreement for the subscription
        $result = $this->create_new_payment_agreement($order);

        // Restore original total
        $order->set_total($original_total);
        $order->save();

        if ($result) {
            $this->log("Successfully created new mandate for subscription payment method change");
            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } else {
            $this->log("Failed to create new mandate for subscription payment method change", 'error');
            return ['result' => 'failure'];
        }
    }

    /**
     * Store subscription mandate information for future renewals
     *
     * @param WC_Order $order
     * @param object $mandate
     */
    public function store_subscription_mandate_info($order, $mandate) {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order->get_id());
        foreach ($subscriptions as $subscription) {
            $subscription->update_meta_data('_monoova_payto_mandate_id', $mandate->payment_agreement_uid);
            $subscription->update_meta_data('_monoova_payto_mandate_status', $mandate->status);
            $subscription->update_meta_data('_monoova_payto_customer_id', $this->get_customer_id_for_order($order, true));
            $subscription->save();

            $this->log("Stored mandate info for subscription #{$subscription->get_id()}: {$mandate->payment_agreement_uid}");
        }
    }

    /**
     * Store subscription metadata for new subscriptions
     *
     * @param WC_Order $order
     */
    public function store_subscription_metadata($order) {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return;
        }

        $agreement_uid = $order->get_meta('_monoova_payto_agreement_uid', true);
        if (empty($agreement_uid)) {
            return;
        }

        $subscriptions = wcs_get_subscriptions_for_order($order->get_id());
        foreach ($subscriptions as $subscription) {
            $subscription->update_meta_data('_monoova_payto_agreement_uid', $agreement_uid);
            $subscription->update_meta_data('_monoova_payto_customer_id', $this->get_customer_id_for_order($order, true));
            $subscription->save();

            $this->log("Stored subscription metadata for subscription #{$subscription->get_id()}: {$agreement_uid}");
        }
    }

    /**
     * Process scheduled subscription payment
     * This follows the official WooCommerce Subscriptions integration pattern
     *
     * @param float $amount_to_charge The amount to charge for this renewal
     * @param WC_Order $renewal_order The renewal order object
     */
    public function scheduled_subscription_payment($amount_to_charge, $renewal_order) {
        $this->log("Processing scheduled subscription payment for renewal order #{$renewal_order->get_id()}, amount: {$amount_to_charge}");

        try {
            // Process the subscription payment using our standard payment processing
            $result = $this->process_subscription_payment($amount_to_charge, $renewal_order);

            if (is_wp_error($result)) {
                // Payment failed - use WooCommerce Subscriptions API
                if (class_exists('WC_Subscriptions_Manager')) {
                    WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
                }
            } else {
                // Payment successful - use WooCommerce Subscriptions API
                if (class_exists('WC_Subscriptions_Manager')) {
                    WC_Subscriptions_Manager::process_subscription_payments_on_order($renewal_order);
                }
            }

        } catch (Exception $e) {
            $this->log("Exception in scheduled subscription payment: " . $e->getMessage(), 'error');

            // Mark the renewal order as failed
            $renewal_order->update_status('failed', sprintf(__('PayTo subscription payment failed: %s', 'monoova-payments-for-woocommerce'), $e->getMessage()));

            // Use WooCommerce Subscriptions API to handle the failed payment
            if (class_exists('WC_Subscriptions_Manager')) {
                WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
            }
        }
    }

    /**
     * Process subscription payment (used for both initial and renewal payments)
     *
     * @param float $amount_to_charge
     * @param WC_Order $order
     * @return array|WP_Error
     */
    public function process_subscription_payment($amount_to_charge, $order) {
        $this->log("Processing subscription payment for order #{$order->get_id()}, amount: {$amount_to_charge}");

        try {
            // Get the subscription to find the PayTo agreement
            if (function_exists('wcs_get_subscriptions_for_renewal_order')) {
                $subscriptions = wcs_get_subscriptions_for_renewal_order($order);
                $subscription = reset($subscriptions);
            } elseif (function_exists('wcs_get_subscriptions_for_order')) {
                $subscriptions = wcs_get_subscriptions_for_order($order->get_id());
                $subscription = reset($subscriptions);
            } else {
                throw new Exception(__('WooCommerce Subscriptions functions not available.', 'monoova-payments-for-woocommerce'));
            }

            if (!$subscription) {
                throw new Exception(__('Subscription not found for order.', 'monoova-payments-for-woocommerce'));
            }

            // Get the PayTo agreement UID from the subscription
            $agreement_uid = $subscription->get_meta('_monoova_payto_agreement_uid', true);
            if (empty($agreement_uid)) {
                throw new Exception(__('No PayTo agreement found for subscription.', 'monoova-payments-for-woocommerce'));
            }

            $this->log("Found PayTo agreement for subscription: {$agreement_uid}");

            // Create payment initiation for the renewal
            $result = $this->create_payment_initiation($order->get_id(), $agreement_uid);

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            // Mark the order as pending payment
            $order->update_status('pending', __('PayTo payment initiation created for subscription payment.', 'monoova-payments-for-woocommerce'));

            $this->log("Successfully created payment initiation for subscription payment");

            return array('result' => 'success');

        } catch (Exception $e) {
            $this->log("Failed to process subscription payment: " . $e->getMessage(), 'error');
            return new WP_Error('subscription_payment_failed', $e->getMessage());
        }
    }

    /**
     * Update failing payment method (following Stripe's pattern)
     * Called when a subscription payment method is updated after a failed payment
     *
     * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
     * @param WC_Order        $renewal_order The order which recorded the successful payment.
     * @return void
     */
    public function update_failing_payment_method($subscription, $renewal_order) {
        $this->log("Updating failing payment method for subscription #{$subscription->get_id()}");

        // Get the new PayTo agreement from the renewal order
        $new_agreement_uid = $renewal_order->get_meta('_monoova_payto_agreement_uid', true);
        if (!empty($new_agreement_uid)) {
            // Update the subscription with the new agreement
            $subscription->update_meta_data('_monoova_payto_agreement_uid', $new_agreement_uid);
            $subscription->update_meta_data('_monoova_payto_customer_id', $renewal_order->get_meta('_monoova_payto_customer_id', true));
            $subscription->save();

            $subscription->add_order_note(sprintf(__('PayTo payment method updated. New agreement: %s', 'monoova-payments-for-woocommerce'), $new_agreement_uid));
            $this->log("Updated subscription #{$subscription->get_id()} with new PayTo agreement: {$new_agreement_uid}");
        }
    }

    /**
     * Handle subscription cancelled status change
     *
     * @param WC_Subscription $subscription
     */
    public function handle_subscription_cancelled($subscription) {
        // Only handle if this subscription uses our gateway
        if ($subscription->get_payment_method() !== $this->id) {
            return;
        }

        $this->log("Handling subscription cancellation for subscription #{$subscription->get_id()}");

        $agreement_uid = $subscription->get_meta('_monoova_payto_agreement_uid', true);
        if (empty($agreement_uid)) {
            $this->log("No PayTo agreement found for subscription #{$subscription->get_id()}", 'warning');
            return;
        }

        // Note: PayTo agreements are typically cancelled automatically when they expire
        // or when the customer cancels them through their bank
        // We mainly need to update our local records

        $subscription->add_order_note(__('PayTo subscription cancelled.', 'monoova-payments-for-woocommerce'));
        $this->log("PayTo subscription #{$subscription->get_id()} cancelled");
    }

    /**
     * Handle subscription suspended (on-hold) status change
     *
     * @param WC_Subscription $subscription
     */
    public function handle_subscription_suspended($subscription) {
        // Only handle if this subscription uses our gateway
        if ($subscription->get_payment_method() !== $this->id) {
            return;
        }

        $this->log("Handling subscription suspension for subscription #{$subscription->get_id()}");

        // PayTo agreements don't have a suspend function, but we can note this
        $subscription->add_order_note(__('PayTo subscription suspended.', 'monoova-payments-for-woocommerce'));
        $this->log("PayTo subscription #{$subscription->get_id()} suspended");
    }

    /**
     * Handle subscription activated status change
     *
     * @param WC_Subscription $subscription
     */
    public function handle_subscription_activated($subscription) {
        // Only handle if this subscription uses our gateway
        if ($subscription->get_payment_method() !== $this->id) {
            return;
        }

        $this->log("Handling subscription activation for subscription #{$subscription->get_id()}");

        // PayTo agreements don't have a reactivate function, but we can note this
        $subscription->add_order_note(__('PayTo subscription activated.', 'monoova-payments-for-woocommerce'));
        $this->log("PayTo subscription #{$subscription->get_id()} activated");
    }

    /**
     * Get PayTo API frequency code for subscription
     *
     * @param int $order_id Order ID
     * @return string PayTo frequency code
     */
    private function get_subscription_frequency($order_id) {
        if (!function_exists('wcs_get_subscriptions_for_order')) {
            return 'ADHO'; // Default to adhoc
        }

        $subscriptions = wcs_get_subscriptions_for_order($order_id, array('order_type' => 'any'));
        if (empty($subscriptions)) {
            return 'ADHO';
        }

        $subscription = reset($subscriptions);
        $billing_period = $subscription->get_billing_period();
        $billing_interval = $subscription->get_billing_interval();

        // Map WooCommerce subscription periods to PayTo API frequency codes
        $frequency_map = array(
            'day' => 'DAIL',     // Daily
            'week' => 'WEEK',    // Weekly
            'month' => 'MNTH',   // Monthly
            'year' => 'YEAR',    // Annual
        );

        // Handle special cases based on interval
        if ($billing_period === 'week' && $billing_interval == 2) {
            return 'FRTN'; // Fortnightly (every 2 weeks)
        }

        if ($billing_period === 'month' && $billing_interval == 3) {
            return 'QURT'; // Quarterly (every 3 months)
        }

        if ($billing_period === 'month' && $billing_interval == 6) {
            return 'MIAN'; // Semi-Annual (every 6 months)
        }

        // Default mapping
        $frequency = $frequency_map[$billing_period] ?? 'ADHO';

        $this->log("Mapped subscription billing period '{$billing_period}' (interval: {$billing_interval}) to PayTo frequency: {$frequency}");

        return $frequency;
    }


    /**
     * Clean up scheduled events on plugin deactivation
     */
    public static function cleanup_scheduled_events() {
        wp_clear_scheduled_hook('monoova_check_payto_agreements');
    }
}
