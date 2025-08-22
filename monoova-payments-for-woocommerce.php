<?php

/**
 * Plugin Name: Monoova Payments for WooCommerce
 * Plugin URI: https://www.monoova.com/woocommerce
 * Description: Accept secure payments using Monoova's Card, PayID, direct bank transfers, Apple Pay, and Google Pay.
 * Version: 1.1.2
 * Author: Monoova
 * Author URI: https://www.monoova.com
 * Text Domain: monoova-payments-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 6.1 // Updated based on block requirements
 * WC tested up to: 8.0.0
 * Requires PHP: 7.2
 *
 * @package Monoova_Payments_For_WooCommerce
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Required minimums and constants
 */
define('MONOOVA_VERSION', '1.1.2'); // Incremented version
define('MONOOVA_MIN_PHP_VER', '7.2.0');
define('MONOOVA_MIN_WC_VER', '6.1.0'); // Blocks require WC 6.1+
define('MONOOVA_PLUGIN_FILE', __FILE__);
define('MONOOVA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MONOOVA_PLUGIN_URL', plugin_dir_url(__FILE__));

// Default API URLs - these can be overridden in settings
if (!defined('MONOOVA_PAYMENTS_WC_SERVERLESS_API_URL')) {
    define('MONOOVA_PAYMENTS_WC_SERVERLESS_API_URL', 'http://localhost:3000/api/v1/payments');
}

/**
 * Monoova API URL information
 *
 * Official Monoova API endpoints:
 * - Card API:
 *   - Sandbox: https://sand-api.monoova.com
 *   - Production: https://api.monoova.com
 * - Payment API:
 *   - Sandbox: https://api.m-pay.com.au
 *   - Production: https://api.mpay.com.au
 *
 * The plugin's serverless component automatically uses the appropriate URL
 * based on the environment setting (test/production).
 */

/**
 * Main Monoova Payments for WooCommerce Class
 */
class Monoova_Payments_For_WooCommerce {

    /**
     * Single instance of the class
     *
     * @var Monoova_Payments_For_WooCommerce
     */
    protected static $instance = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->includes();
        $this->init_hooks();

        // Add script enqueuing
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    /**
     * Get the single instance of the class
     *
     * @return Monoova_Payments_For_WooCommerce
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        // Load textdomain immediately to avoid WordPress 6.7+ early translation loading warning
        $this->load_plugin_textdomain();

        add_action('woocommerce_payment_gateways', array($this, 'add_payment_gateways'));
        add_action('plugins_loaded', array($this, 'check_environment'), 0);
    }

    /**
     * Check plugin environment
     */
    public function check_environment() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Check PHP version
        if (version_compare(PHP_VERSION, MONOOVA_MIN_PHP_VER, '<')) {
            add_action('admin_notices', array($this, 'php_version_notice'));
            return;
        }

        // Check WooCommerce version
        if (defined('WC_VERSION') && version_compare(wc()->version, MONOOVA_MIN_WC_VER, '<')) {
            add_action('admin_notices', array($this, 'wc_version_notice'));
            return;
        }
    }

    /**
     * Include required core files
     */
    public function includes() {
        // Core classes
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-gateway.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-card-gateway.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-payid-gateway.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-payto-gateway.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-unified-gateway.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-webhook-handler.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-subscriptions.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/class-monoova-payto-mandate-manager.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/api/class-monoova-api.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/admin/class-monoova-admin.php';
        require_once MONOOVA_PLUGIN_DIR . 'includes/admin/class-monoova-admin-settings-handler.php';


        // Block integration classes (conditionally loaded later)
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Only load on checkout or account pages
        if (!is_checkout() && !is_account_page() && !is_add_payment_method_page()) {
            return;
        }

        // Enqueue common styles
        wp_enqueue_style(
            'monoova-payments',
            MONOOVA_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            MONOOVA_VERSION
        );
    }

    /**
     * Add payment gateways
     * Implements parent-child architecture where unified gateway controls child gateway visibility
     *
     * @param array $gateways List of available gateways
     * @return array Modified list of gateways
     */
    public function add_payment_gateways($gateways) {
        // Check if required classes exist before adding them
        if (class_exists('Monoova_Card_Gateway')) {
            $gateways[] = 'Monoova_Card_Gateway';
        }
        if (class_exists('Monoova_PayID_Gateway')) {
            $gateways[] = 'Monoova_PayID_Gateway';
        }
        if (class_exists('Monoova_PayTo_Gateway')) {
            $gateways[] = 'Monoova_PayTo_Gateway';
        }
        if (class_exists('Monoova_Unified_Gateway')) {
            $gateways[] = 'Monoova_Unified_Gateway';
        }

        // Debug logging when WooCommerce Subscriptions is active
        if (class_exists('WC_Subscriptions') && defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Monoova: Registering gateways with WooCommerce Subscriptions active. Gateway count: ' . count($gateways));
        }

        return $gateways;
    }

    /**
     * Load plugin textdomain
     */
    public function load_plugin_textdomain() {
        load_plugin_textdomain('monoova-payments-for-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }




    /**
     * WooCommerce not installed notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>'
            . sprintf(__('Monoova Payments for WooCommerce requires WooCommerce to be installed and active. You can download %s here.', 'monoova-payments-for-woocommerce'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>')
            . '</strong></p></div>';
    }

    /**
     * PHP version notice
     */
    public function php_version_notice() {
        echo '<div class="error"><p><strong>'
            . sprintf(__('Monoova Payments for WooCommerce requires PHP %1$s or higher. Your server is running PHP %2$s.', 'monoova-payments-for-woocommerce'), MONOOVA_MIN_PHP_VER, PHP_VERSION)
            . '</strong></p></div>';
    }

    /**
     * WooCommerce version notice
     */
    public function wc_version_notice() {
        $current_version = '[unknown]';
        // Ensure WooCommerce functions are available before trying to get the version.
        if (function_exists('wc')) {
            $current_version = wc()->version;
        }
        echo '<div class="error"><p><strong>'
            . sprintf(__('Monoova Payments for WooCommerce requires WooCommerce %1$s or higher to function correctly. You are running version %2$s.', 'monoova-payments-for-woocommerce'), MONOOVA_MIN_WC_VER, $current_version)
            . '</strong></p></div>';
    }

    /**
     * Handles the request for the Primer redirect URL and serves the redirect template.
     *
     * Hooks into 'template_redirect' to intercept the request before the theme loads.
     */
    public function handle_primer_redirect_request() {
        if (isset($_GET['card_payment_sdk']) && $_GET['card_payment_sdk'] === '1') {
            // At this point, template-primer-redirect.php will handle its own nonce and order_id verification.
            // We just need to ensure the file exists and load it.
            $redirect_template_file = MONOOVA_PLUGIN_DIR . 'template-primer-redirect.php';

            if (file_exists($redirect_template_file)) {
                // Optional: You might want to set up global $order here if the template expects it globally,
                // but the template already does wc_get_order().
                // $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
                // if ($order_id) { global $order; $order = wc_get_order($order_id); }

                include $redirect_template_file;
                exit; // Important: stop WordPress from loading any other template
            } else {
                // Fallback if the template file is somehow missing
                wp_die(
                    'Payment processing template is missing. Please contact support.',
                    'Payment Gateway Error',
                    array('response' => 500)
                );
            }
        }
        // If not our specific redirect, WordPress continues as normal.
    }
}

/**
 * Initialize the plugin
 */
function monoova_payments_for_woocommerce_init() {
    // Ensure WooCommerce is loaded before initializing.
    // The check_environment method hooked to plugins_loaded:0 handles version checks and notices.
    if (class_exists('WooCommerce')) {
        $plugin_instance = Monoova_Payments_For_WooCommerce::get_instance();

        // Initialize webhook handler after gateways are registered
        add_action('init', function() {
            $webhook_handler = new Monoova_Webhook_Handler();
            $webhook_handler->register_hooks();
        }, 25); // Priority 25 to ensure it runs after all other initializations

        // Hook to handle Primer redirect - Changed from template_include and theme_page_templates
        add_action('template_redirect', array($plugin_instance, 'handle_primer_redirect_request'));

        // Register AJAX hooks without triggering gateway initialization
        // This avoids potential circular dependencies during plugin activation
        add_action('wp_ajax_monoova_get_client_token', function () {
            // Get gateway instance only when needed
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['monoova_card']) && $gateways['monoova_card'] instanceof Monoova_Card_Gateway) {
                $gateways['monoova_card']->ajax_get_client_token();
            } else {
                wp_die('Gateway not available', 'Error', array('response' => 500));
            }
        });

        add_action('wp_ajax_nopriv_monoova_get_client_token', function () {
            // Get gateway instance only when needed
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['monoova_card']) && $gateways['monoova_card'] instanceof Monoova_Card_Gateway) {
                $gateways['monoova_card']->ajax_get_client_token();
            } else {
                wp_die('Gateway not available', 'Error', array('response' => 500));
            }
        });

        add_action('wp_ajax_monoova_complete_checkout', function () {
            // Get gateway instance only when needed
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['monoova_card']) && $gateways['monoova_card'] instanceof Monoova_Card_Gateway) {
                $gateways['monoova_card']->ajax_complete_checkout();
            } else {
                wp_die('Gateway not available', 'Error', array('response' => 500));
            }
        });

        add_action('wp_ajax_nopriv_monoova_complete_checkout', function () {
            // Get gateway instance only when needed
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['monoova_card']) && $gateways['monoova_card'] instanceof Monoova_Card_Gateway) {
                $gateways['monoova_card']->ajax_complete_checkout();
            } else {
                wp_die('Gateway not available', 'Error', array('response' => 500));
            }
        });
    }
    // If WooCommerce class doesn't exist, the check_environment method will have already added the 'woocommerce_missing_notice'.
}

// Start the plugin initialization after plugins are loaded.
add_action('plugins_loaded', 'monoova_payments_for_woocommerce_init');


// Add preconnect for Primer SDK
add_action('wp_head', function () {
    // Only add on checkout, account, or add payment method pages
    if (is_checkout() || is_account_page() || is_add_payment_method_page()) {
        echo '<link rel="preconnect" href="https://sdk.primer.io" crossorigin />' . PHP_EOL;
    }
});

// Register block payment method types if Blocks are available
add_action('woocommerce_blocks_loaded', 'register_block_integrations');

/**
 * Register block integrations.
 */
function register_block_integrations() {
    // Check if the required class exists
    if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    // Include block integration classes
    require_once MONOOVA_PLUGIN_DIR . 'includes/blocks/class-monoova-card-blocks-integration.php';
    require_once MONOOVA_PLUGIN_DIR . 'includes/blocks/class-monoova-card-express-blocks-integration.php';
    require_once MONOOVA_PLUGIN_DIR . 'includes/blocks/class-monoova-payid-blocks-integration.php';
    require_once MONOOVA_PLUGIN_DIR . 'includes/blocks/class-monoova-payto-blocks-integration.php';
    require_once MONOOVA_PLUGIN_DIR . 'includes/blocks/class-monoova-payto-express-blocks-integration.php';

    // Register the payment method types
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function (Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry) {
            $payment_method_registry->register(new Monoova_Card_Blocks_Integration());
            $payment_method_registry->register(new Monoova_Card_Express_Blocks_Integration());
            $payment_method_registry->register(new Monoova_PayID_Blocks_Integration());
            $payment_method_registry->register(new Monoova_PayTo_Blocks_Integration());
            $payment_method_registry->register(new Monoova_PayTo_Express_Blocks_Integration());
        },
        10, // Priority to ensure it runs after the registry is initialized
    );
}

// Declare compatibility
add_action(
    'before_woocommerce_init',
    function () {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);

            // Declare compatibility with WooCommerce Subscriptions
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('subscriptions', __FILE__, true);
        }
    }
);

// Plugin deactivation hook
register_deactivation_hook(__FILE__, 'monoova_payments_deactivation');

/**
 * Plugin deactivation callback
 */
function monoova_payments_deactivation() {
    // Clean up scheduled events
    if (class_exists('Monoova_PayTo_Gateway')) {
        Monoova_PayTo_Gateway::cleanup_scheduled_events();
    }

    // Clean up mandate cleanup cron job
    wp_clear_scheduled_hook('monoova_cleanup_expired_mandates');
}
