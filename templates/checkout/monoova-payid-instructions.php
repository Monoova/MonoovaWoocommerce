<?php

/**
 * Monoova PayID/Bank Transfer Payment Instructions - Thank You Page (New Design)
 *
 * @package Monoova_Payments_For_WooCommerce
 */

defined('ABSPATH') || exit;

/**
 * @var WC_Order $order
 * @var array $details (amount_to_pay_formatted, payid_value, reference, qr_code_payload, bank_account_name, bank_bsb, bank_account_number)
 * @var string $status_check_nonce
 * @var array $payment_types_to_show
 * @var bool $show_reference_field
 * @var int $expiry_timestamp
 */

$show_payid = in_array('payid', $payment_types_to_show) && !empty($details['payid_value']);
$show_bank = in_array('bank_transfer', $payment_types_to_show) && !empty($details['bank_bsb']) && !empty($details['bank_account_number']);
?>
<style>
    .monoova-payid-bank-transfer-instructions-wrapper .monoova-instructions-container {
        text-align: center;
        border: 1px solid #e0e0e0;
        padding: 30px;
        border-radius: 12px;
        width: max-content;
        max-width: 480px;
        display: flex;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        text-align: left;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-header h2 {
        font-size: 20px;
        margin: 0;
        color: #333;
    }

    #monoova-method-switcher {
        border-radius: 8px;
        padding: 8px 12px;
        border: 1px solid #ccc;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-instructions-container p {
        color: #555;
        font-size: 14px;
        line-height: 1.5;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-scan-pay h3 {
        font-size: 24px;
        margin: 20px 0 5px 0;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-scan-pay .amount {
        font-size: 28px;
        font-weight: 700;
        color: #0073e6;
        margin-bottom: 5px;
    }

    #monoova-qr-code {
        padding: 10px;
        border: 1px solid #eee;
        border-radius: 8px;
        display: inline-block;
    }

    #monoova-qr-code img {
        display: block;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-instructions-container p {
        color: #555;
        font-size: 14px;
        line-height: 1.5;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-scan-pay {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 10px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-scan-pay h3 {
        font-size: 24px;
        margin: 0;
        font-weight: 500;
        color: #333;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-scan-pay .amount {
        font-size: 28px;
        font-weight: 700;
        color: #2CB5C5;
        margin: 0;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-reference-area .ref-label {
        font-size: 14px;
        color: #666;
        margin-right: 8px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-reference-area .ref-value {
        font-weight: bold;
        font-size: 16px;
        letter-spacing: 0.5px;
        color: #2CB5C5;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-divider {
        color: #999;
        margin: 18px 0;
        font-size: 13px;
        text-transform: uppercase;
        font-weight: 500;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-manual-pay {
        background-color: #ffffff;
        padding: 8px 12px 8px 12px;
        border: 1px solid #E8E8E8;
        border-radius: 8px;
        display: flex;
        max-width: fit-content;
        margin: 0 auto;
        justify-content: space-between;
        align-items: center;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-manual-pay .payid-logo {
        height: 20px;
        margin-right: 12px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-manual-pay .copy-target {
        font-family: monospace;
        font-size: 14px;
        color: #333;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-copy-button {
        background: #ffffff;
        border: 0;
        border-left: 1px solid #E8E8E8;
        cursor: pointer;
        margin-left: 12px;
        line-height: 1;
        border-radius: 0;
        padding: 0 2px 0 12px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-copy-button:hover {
        background-color: #ffffff;
        border: 0;
        border-left: 1px solid #E8E8E8;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-copy-button svg {
        width: 16px;
        height: 16px;
        display: block;
        border: none;
        outline: none;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-confirmation-text {
        margin-top: 25px;
        font-size: 13px;
        color: #666;
    }

    #monoova-expiry-info {
        background-color: #FFF6EB;
        padding: 12px;
        border-radius: 8px;
        margin-top: 20px;
        font-size: 13px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-details {
        text-align: left;
        margin-top: 20px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-details .detail-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 5px;
        border-bottom: 1px solid #f1f1f1;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-details .detail-row:last-child {
        border-bottom: none;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-details .detail-label {
        color: #555;
        font-weight: 500;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-details .detail-value {
        font-weight: 600;
        font-family: monospace;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-details .copy-row .detail-value {
        flex-grow: 1;
        text-align: right;
        margin-right: 10px;
    }

    /* New styles for Bank Transfer view */
    #monoova-bank-view h3 {
        font-size: 18px;
        font-weight: 600;
        margin-top: 0;
        margin-bottom: 20px;
        color: #333;
        text-align: left;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 15px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field {
        background-color: #f8f9fa;
        border: 1px solid #e8e8e8;
        border-radius: 8px;
        padding: 10px 15px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field.full-width {
        grid-column: 1 / -1;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field .field-label {
        display: block;
        font-size: 12px;
        color: #666;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field .field-value-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field .copy-target {
        font-size: 16px;
        font-weight: 500;
        color: #333;
        font-family: monospace;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-bank-field .monoova-copy-button {
        background: transparent;
        border: none;
        padding: 0;
        margin-left: 10px;
        cursor: pointer;
        color: #2CB5C5;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-reference-section {
        margin-top: 25px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-reference-section h4 {
        font-size: 16px;
        font-weight: 600;
        margin-bottom: 5px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-reference-section p {
        font-size: 14px;
        color: #555;
        margin-top: 0;
        margin-bottom: 10px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-reference-section .monoova-bank-field {
        background-color: #fff;
    }

    #monoova-bank-view .monoova-bank-field {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-status {
        display: none;
        text-align: center;
        padding: 40px 20px;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-status p {
        font-size: 16px;
        color: #484848;
        font-weight: 400;
    }

    .monoova-payid-bank-transfer-instructions-wrapper .monoova-payment-status .title {
        font-size: 24px;
        font-weight: 600;
    }
</style>
<div class="monoova-payid-bank-transfer-instructions-wrapper">
    <section class="woocommerce-order-details monoova-instructions-container" id="monoova-payment-instructions" data-order-id="<?php echo esc_attr($order->get_id()); ?>" data-order-key="<?php echo esc_attr($order->get_order_key()); ?>" data-nonce="<?php echo esc_attr($status_check_nonce); ?>" data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" data-expiry-timestamp="<?php echo esc_attr($expiry_timestamp); ?>">

        <div id="monoova-payment-pending">
            <div class="monoova-header">
                <h2><?php esc_html_e('Payment Instructions for', 'monoova-payments-for-woocommerce'); ?></h2>
                <?php if ($show_payid && $show_bank) : ?>
                    <select id="monoova-method-switcher">
                        <option value="payid"><?php esc_html_e('PayID', 'monoova-payments-for-woocommerce'); ?></option>
                        <option value="bank"><?php esc_html_e('Bank Transfer', 'monoova-payments-for-woocommerce'); ?></option>
                    </select>
                <?php endif; ?>
            </div>

            <p><?php echo esc_html($instructions, 'monoova-payments-for-woocommerce'); ?></p>
            <!-- PayID View -->
            <div id="monoova-payid-view" <?php if (!$show_payid) echo 'style="display:none;"'; ?>>
                <div class="monoova-scan-pay">
                    <h3><?php esc_html_e('Pay', 'monoova-payments-for-woocommerce'); ?></h3>
                    <div class="amount"><?php echo wp_kses_post($details['amount_to_pay_formatted']); ?></div>
                </div>
                <p><?php esc_html_e('make your payment easily from your banking app', 'monoova-payments-for-woocommerce'); ?></p>

                <?php if (!empty($details['qr_code_payload'])) : ?>
                    <div id="monoova-qr-code" data-payload="<?php echo esc_attr($details['qr_code_payload']); ?>"></div>
                    <?php if ($show_reference_field) : ?>
                        <div class="monoova-reference-area">
                            <span class="ref-label"><?php esc_html_e('Order reference', 'monoova-payments-for-woocommerce'); ?></span>
                            <span class="ref-value"><?php echo esc_html($details['reference'] . 'P'); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <p class="monoova-divider"><?php esc_html_e('or Pay From Your Internet Banking', 'monoova-payments-for-woocommerce'); ?></p>

                <div class="monoova-manual-pay">
                    <img src="<?php echo esc_url(MONOOVA_PLUGIN_URL . 'assets/images/payid-logo.png'); ?>" alt="PayID" class="payid-logo" />
                    <span class="copy-target" data-copy-id="payid-value"><?php echo esc_html($details['payid_value']); ?></span>
                    <button type="button" class="monoova-copy-button" data-target-id="payid-value" title="<?php esc_attr_e('Copy PayID', 'monoova-payments-for-woocommerce'); ?>">
                        <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12.4868 9.675V12.825C12.4868 15.45 11.4368 16.5 8.81182 16.5H5.66182C3.03682 16.5 1.98682 15.45 1.98682 12.825V9.675C1.98682 7.05 3.03682 6 5.66182 6H8.81182C11.4368 6 12.4868 7.05 12.4868 9.675Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M16.9868 5.175V8.325C16.9868 10.95 15.9368 12 13.3118 12H12.4868V9.675C12.4868 7.05 11.4368 6 8.81182 6H6.48682V5.175C6.48682 2.55 7.53682 1.5 10.1618 1.5H13.3118C15.9368 1.5 16.9868 2.55 16.9868 5.175Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Bank Transfer View -->
            <div id="monoova-bank-view" style="display:<?php echo $show_payid ? 'none' : 'block'; ?>;">
                <h3><?php esc_html_e('Pay via Bank Transfer', 'monoova-payments-for-woocommerce'); ?></h3>

                <div class="monoova-bank-field-grid">
                    <div class="monoova-bank-field">
                        <span class="field-label"><?php esc_html_e('Account Name', 'monoova-payments-for-woocommerce'); ?></span>
                        <div class="field-value-wrapper">
                            <span class="copy-target" data-copy-id="bank-name" style="font-family: sans-serif; font-weight: 500;"><?php echo esc_html($details['bank_account_name']); ?></span>
                        </div>
                    </div>
                    <div class="monoova-bank-field">
                        <span class="field-label"><?php esc_html_e('BSB', 'monoova-payments-for-woocommerce'); ?></span>
                        <div class="field-value-wrapper">
                            <span class="copy-target" data-copy-id="bsb"><?php echo esc_html($details['bank_bsb']); ?></span>
                            <button type="button" class="monoova-copy-button" data-target-id="bsb" title="<?php esc_attr_e('Copy BSB', 'monoova-payments-for-woocommerce'); ?>">
                                <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M12.4868 9.675V12.825C12.4868 15.45 11.4368 16.5 8.81182 16.5H5.66182C3.03682 16.5 1.98682 15.45 1.98682 12.825V9.675C1.98682 7.05 3.03682 6 5.66182 6H8.81182C11.4368 6 12.4868 7.05 12.4868 9.675Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <path d="M16.9868 5.175V8.325C16.9868 10.95 15.9368 12 13.3118 12H12.4868V9.675C12.4868 7.05 11.4368 6 8.81182 6H6.48682V5.175C6.48682 2.55 7.53682 1.5 10.1618 1.5H13.3118C15.9368 1.5 16.9868 2.55 16.9868 5.175Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="monoova-bank-field full-width">
                    <span class="field-label"><?php esc_html_e('Account Number', 'monoova-payments-for-woocommerce'); ?></span>
                    <div class="field-value-wrapper">
                        <span class="copy-target" data-copy-id="account"><?php echo esc_html($details['bank_account_number']); ?></span>
                        <button type="button" class="monoova-copy-button" data-target-id="account" title="<?php esc_attr_e('Copy Account Number', 'monoova-payments-for-woocommerce'); ?>">
                            <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12.4868 9.675V12.825C12.4868 15.45 11.4368 16.5 8.81182 16.5H5.66182C3.03682 16.5 1.98682 15.45 1.98682 12.825V9.675C1.98682 7.05 3.03682 6 5.66182 6H8.81182C11.4368 6 12.4868 7.05 12.4868 9.675Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M16.9868 5.175V8.325C16.9868 10.95 15.9368 12 13.3118 12H12.4868V9.675C12.4868 7.05 11.4368 6 8.81182 6H6.48682V5.175C6.48682 2.55 7.53682 1.5 10.1618 1.5H13.3118C15.9368 1.5 16.9868 2.55 16.9868 5.175Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        </button>
                    </div>
                </div>

                <?php if ($show_reference_field) : ?>
                    <div class="monoova-payment-reference-section">
                        <h4><?php esc_html_e('Payment Reference', 'monoova-payments-for-woocommerce'); ?></h4>
                        <p><?php esc_html_e('Please use the following reference for your payment', 'monoova-payments-for-woocommerce'); ?></p>
                        <div class="monoova-bank-field full-width">
                            <div class="field-value-wrapper">
                                <span class="copy-target" data-copy-id="reference"><?php echo esc_html($details['reference'] . 'B'); ?></span>
                                <button type="button" class="monoova-copy-button" data-target-id="reference" title="<?php esc_attr_e('Copy Reference', 'monoova-payments-for-woocommerce'); ?>">
                                    <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M12.4868 9.675V12.825C12.4868 15.45 11.4368 16.5 8.81182 16.5H5.66182C3.03682 16.5 1.98682 15.45 1.98682 12.825V9.675C1.98682 7.05 3.03682 6 5.66182 6H8.81182C11.4368 6 12.4868 7.05 12.4868 9.675Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <path d="M16.9868 5.175V8.325C16.9868 10.95 15.9368 12 13.3118 12H12.4868V9.675C12.4868 7.05 11.4368 6 8.81182 6H6.48682V5.175C6.48682 2.55 7.53682 1.5 10.1618 1.5H13.3118C15.9368 1.5 16.9868 2.55 16.9868 5.175Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <p class="monoova-confirmation-text"><?php esc_html_e('You will receive a payment confirmation email once payment is received.', 'monoova-payments-for-woocommerce'); ?></p>

            <?php if ($expiry_timestamp > 0) : ?>
                <div id="monoova-expiry-info">
                    <span class="monoova-expiry-label"><?php esc_html_e('These payment details are valid until:', 'monoova-payments-for-woocommerce'); ?></span>
                    <span class="monoova-expiry-time"></span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Status Views: Confirmed, Rejected, Expired -->
        <div id="monoova-payment-confirmed" class="monoova-payment-status">
            <h3 class="title" style="color: #2CB5C5;"><?php esc_html_e('Payment Received', 'monoova-payments-for-woocommerce'); ?></h3>
            <p><?php esc_html_e('Thank you! Your payment has been confirmed and your order is now being processed.', 'monoova-payments-for-woocommerce'); ?></p>
            <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="button"><?php esc_html_e('View Order Details', 'monoova-payments-for-woocommerce'); ?></a>
        </div>
        <div id="monoova-payment-rejected" class="monoova-payment-status">
            <h3 class="title failure" style="color: #FF2525;"><?php esc_html_e('Payment Issue', 'monoova-payments-for-woocommerce'); ?></h3>
            <p><?php esc_html_e('There was an issue with your payment. Please check your bank account or contact us for assistance.', 'monoova-payments-for-woocommerce'); ?></p>
            <p id="monoova-rejection-reason" style="font-style: italic; margin-top: 15px;"></p>
        </div>
        <div id="monoova-payment-expired" class="monoova-payment-status">
            <h3 class="title" style="color: #FF782A;"><?php esc_html_e('Payment Expired', 'monoova-payments-for-woocommerce'); ?></h3>
            <p><?php esc_html_e('The payment window for this order has expired. Please place a new order.', 'monoova-payments-for-woocommerce'); ?></p>
        </div>
    </section>
</div>