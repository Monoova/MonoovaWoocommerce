import { useState, useEffect, useRef } from '@wordpress/element';
import { RadioControl, Button } from '@wordpress/components';
import { NavigationCountdown } from './NavigationCountdown';

// QR Code generation component (simplified version)
const QRCodeDisplay = ({ payload, size = 180 }) => {
    const qrRef = useRef(null);

    useEffect(() => {
        if (!payload || !qrRef.current || typeof window.QRCode === 'undefined') return;

        // Clear any previous QR code
        qrRef.current.innerHTML = '';
        
        new window.QRCode(qrRef.current, {
            text: payload,
            width: size,
            height: size,
            colorDark: '#000000',
            colorLight: '#ffffff',
            correctLevel: window.QRCode?.CorrectLevel?.H
        });
    }, [payload, size]);

    return <div ref={qrRef} id="monoova-qr-code" />;
};

// Copy button component
const CopyButton = ({ text, onCopy }) => {
    const [copied, setCopied] = useState(false);

    const handleCopy = async () => {
        try {
            await navigator.clipboard.writeText(text);
            setCopied(true);
            onCopy && onCopy();
            setTimeout(() => setCopied(false), 2000);
        } catch (err) {
            console.error('Failed to copy:', err);
        }
    };

    return (
        <button 
            type="button"
            className="monoova-copy-button" 
            onClick={handleCopy}
            title={copied ? 'Copied!' : 'Copy'}
        >
            <svg width="19" height="18" viewBox="0 0 19 18" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12.4868 9.675V12.825C12.4868 15.45 11.4368 16.5 8.81182 16.5H5.66182C3.03682 16.5 1.98682 15.45 1.98682 12.825V9.675C1.98682 7.05 3.03682 6 5.66182 6H8.81182C11.4368 6 12.4868 7.05 12.4868 9.675Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                <path d="M16.9868 5.175V8.325C16.9868 10.95 15.9368 12 13.3118 12H12.4868V9.675C12.4868 7.05 11.4368 6 8.81182 6H6.48682V5.175C6.48682 2.55 7.53682 1.5 10.1618 1.5H13.3118C15.9368 1.5 16.9868 2.55 16.9868 5.175Z" stroke="#2CB5C5" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </button>
    );
};

// Countdown timer component
const CountdownTimer = ({ expiryTimestamp, onExpired, strings }) => {
    const [timeLeft, setTimeLeft] = useState('');

    useEffect(() => {
        if (!expiryTimestamp || expiryTimestamp <= 0) {
            setTimeLeft('');
            return;
        }

        const calculateTimeLeft = () => {
            const distance = expiryTimestamp * 1000 - new Date().getTime();
            
            if (distance < 0) {
                setTimeLeft('');
                onExpired && onExpired();
                return null;
            }

            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            let timeArr = [];
            if (days > 0) timeArr.push(`${days} ${days === 1 ? "day" : "days"}`);
            if (hours > 0) timeArr.push(`${hours} ${hours === 1 ? "hour" : "hours"}`);
            if (minutes > 0) timeArr.push(`${minutes} ${minutes === 1 ? "minute" : "minutes"}`);
            if (seconds > 0) timeArr.push(`${seconds} ${seconds === 1 ? "second" : "seconds"}`);
            
            return `(${timeArr.join(" and ")} remaining)`;
        };

        // Set initial value
        const initialTime = calculateTimeLeft();
        if (initialTime) {
            setTimeLeft(initialTime);
        }

        const interval = setInterval(() => {
            const newTime = calculateTimeLeft();
            if (newTime) {
                setTimeLeft(newTime);
            } else {
                clearInterval(interval);
            }
        }, 1000);

        return () => clearInterval(interval);
    }, [expiryTimestamp, onExpired]);

    if (!timeLeft || expiryTimestamp < Date.now() / 1000) return null;

    return (
        <div id="monoova-expiry-info" className="monoova-expiry-info">
            <span className="monoova-expiry-label">{strings.expires_in}</span>
            <span className="monoova-expiry-time">
                <strong>{new Date(expiryTimestamp * 1000).toLocaleString()} {timeLeft}</strong>
            </span>
        </div>
    );
};

// Main PayID instructions component
export const PayIDInstructionsContainer = ({ 
    instructions, 
    settings, 
    paymentStatus = 'pending',
    expiryTime,
    paymentFailedReason = null,
    onPaymentStatusChange,
    onRegenerateInstructions, // New prop for regeneration
    orderId = null // Order ID for generating order received URL
}) => {
    const [selectedMethod, setSelectedMethod] = useState('payid');
    
    if (!instructions) return null;

    const { details, view_order_url } = instructions;
    const showPayID = settings.payment_types.includes('payid') && details.payid_value;
    const showBank = settings.payment_types.includes('bank_transfer') && details.bank_bsb && details.bank_account_number;
    const showMethodSwitcher = showPayID && showBank;

    // Default to first available method
    useEffect(() => {
        if (showPayID && selectedMethod !== 'payid' && selectedMethod !== 'bank_transfer') {
            setSelectedMethod('payid');
        } else if (!showPayID && showBank) {
            setSelectedMethod('bank_transfer');
        }
    }, [showPayID, showBank, selectedMethod]);

    const handleExpired = () => {
        onPaymentStatusChange && onPaymentStatusChange('expired');
    };

    const handlePlaceNewOrder = () => {
        if (onRegenerateInstructions) {
            onRegenerateInstructions();
        }
        onPaymentStatusChange('pending');
    };

    const handlePayAgain = () => {
        // Just reset the UI to show the instructions again without regenerating
        onPaymentStatusChange('pending');
    };

    // Generate order received URL with order ID and key
    const generateOrderReceivedUrl = () => {
        if (view_order_url) {
            return view_order_url;
        }
        
        // Get order key from the current URL if available
        const urlParams = new URLSearchParams(window.location.search);
        const orderKey = urlParams.get('key');
        
        // Construct the order received URL with order ID and key
        let orderReceivedUrl = settings.order_received_url;
        if (orderReceivedUrl.includes('?')) {
            orderReceivedUrl += `&order-received=${orderId}`;
        } else {
            orderReceivedUrl += `?order-received=${orderId}`;
        }
        
        if (orderKey) {
            orderReceivedUrl += `&key=${orderKey}`;
        }
        
        return orderReceivedUrl;
    };

    const renderPaymentStatus = () => {
        switch (paymentStatus) {
            case 'paid':
                return (
                    <div id="monoova-payment-confirmed" className="monoova-payment-status">
                        <div>
                            <svg width="45" height="46" viewBox="0 0 45 46" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M31.8167 18.8513L21.0413 29.6267C20.6983 29.9697 20.2527 30.14 19.8047 30.14C19.3543 30.14 18.9087 29.9697 18.5657 29.6267L13.178 24.239C12.4943 23.5553 12.4943 22.447 13.178 21.7633C13.8617 21.0797 14.9677 21.0797 15.6513 21.7633L19.8047 25.9143L29.341 16.3757C30.0247 15.692 31.133 15.692 31.8167 16.3757C32.5003 17.0593 32.5003 18.1677 31.8167 18.8513ZM22.4997 0.833344C10.2777 0.833344 0.333008 10.778 0.333008 23C0.333008 35.2243 10.2777 45.1667 22.4997 45.1667C34.7217 45.1667 44.6663 35.2243 44.6663 23C44.6663 10.778 34.7217 0.833344 22.4997 0.833344Z" fill="#2CB5C5"/>
                            </svg>
                        </div>
                        <h3 className="title">{settings.strings.payment_confirmed}</h3>
                        <p>{settings.strings.payment_confirmed_message}</p>
                        <div className="monoova-payment-status-actions">
                            <Button variant="primary" href={generateOrderReceivedUrl()}>
                                {settings.strings.view_order_details}
                            </Button>
                        </div>
                        <NavigationCountdown 
                            initialSeconds={5}
                            redirectUrl={generateOrderReceivedUrl()}
                            message={settings.strings.redirecting_to_order_page}
                        />
                    </div>
                );
            case 'expired':
                return (
                    <div id="monoova-payment-expired" className="monoova-payment-status">
                        <div>
                            <svg width="45" height="46" viewBox="0 0 45 46" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M24.2647 31.617C24.2647 32.583 23.4807 33.367 22.5147 33.367C21.5487 33.367 20.7647 32.583 20.7647 31.617V21.586C20.7647 20.62 21.5487 19.836 22.5147 19.836C23.4807 19.836 24.2647 20.62 24.2647 21.586V31.617ZM20.7507 14.3456C20.7507 13.3796 21.5347 12.5956 22.5007 12.5956C23.4667 12.5956 24.2507 13.3796 24.2507 14.3456C24.2507 15.3116 23.4667 16.1703 22.5007 16.1703C21.5347 16.1703 20.7507 15.4586 20.7507 14.4926V14.3456ZM22.5007 0.833313C10.2787 0.833313 0.333984 10.7756 0.333984 23C0.333984 35.222 10.2787 45.1666 22.5007 45.1666C34.7227 45.1666 44.6673 35.222 44.6673 23C44.6673 10.7756 34.7227 0.833313 22.5007 0.833313Z" fill="#FF782A"/>
                            </svg>

                        </div>
                        <h3 className="title">{settings.strings.payment_expired}</h3>
                        <p>{settings.strings.payment_expired_message}</p>
                        <div className="monoova-payment-status-actions">
                            <Button variant="primary" onClick={() => handlePlaceNewOrder()}>
                                {settings.strings.place_new_order}
                            </Button>
                        </div>
                    </div>
                );
            case 'failed':
                return (
                    <div id="monoova-payment-rejected" className="monoova-payment-status">
                        <div>
                            <svg width="45" height="46" viewBox="0 0 45 46" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd" clip-rule="evenodd" d="M24.2647 31.617C24.2647 32.583 23.4807 33.367 22.5147 33.367C21.5487 33.367 20.7647 32.583 20.7647 31.617V21.586C20.7647 20.62 21.5487 19.836 22.5147 19.836C23.4807 19.836 24.2647 20.62 24.2647 21.586V31.617ZM20.7507 14.3456C20.7507 13.3796 21.5347 12.5956 22.5007 12.5956C23.4667 12.5956 24.2507 13.3796 24.2507 14.3456C24.2507 15.3116 23.4667 16.1703 22.5007 16.1703C21.5347 16.1703 20.7507 15.4586 20.7507 14.4926V14.3456ZM22.5007 0.833313C10.2787 0.833313 0.333984 10.7756 0.333984 23C0.333984 35.222 10.2787 45.1666 22.5007 45.1666C34.7227 45.1666 44.6673 35.222 44.6673 23C44.6673 10.7756 34.7227 0.833313 22.5007 0.833313Z" fill="#FF0000"/>
                            </svg>
                        </div>
                        <h3 className="title">{settings.strings.payment_failed}</h3>
                        <p>{settings.strings.payment_failed_message}</p>
                        <p id="monoova-rejection-reason" style={{ fontStyle: 'italic', marginTop: 15 }}>
                            Reason: <span dangerouslySetInnerHTML={{ __html: paymentFailedReason }} />
                        </p>
                        <div className="monoova-payment-status-actions">
                            <Button variant="primary" onClick={() => handlePayAgain()}>
                                {settings.strings.try_again}
                            </Button>
                        </div>
                    </div>
                );
            default:
                return null;
        }
    };

    if (paymentStatus !== 'pending' && paymentStatus !== 'initial') {
        return (
            <div className="monoova-payid-bank-transfer-instructions-wrapper">
                <div className="monoova-instructions-container">
                    {renderPaymentStatus()}
                </div>
            </div>
        );
    }

    return (
        <div className="monoova-payid-bank-transfer-instructions-wrapper">
            {
                showMethodSwitcher && (
                    <div className="monoova-instruction-method-selection">
                        <div style={{ fontWeight: 600 }}>
                            {settings.strings.pay_with}
                        </div>
                        <RadioControl
                            selected={selectedMethod}
                            options={[
                                { label: settings.strings.payid_method, value: 'payid' },
                                { label: settings.strings.bank_method, value: 'bank_transfer' },
                            ]}
                            onChange={(v) => setSelectedMethod(v)}
                        />
                    </div>
                )
            }

            <div>{settings.strings.payment_instructions_description}</div>
            <div className="monoova-instructions-container">
                <div id="monoova-payment-pending">

                    {/* Instructions */}
                    {settings.instructions && (
                        <p
                            dangerouslySetInnerHTML={{ __html: settings.instructions }}
                        />
                    )}

                    {/* PayID View */}
                    {selectedMethod === 'payid' && showPayID && (
                        <div id="monoova-payid-view">
                            <div className="monoova-scan-pay">

                                <div
                                    className="pay-label"
                                    style={{
                                        fontSize: settings.payid_checkout_ui_styles?.scan_pay?.pay_label?.font_size || '28px',
                                        fontWeight: settings.payid_checkout_ui_styles?.scan_pay?.pay_label?.font_weight || '700',
                                        color: settings.payid_checkout_ui_styles?.scan_pay?.pay_label?.color || '#000000',
                                    }}
                                >
                                    {settings.strings.scan_pay}
                                </div>
                                <div
                                    className="amount"
                                    style={{
                                        fontSize: settings.payid_checkout_ui_styles?.scan_pay?.amount?.font_size || '28px',
                                        fontWeight: settings.payid_checkout_ui_styles?.scan_pay?.amount?.font_weight || '700',
                                        color: settings.payid_checkout_ui_styles?.scan_pay?.amount?.color || '#2CB5C5',
                                    }}
                                    dangerouslySetInnerHTML={{ __html: details.amount_to_pay_formatted }}
                                />

                            </div>
                            <p>{settings.strings.scan_pay_description}</p>
                            {/* {details.qr_code_payload && (
                                <QRCodeDisplay payload={details.qr_code_payload} />
                            )} */}

                            {/* {settings.show_reference_field && (
                                <div className="monoova-reference-area">
                                    <span className="ref-label">{settings.strings.reference}:</span>
                                    <span className="ref-value">{details.reference + 'P'}</span>
                                </div>
                            )} */}

                            {/* <div className="monoova-divider">{settings.strings.or_divider}</div> */}

                            <div className="monoova-manual-pay">
                                <img 
                                    src={`${settings.plugin_url}assets/images/payid-logo.svg`} 
                                    alt="PayID" 
                                    className="payid-logo" 
                                />
                                <span className="copy-target" data-copy-id="payid">
                                    {details.payid_value}
                                </span>
                                <CopyButton text={details.payid_value} />
                            </div>

                            {settings.show_reference_field && (
                                <div className="monoova-payment-reference-section">
                                    <h4>{settings.strings.payment_reference}</h4>
                                    <p>{settings.strings.include_reference_with_payment}</p>
                                    <div className="monoova-reference-field full-width">
                                        <div className="field-label">{settings.strings.reference}</div>
                                        <div className="field-value-wrapper">
                                            <span className="copy-target" data-copy-id="reference">
                                                {details.reference + 'P'} {/* Append 'P' for PayID */}
                                            </span>
                                            <CopyButton text={details.reference + 'P'} />
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Bank View */}
                    {selectedMethod === 'bank_transfer' && showBank && (
                        <div id="monoova-bank-view">
                            <h3>{settings.strings.bank_transfer_details}</h3>
                            
                            <div className="monoova-bank-field-grid">
                                <div className="monoova-bank-field">
                                    <div className="field-label">{settings.strings.account_name}</div>
                                    <div className="field-value-wrapper">
                                        <span className="copy-target" data-copy-id="account-name">
                                            {details.bank_account_name}
                                        </span>
                                        {/* <CopyButton text={details.bank_account_name} /> */}
                                    </div>
                                </div>

                                <div className="monoova-bank-field">
                                    <div className="field-label">{settings.strings.bsb}</div>
                                    <div className="field-value-wrapper">
                                        <span className="copy-target" data-copy-id="bsb">
                                            {details.bank_bsb}
                                        </span>
                                        <CopyButton text={details.bank_bsb} />
                                    </div>
                                </div>
                            </div>
                            <div className="monoova-bank-field full-width">
                                <div className="field-label">{settings.strings.account_number}</div>
                                <div className="field-value-wrapper">
                                    <span className="copy-target" data-copy-id="account-number">
                                        {details.bank_account_number}
                                    </span>
                                    <CopyButton text={details.bank_account_number} />
                                </div>
                            </div>

                            {settings.show_reference_field && (
                                <div className="monoova-payment-reference-section">
                                    <h4>{settings.strings.payment_reference}</h4>
                                    <p>{settings.strings.include_reference_with_payment}</p>
                                    <div className="monoova-bank-field full-width">
                                        <div className="field-label">{settings.strings.reference}</div>
                                        <div className="field-value-wrapper">
                                            <span className="copy-target" data-copy-id="reference">
                                                {details.reference + 'B'} {/* Append 'B' for Bank Transfer */}
                                            </span>
                                            <CopyButton text={details.reference + 'B'} />
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}

                    <div className="monoova-confirmation-text">
                        <p>{settings.strings.payment_confirmed_automatically}</p>
                    </div>

                    {expiryTime && (
                        <CountdownTimer 
                            expiryTimestamp={expiryTime}
                            onExpired={handleExpired}
                            strings={settings.strings}
                        />
                    )}
                </div>
            </div>
        </div>
    );
};
