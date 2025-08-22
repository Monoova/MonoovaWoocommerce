<?php
/**
 * Template Name: Monoova Primer Redirect
 * Description: Handles the Primer Universal Checkout redirection.
 *
 * This template should be assigned to a WordPress page (e.g., with slug 'primer-redirect')
 * that the Monoova Card Gateway redirects to after initial checkout processing.
 */

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Get order_id and nonce from URL parameters
$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
$nonce = isset($_GET['m_nonce']) ? sanitize_text_field($_GET['m_nonce']) : '';

// Verify the nonce
if (!$order_id || !$nonce || !wp_verify_nonce($nonce, 'card_payment_redirect_nonce_' . $order_id)) {
    wp_die('Invalid payment link or session expired. Please try checking out again or contact support if the issue persists.', 'Payment Error', array('response' => 403));
}

$order = wc_get_order($order_id);
if (!$order) {
    wp_die('Order not found. Please try checking out again or contact support.', 'Payment Error', array('response' => 404));
}

// Get gateway instance to access admin settings
$gateway = null;
$available_gateways = WC()->payment_gateways->get_available_payment_gateways();
if (isset($available_gateways['monoova_card'])) {
    $gateway = $available_gateways['monoova_card'];
}

// Data to pass to primer-payment-handler.js
$redirect_data = array(
    'ajax_url' => admin_url('admin-ajax.php'),
    'order_id' => $order_id,
    'client_token_nonce' => $nonce, // The nonce received from the initial redirect, to be used for fetching client token
    'complete_payment_nonce' => wp_create_nonce('card_sdk_complete_payment_nonce_' . $order_id), // New nonce for the completion step
    'checkout_url' => wc_get_checkout_url(),
    // Add gateway settings for Primer configuration
    'gateway_settings' => $gateway ? array(
        'saved_cards' => 'yes' === $gateway->get_option('saved_cards', 'yes'),
        'enable_apple_pay' => 'yes' === $gateway->get_option('enable_apple_pay', 'no'),
        'enable_google_pay' => 'yes' === $gateway->get_option('enable_google_pay', 'no'),
        'capture' => 'yes' === $gateway->get_option('capture', 'yes'),
        // Checkout UI Style Settings
        'checkout_ui_styles' => $gateway->get_option('checkout_ui_styles', array(
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
        )),
    ) : array(
        'saved_cards' => false,
        'enable_apple_pay' => false,
        'enable_google_pay' => false,
        'capture' => true,
        // Default style settings
        'checkout_ui_styles' => array(
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
    ),
    'i18n' => array(
        'error_fetching_token' => 'Could not retrieve payment session. Please try again or contact support.',
        'error_invalid_token' => 'Invalid payment session token provided. Please refresh and try again.',
        'payment_successful' => 'Payment Successful!',
        'error_completing_payment' => 'Payment completion data is invalid. Please contact support.',
        'error_finalizing_order' => 'Could not finalize your order. Please contact customer support with your order ID.',
        'payment_failed_try_again' => 'Your payment could not be processed. Please try again later or use a different payment method.',
        'checkout_cancelled' => 'Checkout was cancelled. Your order has not been processed.',
        'auth_failed' => 'Payment authorization failed. Please check your details and try again.',
        'payment_failed' => 'Payment failed. Please try again.',
        'error_init_payment_form' => 'Failed to initialize the payment form. Please ensure JavaScript is enabled and try refreshing the page.',
        'error_missing_order_id' => 'Order ID is missing. Cannot proceed with payment.',
        'error_missing_nonce' => 'Security token is missing. Cannot proceed with payment.',
    ),
);

?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Process Payment - <?php bloginfo('name'); ?></title>
    <?php wp_head(); // WordPress head hooks, includes enqueued styles/scripts ?>
    <style>
      /* Minimal styling for the redirect page, can be moved to primer-redirect-page.css */
      body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        margin: 0;
        padding: 0;
        background-color: #f0f0f1;
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 100vh;
      }
      #main-wrapper {
        min-height: 300px;
        padding: 20px;
        width: 100%;
        max-width: 500px; /* Max width for the checkout form area */
        box-sizing: border-box;
        background: #fff;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      }
      #error-box {
        background-color: #f44336; /* Red for errors */
        color: white;
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 4px;
        display: none; /* Shown by JS when active */
        text-align: center;
      }
      #error-box.active {
          display: block;
      }
      #checkout-container {
        /* Styles for the Primer container will be largely controlled by Primer itself */
        /* Ensure it has some min-height or visibility initially if needed */
        min-height: 300px; /* Adjust as needed */
      }
      .loading-payment {
          text-align: center;
          padding: 40px;
          font-size: 1.2em;
          color: #555;
      }
    </style>
</head>
<body>
    <noscript>
      <div style="text-align: center; margin: 20px; padding: 20px; background-color: #f44336; color: white;">
        Please enable JavaScript to continue using this application.
      </div>
    </noscript>

    <div id="main-wrapper">
      <div id="error-box"></div>
      <div id="checkout-container" class="shadow mt-2"></div>
    </div>
    
    <!-- Add redirect data directly to the page -->
    <script type="text/javascript">
    /* <![CDATA[ */
    var primerRedirectData = <?php echo json_encode($redirect_data); ?>;
    /* ]]> */
    </script>
    
    <!-- Load scripts in the correct order: 1) jQuery, 2) Primer, 3) Our handler -->
    <!-- First load jQuery with Ajax support -->
    <script
      id="jquery"
      src="https://code.jquery.com/jquery-3.6.3.min.js"
      integrity="sha256-pvPw+upLPUjgMXY0G+8O0xUf+/Im1MZjXxxgOcBQBXU="
      crossorigin="anonymous"
    ></script>
    
    <!-- Then load Primer SDK -->
    <link rel="stylesheet" href="https://sdk.primer.io/web/v2.45.8/Checkout.css" />
    <script 
      id="primer-sdk-js"
      src="https://sdk.primer.io/web/v2.45.8/Primer.min.js" 
      crossorigin="anonymous"
    ></script>
    
    <!-- Finally load our custom handler script -->
    <script 
      id="monoova-primer-payment-handler"
      src="<?php echo esc_url(MONOOVA_PLUGIN_URL . 'assets/js/src/primer-payment-handler.js'); ?>"
    ></script>

    <?php wp_footer(); // WordPress footer hooks ?>
</body>
</html>