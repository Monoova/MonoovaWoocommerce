<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Monoova PayTo Mandate Manager
 * 
 * Handles storage and retrieval of PayTo mandates for express checkout and reuse
 *
 * @class       Monoova_PayTo_Mandate_Manager
 * @version     1.0.0
 * @package     Monoova_Payments_For_WooCommerce/Classes
 * @author      Monoova
 */
class Monoova_PayTo_Mandate_Manager {

    /**
     * Table name for storing PayTo mandates
     */
    const TABLE_NAME = 'monoova_payto_mandates';

    /**
     * Database version for migrations
     */
    const DB_VERSION = '1.1.0';

    /**
     * Logger instance
     * @var WC_Logger|null
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = wc_get_logger();

        // Hook into WordPress init to create table if needed
        add_action('init', array($this, 'maybe_create_table'));

        // Schedule cleanup cron job
        add_action('monoova_cleanup_expired_mandates', array($this, 'cleanup_expired_mandates'));

        if (!wp_next_scheduled('monoova_cleanup_expired_mandates')) {
            wp_schedule_event(time(), 'daily', 'monoova_cleanup_expired_mandates');
        }
    }

    /**
     * Create the PayTo mandates table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $installed_version = get_option('monoova_payto_mandates_db_version', '0.0.0');

        if (version_compare($installed_version, self::DB_VERSION, '<')) {
            $this->create_table();
            update_option('monoova_payto_mandates_db_version', self::DB_VERSION);
        }
    }

    /**
     * Create the PayTo mandates table
     */
    private function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            payment_agreement_uid varchar(255) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            customer_email varchar(100) NOT NULL,
            agreement_type varchar(10) NOT NULL DEFAULT 'VARI',
            maximum_amount decimal(10,2) NOT NULL DEFAULT 1000.00,
            status varchar(20) NOT NULL DEFAULT 'Created',
            payment_method varchar(20) NOT NULL DEFAULT 'PAYTO',
            automatic_renewal tinyint(1) NOT NULL DEFAULT 0,
            num_of_transactions_permitted int(11) NOT NULL DEFAULT 10,
            num_of_paid_transactions int(11) NOT NULL DEFAULT 0,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY payment_agreement_uid (payment_agreement_uid),
            KEY user_id (user_id),
            KEY customer_email (customer_email),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $this->log('PayTo mandates table created or updated');
    }

    /**
     * Store a PayTo mandate
     *
     * @param array $mandate_data Mandate data from API response and order
     * @return string|false Payment agreement UID on success, false on failure
     */
    public function store_mandate($mandate_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Validate required fields
        $required_fields = ['payment_agreement_uid', 'user_id', 'customer_email'];
        foreach ($required_fields as $field) {
            if (empty($mandate_data[$field])) {
                $this->log("Missing required field: $field", 'error');
                return false;
            }
        }

        // Prepare data for insertion
        $data = array(
            'payment_agreement_uid' => sanitize_text_field($mandate_data['payment_agreement_uid']),
            'user_id' => absint($mandate_data['user_id']),
            'customer_email' => sanitize_email($mandate_data['customer_email']),
            'agreement_type' => sanitize_text_field($mandate_data['agreement_type'] ?? 'VARI'),
            'maximum_amount' => floatval($mandate_data['maximum_amount'] ?? 1000.00),
            'status' => sanitize_text_field($mandate_data['status'] ?? 'Created'),
            'payment_method' => sanitize_text_field($mandate_data['payment_method'] ?? 'PAYTO'),
            'automatic_renewal' => !empty($mandate_data['automatic_renewal']) ? 1 : 0,
            'num_of_transactions_permitted' => absint($mandate_data['num_of_transactions_permitted'] ?? 10),
            'num_of_paid_transactions' => absint($mandate_data['num_of_paid_transactions'] ?? 0),
            'expires_at' => !empty($mandate_data['expires_at']) ? date('Y-m-d H:i:s', $mandate_data['expires_at']) : null,
        );

        // Check if mandate already exists
        $existing = $this->get_mandate_by_uid($mandate_data['payment_agreement_uid']);
        if ($existing) {
            // Update existing mandate
            $result = $wpdb->update(
                $table_name,
                $data,
                array('payment_agreement_uid' => $mandate_data['payment_agreement_uid']),
                array('%s', '%d', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s'),
                array('%s')
            );

            if ($result !== false) {
                $this->log("Updated PayTo mandate: {$mandate_data['payment_agreement_uid']}");
                return $mandate_data['payment_agreement_uid'];
            }
        } else {
            // Insert new mandate
            $result = $wpdb->insert($table_name, $data, array('%s', '%d', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s'));

            if ($result !== false) {
                $mandate_id = $wpdb->insert_id;
                $this->log("Stored new PayTo mandate: {$mandate_data['payment_agreement_uid']} (ID: $mandate_id)");
                return $mandate_data['payment_agreement_uid'];
            }
        }

        $this->log("Failed to store PayTo mandate: {$mandate_data['payment_agreement_uid']}", 'error');
        return false;
    }

    /**
     * Get active PayTo mandate for a user
     *
     * @param int $user_id WordPress user ID
     * @param string $customer_email Customer email as fallback
     * @return object|null Mandate object or null if not found
     */
    public function get_active_mandate_for_user($user_id, $customer_email = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build query conditions
        $where_conditions = array();
        $where_values = array();

        if ($user_id > 0) {
            $where_conditions[] = 'user_id = %d';
            $where_values[] = $user_id;
        }

        if (!empty($customer_email)) {
            if (!empty($where_conditions)) {
                $where_conditions[] = 'OR customer_email = %s';
            } else {
                $where_conditions[] = 'customer_email = %s';
            }
            $where_values[] = $customer_email;
        }

        if (empty($where_conditions)) {
            return null;
        }

        $where_clause = '(' . implode(' ', $where_conditions) . ')';

        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE $where_clause 
             AND status = 'Active' 
             AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC 
             LIMIT 1",
            ...$where_values
        );

        $mandate = $wpdb->get_row($sql);

        if ($mandate) {
            $this->log("Found active mandate for user $user_id: {$mandate->payment_agreement_uid}");
        }

        return $mandate;
    }

    /**
     * Get mandate by payment agreement UID
     *
     * @param string $payment_agreement_uid
     * @return object|null Mandate object or null if not found
     */
    public function get_mandate_by_uid($payment_agreement_uid) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE payment_agreement_uid = %s",
            $payment_agreement_uid
        );

        $mandate = $wpdb->get_row($sql);

        return $mandate;
    }

    /**
     * Update mandate status
     *
     * @param string $payment_agreement_uid
     * @param string $status New status
     * @return bool Success
     */
    public function update_mandate_status($payment_agreement_uid, $status) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->update(
            $table_name,
            array('status' => sanitize_text_field($status)),
            array('payment_agreement_uid' => $payment_agreement_uid),
            array('%s'),
            array('%s')
        );

        if ($result !== false) {
            $this->log("Updated mandate status: $payment_agreement_uid -> $status");
            return true;
        }

        $this->log("Failed to update mandate status: $payment_agreement_uid", 'error');
        return false;
    }

    /**
     * Get all mandates for a user
     *
     * @param int $user_id WordPress user ID
     * @param string $status Optional status filter
     * @return array Array of mandate objects
     */
    public function get_user_mandates($user_id, $status = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where_clause = 'user_id = %d';
        $where_values = array($user_id);

        if (!empty($status)) {
            $where_clause .= ' AND status = %s';
            $where_values[] = $status;
        }

        $sql = $wpdb->prepare(
            "SELECT * FROM $table_name WHERE $where_clause ORDER BY created_at DESC",
            ...$where_values
        );

        $mandates = $wpdb->get_results($sql);

        return $mandates;
    }

    /**
     * Check if mandate has available transactions
     *
     * @param string $payment_agreement_uid
     * @return bool True if transactions are available, false otherwise
     */
    public function has_available_transactions($payment_agreement_uid) {
        $mandate = $this->get_mandate_by_uid($payment_agreement_uid);

        if (!$mandate) {
            return false;
        }

        return $mandate->num_of_paid_transactions < $mandate->num_of_transactions_permitted;
    }

    /**
     * Increment paid transaction count for a mandate
     *
     * @param string $payment_agreement_uid
     * @return bool Success
     */
    public function increment_paid_transactions($payment_agreement_uid) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE $table_name
                 SET num_of_paid_transactions = num_of_paid_transactions + 1
                 WHERE payment_agreement_uid = %s",
                $payment_agreement_uid
            )
        );

        if ($result !== false) {
            $this->log("Incremented paid transactions for mandate: $payment_agreement_uid");
            return true;
        }

        $this->log("Failed to increment paid transactions for mandate: $payment_agreement_uid", 'error');
        return false;
    }

    /**
     * Get transaction usage for a mandate
     *
     * @param string $payment_agreement_uid
     * @return array|null Array with usage info or null if not found
     */
    public function get_transaction_usage($payment_agreement_uid) {
        $mandate = $this->get_mandate_by_uid($payment_agreement_uid);

        if (!$mandate) {
            return null;
        }

        return array(
            'num_of_paid_transactions' => (int) $mandate->num_of_paid_transactions,
            'num_of_transactions_permitted' => (int) $mandate->num_of_transactions_permitted,
            'remaining_transactions' => (int) ($mandate->num_of_transactions_permitted - $mandate->num_of_paid_transactions),
            'has_available_transactions' => $mandate->num_of_paid_transactions < $mandate->num_of_transactions_permitted
        );
    }

    /**
     * Deactivate all existing active mandates for a user
     *
     * @param int $user_id WordPress user ID
     * @param string $customer_email Customer email as fallback
     * @return int Number of deactivated mandates
     */
    public function deactivate_existing_mandates_for_user($user_id, $customer_email = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Build query conditions
        $where_conditions = array();
        $where_values = array();

        if ($user_id > 0) {
            $where_conditions[] = 'user_id = %d';
            $where_values[] = $user_id;
        }

        if (!empty($customer_email)) {
            if (!empty($where_conditions)) {
                $where_conditions[] = 'OR customer_email = %s';
            } else {
                $where_conditions[] = 'customer_email = %s';
            }
            $where_values[] = $customer_email;
        }

        if (empty($where_conditions)) {
            $this->log("No user ID or email provided for mandate deactivation", 'error');
            return 0;
        }

        $where_clause = implode(' ', $where_conditions);

        $sql = $wpdb->prepare(
            "UPDATE $table_name
             SET status = 'Replaced'
             WHERE ($where_clause)
             AND status = 'Active'",
            ...$where_values
        );

        $result = $wpdb->query($sql);

        if ($result > 0) {
            $this->log("Deactivated $result existing active mandates for user $user_id");
        }

        return $result;
    }

    /**
     * Clean up expired mandates
     *
     * @return int Number of cleaned up mandates
     */
    public function cleanup_expired_mandates() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->query(
            "UPDATE $table_name
             SET status = 'Expired'
             WHERE expires_at IS NOT NULL
             AND expires_at < NOW()
             AND status NOT IN ('Expired', 'Cancelled', 'Rejected')"
        );

        if ($result > 0) {
            $this->log("Cleaned up $result expired PayTo mandates");
        }

        return $result;
    }



    /**
     * Get mandate summary for display (e.g., in checkout)
     *
     * @param object $mandate
     * @return array
     */
    public function get_mandate_summary($mandate) {
        if (!$mandate) {
            return array();
        }

        return array(
            'payment_agreement_uid' => $mandate->payment_agreement_uid,
            'maximum_amount' => $mandate->maximum_amount,
            'status' => $mandate->status,
            'created_at' => $mandate->created_at,
            'expires_at' => $mandate->expires_at,
            'num_of_paid_transactions' => (int) $mandate->num_of_paid_transactions,
            'num_of_transactions_permitted' => (int) $mandate->num_of_transactions_permitted,
            'remaining_transactions' => (int) ($mandate->num_of_transactions_permitted - $mandate->num_of_paid_transactions),
            'has_available_transactions' => $mandate->num_of_paid_transactions < $mandate->num_of_transactions_permitted
        );
    }

    /**
     * Store mandate for subscription
     *
     * @param array $mandate_data Mandate data
     * @param int $subscription_id Subscription ID
     * @return int|false Mandate ID on success, false on failure
     */
    public function store_subscription_mandate($mandate_data, $subscription_id) {
        // Store the mandate first
        $mandate_id = $this->store_mandate($mandate_data);

        if ($mandate_id) {
            // Add subscription-specific metadata
            global $wpdb;
            $table_name = $wpdb->prefix . self::TABLE_NAME;

            // Update the mandate with subscription info
            $wpdb->update(
                $table_name,
                array('automatic_renewal' => 1), // Subscriptions should auto-renew
                array('id' => $mandate_id),
                array('%d'),
                array('%d')
            );

            $this->log("Stored subscription mandate for subscription #{$subscription_id}, mandate ID: {$mandate_id}");
        }

        return $mandate_id;
    }

    /**
     * Get mandate for subscription
     *
     * @param int $subscription_id Subscription ID
     * @return object|null Mandate object or null if not found
     */
    public function get_mandate_for_subscription($subscription_id) {
        if (!function_exists('wcs_get_subscription')) {
            return null;
        }

        $subscription = wcs_get_subscription($subscription_id);
        if (!$subscription) {
            return null;
        }

        $agreement_uid = $subscription->get_meta('_monoova_payto_agreement_uid', true);
        if (empty($agreement_uid)) {
            return null;
        }

        return $this->get_mandate_by_uid($agreement_uid);
    }

    /**
     * Update mandate status for subscription
     *
     * @param int $subscription_id Subscription ID
     * @param string $status New status
     * @return bool Success
     */
    public function update_subscription_mandate_status($subscription_id, $status) {
        $mandate = $this->get_mandate_for_subscription($subscription_id);
        if (!$mandate) {
            return false;
        }

        return $this->update_mandate_status($mandate->payment_agreement_uid, $status);
    }

    /**
     * Check if subscription has active mandate
     *
     * @param int $subscription_id Subscription ID
     * @return bool True if has active mandate
     */
    public function subscription_has_active_mandate($subscription_id) {
        $mandate = $this->get_mandate_for_subscription($subscription_id);
        return $mandate && strtolower($mandate->status) === 'active';
    }

    /**
     * Get subscription mandates for user
     *
     * @param int $user_id User ID
     * @param string $email Customer email
     * @return array Array of mandate objects
     */
    public function get_subscription_mandates_for_user($user_id, $email = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where_conditions = array('automatic_renewal = 1');
        $where_values = array();

        if ($user_id > 0) {
            $where_conditions[] = 'user_id = %d';
            $where_values[] = $user_id;
        }

        if (!empty($email)) {
            $where_conditions[] = 'customer_email = %s';
            $where_values[] = sanitize_email($email);
        }

        if (empty($where_values)) {
            return array();
        }

        $where_clause = implode(' AND ', $where_conditions);

        $query = $wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE {$where_clause} ORDER BY created_at DESC",
            $where_values
        );

        $results = $wpdb->get_results($query);
        return $results ?: array();
    }

    /**
     * Log message
     *
     * @param string $message
     * @param string $level
     */
    private function log($message, $level = 'info') {
        if ($this->logger) {
            $this->logger->log($level, $message, array('source' => 'monoova-payto-mandate-manager'));
        }
    }
}
