<?php

/**
 * Monoova Admin Class
 *
 * Handles the admin interface and settings
 *
 * @package Monoova_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova Admin Class
 */
class Monoova_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        // Add Monoova menu to WooCommerce sidebar
        add_action('admin_menu', array($this, 'add_monoova_menu'));

        // Add admin styles and scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Add action links to plugins page
        add_filter('plugin_action_links_' . plugin_basename(MONOOVA_PLUGIN_FILE), array($this, 'add_plugin_action_links'));
    }

    /**
     * Add Monoova menu item to WooCommerce sidebar
     */
    public function add_monoova_menu() {
        add_submenu_page(
            'woocommerce',
            __('Monoova Payments', 'monoova-payments-for-woocommerce'),
            __('Monoova Payments', 'monoova-payments-for-woocommerce'),
            'manage_woocommerce',
            'monoova-payments',
            array($this, 'render_dashboard_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        $is_monoova_dashboard = ($hook === 'woocommerce_page_monoova-payments');
        $is_monoova_settings_page = ($hook === 'woocommerce_page_wc-settings' && isset($_GET['section']) && strpos($_GET['section'], 'monoova_') === 0);

        // Only load assets on our specific pages.
        if (!$is_monoova_dashboard && !$is_monoova_settings_page) {
            return;
        }

        // Admin CSS
        wp_enqueue_style(
            'monoova-admin-styles',
            MONOOVA_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MONOOVA_VERSION
        );

        // Admin JS - using the built script
        wp_enqueue_script(
            'monoova-admin-scripts',
            MONOOVA_PLUGIN_URL . 'assets/js/build/admin.js',
            array('jquery'),
            MONOOVA_VERSION,
            true
        );

        // Pass localization data to script
        wp_localize_script(
            'monoova-admin-scripts',
            'monoova_admin_params',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('monoova-admin'),
                'i18n'     => array(
                    'loading'     => __('Loading...', 'monoova-payments-for-woocommerce'),
                    'error'       => __('Error', 'monoova-payments-for-woocommerce'),
                    'success'     => __('Success', 'monoova-payments-for-woocommerce'),
                    'confirm'     => __('Are you sure?', 'monoova-payments-for-woocommerce'),
                ),
            )
        );

        if ($is_monoova_settings_page) {
            wp_localize_script(
                'monoova-admin-scripts',
                'monoovaGenerateAutomatcherNonce',
                wp_create_nonce('monoova_generate_automatcher_nonce')
            );

            wp_localize_script(
                'monoova-admin-scripts',
                'monoovaAdminNonce',
                wp_create_nonce('monoova-admin')
            );
        }
    }

    /**
     * Add plugin action links
     *
     * @param array $links Plugin action links
     * @return array Modified action links
     */
    public function add_plugin_action_links($links) {
        $plugin_links = array(
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_card') . '">' . __('Card Settings', 'monoova-payments-for-woocommerce') . '</a>',
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_payid') . '">' . __('PayID Settings', 'monoova-payments-for-woocommerce') . '</a>',
            '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_payto') . '">' . __('PayTo Settings', 'monoova-payments-for-woocommerce') . '</a>',
            '<a href="' . admin_url('admin.php?page=monoova-payments') . '">' . __('Dashboard', 'monoova-payments-for-woocommerce') . '</a>',
        );

        return array_merge($plugin_links, $links);
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        // Get payment gateways
        $card_gateway = new Monoova_Card_Gateway();
        $payid_gateway = new Monoova_PayID_Gateway();
        $payto_gateway = new Monoova_PayTo_Gateway();

        // Check if production mode is enabled
        $is_production = 'production' === $card_gateway->get_option('mode', 'sandbox');
        $production_ready = $this->check_if_production_ready();

        // Get transaction stats
        $stats = $this->get_transaction_stats();

        // Get latest transactions
        $latest_transactions = $this->get_latest_transactions();

        // Get PayTo mandate stats
        $mandate_stats = $this->get_payto_mandate_stats();

        // Load template
        include_once MONOOVA_PLUGIN_DIR . 'includes/admin/views/html-admin-dashboard.php';
    }

    /**
     * Check if production mode is ready
     *
     * @return bool Whether production mode is ready
     */
    private function check_if_production_ready() {
        $card_gateway = new Monoova_Card_Gateway();

        // Check for required production settings
        $api_key = $card_gateway->get_option('live_api_key');
        $publishable_key = $card_gateway->get_option('live_publishable_key');
        $webhook_secret = $card_gateway->get_option('webhook_secret');

        return !empty($api_key) && !empty($publishable_key) && !empty($webhook_secret);
    }

    /**
     * Get transaction stats
     *
     * @return array Transaction statistics
     */
    private function get_transaction_stats() {
        global $wpdb;

        // Time ranges
        $today_start = date('Y-m-d 00:00:00');
        $month_start = date('Y-m-01 00:00:00');

        // Get today's orders with Monoova
        $today_orders = wc_get_orders(array(
            'payment_method' => array('monoova_card', 'monoova_payid', 'monoova_payto'),
            'date_created' => '>' . strtotime($today_start),
            'return' => 'ids',
        ));

        $today_amount = 0;
        foreach ($today_orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->is_paid()) {
                $today_amount += $order->get_total();
            }
        }

        // Get this month's orders with Monoova
        $month_orders = wc_get_orders(array(
            'payment_method' => array('monoova_card', 'monoova_payid', 'monoova_payto'),
            'date_created' => '>' . strtotime($month_start),
            'return' => 'ids',
        ));

        $month_amount = 0;
        foreach ($month_orders as $order_id) {
            $order = wc_get_order($order_id);
            if ($order && $order->is_paid()) {
                $month_amount += $order->get_total();
            }
        }

        // Get total orders with Monoova
        $total_orders = count(wc_get_orders(array(
            'payment_method' => array('monoova_card', 'monoova_payid', 'monoova_payto'),
            'return' => 'ids',
        )));

        // Get counts by payment method
        $card_count = count(wc_get_orders(array(
            'payment_method' => 'monoova_card',
            'return' => 'ids',
        )));

        $payid_count = count(wc_get_orders(array(
            'payment_method' => 'monoova_payid',
            'return' => 'ids',
        )));

        $payto_count = count(wc_get_orders(array(
            'payment_method' => 'monoova_payto',
            'return' => 'ids',
        )));

        // Get refunded orders
        $refunded_orders = wc_get_orders(array(
            'payment_method' => array('monoova_card', 'monoova_payid', 'monoova_payto'),
            'status' => 'refunded',
            'return' => 'ids',
        ));

        return array(
            'today' => array(
                'count' => count($today_orders),
                'amount' => $today_amount,
            ),
            'month' => array(
                'count' => count($month_orders),
                'amount' => $month_amount,
            ),
            'total' => array(
                'count' => $total_orders,
            ),
            'card' => array(
                'count' => $card_count,
            ),
            'payid' => array(
                'count' => $payid_count,
            ),
            'payto' => array(
                'count' => $payto_count,
            ),
            'refunded' => array(
                'count' => count($refunded_orders),
            ),
        );
    }

    /**
     * Get latest transactions
     *
     * @param int $limit Number of transactions to get
     * @return array Latest transactions
     */
    private function get_latest_transactions($limit = 10) {
        // Get orders with Monoova payment methods
        $orders = wc_get_orders(array(
            'payment_method' => array('monoova_card', 'monoova_payid', 'monoova_payto'),
            'limit' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        $transactions = array();

        foreach ($orders as $order) {
            $payment_method = $order->get_payment_method();
            $payment_method_title = $order->get_payment_method_title();
            $payment_id = $order->get_meta('_monoova_payment_id');

            $transactions[] = array(
                'id' => $order->get_id(),
                'date' => $order->get_date_created()->date('Y-m-d H:i'),
                'amount' => $order->get_total(),
                'currency' => $order->get_currency(),
                'status' => $order->get_status(),
                'payment_method' => $payment_method,
                'payment_method_title' => $payment_method_title,
                'customer' => $order->get_formatted_billing_full_name(),
                'payment_id' => $payment_id,
            );
        }

        return $transactions;
    }

    /**
     * Get PayTo mandate statistics
     *
     * @return array PayTo mandate statistics
     */
    private function get_payto_mandate_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'monoova_payto_mandates';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return array(
                'total_mandates' => 0,
                'active_mandates' => 0,
                'expired_mandates' => 0,
                'subscription_mandates' => 0,
                'total_transactions_used' => 0,
                'total_transactions_available' => 0,
            );
        }

        // Get total mandates
        $total_mandates = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");

        // Get active mandates (not expired and have available transactions)
        $active_mandates = $wpdb->get_var("
            SELECT COUNT(*) FROM $table_name
            WHERE (expires_at IS NULL OR expires_at > NOW())
            AND num_of_paid_transactions < num_of_transactions_permitted
        ");

        // Get expired mandates
        $expired_mandates = $wpdb->get_var("
            SELECT COUNT(*) FROM $table_name
            WHERE expires_at IS NOT NULL AND expires_at <= NOW()
        ");

        // Get subscription mandates (automatic renewal enabled)
        $subscription_mandates = $wpdb->get_var("
            SELECT COUNT(*) FROM $table_name
            WHERE automatic_renewal = 1
        ");

        // Get transaction usage statistics
        $transaction_stats = $wpdb->get_row("
            SELECT
                SUM(num_of_paid_transactions) as total_used,
                SUM(num_of_transactions_permitted) as total_available
            FROM $table_name
        ");

        return array(
            'total_mandates' => intval($total_mandates),
            'active_mandates' => intval($active_mandates),
            'expired_mandates' => intval($expired_mandates),
            'subscription_mandates' => intval($subscription_mandates),
            'total_transactions_used' => intval($transaction_stats->total_used ?? 0),
            'total_transactions_available' => intval($transaction_stats->total_available ?? 0),
        );
    }
}

// Initialize the admin class after gateways are registered
add_action('woocommerce_payment_gateways', function() {
    // Initialize on a later hook to ensure gateways are registered
    add_action('init', function() {
        new Monoova_Admin();
    }, 20); // Priority 20 to ensure it runs after gateway registration
});
