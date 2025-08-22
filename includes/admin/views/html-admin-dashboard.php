<?php
/**
 * Admin Dashboard Page
 *
 * @package Monoova_Payments_For_WooCommerce
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Get settings URLs
$card_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_card');
$payid_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_payid');
$payto_settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=monoova_payto');
?>

<div class="wrap monoova-admin-wrap">
    <div class="monoova-header">
        <h1><?php esc_html_e('Monoova Payments Dashboard', 'monoova-payments-for-woocommerce'); ?></h1>
        <div class="monoova-header-actions">
            <a href="<?php echo esc_url($card_settings_url); ?>" class="button button-primary">
                <?php esc_html_e('Card Settings', 'monoova-payments-for-woocommerce'); ?>
            </a>
            <a href="<?php echo esc_url($payid_settings_url); ?>" class="button">
                <?php esc_html_e('PayID Settings', 'monoova-payments-for-woocommerce'); ?>
            </a>
            <a href="<?php echo esc_url($payto_settings_url); ?>" class="button">
                <?php esc_html_e('PayTo Settings', 'monoova-payments-for-woocommerce'); ?>
            </a>
        </div>
    </div>

    <?php if (!$is_production && !$production_ready) : ?>
    <div class="monoova-notice notice-warning">
        <p>
            <strong><?php esc_html_e('Test Mode Active', 'monoova-payments-for-woocommerce'); ?></strong> - 
            <?php esc_html_e('You need to add your production API keys before going live.', 'monoova-payments-for-woocommerce'); ?>
            <a href="<?php echo esc_url($card_settings_url); ?>"><?php esc_html_e('Add production keys', 'monoova-payments-for-woocommerce'); ?></a>
        </p>
    </div>
    <?php elseif ($is_production) : ?>
    <div class="monoova-notice notice-success">
        <p>
            <strong><?php esc_html_e('Production Mode Active', 'monoova-payments-for-woocommerce'); ?></strong> - 
            <?php esc_html_e('Your store is processing live payments.', 'monoova-payments-for-woocommerce'); ?>
        </p>
    </div>
    <?php else : ?>
    <div class="monoova-notice notice-info">
        <p>
            <strong><?php esc_html_e('Test Mode Active', 'monoova-payments-for-woocommerce'); ?></strong> - 
            <?php esc_html_e('Production API keys are set. You can switch to production mode when ready.', 'monoova-payments-for-woocommerce'); ?>
            <a href="<?php echo esc_url($card_settings_url); ?>"><?php esc_html_e('Switch to production', 'monoova-payments-for-woocommerce'); ?></a>
        </p>
    </div>
    <?php endif; ?>

    <div class="monoova-dashboard-content">
        <!-- Transaction Stats -->
        <div class="monoova-dashboard-row">
            <div class="monoova-card monoova-card-today">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('Today', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <div class="monoova-stat">
                        <span class="monoova-stat-value"><?php echo esc_html($stats['today']['count']); ?></span>
                        <span class="monoova-stat-label"><?php esc_html_e('Transactions', 'monoova-payments-for-woocommerce'); ?></span>
                    </div>
                    <div class="monoova-stat">
                        <span class="monoova-stat-value"><?php echo wc_price($stats['today']['amount']); ?></span>
                        <span class="monoova-stat-label"><?php esc_html_e('Amount', 'monoova-payments-for-woocommerce'); ?></span>
                    </div>
                </div>
            </div>
            <div class="monoova-card monoova-card-month">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('This Month', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <div class="monoova-stat">
                        <span class="monoova-stat-value"><?php echo esc_html($stats['month']['count']); ?></span>
                        <span class="monoova-stat-label"><?php esc_html_e('Transactions', 'monoova-payments-for-woocommerce'); ?></span>
                    </div>
                    <div class="monoova-stat">
                        <span class="monoova-stat-value"><?php echo wc_price($stats['month']['amount']); ?></span>
                        <span class="monoova-stat-label"><?php esc_html_e('Amount', 'monoova-payments-for-woocommerce'); ?></span>
                    </div>
                </div>
            </div>
            <div class="monoova-card monoova-card-total">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('All Time', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <div class="monoova-stat">
                        <span class="monoova-stat-value"><?php echo esc_html($stats['total']['count']); ?></span>
                        <span class="monoova-stat-label"><?php esc_html_e('Total Transactions', 'monoova-payments-for-woocommerce'); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment Method Stats -->
        <div class="monoova-dashboard-row">
            <div class="monoova-card monoova-card-payment-methods">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('Payment Methods', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <div class="monoova-payment-methods-stats">
                        <div class="monoova-payment-method">
                            <div class="monoova-payment-method-icon">
                                <img src="<?php echo esc_url(MONOOVA_PLUGIN_URL . 'assets/images/mastercard.png'); ?>" alt="Card">
                            </div>
                            <div class="monoova-payment-method-info">
                                <h4><?php esc_html_e('Card Payments', 'monoova-payments-for-woocommerce'); ?></h4>
                                <span class="monoova-payment-method-count"><?php echo esc_html($stats['card']['count']); ?> <?php esc_html_e('transactions', 'monoova-payments-for-woocommerce'); ?></span>
                            </div>
                            <div class="monoova-payment-method-status">
                                <?php if ($card_gateway->is_available()) : ?>
                                    <span class="monoova-status monoova-status-active"><?php esc_html_e('Active', 'monoova-payments-for-woocommerce'); ?></span>
                                <?php else : ?>
                                    <span class="monoova-status monoova-status-inactive"><?php esc_html_e('Inactive', 'monoova-payments-for-woocommerce'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="monoova-payment-method">
                            <div class="monoova-payment-method-icon">
                                <img src="<?php echo esc_url(MONOOVA_PLUGIN_URL . 'assets/images/payid-logo.svg'); ?>" alt="PayID">
                            </div>
                            <div class="monoova-payment-method-info">
                                <h4><?php esc_html_e('PayID / Bank Transfer', 'monoova-payments-for-woocommerce'); ?></h4>
                                <span class="monoova-payment-method-count"><?php echo esc_html($stats['payid']['count']); ?> <?php esc_html_e('transactions', 'monoova-payments-for-woocommerce'); ?></span>
                            </div>
                            <div class="monoova-payment-method-status">
                                <?php if ($payid_gateway->is_available()) : ?>
                                    <span class="monoova-status monoova-status-active"><?php esc_html_e('Active', 'monoova-payments-for-woocommerce'); ?></span>
                                <?php else : ?>
                                    <span class="monoova-status monoova-status-inactive"><?php esc_html_e('Inactive', 'monoova-payments-for-woocommerce'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="monoova-payment-method">
                            <div class="monoova-payment-method-icon">
                                <img src="<?php echo esc_url(MONOOVA_PLUGIN_URL . 'assets/images/payto-logo.svg'); ?>" alt="PayTo">
                            </div>
                            <div class="monoova-payment-method-info">
                                <h4><?php esc_html_e('PayTo', 'monoova-payments-for-woocommerce'); ?></h4>
                                <span class="monoova-payment-method-count"><?php echo esc_html($stats['payto']['count']); ?> <?php esc_html_e('transactions', 'monoova-payments-for-woocommerce'); ?></span>
                            </div>
                            <div class="monoova-payment-method-status">
                                <?php if ($payto_gateway->is_available()) : ?>
                                    <span class="monoova-status monoova-status-active"><?php esc_html_e('Active', 'monoova-payments-for-woocommerce'); ?></span>
                                <?php else : ?>
                                    <span class="monoova-status monoova-status-inactive"><?php esc_html_e('Inactive', 'monoova-payments-for-woocommerce'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="monoova-card-footer">
                    <a href="<?php echo esc_url($card_settings_url); ?>" class="button"><?php esc_html_e('Card Settings', 'monoova-payments-for-woocommerce'); ?></a>
                    <a href="<?php echo esc_url($payid_settings_url); ?>" class="button"><?php esc_html_e('PayID Settings', 'monoova-payments-for-woocommerce'); ?></a>
                    <a href="<?php echo esc_url($payto_settings_url); ?>" class="button"><?php esc_html_e('PayTo Settings', 'monoova-payments-for-woocommerce'); ?></a>
                </div>
            </div>

            <div class="monoova-card monoova-card-refunds">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('Refunds', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <div class="monoova-stat monoova-stat-large">
                        <span class="monoova-stat-value"><?php echo esc_html($stats['refunded']['count']); ?></span>
                        <span class="monoova-stat-label"><?php esc_html_e('Total Refunds', 'monoova-payments-for-woocommerce'); ?></span>
                    </div>
                    <p class="monoova-refund-info">
                        <?php esc_html_e('You can refund payments directly from the order admin view.', 'monoova-payments-for-woocommerce'); ?>
                    </p>
                </div>
            </div>

            <div class="monoova-card monoova-card-payto-mandates">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('PayTo Mandates', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <div class="monoova-mandate-stats">
                        <div class="monoova-stat">
                            <span class="monoova-stat-value"><?php echo esc_html($mandate_stats['total_mandates']); ?></span>
                            <span class="monoova-stat-label"><?php esc_html_e('Total Mandates', 'monoova-payments-for-woocommerce'); ?></span>
                        </div>
                        <div class="monoova-stat">
                            <span class="monoova-stat-value"><?php echo esc_html($mandate_stats['active_mandates']); ?></span>
                            <span class="monoova-stat-label"><?php esc_html_e('Active Mandates', 'monoova-payments-for-woocommerce'); ?></span>
                        </div>
                        <div class="monoova-stat">
                            <span class="monoova-stat-value"><?php echo esc_html($mandate_stats['subscription_mandates']); ?></span>
                            <span class="monoova-stat-label"><?php esc_html_e('Subscription Mandates', 'monoova-payments-for-woocommerce'); ?></span>
                        </div>
                    </div>
                    <div class="monoova-transaction-usage">
                        <p>
                            <strong><?php esc_html_e('Transaction Usage:', 'monoova-payments-for-woocommerce'); ?></strong>
                            <?php echo esc_html($mandate_stats['total_transactions_used']); ?> / <?php echo esc_html($mandate_stats['total_transactions_available']); ?>
                            <?php esc_html_e('transactions used', 'monoova-payments-for-woocommerce'); ?>
                        </p>
                    </div>
                </div>
                <div class="monoova-card-footer">
                    <a href="<?php echo esc_url($payto_settings_url); ?>" class="button"><?php esc_html_e('PayTo Settings', 'monoova-payments-for-woocommerce'); ?></a>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="monoova-dashboard-row">
            <div class="monoova-card monoova-card-transactions">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('Recent Transactions', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <?php if (!empty($latest_transactions)) : ?>
                        <table class="monoova-transactions-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Order', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Date', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Customer', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Amount', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Payment Method', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Status', 'monoova-payments-for-woocommerce'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($latest_transactions as $transaction) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $transaction['id'] . '&action=edit')); ?>">
                                                #<?php echo esc_html($transaction['id']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo esc_html($transaction['date']); ?></td>
                                        <td><?php echo esc_html($transaction['customer']); ?></td>
                                        <td><?php echo wc_price($transaction['amount'], array('currency' => $transaction['currency'])); ?></td>
                                        <td><?php echo esc_html($transaction['payment_method_title']); ?></td>
                                        <td><span class="order-status status-<?php echo esc_attr($transaction['status']); ?>"><?php echo esc_html(wc_get_order_status_name($transaction['status'])); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <div class="monoova-empty-state">
                            <p><?php esc_html_e('No transactions found.', 'monoova-payments-for-woocommerce'); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="monoova-card-footer">
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=shop_order')); ?>" class="button"><?php esc_html_e('View All Orders', 'monoova-payments-for-woocommerce'); ?></a>
                </div>
            </div>
        </div>

        <!-- Support & Resources -->
        <div class="monoova-dashboard-row">
            

            <div class="monoova-card monoova-card-webhook">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('Webhook Setup', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <p>
                        <?php esc_html_e('For real-time payment updates, set up webhooks in your Monoova dashboard using the following URL:', 'monoova-payments-for-woocommerce'); ?>
                    </p>
                    <div class="monoova-webhook-url">
                        <code><?php echo esc_url(home_url('/?wc-api=monoova_webhook')); ?></code>
                        <button type="button" class="button monoova-copy-webhook" data-clipboard-text="<?php echo esc_url(home_url('/?wc-api=monoova_webhook')); ?>">
                            <?php esc_html_e('Copy', 'monoova-payments-for-woocommerce'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- API Information Section -->
        <div class="monoova-dashboard-row">
            <div class="monoova-card monoova-card-api-info">
                <div class="monoova-card-header">
                    <h2><?php esc_html_e('API Information', 'monoova-payments-for-woocommerce'); ?></h2>
                </div>
                <div class="monoova-card-body">
                    <p><?php esc_html_e('This plugin integrates with the following Monoova APIs:', 'monoova-payments-for-woocommerce'); ?></p>
                    
                    <div class="monoova-api-info-tables">
                        <h3><?php esc_html_e('Card API Endpoints', 'monoova-payments-for-woocommerce'); ?></h3>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Environment', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Base URL', 'monoova-payments-for-woocommerce'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php esc_html_e('Sandbox', 'monoova-payments-for-woocommerce'); ?></td>
                                    <td><code>https://sand-api.monoova.com</code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Production', 'monoova-payments-for-woocommerce'); ?></td>
                                    <td><code>https://api.monoova.com</code></td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <h3><?php esc_html_e('Payment API Endpoints', 'monoova-payments-for-woocommerce'); ?></h3>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Environment', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Base URL', 'monoova-payments-for-woocommerce'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php esc_html_e('Sandbox', 'monoova-payments-for-woocommerce'); ?></td>
                                    <td><code>https://api.m-pay.com.au</code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Production', 'monoova-payments-for-woocommerce'); ?></td>
                                    <td><code>https://api.mpay.com.au</code></td>
                                </tr>
                            </tbody>
                        </table>

                        <h3><?php esc_html_e('PayTo API Endpoints', 'monoova-payments-for-woocommerce'); ?></h3>
                        <table class="widefat">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Environment', 'monoova-payments-for-woocommerce'); ?></th>
                                    <th><?php esc_html_e('Base URL', 'monoova-payments-for-woocommerce'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><?php esc_html_e('Sandbox', 'monoova-payments-for-woocommerce'); ?></td>
                                    <td><code>https://api.m-pay.com.au</code></td>
                                </tr>
                                <tr>
                                    <td><?php esc_html_e('Production', 'monoova-payments-for-woocommerce'); ?></td>
                                    <td><code>https://api.mpay.com.au</code></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="monoova-card-footer">
                    <a href="https://api-docs.monoova.com/" target="_blank" class="button"><?php esc_html_e('View API Documentation', 'monoova-payments-for-woocommerce'); ?></a>
                </div>
            </div>
        </div>
    </div>
</div>
<script type="text/javascript">
    jQuery(document).ready(function($) {
        $('.monoova-copy-webhook').on('click', function() {
            var $temp = $("<input>");
            $("body").append($temp);
            $temp.val($(this).data('clipboard-text')).select();
            document.execCommand("copy");
            $temp.remove();
            
            var $button = $(this);
            var originalText = $button.text();
            $button.text('<?php esc_html_e('Copied!', 'monoova-payments-for-woocommerce'); ?>');
            
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        });
    });
</script>