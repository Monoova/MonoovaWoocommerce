/**
 * Monoova Payment Settings - React-based Admin Interface
 */

import { useState, useEffect, useCallback, useMemo, memo } from "@wordpress/element"
import {
    TabPanel,
    Card,
    CardBody,
    CheckboxControl,
    TextControl,
    TextareaControl,
    SelectControl,
    __experimentalGrid as Grid,
    __experimentalText as Text,
    __experimentalHeading as Heading,
    Button,
    Notice,
    PanelRow,
    BaseControl,
    __experimentalDivider as Divider,
    __experimentalVStack as VStack,
    __experimentalHStack as HStack,
    __experimentalSpacer as Spacer,
    Flex,
    ColorPicker,
    Popover,
    Spinner,
} from "@wordpress/components"
import { __ } from "@wordpress/i18n"

// Info Icon component with hover popover
const InfoIcon = memo(({ type }) => {
    const [isHovered, setIsHovered] = useState(false)

    const getPopoverContent = () => {
        switch (type) {
            case "label":
                return (
                    <div style={{ padding: "24px 16px", width: "300px" }}>
                        <div
                            style={{
                                fontSize: "14px",
                                fontWeight: "500",
                                marginBottom: "16px",
                            }}>
                            Customize the Field of the card details
                        </div>
                        <div
                            style={{
                                backgroundColor: "#FAFAFA",
                                padding: "16px 12px",
                            }}>
                            <div
                                style={{
                                    fontSize: "14px",
                                    fontWeight: "500",
                                    color: "#000000",
                                    marginBottom: "4px",
                                    margin: "-6px",
                                    border: "2px solid #FF4E4E",
                                    padding: "6px",
                                    borderRadius: "4px",
                                    display: "inline-block",
                                }}>
                                Card number
                            </div>
                            <div
                                style={{
                                    border: "1px solid #d1d5db",
                                    borderRadius: "8px",
                                    padding: "10px",
                                    fontSize: "14px",
                                    color: "#999",
                                }}>
                                1234 5678 9012 3456
                            </div>
                        </div>
                    </div>
                )
            case "scan_pay":
                return (
                    <div style={{ padding: "24px 16px", width: "300px" }}>
                        <div
                            style={{
                                fontSize: "14px",
                                fontWeight: "500",
                                marginBottom: "16px",
                            }}>
                            Customize the Scan Pay Display elements
                        </div>
                        <div
                            style={{
                                backgroundColor: "#FAFAFA",
                                padding: "16px 12px",
                                textAlign: "center",
                            }}>
                            <div
                                style={{
                                    fontSize: "28px",
                                    fontWeight: "700",
                                    marginBottom: "8px",
                                }}>
                                <span style={{ color: "#000000" }}>Pay </span>
                                <span style={{ color: "#2CB5C5" }}>$10.00</span>
                            </div>
                        </div>
                    </div>
                )
            case "input":
                return (
                    <div style={{ padding: "24px 16px", width: "300px" }}>
                        <div
                            style={{
                                fontSize: "14px",
                                fontWeight: "500",
                                marginBottom: "16px",
                            }}>
                            Customize the Field of the card details
                        </div>
                        <div
                            style={{
                                backgroundColor: "#FAFAFA",
                                padding: "16px 12px",
                            }}>
                            <div
                                style={{
                                    fontSize: "14px",
                                    fontWeight: "500",
                                    color: "#000000",
                                    marginBottom: "4px",
                                }}>
                                Card number
                            </div>
                            <div
                                style={{
                                    border: "2px solid #FF4E4E",
                                    borderRadius: "4px",
                                    padding: "6px",
                                    margin: "-6px",
                                }}>
                                <div
                                    style={{
                                        border: "1px solid #d1d5db",
                                        borderRadius: "8px",
                                        padding: "10px",
                                        fontSize: "14px",
                                        color: "#999",
                                    }}>
                                    1234 5678 9012 3456
                                </div>
                            </div>
                        </div>
                    </div>
                )
            case "button":
                return (
                    <div style={{ padding: "24px 16px", width: "300px" }}>
                        <div
                            style={{
                                backgroundColor: "#2ab5c4",
                                borderRadius: "10px",
                                padding: "12px 24px",
                                textAlign: "center",
                                fontSize: "17px",
                                fontWeight: "bold",
                                color: "#000000",
                                cursor: "pointer",
                            }}>
                            Pay
                        </div>
                    </div>
                )
            default:
                return null
        }
    }

    return (
        <div style={{ position: "relative", display: "inline-block" }}>
            <div
                onMouseEnter={() => setIsHovered(true)}
                onMouseLeave={() => setIsHovered(false)}
                style={{
                    width: "13px",
                    height: "13px",
                    borderRadius: "50%",
                    backgroundColor: "#D4D4D4",
                    color: "white",
                    display: "flex",
                    alignItems: "center",
                    justifyContent: "center",
                    fontSize: "8px",
                    fontWeight: "bold",
                    cursor: "help",
                }}>
                i
            </div>

            {isHovered && (
                <Popover position="top right" noArrow={false} onClose={() => setIsHovered(false)}>
                    {getPopoverContent()}
                </Popover>
            )}
        </div>
    )
})

// Stable TextControl that prevents focus loss through proper memoization
const StableTextControl = memo(({ value, onChange, error, ...props }) => {
    const className = error ? "has-error" : ""
    return (
        <div>
            <TextControl
                {...props}
                value={value || ""}
                onChange={onChange}
                className={className}
                style={error ? { borderColor: "#d63638" } : {}}
            />
            {error && (
                <Text color="#d63638" size="12" style={{ marginTop: "4px", display: "block" }}>
                    {error}
                </Text>
            )}
        </div>
    )
})

// Stable TextareaControl that prevents focus loss through proper memoization
const StableTextareaControl = memo(({ value, onChange, ...props }) => {
    return <TextareaControl {...props} value={value || ""} onChange={onChange} />
})

// Form field wrapper component similar to Stripe's layout
const FormField = memo(({ label, description, required = false, children }) => (
    <BaseControl className="monoova-form-field">
        <VStack spacing={2}>
            <Flex align="center" justify="flex-start" gap={1}>
                <Text weight="500" size="14" color="#1e1e1e">
                    {label}
                </Text>
                {required && (
                    <Text color="#d63638" size="14">
                        *
                    </Text>
                )}
            </Flex>
            {children}
            {description && (
                <Text variant="muted" size="13" lineHeight="1.4">
                    {description}
                </Text>
            )}
        </VStack>
    </BaseControl>
))

// Color field component with ColorPicker popup
const ColorField = memo(({ label, description, value, onChange, disabled = false }) => {
    const [isOpen, setIsOpen] = useState(false)

    return (
        <FormField label={label} description={description}>
            <div style={{ position: "relative" }}>
                <Button
                    //variant="secondary"
                    disabled={disabled}
                    onClick={() => setIsOpen(!isOpen)}
                    style={{
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "space-between",
                        gap: "8px",
                        padding: "10px",
                        border: "1px solid #d1d5db",
                        borderRadius: "8px",
                        background: "#fff",
                        cursor: disabled ? "not-allowed" : "pointer",
                        width: "100%",
                        height: "45px",
                    }}>
                    <span>{value || "#000000"}</span>
                    <div
                        style={{
                            width: "25px",
                            height: "25px",
                            borderRadius: "8px",
                            backgroundColor: value || "#000000",
                            border: "1px solid #ddd",
                        }}
                    />
                </Button>
                {isOpen && (
                    <Popover position="bottom left" onClose={() => setIsOpen(false)} noArrow={false}>
                        <div style={{ padding: "16px" }}>
                            <ColorPicker color={value || "#000000"} onChange={onChange} enableAlpha={false} />
                        </div>
                    </Popover>
                )}
            </div>
        </FormField>
    )
})

// Webhook Configuration Component
const WebhookConfigurationSection = memo(({ mode, modeLabel, status, onCheckStatus, onSubscribe }) => (
    <Card>
        <CardBody>
            <VStack spacing={4}>
                <Text size="14" color="#374151">
                    {__(
                        `Configure ${modeLabel} webhook notifications to receive real-time payment and transaction status updates from Monoova.`,
                        "monoova-payments-for-woocommerce"
                    )}
                </Text>

                {/* Show validation error if credentials are missing */}
                {status?.validationError && (
                    <Notice status="warning" isDismissible={false}>
                        <Text size="14" color="#B45309">
                            {status.validationError}
                        </Text>
                    </Notice>
                )}

                <Flex align="center" justify="space-between" gap={3} style={{ width: "100%" }}>
                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                        <Text weight="600" size="14">
                            {__(`${modeLabel} webhook notifications`, "monoova-payments-for-woocommerce")}
                        </Text>
                        <div className={`monoova-webhook-status-chip ${status?.all_active ? "active" : "inactive"}`}>
                            {status?.isChecking ? (
                                <Flex align="center" gap={2}>
                                    <Spinner style={{ width: "14px", height: "14px", margin: 0 }} />
                                    <span>{__("Checking", "monoova-payments-for-woocommerce")}</span>
                                </Flex>
                            ) : status?.all_active ? (
                                __("Active", "monoova-payments-for-woocommerce")
                            ) : (
                                __("Inactive", "monoova-payments-for-woocommerce")
                            )}
                        </div>
                    </Flex>
                    <div>
                        <Button
                            variant="primary"
                            style={{ justifyContent: "center" }}
                            onClick={onSubscribe}
                            isBusy={status?.isConnecting}
                            disabled={
                                status?.isConnecting ||
                                status?.isChecking ||
                                status?.all_active ||
                                status?.validationError
                            }>
                            {status?.isConnecting ? (
                                <Flex align="center" gap={2}>
                                    <Spinner style={{ width: "14px", height: "14px", margin: 0 }} />
                                    <span>{__("Connecting", "monoova-payments-for-woocommerce")}</span>
                                </Flex>
                            ) : status?.all_active ? (
                                __("Connected", "monoova-payments-for-woocommerce")
                            ) : (
                                __("Connect", "monoova-payments-for-woocommerce")
                            )}
                        </Button>
                    </div>
                </Flex>
            </VStack>
        </CardBody>
    </Card>
))

// Tab components defined outside main component to prevent recreation on re-renders
const GeneralSettingsTab = memo(
    ({
        settings,
        saveNotice,
        onChangeHandlers,
        setSaveNotice,
        validationErrors = {},
        webhookStatus,
        onCheckWebhookStatus,
        onSubscribeToWebhooks,
    }) => (
        <VStack spacing={6} className="monoova-general-settings-tab">
            {saveNotice && (
                <Notice
                    className="monoova-save-notice"
                    status={saveNotice.type}
                    onRemove={() => setSaveNotice(null)}
                    isDismissible={true}>
                    {saveNotice.message}
                </Notice>
            )}

            {/* Basic Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Basic Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__("Configure basic payment gateway settings.", "monoova-payments-for-woocommerce")}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.enabled}
                                            onChange={value => {
                                                onChangeHandlers.enabled(value)
                                                // When unified gateway is disabled, also disable child gateways
                                                if (!value) {
                                                    onChangeHandlers.enable_card_payments(false)
                                                    onChangeHandlers.enable_payid_payments(false)
                                                    onChangeHandlers.enable_payto_payments(false)
                                                }
                                            }}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Enable Monoova Payments", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Enable this payment gateway to accept payments.",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* Account Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Account Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__("Configure your Monoova account details.", "monoova-payments-for-woocommerce")}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <FormField
                                label={__("Monoova mAccount Number", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "Your general Monoova mAccount number for transactions.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                required={true}>
                                <StableTextControl
                                    value={settings.maccount_number || ""}
                                    onChange={onChangeHandlers.maccount_number}
                                    placeholder={__("Enter M-Account number", "monoova-payments-for-woocommerce")}
                                />
                            </FormField>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* API Credentials */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("API Credentials", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__("Secure API keys for connecting to Monoova services.", "monoova-payments-for-woocommerce")}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <Grid columns={2} gap={4}>
                                    <FormField
                                        label={__("Test API Key", "monoova-payments-for-woocommerce")}
                                        description={__(
                                            "Get your Test API key from your Monoova dashboard.",
                                            "monoova-payments-for-woocommerce"
                                        )}>
                                        <StableTextControl
                                            value={settings.test_api_key || ""}
                                            onChange={onChangeHandlers.test_api_key}
                                            type="password"
                                            placeholder={__("Enter test API key", "monoova-payments-for-woocommerce")}
                                        />
                                    </FormField>

                                    <FormField
                                        label={__("Live API Key", "monoova-payments-for-woocommerce")}
                                        description={__(
                                            "Get your Live API key from your Monoova dashboard.",
                                            "monoova-payments-for-woocommerce"
                                        )}>
                                        <StableTextControl
                                            value={settings.live_api_key || ""}
                                            onChange={onChangeHandlers.live_api_key}
                                            type="password"
                                            placeholder={__("Enter live API key", "monoova-payments-for-woocommerce")}
                                            error={validationErrors.live_api_key}
                                        />
                                    </FormField>
                                </Grid>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* API URLs - Advanced */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("API URLs (Advanced)", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Override default API URLs if needed. Leave blank to use defaults.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <Grid columns={2} gap={4}>
                                    <FormField
                                        label={__("PayID API URL (Sandbox)", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={
                                                settings.monoova_payments_api_url_sandbox || "https://api.m-pay.com.au"
                                            }
                                            onChange={onChangeHandlers.monoova_payments_api_url_sandbox}
                                            placeholder="https://api.m-pay.com.au"
                                        />
                                    </FormField>

                                    <FormField label={__("PayID API URL (Live)", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={settings.monoova_payments_api_url_live || "https://api.mpay.com.au"}
                                            onChange={onChangeHandlers.monoova_payments_api_url_live}
                                            placeholder="https://api.mpay.com.au"
                                            error={validationErrors.monoova_payments_api_url_live}
                                        />
                                    </FormField>
                                </Grid>

                                <Grid columns={2} gap={4}>
                                    <FormField label={__("Card API URL (Sandbox)", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={
                                                settings.monoova_card_api_url_sandbox || "https://sand-api.monoova.com"
                                            }
                                            onChange={onChangeHandlers.monoova_card_api_url_sandbox}
                                            placeholder="https://sand-api.monoova.com"
                                        />
                                    </FormField>

                                    <FormField label={__("Card API URL (Live)", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={settings.monoova_card_api_url_live || "https://api.monoova.com"}
                                            onChange={onChangeHandlers.monoova_card_api_url_live}
                                            placeholder="https://api.monoova.com"
                                            error={validationErrors.monoova_card_api_url_live}
                                        />
                                    </FormField>
                                </Grid>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* Webhook Configuration - Sandbox */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>
                        {__("Webhook Configuration - Sandbox mode", "monoova-payments-for-woocommerce")}
                    </Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure SANDBOX webhook notifications for testing. You MUST subscribe to all webhook events to receive payment and transaction status notifications from Monoova.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <WebhookConfigurationSection
                        mode="sandbox"
                        modeLabel="Sandbox"
                        status={webhookStatus?.sandbox}
                        onCheckStatus={() => onCheckWebhookStatus(true)}
                        onSubscribe={() => onSubscribeToWebhooks(true)}
                    />
                </VStack>
            </Grid>

            {/* Webhook Configuration - Live */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>
                        {__("Webhook Configuration - Live mode", "monoova-payments-for-woocommerce")}
                    </Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure LIVE webhook notifications for production. You MUST subscribe to all webhook events to receive payment and transaction status notifications from Monoova.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <WebhookConfigurationSection
                        mode="live"
                        modeLabel="Live"
                        status={webhookStatus?.live}
                        onCheckStatus={() => onCheckWebhookStatus(false)}
                        onSubscribe={() => onSubscribeToWebhooks(false)}
                    />
                </VStack>
            </Grid>
        </VStack>
    )
)

// Payment Method Option Component (Stripe-like)
const PaymentMethodOption = memo(({ label, description, icons, checked, onChange, disabled = false }) => (
    <PanelRow>
        <Flex justify="flex-start" align="center" gap={3}>
            <CheckboxControl checked={checked} onChange={onChange} disabled={disabled} />

            <VStack spacing={1}>
                <Text size="14" weight="500" color={disabled ? "#757575" : "#1e1e1e"}>
                    <Flex align="center" justify="flex-start" gap={1}>
                        <span style={{ marginRight: "8px" }}>{label}</span>
                        {icons &&
                            icons.map((icon, index) => (
                                <img key={index} src={icon.src} alt={icon.alt} width="24" height="16" />
                            ))}
                    </Flex>
                </Text>
                {description && (
                    <Text size="12" color="#757575" lineHeight="1.4">
                        {description}
                    </Text>
                )}
            </VStack>
        </Flex>
    </PanelRow>
))

const PaymentMethodsTab = memo(({ settings, saveNotice, onChangeHandlers, setSaveNotice }) => (
    <VStack spacing={6} className="monoova-payment-methods-tab">
        {saveNotice && (
            <Notice status={saveNotice.type} onRemove={() => setSaveNotice(null)}>
                {saveNotice.message}
            </Notice>
        )}

        {/* Payments accepted on checkout */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Payments accepted on checkout", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__(
                        "Select payments available to customers at checkout. Based on their device type, location, and purchase history, your customers will only see the most relevant payment methods.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <PaymentMethodOption
                                label={__("Credit / debit card", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "Let your customers pay with major credit and debit cards without leaving your store.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                icons={[
                                    { src: `${window.monoovaPluginUrl || ""}assets/images/visa.png`, alt: "Visa" },
                                    {
                                        src: `${window.monoovaPluginUrl || ""}assets/images/mastercard.png`,
                                        alt: "Mastercard",
                                    },
                                ]}
                                checked={settings.enable_card_payments}
                                onChange={onChangeHandlers.enable_card_payments}
                            />

                            <PaymentMethodOption
                                label={__("PayID / Bank Transfer", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "Allow customers to pay using PayID or direct bank transfer with real-time payment confirmation.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                icons={[
                                    {
                                        src: `${window.monoovaPluginUrl || ""}assets/images/payid-logo.png`,
                                        alt: "PayID",
                                    },
                                    {
                                        src: `${window.monoovaPluginUrl || ""}assets/images/bank-transfer.png`,
                                        alt: "Bank Transfer",
                                    },
                                ]}
                                checked={settings.enable_payid_payments}
                                onChange={onChangeHandlers.enable_payid_payments}
                            />
                            <PaymentMethodOption
                                label={__("PayTo", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "Customer approves a payment agreement.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                icons={[
                                    {
                                        src: `${window.monoovaPluginUrl || ""}assets/images/payto-logo.svg`,
                                        alt: "PayTo",
                                    },
                                ]}
                                checked={settings.enable_payto_payments}
                                onChange={onChangeHandlers.enable_payto_payments}
                                disabled={!settings.enabled}
                            />
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>

        {/* Express checkouts */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Express checkouts", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__(
                        "Let your customers use their favorite express checkout methods.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <PaymentMethodOption
                                label={__(
                                    "Express checkout by credit / debit card",
                                    "monoova-payments-for-woocommerce"
                                )}
                                description={__(
                                    "Allow customers to skip the checkout form with saved card payment details.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                icons={[
                                    {
                                        src: `${window.monoovaPluginUrl || ""}assets/images/cards.png`,
                                        alt: "Express Checkout",
                                    },
                                ]}
                                checked={settings.enable_express_checkout}
                                onChange={onChangeHandlers.enable_express_checkout}
                                disabled={!settings.enable_card_payments}
                            />

                            <PaymentMethodOption
                                label={__("Express checkout by PayTo agreement", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "Allow customers to skip the checkout form with saved PayTo agreements.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                icons={[
                                    {
                                        src: `${window.monoovaPluginUrl || ""}assets/images/bank-transfer.png`,
                                        alt: "PayTo Express Checkout",
                                    },
                                ]}
                                checked={settings.enable_payto_express_checkout}
                                onChange={onChangeHandlers.enable_payto_express_checkout}
                                disabled={!settings.enable_payto_payments}
                            />

                            {/* Express Checkout Method Priority */}
                            {settings.enable_express_checkout && settings.enable_payto_express_checkout && (
                                <Card>
                                    <CardBody>
                                        <VStack spacing={3}>
                                            <Heading level={5}>
                                                {__(
                                                    "Express Checkout Method Priority",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Heading>
                                            <Text variant="muted" size="14">
                                                {__(
                                                    "Choose which express checkout method to show first when both Card and PayTo are available.",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                            <SelectControl
                                                value={settings.express_checkout_method_priority || "card"}
                                                onChange={onChangeHandlers.express_checkout_method_priority}
                                                options={[
                                                    {
                                                        label: __(
                                                            "Card Express Checkout First",
                                                            "monoova-payments-for-woocommerce"
                                                        ),
                                                        value: "card",
                                                    },
                                                    {
                                                        label: __(
                                                            "PayTo Express Checkout First",
                                                            "monoova-payments-for-woocommerce"
                                                        ),
                                                        value: "payto",
                                                    },
                                                ]}
                                            />
                                        </VStack>
                                    </CardBody>
                                </Card>
                            )}
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>
    </VStack>
))

const CardSettingsTab = memo(({ settings, saveNotice, onChangeHandlers, setSaveNotice, handleSettingChange }) => (
    <VStack spacing={6} className="monoova-card-settings-tab">
        {saveNotice && (
            <Notice
                className="monoova-save-notice"
                status={saveNotice.type}
                onRemove={() => setSaveNotice(null)}
                isDismissible={true}>
                {saveNotice.message}
            </Notice>
        )}

        {!settings.enable_card_payments && (
            <Notice status="warning" isDismissible={false}>
                {__(
                    "Card payments are disabled. Enable them in the Payment Methods tab to configure these settings.",
                    "monoova-payments-for-woocommerce"
                )}
            </Notice>
        )}

        {/* Account Status Section */}
        {settings.card_testmode && (
            <Card className="monoova-account-status">
                <CardBody>
                    <VStack spacing={2}>
                        <Text weight="500" size="14">
                            {__("Test Mode", "monoova-payments-for-woocommerce")}
                        </Text>
                        <Text size="13">
                            {__(
                                "When enabled, card payment methods powered by Monoova will appear on checkout in test mode. No live transactions are processed.",
                                "monoova-payments-for-woocommerce"
                            )}
                        </Text>
                    </VStack>
                </CardBody>
            </Card>
        )}

        {/* Basic Information */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Basic Information", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__("Configure how this payment method appears to customers.", "monoova-payments-for-woocommerce")}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <FormField
                                label={__("Title", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "This controls the title which the user sees during checkout.",
                                    "monoova-payments-for-woocommerce"
                                )}
                                required={true}>
                                <StableTextControl
                                    value={settings.card_title || ""}
                                    onChange={onChangeHandlers.card_title}
                                    disabled={!settings.enable_card_payments}
                                    placeholder={__(
                                        "Enter card payment method title",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                />
                            </FormField>

                            <FormField
                                label={__("Description", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "This controls the description which the user sees during checkout.",
                                    "monoova-payments-for-woocommerce"
                                )}>
                                <StableTextareaControl
                                    value={settings.card_description || ""}
                                    onChange={onChangeHandlers.card_description}
                                    disabled={!settings.enable_card_payments}
                                    rows={3}
                                    placeholder={__(
                                        "Enter card payment method description",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                />
                            </FormField>
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>

        {/* Card Settings */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Card Settings", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__("Configure test mode and logging for card payments.", "monoova-payments-for-woocommerce")}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <Grid columns={2} gap={4}>
                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.card_testmode}
                                            disabled={!settings.enable_card_payments}
                                            onChange={onChangeHandlers.card_testmode}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Test Mode", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Process card payments using test API keys",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>

                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.card_debug}
                                            disabled={!settings.enable_card_payments}
                                            onChange={onChangeHandlers.card_debug}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Enable logging", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Log card payment events for debugging purposes",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>
                            </Grid>
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>

        {/* Payment Processing */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Payment Processing", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__("Configure how card payments are processed and handled.", "monoova-payments-for-woocommerce")}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <PanelRow>
                                <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                    <CheckboxControl
                                        checked={settings.capture}
                                        disabled={!settings.enable_card_payments}
                                        onChange={onChangeHandlers.capture}
                                    />
                                    <VStack spacing={1}>
                                        <Text weight="500" size="14">
                                            {__("Capture payments immediately", "monoova-payments-for-woocommerce")}
                                        </Text>
                                        <Text variant="muted" size="13">
                                            {__(
                                                "Capture the payment immediately when the order is placed",
                                                "monoova-payments-for-woocommerce"
                                            )}
                                        </Text>
                                    </VStack>
                                </Flex>
                            </PanelRow>

                            <PanelRow>
                                <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                    <CheckboxControl
                                        checked={settings.saved_cards}
                                        disabled={!settings.enable_card_payments}
                                        onChange={onChangeHandlers.saved_cards}
                                    />
                                    <VStack spacing={1}>
                                        <Text weight="500" size="14">
                                            {__("Enable saved cards", "monoova-payments-for-woocommerce")}
                                        </Text>
                                        <Text variant="muted" size="13">
                                            {__(
                                                "Allow customers to save payment methods for future purchases",
                                                "monoova-payments-for-woocommerce"
                                            )}
                                        </Text>
                                    </VStack>
                                </Flex>
                            </PanelRow>

                            <Divider />

                            <PanelRow>
                                <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                    <CheckboxControl
                                        checked={settings.apply_surcharge}
                                        disabled={!settings.enable_card_payments}
                                        onChange={onChangeHandlers.apply_surcharge}
                                    />
                                    <VStack spacing={1} style={{ flexGrow: 1 }}>
                                        <Text weight="500" size="14">
                                            {__("Apply surcharge", "monoova-payments-for-woocommerce")}
                                        </Text>
                                        <Text variant="muted" size="13">
                                            {__(
                                                "Add a surcharge to card payments to cover processing fees",
                                                "monoova-payments-for-woocommerce"
                                            )}
                                        </Text>
                                    </VStack>
                                    {settings.apply_surcharge && (
                                        <div style={{ width: "120px" }}>
                                            <TextControl
                                                value={settings.surcharge_amount}
                                                disabled={!settings.enable_card_payments}
                                                onChange={value =>
                                                    handleSettingChange("surcharge_amount", parseFloat(value) || 0)
                                                }
                                                type="number"
                                                min={0}
                                                max={10}
                                                step={0.01}
                                            />
                                        </div>
                                    )}
                                </Flex>
                            </PanelRow>
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>

        {/* Security & Wallet Settings */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Security & Wallet Settings", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__("Configure security features and wallet payment options.", "monoova-payments-for-woocommerce")}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <Grid columns={2} gap={4}>
                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.enable_apple_pay}
                                            disabled={!settings.enable_card_payments}
                                            onChange={onChangeHandlers.enable_apple_pay}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Enable Apple Pay", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Allow customers to pay using Apple Pay on supported devices",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>

                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.enable_google_pay}
                                            disabled={!settings.enable_card_payments}
                                            onChange={onChangeHandlers.enable_google_pay}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Enable Google Pay", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Allow customers to pay using Google Pay on supported devices",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>
                            </Grid>

                            <Divider />

                            <FormField
                                label={__("Order button text", "monoova-payments-for-woocommerce")}
                                description={__(
                                    "Customize the text displayed on the payment button.",
                                    "monoova-payments-for-woocommerce"
                                )}>
                                <StableTextControl
                                    value={settings.order_button_text || ""}
                                    onChange={onChangeHandlers.order_button_text}
                                    disabled={!settings.enable_card_payments}
                                    placeholder={__("Pay with Card", "monoova-payments-for-woocommerce")}
                                />
                            </FormField>
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>

        {/* Checkout UI Style Settings */}
        <Grid columns={12} gap={6} className="monoova-settings-section">
            <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                <Heading level={3}>{__("Checkout UI Style Settings", "monoova-payments-for-woocommerce")}</Heading>
                <Text variant="muted" size="14">
                    {__(
                        "Customize the appearance of the checkout form fields and buttons.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Text>
            </VStack>

            <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                {/* Label Input Fields Of Card Details Card */}
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <Flex align="center" justify="flex-start">
                                <Text weight="500" size="15">
                                    {__("Label Input Fields Of Card Details", "monoova-payments-for-woocommerce")}
                                </Text>
                                <InfoIcon type="label" />
                            </Flex>

                            {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                            <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                <div style={{ gridColumn: "span 7" }}>
                                    <FormField label={__("Font family", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={
                                                settings.checkout_ui_styles?.input_label?.font_family ||
                                                "Helvetica, Arial, sans-serif"
                                            }
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input_label: {
                                                        ...settings.checkout_ui_styles?.input_label,
                                                        font_family: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "Inter", value: "Inter" },
                                                { label: "Helvetica", value: "Helvetica, Arial, sans-serif" },
                                                { label: "Arial", value: "Arial, sans-serif" },
                                                { label: "Times New Roman", value: "Times New Roman, serif" },
                                                { label: "Courier New", value: "Courier New, monospace" },
                                            ]}
                                        />
                                    </FormField>
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={settings.checkout_ui_styles?.input_label?.font_weight || "normal"}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input_label: {
                                                        ...settings.checkout_ui_styles?.input_label,
                                                        font_weight: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "Regular", value: "normal" },
                                                { label: "Bold", value: "bold" },
                                                { label: "Light", value: "300" },
                                                { label: "Medium", value: "500" },
                                                { label: "Semi Bold", value: "600" },
                                            ]}
                                        />
                                    </FormField>
                                </div>

                                <div style={{ gridColumn: "span 2" }}>
                                    <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={settings.checkout_ui_styles?.input_label?.font_size || "14px"}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input_label: {
                                                        ...settings.checkout_ui_styles?.input_label,
                                                        font_size: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "12px", value: "12px" },
                                                { label: "14px", value: "14px" },
                                                { label: "16px", value: "16px" },
                                                { label: "18px", value: "18px" },
                                                { label: "20px", value: "20px" },
                                            ]}
                                        />
                                    </FormField>
                                </div>
                            </Grid>

                            {/* Row 2: Color field in 12-column grid (3 columns) */}
                            <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Text color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.input_label?.color || "#000000"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                input_label: {
                                                    ...settings.checkout_ui_styles?.input_label,
                                                    color: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>
                            </Grid>
                        </VStack>
                    </CardBody>
                </Card>

                {/* Input Fields Of Card Details Card */}
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <Flex align="center" justify="flex-start">
                                <Text weight="500" size="15">
                                    {__("Input Fields Of Card Details", "monoova-payments-for-woocommerce")}
                                </Text>
                                <InfoIcon type="input" />
                            </Flex>

                            {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                            <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                <div style={{ gridColumn: "span 7" }}>
                                    <FormField label={__("Font family", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={
                                                settings.checkout_ui_styles?.input?.font_family ||
                                                "Helvetica, Arial, sans-serif"
                                            }
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input: {
                                                        ...settings.checkout_ui_styles?.input,
                                                        font_family: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "Inter", value: "Inter" },
                                                { label: "Helvetica", value: "Helvetica, Arial, sans-serif" },
                                                { label: "Arial", value: "Arial, sans-serif" },
                                                { label: "Times New Roman", value: "Times New Roman, serif" },
                                                { label: "Courier New", value: "Courier New, monospace" },
                                            ]}
                                        />
                                    </FormField>
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={settings.checkout_ui_styles?.input?.font_weight || "normal"}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input: {
                                                        ...settings.checkout_ui_styles?.input,
                                                        font_weight: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "Regular", value: "normal" },
                                                { label: "Bold", value: "bold" },
                                                { label: "Light", value: "300" },
                                                { label: "Medium", value: "500" },
                                                { label: "Semi Bold", value: "600" },
                                            ]}
                                        />
                                    </FormField>
                                </div>

                                <div style={{ gridColumn: "span 2" }}>
                                    <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={settings.checkout_ui_styles?.input?.font_size || "14px"}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input: {
                                                        ...settings.checkout_ui_styles?.input,
                                                        font_size: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "12px", value: "12px" },
                                                { label: "14px", value: "14px" },
                                                { label: "16px", value: "16px" },
                                                { label: "18px", value: "18px" },
                                                { label: "20px", value: "20px" },
                                            ]}
                                        />
                                    </FormField>
                                </div>
                            </Grid>

                            {/* Row 2: Color fields in 12-column grid (3 columns each) */}
                            <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Background color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.input?.background_color || "#FAFAFA"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                input: {
                                                    ...settings.checkout_ui_styles?.input,
                                                    background_color: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Border color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.input?.border_color || "#E8E8E8"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                input: {
                                                    ...settings.checkout_ui_styles?.input,
                                                    border_color: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Text color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.input?.text_color || "#000000"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                input: {
                                                    ...settings.checkout_ui_styles?.input,
                                                    text_color: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <FormField label={__("Border radius (px)", "monoova-payments-for-woocommerce")}>
                                        <TextControl
                                            value={settings.checkout_ui_styles?.input?.border_radius || 8}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    input: {
                                                        ...settings.checkout_ui_styles?.input,
                                                        border_radius: parseInt(value) || 8,
                                                    },
                                                })
                                            }
                                            type="number"
                                            min={0}
                                            max={50}
                                            disabled={!settings.enable_card_payments}
                                            style={{ borderRadius: "8px", padding: "10px", borderColor: "#D0D5DD" }}
                                        />
                                    </FormField>
                                </div>
                            </Grid>
                        </VStack>
                    </CardBody>
                </Card>

                {/* Pay Button Card */}
                <Card>
                    <CardBody>
                        <VStack spacing={4}>
                            <Flex align="center" justify="flex-start">
                                <Text weight="500" size="15">
                                    {__("Pay Button", "monoova-payments-for-woocommerce")}
                                </Text>
                                <InfoIcon type="button" />
                            </Flex>

                            {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                            <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                <div style={{ gridColumn: "span 7" }}>
                                    <FormField label={__("Font family", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={
                                                settings.checkout_ui_styles?.submit_button?.font_family ||
                                                "Helvetica, Arial, sans-serif"
                                            }
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.checkout_ui_styles?.submit_button,
                                                        font_family: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "Inter", value: "Inter" },
                                                { label: "Helvetica", value: "Helvetica, Arial, sans-serif" },
                                                { label: "Arial", value: "Arial, sans-serif" },
                                                { label: "Times New Roman", value: "Times New Roman, serif" },
                                                { label: "Courier New", value: "Courier New, monospace" },
                                            ]}
                                        />
                                    </FormField>
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={settings.checkout_ui_styles?.submit_button?.font_weight || "bold"}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.checkout_ui_styles?.submit_button,
                                                        font_weight: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "Regular", value: "normal" },
                                                { label: "Bold", value: "bold" },
                                                { label: "Light", value: "300" },
                                                { label: "Medium", value: "500" },
                                                { label: "Semi Bold", value: "600" },
                                            ]}
                                        />
                                    </FormField>
                                </div>

                                <div style={{ gridColumn: "span 2" }}>
                                    <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                        <SelectControl
                                            value={settings.checkout_ui_styles?.submit_button?.font_size || "17px"}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.checkout_ui_styles?.submit_button,
                                                        font_size: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_card_payments}
                                            options={[
                                                { label: "14px", value: "14px" },
                                                { label: "16px", value: "16px" },
                                                { label: "17px", value: "17px" },
                                                { label: "18px", value: "18px" },
                                                { label: "20px", value: "20px" },
                                            ]}
                                        />
                                    </FormField>
                                </div>
                            </Grid>

                            {/* Row 2: Color fields in 12-column grid (3 columns each) */}
                            <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Background color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.submit_button?.background || "#2ab5c4"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                submit_button: {
                                                    ...settings.checkout_ui_styles?.submit_button,
                                                    background: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Border color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                submit_button: {
                                                    ...settings.checkout_ui_styles?.submit_button,
                                                    border_color: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <ColorField
                                        label={__("Text color", "monoova-payments-for-woocommerce")}
                                        value={settings.checkout_ui_styles?.submit_button?.text_color || "#000000"}
                                        onChange={value =>
                                            handleSettingChange("checkout_ui_styles", {
                                                ...settings.checkout_ui_styles,
                                                submit_button: {
                                                    ...settings.checkout_ui_styles?.submit_button,
                                                    text_color: value,
                                                },
                                            })
                                        }
                                        disabled={!settings.enable_card_payments}
                                    />
                                </div>

                                <div style={{ gridColumn: "span 3" }}>
                                    <FormField label={__("Border radius", "monoova-payments-for-woocommerce")}>
                                        <TextControl
                                            value={settings.checkout_ui_styles?.submit_button?.border_radius || 10}
                                            onChange={value =>
                                                handleSettingChange("checkout_ui_styles", {
                                                    ...settings.checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.checkout_ui_styles?.submit_button,
                                                        border_radius: parseInt(value) || 10,
                                                    },
                                                })
                                            }
                                            type="number"
                                            min={0}
                                            max={50}
                                            disabled={!settings.enable_card_payments}
                                            style={{ borderRadius: "8px", padding: "10px", borderColor: "#D0D5DD" }}
                                        />
                                    </FormField>
                                </div>
                            </Grid>
                        </VStack>
                    </CardBody>
                </Card>
            </VStack>
        </Grid>
    </VStack>
))

const PayIDSettingsTab = memo(
    ({ settings, saveNotice, onChangeHandlers, setSaveNotice, onGenerateAutomatcher, isGenerating }) => (
        <VStack spacing={6} className="monoova-payid-settings-tab">
            {saveNotice && (
                <Notice
                    className="monoova-save-notice"
                    status={saveNotice.type}
                    onRemove={() => setSaveNotice(null)}
                    isDismissible={true}>
                    {saveNotice.message}
                </Notice>
            )}

            {!settings.enable_payid_payments && (
                <Notice status="warning" isDismissible={false}>
                    {__(
                        "PayID payments are disabled. Enable them in the Payment Methods tab to configure these settings.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Notice>
            )}

            {/* Basic Information */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Basic Information", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure how this payment method appears to customers.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <FormField
                                    label={__("Title", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "This controls the title which the user sees during checkout.",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                    required={true}>
                                    <StableTextControl
                                        value={settings.payid_title || ""}
                                        onChange={onChangeHandlers.payid_title}
                                        disabled={!settings.enable_payid_payments}
                                        placeholder={__(
                                            "Enter PayID payment method title",
                                            "monoova-payments-for-woocommerce"
                                        )}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Description", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "This controls the description which the user sees during checkout.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <StableTextareaControl
                                        value={settings.payid_description || ""}
                                        onChange={onChangeHandlers.payid_description}
                                        disabled={!settings.enable_payid_payments}
                                        rows={3}
                                        placeholder={__(
                                            "Enter PayID payment method description",
                                            "monoova-payments-for-woocommerce"
                                        )}
                                    />
                                </FormField>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* PayID Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("PayID Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__("Configure test mode and logging for PayID payments.", "monoova-payments-for-woocommerce")}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <Grid columns={2} gap={4}>
                                    <PanelRow>
                                        <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                            <CheckboxControl
                                                checked={settings.payid_testmode}
                                                disabled={!settings.enable_payid_payments}
                                                onChange={onChangeHandlers.payid_testmode}
                                            />
                                            <VStack spacing={1}>
                                                <Text weight="500" size="14">
                                                    {__("Test Mode", "monoova-payments-for-woocommerce")}
                                                </Text>
                                                <Text variant="muted" size="13">
                                                    {__(
                                                        "Process PayID payments using test API keys",
                                                        "monoova-payments-for-woocommerce"
                                                    )}
                                                </Text>
                                            </VStack>
                                        </Flex>
                                    </PanelRow>

                                    <PanelRow>
                                        <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                            <CheckboxControl
                                                checked={settings.payid_debug}
                                                disabled={!settings.enable_payid_payments}
                                                onChange={onChangeHandlers.payid_debug}
                                            />
                                            <VStack spacing={1}>
                                                <Text weight="500" size="14">
                                                    {__("Enable logging", "monoova-payments-for-woocommerce")}
                                                </Text>
                                                <Text variant="muted" size="13">
                                                    {__(
                                                        "Log PayID payment events for debugging purposes",
                                                        "monoova-payments-for-woocommerce"
                                                    )}
                                                </Text>
                                            </VStack>
                                        </Flex>
                                    </PanelRow>
                                </Grid>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* Payment Options */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Payment Options", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure payment types, expiry settings, and customer instructions.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <FormField
                                    label={__("Account Name", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "The account name to use when generating the store-wide Automatcher account.",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                    required={true}>
                                    <StableTextControl
                                        value={settings.account_name || ""}
                                        onChange={onChangeHandlers.account_name}
                                        disabled={!settings.enable_payid_payments}
                                        placeholder={__("e.g. Your Store Name", "monoova-payments-for-woocommerce")}
                                    />
                                </FormField>

                                <Grid columns={2} gap={4}>
                                    <FormField label={__("Store BSB", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={settings.static_bsb || ""}
                                            readOnly
                                            disabled={!settings.enable_payid_payments}
                                            placeholder={__("Generated by Monoova", "monoova-payments-for-woocommerce")}
                                        />
                                    </FormField>
                                    <FormField label={__("Store Account Number", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={settings.static_account_number || ""}
                                            readOnly
                                            disabled={!settings.enable_payid_payments}
                                            placeholder={__("Generated by Monoova", "monoova-payments-for-woocommerce")}
                                        />
                                    </FormField>
                                </Grid>

                                <Button
                                    variant="secondary"
                                    onClick={onGenerateAutomatcher}
                                    isBusy={isGenerating}
                                    disabled={!settings.enable_payid_payments || isGenerating}>
                                    {__(
                                        "Generate / Replace Store Automatcher Account",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                </Button>
                                <Text variant="muted" size="13">
                                    {__(
                                        "Note: It may take up to 5 minutes for a newly generated account to become fully active for receiving payments.",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                </Text>

                                <Divider />

                                <FormField
                                    label={__("Payment types", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Select which payment types to accept.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <SelectControl
                                        multiple
                                        value={settings.payment_types}
                                        onChange={onChangeHandlers.payment_types}
                                        disabled={!settings.enable_payid_payments}
                                        options={[
                                            { label: __("PayID", "monoova-payments-for-woocommerce"), value: "payid" },
                                            {
                                                label: __("Bank Transfer", "monoova-payments-for-woocommerce"),
                                                value: "bank_transfer",
                                            },
                                        ]}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Payment expiry (hours)", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Number of hours before payment instructions expire.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <TextControl
                                        value={settings.expire_hours}
                                        onChange={onChangeHandlers.expire_hours}
                                        type="number"
                                        min={1}
                                        max={168}
                                        disabled={!settings.enable_payid_payments}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Payment Instructions", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Additional instructions to show customers about PayID/Bank Transfer payments.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <StableTextareaControl
                                        value={settings.instructions || ""}
                                        onChange={onChangeHandlers.instructions}
                                        rows={4}
                                        disabled={!settings.enable_payid_payments}
                                    />
                                </FormField>
                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.payid_show_reference_field}
                                            onChange={onChangeHandlers.payid_show_reference_field}
                                            disabled={!settings.enable_payid_payments}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__(
                                                    "Display Payment Reference Field",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "If enabled, a separate 'Payment Reference' field will be shown below the QR code and in the bank transfer details.",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* Checkout UI Style Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Checkout UI Style Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Customize the appearance of the PayID payment display elements.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    {/* Scan Pay Display Card */}
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <Flex align="center" justify="flex-start">
                                    <Text weight="500" size="15">
                                        {__("Order Amount Display", "monoova-payments-for-woocommerce")}
                                    </Text>
                                    <InfoIcon type="scan_pay" />
                                </Flex>

                                {/* Pay Label Settings */}
                                <VStack spacing={3}>
                                    <Text weight="500" size="14">
                                        {__("Pay Label", "monoova-payments-for-woocommerce")}
                                    </Text>

                                    {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                                    <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                        <div style={{ gridColumn: "span 7" }}>
                                            <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={
                                                        settings.payid_checkout_ui_styles?.scan_pay?.pay_label?.font_size ||
                                                        "28px"
                                                    }
                                                    onChange={value =>
                                                        onChangeHandlers.payid_checkout_ui_styles({
                                                            ...settings.payid_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payid_checkout_ui_styles?.scan_pay,
                                                                pay_label: {
                                                                    ...settings.payid_checkout_ui_styles?.scan_pay?.pay_label,
                                                                    font_size: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payid_payments}
                                                    options={[
                                                        { label: "20px", value: "20px" },
                                                        { label: "24px", value: "24px" },
                                                        { label: "28px", value: "28px" },
                                                        { label: "32px", value: "32px" },
                                                        { label: "36px", value: "36px" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 3" }}>
                                            <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={settings.payid_checkout_ui_styles?.scan_pay?.pay_label?.font_weight || "700"}
                                                    onChange={value =>
                                                        onChangeHandlers.payid_checkout_ui_styles({
                                                            ...settings.payid_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payid_checkout_ui_styles?.scan_pay,
                                                                pay_label: {
                                                                    ...settings.payid_checkout_ui_styles?.scan_pay?.pay_label,
                                                                    font_weight: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payid_payments}
                                                    options={[
                                                        { label: "Regular", value: "normal" },
                                                        { label: "Bold", value: "bold" },
                                                        { label: "Light", value: "300" },
                                                        { label: "Medium", value: "500" },
                                                        { label: "Semi Bold", value: "600" },
                                                        { label: "Extra Bold", value: "700" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 2" }}>
                                            <ColorField
                                                label={__("Color", "monoova-payments-for-woocommerce")}
                                                value={settings.payid_checkout_ui_styles?.scan_pay?.pay_label?.color || "#000000"}
                                                onChange={value =>
                                                    onChangeHandlers.payid_checkout_ui_styles({
                                                        ...settings.payid_checkout_ui_styles,
                                                        scan_pay: {
                                                            ...settings.payid_checkout_ui_styles?.scan_pay,
                                                            pay_label: {
                                                                ...settings.payid_checkout_ui_styles?.scan_pay?.pay_label,
                                                                color: value,
                                                            },
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payid_payments}
                                            />
                                        </div>
                                    </Grid>
                                </VStack>

                                {/* Amount Settings */}
                                <VStack spacing={3}>
                                    <Text weight="500" size="14">
                                        {__("Amount", "monoova-payments-for-woocommerce")}
                                    </Text>

                                    {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                                    <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                        <div style={{ gridColumn: "span 7" }}>
                                            <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={
                                                        settings.payid_checkout_ui_styles?.scan_pay?.amount?.font_size ||
                                                        "28px"
                                                    }
                                                    onChange={value =>
                                                        onChangeHandlers.payid_checkout_ui_styles({
                                                            ...settings.payid_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payid_checkout_ui_styles?.scan_pay,
                                                                amount: {
                                                                    ...settings.payid_checkout_ui_styles?.scan_pay?.amount,
                                                                    font_size: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payid_payments}
                                                    options={[
                                                        { label: "20px", value: "20px" },
                                                        { label: "24px", value: "24px" },
                                                        { label: "28px", value: "28px" },
                                                        { label: "32px", value: "32px" },
                                                        { label: "36px", value: "36px" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 3" }}>
                                            <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={settings.payid_checkout_ui_styles?.scan_pay?.amount?.font_weight || "700"}
                                                    onChange={value =>
                                                        onChangeHandlers.payid_checkout_ui_styles({
                                                            ...settings.payid_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payid_checkout_ui_styles?.scan_pay,
                                                                amount: {
                                                                    ...settings.payid_checkout_ui_styles?.scan_pay?.amount,
                                                                    font_weight: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payid_payments}
                                                    options={[
                                                        { label: "Regular", value: "normal" },
                                                        { label: "Bold", value: "bold" },
                                                        { label: "Light", value: "300" },
                                                        { label: "Medium", value: "500" },
                                                        { label: "Semi Bold", value: "600" },
                                                        { label: "Extra Bold", value: "700" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 2" }}>
                                            <ColorField
                                                label={__("Color", "monoova-payments-for-woocommerce")}
                                                value={settings.payid_checkout_ui_styles?.scan_pay?.amount?.color || "#2CB5C5"}
                                                onChange={value =>
                                                    onChangeHandlers.payid_checkout_ui_styles({
                                                        ...settings.payid_checkout_ui_styles,
                                                        scan_pay: {
                                                            ...settings.payid_checkout_ui_styles?.scan_pay,
                                                            amount: {
                                                                ...settings.payid_checkout_ui_styles?.scan_pay?.amount,
                                                                color: value,
                                                            },
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payid_payments}
                                            />
                                        </div>
                                    </Grid>
                                </VStack>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>
        </VStack>
    )
)

const PayToSettingsTab = memo(
    ({ settings, saveNotice, onChangeHandlers, setSaveNotice, onGenerateAutomatcher, isGenerating }) => (
        <VStack spacing={6} className="monoova-payto-settings-tab">
            {saveNotice && (
                <Notice
                    className="monoova-save-notice"
                    status={saveNotice.type}
                    onRemove={() => setSaveNotice(null)}
                    isDismissible={true}>
                    {saveNotice.message}
                </Notice>
            )}

            {!settings.enable_payto_payments && (
                <Notice status="warning" isDismissible={false}>
                    {__(
                        "PayTo payments are disabled. Enable them in the Payment Methods tab to configure these settings.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Notice>
            )}

            {settings.enable_payto_payments && settings.enable_payto_express_checkout && (
                <Notice status="info" isDismissible={false}>
                    {__(
                        "PayTo Express Checkout is enabled. Customers with saved PayTo agreements can complete purchases with a single click.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Notice>
            )}

            {settings.enable_payto_payments && !settings.enable_payto_express_checkout && (
                <Notice status="info" isDismissible={false}>
                    {__(
                        "Enable PayTo Express Checkout in the Payment Methods tab to allow customers with saved agreements to complete purchases faster.",
                        "monoova-payments-for-woocommerce"
                    )}
                </Notice>
            )}

            {/* Basic Information */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Basic Information", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure how this payment method appears to customers.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <FormField
                                    label={__("Title", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "This controls the title which the user sees during checkout.",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                    required={true}>
                                    <StableTextControl
                                        value={settings.payto_title || ""}
                                        onChange={onChangeHandlers.payto_title}
                                        disabled={!settings.enable_payto_payments}
                                        placeholder={__(
                                            "Enter PayTo payment method title",
                                            "monoova-payments-for-woocommerce"
                                        )}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Description", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "This controls the description which the user sees during checkout.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <StableTextareaControl
                                        value={settings.payto_description || ""}
                                        onChange={onChangeHandlers.payto_description}
                                        disabled={!settings.enable_payto_payments}
                                        rows={3}
                                        placeholder={__(
                                            "Enter PayTo payment method description",
                                            "monoova-payments-for-woocommerce"
                                        )}
                                    />
                                </FormField>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* PayTo Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("PayTo Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure PayTo payment gateway behavior and debugging options.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.payto_testmode}
                                            onChange={onChangeHandlers.payto_testmode}
                                            disabled={!settings.enable_payto_payments}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Enable Test Mode", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Enable this to use sandbox/test environment for PayTo payments.",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>

                                <PanelRow>
                                    <Flex align="center" justify="flex-start" gap={3} style={{ width: "100%" }}>
                                        <CheckboxControl
                                            checked={settings.payto_debug}
                                            onChange={onChangeHandlers.payto_debug}
                                            disabled={!settings.enable_payto_payments}
                                        />
                                        <VStack spacing={1}>
                                            <Text weight="500" size="14">
                                                {__("Enable Debug Mode", "monoova-payments-for-woocommerce")}
                                            </Text>
                                            <Text variant="muted" size="13">
                                                {__(
                                                    "Enable debug logging for PayTo transactions. Check logs under WooCommerce > Status > Logs.",
                                                    "monoova-payments-for-woocommerce"
                                                )}
                                            </Text>
                                        </VStack>
                                    </Flex>
                                </PanelRow>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* PayTo Agreement Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Payment Agreement Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Configure PayTo payment agreement settings including purpose codes and expiry dates.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <FormField
                                    label={__("Purpose Code", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Purpose code for PayTo agreements as per ISO 20022 standards. This indicates the purpose of the payment.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <SelectControl
                                        value={settings.payto_purpose || "OTHR"}
                                        onChange={onChangeHandlers.payto_purpose}
                                        disabled={!settings.enable_payto_payments}
                                        options={[
                                            { label: "MORT - Mortgage", value: "MORT" },
                                            { label: "UTIL - Utilities", value: "UTIL" },
                                            { label: "LOAN - Loan", value: "LOAN" },
                                            { label: "DEPD - Deposit", value: "DEPD" },
                                            { label: "GAMP - Gaming/Gambling", value: "GAMP" },
                                            { label: "RETL - Retail", value: "RETL" },
                                            { label: "SALA - Salary Payment", value: "SALA" },
                                            { label: "PERS - Personal", value: "PERS" },
                                            { label: "GOVT - Government", value: "GOVT" },
                                            { label: "PENS - Pension", value: "PENS" },
                                            { label: "TAXS - Tax Payment", value: "TAXS" },
                                            { label: "OTHR - Other", value: "OTHR" },
                                        ]}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Agreement Expiry (Days)", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Number of days from creation after which the PayTo agreement will expire. Leave empty for no expiry.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <TextControl
                                        value={settings.payto_agreement_expiry_days || ""}
                                        onChange={onChangeHandlers.payto_agreement_expiry_days}
                                        disabled={!settings.enable_payto_payments}
                                        type="number"
                                        min={1}
                                        max={365}
                                        placeholder={__("e.g. 30", "monoova-payments-for-woocommerce")}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Payee Type", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Type of payee for PayTo agreements. ORGN for organizations/businesses, PERS for individuals.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <SelectControl
                                        value={settings.payto_payee_type || "ORGN"}
                                        onChange={onChangeHandlers.payto_payee_type}
                                        disabled={!settings.enable_payto_payments}
                                        options={[
                                            { label: "ORGN - Organization", value: "ORGN" },
                                            { label: "PERS - Person", value: "PERS" },
                                        ]}
                                    />
                                </FormField>

                                <FormField
                                    label={__("Default Maximum Amount (AUD)", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "Default maximum amount for PayTo agreements. This can be modified by customers during checkout. Higher amounts provide more flexibility for variable pricing.",
                                        "monoova-payments-for-woocommerce"
                                    )}>
                                    <TextControl
                                        value={settings.payto_maximum_amount || "1000"}
                                        onChange={onChangeHandlers.payto_maximum_amount}
                                        disabled={!settings.enable_payto_payments}
                                        type="number"
                                        min={0.01}
                                        step={0.01}
                                        placeholder={__("e.g. 1000", "monoova-payments-for-woocommerce")}
                                    />
                                </FormField>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* Unified Automatcher Account */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Unified Automatcher Account", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "PayTo requires the same unified automatcher account as PayID for payment processing. This account is shared between PayID and PayTo payment methods.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <FormField
                                    label={__("Account Name", "monoova-payments-for-woocommerce")}
                                    description={__(
                                        "The account name for the unified Automatcher account used by both PayID and PayTo.",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                    required={true}>
                                    <StableTextControl
                                        value={settings.account_name || ""}
                                        onChange={onChangeHandlers.account_name}
                                        disabled={!settings.enable_payto_payments}
                                        placeholder={__("e.g. Your Store Name", "monoova-payments-for-woocommerce")}
                                    />
                                </FormField>

                                <Grid columns={2} gap={4}>
                                    <FormField label={__("Store BSB", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={settings.static_bsb || ""}
                                            readOnly
                                            disabled={!settings.enable_payto_payments}
                                            placeholder={__("Generated by Monoova", "monoova-payments-for-woocommerce")}
                                        />
                                    </FormField>
                                    <FormField label={__("Store Account Number", "monoova-payments-for-woocommerce")}>
                                        <StableTextControl
                                            value={settings.static_account_number || ""}
                                            readOnly
                                            disabled={!settings.enable_payto_payments}
                                            placeholder={__("Generated by Monoova", "monoova-payments-for-woocommerce")}
                                        />
                                    </FormField>
                                </Grid>

                                <Button
                                    variant="secondary"
                                    onClick={onGenerateAutomatcher}
                                    isBusy={isGenerating}
                                    disabled={!settings.enable_payto_payments || isGenerating}>
                                    {__(
                                        "Generate / Replace Store Automatcher Account",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                </Button>
                                <Text variant="muted" size="13">
                                    {__(
                                        "Note: This is the same automatcher account used by PayID. Changes here will affect both PayID and PayTo payment methods. It may take up to 5 minutes for a newly generated account to become fully active.",
                                        "monoova-payments-for-woocommerce"
                                    )}
                                </Text>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>

            {/* Checkout UI Style Settings */}
            <Grid columns={12} gap={6} className="monoova-settings-section">
                <VStack spacing={3} style={{ gridColumn: "span 4" }}>
                    <Heading level={3}>{__("Checkout UI Style Settings", "monoova-payments-for-woocommerce")}</Heading>
                    <Text variant="muted" size="14">
                        {__(
                            "Customize the appearance of the PayTo payment display elements and buttons.",
                            "monoova-payments-for-woocommerce"
                        )}
                    </Text>
                </VStack>

                <VStack spacing={4} style={{ gridColumn: "span 8" }}>
                    {/* Scan Pay Display Card */}
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <Flex align="center" justify="flex-start">
                                    <Text weight="500" size="15">
                                        {__("Order Amount Display", "monoova-payments-for-woocommerce")}
                                    </Text>
                                    <InfoIcon type="scan_pay" />
                                </Flex>

                                {/* Pay Label Settings */}
                                <VStack spacing={3}>
                                    <Text weight="500" size="14">
                                        {__("Pay Label", "monoova-payments-for-woocommerce")}
                                    </Text>

                                    {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                                    <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                        <div style={{ gridColumn: "span 7" }}>
                                            <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={
                                                        settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_size ||
                                                        "28px"
                                                    }
                                                    onChange={value =>
                                                        onChangeHandlers.payto_checkout_ui_styles({
                                                            ...settings.payto_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payto_checkout_ui_styles?.scan_pay,
                                                                pay_label: {
                                                                    ...settings.payto_checkout_ui_styles?.scan_pay?.pay_label,
                                                                    font_size: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payto_payments}
                                                    options={[
                                                        { label: "20px", value: "20px" },
                                                        { label: "24px", value: "24px" },
                                                        { label: "28px", value: "28px" },
                                                        { label: "32px", value: "32px" },
                                                        { label: "36px", value: "36px" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 3" }}>
                                            <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.font_weight || "700"}
                                                    onChange={value =>
                                                        onChangeHandlers.payto_checkout_ui_styles({
                                                            ...settings.payto_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payto_checkout_ui_styles?.scan_pay,
                                                                pay_label: {
                                                                    ...settings.payto_checkout_ui_styles?.scan_pay?.pay_label,
                                                                    font_weight: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payto_payments}
                                                    options={[
                                                        { label: "Regular", value: "normal" },
                                                        { label: "Bold", value: "bold" },
                                                        { label: "Light", value: "300" },
                                                        { label: "Medium", value: "500" },
                                                        { label: "Semi Bold", value: "600" },
                                                        { label: "Extra Bold", value: "700" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 2" }}>
                                            <ColorField
                                                label={__("Color", "monoova-payments-for-woocommerce")}
                                                value={settings.payto_checkout_ui_styles?.scan_pay?.pay_label?.color || "#000000"}
                                                onChange={value =>
                                                    onChangeHandlers.payto_checkout_ui_styles({
                                                        ...settings.payto_checkout_ui_styles,
                                                        scan_pay: {
                                                            ...settings.payto_checkout_ui_styles?.scan_pay,
                                                            pay_label: {
                                                                ...settings.payto_checkout_ui_styles?.scan_pay?.pay_label,
                                                                color: value,
                                                            },
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payto_payments}
                                            />
                                        </div>
                                    </Grid>
                                </VStack>

                                {/* Amount Settings */}
                                <VStack spacing={3}>
                                    <Text weight="500" size="14">
                                        {__("Amount", "monoova-payments-for-woocommerce")}
                                    </Text>

                                    {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                                    <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                        <div style={{ gridColumn: "span 7" }}>
                                            <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={
                                                        settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_size ||
                                                        "28px"
                                                    }
                                                    onChange={value =>
                                                        onChangeHandlers.payto_checkout_ui_styles({
                                                            ...settings.payto_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payto_checkout_ui_styles?.scan_pay,
                                                                amount: {
                                                                    ...settings.payto_checkout_ui_styles?.scan_pay?.amount,
                                                                    font_size: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payto_payments}
                                                    options={[
                                                        { label: "20px", value: "20px" },
                                                        { label: "24px", value: "24px" },
                                                        { label: "28px", value: "28px" },
                                                        { label: "32px", value: "32px" },
                                                        { label: "36px", value: "36px" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 3" }}>
                                            <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                                <SelectControl
                                                    value={settings.payto_checkout_ui_styles?.scan_pay?.amount?.font_weight || "700"}
                                                    onChange={value =>
                                                        onChangeHandlers.payto_checkout_ui_styles({
                                                            ...settings.payto_checkout_ui_styles,
                                                            scan_pay: {
                                                                ...settings.payto_checkout_ui_styles?.scan_pay,
                                                                amount: {
                                                                    ...settings.payto_checkout_ui_styles?.scan_pay?.amount,
                                                                    font_weight: value,
                                                                },
                                                            },
                                                        })
                                                    }
                                                    disabled={!settings.enable_payto_payments}
                                                    options={[
                                                        { label: "Regular", value: "normal" },
                                                        { label: "Bold", value: "bold" },
                                                        { label: "Light", value: "300" },
                                                        { label: "Medium", value: "500" },
                                                        { label: "Semi Bold", value: "600" },
                                                        { label: "Extra Bold", value: "700" },
                                                    ]}
                                                />
                                            </FormField>
                                        </div>

                                        <div style={{ gridColumn: "span 2" }}>
                                            <ColorField
                                                label={__("Color", "monoova-payments-for-woocommerce")}
                                                value={settings.payto_checkout_ui_styles?.scan_pay?.amount?.color || "#2CB5C5"}
                                                onChange={value =>
                                                    onChangeHandlers.payto_checkout_ui_styles({
                                                        ...settings.payto_checkout_ui_styles,
                                                        scan_pay: {
                                                            ...settings.payto_checkout_ui_styles?.scan_pay,
                                                            amount: {
                                                                ...settings.payto_checkout_ui_styles?.scan_pay?.amount,
                                                                color: value,
                                                            },
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payto_payments}
                                            />
                                        </div>
                                    </Grid>
                                </VStack>
                            </VStack>
                        </CardBody>
                    </Card>

                    {/* Pay Button Card */}
                    <Card>
                        <CardBody>
                            <VStack spacing={4}>
                                <Flex align="center" justify="flex-start">
                                    <Text weight="500" size="15">
                                        {__("Pay Button", "monoova-payments-for-woocommerce")}
                                    </Text>
                                    <InfoIcon type="button" />
                                </Flex>

                                {/* Row 1: Font configuration fields in 12-column grid (7:3:2 ratio) */}
                                <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                    <div style={{ gridColumn: "span 7" }}>
                                        <FormField label={__("Font family", "monoova-payments-for-woocommerce")}>
                                            <SelectControl
                                                value={
                                                    settings.payto_checkout_ui_styles?.submit_button?.font_family ||
                                                    "Helvetica, Arial, sans-serif"
                                                }
                                                onChange={value =>
                                                    onChangeHandlers.payto_checkout_ui_styles({
                                                        ...settings.payto_checkout_ui_styles,
                                                        submit_button: {
                                                            ...settings.payto_checkout_ui_styles?.submit_button,
                                                            font_family: value,
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payto_payments}
                                                options={[
                                                    { label: "Inter", value: "Inter" },
                                                    { label: "Helvetica", value: "Helvetica, Arial, sans-serif" },
                                                    { label: "Arial", value: "Arial, sans-serif" },
                                                    { label: "Times New Roman", value: "Times New Roman, serif" },
                                                    { label: "Courier New", value: "Courier New, monospace" },
                                                ]}
                                            />
                                        </FormField>
                                    </div>

                                    <div style={{ gridColumn: "span 3" }}>
                                        <FormField label={__("Font weight", "monoova-payments-for-woocommerce")}>
                                            <SelectControl
                                                value={settings.payto_checkout_ui_styles?.submit_button?.font_weight || "bold"}
                                                onChange={value =>
                                                    onChangeHandlers.payto_checkout_ui_styles({
                                                        ...settings.payto_checkout_ui_styles,
                                                        submit_button: {
                                                            ...settings.payto_checkout_ui_styles?.submit_button,
                                                            font_weight: value,
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payto_payments}
                                                options={[
                                                    { label: "Regular", value: "normal" },
                                                    { label: "Bold", value: "bold" },
                                                    { label: "Light", value: "300" },
                                                    { label: "Medium", value: "500" },
                                                    { label: "Semi Bold", value: "600" },
                                                    { label: "Extra Bold", value: "700" },
                                                    
                                                ]}
                                            />
                                        </FormField>
                                    </div>

                                    <div style={{ gridColumn: "span 2" }}>
                                        <FormField label={__("Font size", "monoova-payments-for-woocommerce")}>
                                            <SelectControl
                                                value={settings.payto_checkout_ui_styles?.submit_button?.font_size || "17px"}
                                                onChange={value =>
                                                    onChangeHandlers.payto_checkout_ui_styles({
                                                        ...settings.payto_checkout_ui_styles,
                                                        submit_button: {
                                                            ...settings.payto_checkout_ui_styles?.submit_button,
                                                            font_size: value,
                                                        },
                                                    })
                                                }
                                                disabled={!settings.enable_payto_payments}
                                                options={[
                                                    { label: "14px", value: "14px" },
                                                    { label: "16px", value: "16px" },
                                                    { label: "17px", value: "17px" },
                                                    { label: "18px", value: "18px" },
                                                    { label: "20px", value: "20px" },
                                                ]}
                                            />
                                        </FormField>
                                    </div>
                                </Grid>

                                {/* Row 2: Color fields in 12-column grid (3 columns each) */}
                                <Grid columns={12} gap={4} style={{ gap: "16px" }}>
                                    <div style={{ gridColumn: "span 3" }}>
                                        <ColorField
                                            label={__("Background color", "monoova-payments-for-woocommerce")}
                                            value={settings.payto_checkout_ui_styles?.submit_button?.background || "#2ab5c4"}
                                            onChange={value =>
                                                onChangeHandlers.payto_checkout_ui_styles({
                                                    ...settings.payto_checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.payto_checkout_ui_styles?.submit_button,
                                                        background: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_payto_payments}
                                        />
                                    </div>

                                    <div style={{ gridColumn: "span 3" }}>
                                        <ColorField
                                            label={__("Border color", "monoova-payments-for-woocommerce")}
                                            value={settings.payto_checkout_ui_styles?.submit_button?.border_color || "#2ab5c4"}
                                            onChange={value =>
                                                onChangeHandlers.payto_checkout_ui_styles({
                                                    ...settings.payto_checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.payto_checkout_ui_styles?.submit_button,
                                                        border_color: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_payto_payments}
                                        />
                                    </div>

                                    <div style={{ gridColumn: "span 3" }}>
                                        <ColorField
                                            label={__("Text color", "monoova-payments-for-woocommerce")}
                                            value={settings.payto_checkout_ui_styles?.submit_button?.text_color || "#000000"}
                                            onChange={value =>
                                                onChangeHandlers.payto_checkout_ui_styles({
                                                    ...settings.payto_checkout_ui_styles,
                                                    submit_button: {
                                                        ...settings.payto_checkout_ui_styles?.submit_button,
                                                        text_color: value,
                                                    },
                                                })
                                            }
                                            disabled={!settings.enable_payto_payments}
                                        />
                                    </div>

                                    <div style={{ gridColumn: "span 3" }}>
                                        <FormField label={__("Border radius (px)", "monoova-payments-for-woocommerce")}>
                                            <TextControl
                                                value={settings.payto_checkout_ui_styles?.submit_button?.border_radius || 10}
                                                onChange={value =>
                                                    onChangeHandlers.payto_checkout_ui_styles({
                                                        ...settings.payto_checkout_ui_styles,
                                                        submit_button: {
                                                            ...settings.payto_checkout_ui_styles?.submit_button,
                                                            border_radius: parseInt(value) || 10,
                                                        },
                                                    })
                                                }
                                                type="number"
                                                min={0}
                                                max={50}
                                                disabled={!settings.enable_payto_payments}
                                                style={{ borderRadius: "8px", padding: "10px", borderColor: "#D0D5DD" }}
                                            />
                                        </FormField>
                                    </div>
                                </Grid>
                            </VStack>
                        </CardBody>
                    </Card>
                </VStack>
            </Grid>
        </VStack>
    )
)

const PaymentSettings = () => {
    const [settings, setSettings] = useState({
        // Payment Methods Tab
        enable_card_payments: false,
        enable_payid_payments: false,
        enable_payto_payments: false,
        enable_express_checkout: false,
        enable_payto_express_checkout: false,
        express_checkout_method_priority: "card",

        // Card Settings Tab
        card_title: "Credit / Debit Card",
        card_description: "Pay with your credit or debit card via Monoova.",
        card_testmode: true,
        card_debug: true,
        capture: true,
        saved_cards: true,
        apply_surcharge: false,
        surcharge_amount: 0.0,
        enable_apple_pay: true,
        enable_google_pay: true,
        order_button_text: "Pay with Card",

        // Checkout UI Style Settings
        checkout_ui_styles: {
            input_label: {
                font_family: "Helvetica, Arial, sans-serif",
                font_weight: "normal",
                font_size: "14px",
                color: "#000000",
            },
            input: {
                font_family: "Helvetica, Arial, sans-serif",
                font_weight: "normal",
                font_size: "14px",
                background_color: "#FAFAFA",
                border_color: "#E8E8E8",
                border_radius: 8,
                text_color: "#000000",
            },
            submit_button: {
                font_family: "Helvetica, Arial, sans-serif",
                font_size: "17px",
                background: "#2ab5c4",
                border_radius: 10,
                border_color: "#2ab5c4",
                font_weight: "bold",
                text_color: "#000000",
            },
        },

        // PayID Settings Tab
        payid_title: "PayID / Bank Transfer",
        payid_description: "Pay using PayID or bank transfer.",
        payid_testmode: true,
        payid_debug: true,
        payid_show_reference_field: true,
        static_bank_account_name: "",
        static_bsb: "",
        static_account_number: "",
        payment_types: ["payid", "bank_transfer"],
        expire_hours: 24,
        account_name: "",
        instructions: "",

        // PayID Checkout UI Style Settings
        payid_checkout_ui_styles: {
            scan_pay: {
                pay_label: {
                    font_size: "28px",
                    font_weight: "700",
                    color: "#000000",
                },
                amount: {
                    font_size: "28px",
                    font_weight: "700",
                    color: "#2CB5C5",
                },
            },
        },

        // PayTo Settings Tab
        payto_title: "PayTo",
        payto_description: "Approve a payment agreement to complete the purchase.",
        payto_testmode: true,
        payto_debug: true,
        payto_purpose: "OTHR",
        payto_agreement_expiry_days: "",

        // PayTo Checkout UI Style Settings
        payto_checkout_ui_styles: {
            scan_pay: {
                pay_label: {
                    font_size: "28px",
                    font_weight: "700",
                    color: "#000000",
                },
                amount: {
                    font_size: "28px",
                    font_weight: "700",
                    color: "#2CB5C5",
                },
            },
            submit_button: {
                font_family: "Helvetica, Arial, sans-serif",
                font_size: "17px",
                background: "#2ab5c4",
                border_radius: 10,
                border_color: "#2ab5c4",
                font_weight: "bold",
                text_color: "#000000",
            },
        },
        payto_payee_type: "ORGN",
        payto_maximum_amount: 1000,

        // General settings from parent gateway
        enabled: true,
        maccount_number: "",
        test_api_key: "",
        live_api_key: "",
        monoova_payments_api_url_sandbox: "https://api.m-pay.com.au",
        monoova_payments_api_url_live: "https://api.mpay.com.au",
        monoova_card_api_url_sandbox: "https://sand-api.monoova.com",
        monoova_card_api_url_live: "https://api.monoova.com",
    })

    const [isSaving, setIsSaving] = useState(false)
    const [saveNotice, setSaveNotice] = useState(null)
    const [validationErrors, setValidationErrors] = useState({})

    const [isGenerating, setIsGenerating] = useState(false)

    // Webhook status state for both sandbox and live modes
    const [webhookStatus, setWebhookStatus] = useState({
        sandbox: {
            all_active: false,
            card_active: false,
            payto_active: false,
            payments_active: false,
            isChecking: false,
            isConnecting: false,
            lastChecked: null,
        },
        live: {
            all_active: false,
            card_active: false,
            payto_active: false,
            payments_active: false,
            isChecking: false,
            isConnecting: false,
            lastChecked: null,
        },
    })

    // Utility function to scroll to notice
    const scrollToNotice = useCallback(() => {
        setTimeout(() => {
            const noticeElement = document.querySelector(".monoova-save-notice")
            if (noticeElement) {
                noticeElement.scrollIntoView({
                    behavior: "smooth",
                    block: "center",
                })
            }
        }, 100)
    }, [])

    // Validation function to check if API credentials are available for a mode
    const validateApiCredentials = isTestmode => {
        const mode = isTestmode ? "sandbox" : "live"
        const apiKey = isTestmode ? settings.test_api_key : settings.live_api_key
        const paymentsApiUrl = isTestmode
            ? settings.monoova_payments_api_url_sandbox
            : settings.monoova_payments_api_url_live
        const cardApiUrl = isTestmode ? settings.monoova_card_api_url_sandbox : settings.monoova_card_api_url_live
        const maccountNumber = settings.maccount_number

        const missingFields = []
        if (!apiKey || apiKey.trim() === "") {
            missingFields.push("API Key")
        }
        if (!paymentsApiUrl || paymentsApiUrl.trim() === "") {
            missingFields.push("PayID API URL")
        }
        if (!cardApiUrl || cardApiUrl.trim() === "") {
            missingFields.push("Card API URL")
        }
        if (!maccountNumber || maccountNumber.trim() === "") {
            missingFields.push("mAccount Number")
        }

        return {
            isValid: missingFields.length === 0,
            missingFields,
            mode: mode.charAt(0).toUpperCase() + mode.slice(1),
        }
    }

    // Load settings on component mount
    useEffect(() => {
        const loadSettings = async () => {
            if (window.monoovaAdminSettings) {
                const processedSettings = { ...window.monoovaAdminSettings }

                // List of known boolean fields that need conversion
                const booleanFields = [
                    "enabled",
                    "enable_card_payments",
                    "enable_payid_payments",
                    "enable_payto_payments",
                    "enable_express_checkout",
                    "enable_payto_express_checkout",
                    "capture",
                    "saved_cards",
                    "apply_surcharge",
                    "enable_apple_pay",
                    "enable_google_pay",
                    "card_testmode",
                    "card_debug",
                    "payid_testmode",
                    "payid_debug",
                    "payid_show_reference_field",
                    "payto_testmode",
                    "payto_debug",
                ]

                // Convert various boolean formats to actual booleans
                booleanFields.forEach(field => {
                    const value = processedSettings[field]

                    if (typeof value === "string") {
                        // Handle 'yes'/'no', '1'/'0', and empty strings
                        processedSettings[field] = value === "yes" || value === "1" || value === "true"
                    } else if (typeof value === "number") {
                        // Handle numeric 1/0
                        processedSettings[field] = Boolean(value)
                    } else if (typeof value === "boolean") {
                        // Already boolean, no conversion needed
                        processedSettings[field] = value
                    } else {
                        // Default to false for any other type (null, undefined, etc.)
                        processedSettings[field] = false
                    }
                })

                setSettings(prevSettings => ({
                    ...prevSettings,
                    ...processedSettings,
                }))
            }
        }

        loadSettings()
    }, [])

    // Webhook status check function
    const checkWebhookStatus = async (isTestmode = true) => {
        const mode = isTestmode ? "sandbox" : "live"

        // Validate API credentials first
        const validation = validateApiCredentials(isTestmode)
        if (!validation.isValid) {
            setWebhookStatus(prev => ({
                ...prev,
                [mode]: {
                    ...prev[mode],
                    isChecking: false,
                    all_active: false,
                    card_active: false,
                    payto_active: false,
                    payments_active: false,
                    validationError: `Monoova API client (${validation.mode}) is not available. Please check your configuration on API Credentials and API URLs sections.`,
                },
            }))
            return
        }

        setWebhookStatus(prev => ({
            ...prev,
            [mode]: { ...prev[mode], isChecking: true, validationError: null },
        }))

        try {
            if (!window.monoovaCheckWebhookSubscriptionsNonce) {
                throw new Error(
                    "Security nonce for checking webhook subscriptions not available. Please refresh the page."
                )
            }

            const formData = new FormData()
            formData.append("action", "monoova_check_webhook_subscriptions_status")
            formData.append("nonce", window.monoovaCheckWebhookSubscriptionsNonce)
            formData.append("is_testmode", isTestmode.toString())

            const response = await fetch(window.ajaxUrl, {
                method: "POST",
                body: formData,
            })

            const result = await response.json()

            if (result.success) {
                setWebhookStatus(prev => ({
                    ...prev,
                    [mode]: {
                        ...prev[mode],
                        all_active: result.data.all_active,
                        card_active: result.data.card_active,
                        payto_active: result.data.payto_active,
                        payments_active: result.data.payments_active,
                        lastChecked: new Date(),
                        isChecking: false,
                    },
                }))
            } else {
                throw new Error(result.data?.message || "Failed to check webhook status.")
            }
        } catch (error) {
            console.error("Error checking webhook status:", error)
            setWebhookStatus(prev => ({
                ...prev,
                [mode]: { ...prev[mode], isChecking: false },
            }))
        }
    }

    // Re-check webhook status when API credentials change
    useEffect(() => {
        // Only re-check if we have some settings loaded (not initial empty state)
        if (settings.maccount_number || settings.test_api_key || settings.live_api_key) {
            checkWebhookStatus(true) // Re-check sandbox
            checkWebhookStatus(false) // Re-check live
        }
    }, [
        settings.test_api_key,
        settings.live_api_key,
        settings.maccount_number,
        settings.monoova_payments_api_url_sandbox,
        settings.monoova_payments_api_url_live,
        settings.monoova_card_api_url_sandbox,
        settings.monoova_card_api_url_live,
    ])

    const saveSettings = useCallback(
        async (tabName = "all") => {
            setIsSaving(true)
            setSaveNotice(null)
            setValidationErrors({})

            try {
                // Validate live environment requirements
                const errors = {}

                // Check each payment method's live mode status independently
                const isCardLive = settings.enable_card_payments && !settings.card_testmode
                const isPayidLive = settings.enable_payid_payments && !settings.payid_testmode
                const isAnyLive = isCardLive || isPayidLive

                if (isAnyLive) {
                    // Live API Key is always required when any payment method is in live mode
                    if (!settings.live_api_key || settings.live_api_key.trim() === "") {
                        errors.live_api_key = __(
                            "Live API Key is required when any payment method has test mode disabled.",
                            "monoova-payments-for-woocommerce"
                        )
                    }
                }

                // Validate Payments API URL if PayID is in live mode
                if (isPayidLive) {
                    if (
                        !settings.monoova_payments_api_url_live ||
                        settings.monoova_payments_api_url_live.trim() === ""
                    ) {
                        errors.monoova_payments_api_url_live = __(
                            "Live Payments API URL is required when PayID test mode is disabled.",
                            "monoova-payments-for-woocommerce"
                        )
                    }
                }

                // Validate Card API URL if Card is in live mode
                if (isCardLive) {
                    if (!settings.monoova_card_api_url_live || settings.monoova_card_api_url_live.trim() === "") {
                        errors.monoova_card_api_url_live = __(
                            "Live Card API URL is required when Card test mode is disabled.",
                            "monoova-payments-for-woocommerce"
                        )
                    }
                }

                // If there are validation errors, show them and stop the save process
                if (Object.keys(errors).length > 0) {
                    setValidationErrors(errors)

                    // Create a user-friendly error message with field labels
                    const fieldLabels = {
                        live_api_key: __("Live API Key", "monoova-payments-for-woocommerce"),
                        monoova_payments_api_url_live: __("Live Payments API URL", "monoova-payments-for-woocommerce"),
                        monoova_card_api_url_live: __("Live Card API URL", "monoova-payments-for-woocommerce"),
                    }

                    const errorFields = Object.keys(errors)
                        .map(field => fieldLabels[field] || field)
                        .join(", ")
                    const errorMessage =
                        __(
                            "Please fix the following validation errors before saving: ",
                            "monoova-payments-for-woocommerce"
                        ) + errorFields

                    setSaveNotice({
                        type: "error",
                        message: errorMessage,
                    })
                    setIsSaving(false)

                    // Scroll to the notice
                    scrollToNotice()

                    return
                }

                // Ensure nonce is available
                // if (!window.monoovaAdminNonce) {
                //     throw new Error("Security nonce not available")
                // }

                // Ensure boolean values are explicitly set as booleans
                const preparedSettings = { ...settings }

                // List of known boolean fields
                const booleanFields = [
                    "enabled",
                    "enable_card_payments",
                    "enable_payid_payments",
                    "enable_payto_payments",
                    "enable_express_checkout",
                    "enable_payto_express_checkout",
                    "capture",
                    "saved_cards",
                    "apply_surcharge",
                    "enable_apple_pay",
                    "enable_google_pay",
                    "card_testmode",
                    "card_debug",
                    "payid_testmode",
                    "payid_debug",
                    "payid_show_reference_field",
                    "payto_testmode",
                    "payto_debug",
                ]

                booleanFields.forEach(field => {
                    preparedSettings[field] = Boolean(preparedSettings[field])
                })

                const formData = new FormData()
                formData.append("action", "monoova_save_payment_settings")
                // formData.append("nonce", window.monoovaAdminNonce)
                formData.append("settings", JSON.stringify(preparedSettings))
                formData.append("tab", tabName)

                const response = await fetch(window.ajaxUrl, {
                    method: "POST",
                    body: formData,
                })

                const result = await response.json()

                if (result.success) {
                    setSaveNotice({
                        type: "success",
                        message:
                            result.data.message ||
                            __("Settings saved successfully!", "monoova-payments-for-woocommerce"),
                    })

                    // Update local settings with any changes from server
                    if (result.data.settings) {
                        setSettings(prevSettings => ({
                            ...prevSettings,
                            ...result.data.settings,
                        }))
                    }

                    // Scroll to the success notice
                    scrollToNotice()
                } else {
                    throw new Error(result.data?.message || "Unknown error occurred")
                }
            } catch (error) {
                console.error("Error saving settings:", error)
                setSaveNotice({
                    type: "error",
                    message:
                        error.message ||
                        __("Failed to save settings. Please try again.", "monoova-payments-for-woocommerce"),
                })

                // Scroll to the error notice
                scrollToNotice()
            } finally {
                setIsSaving(false)
            }
        },
        [settings, scrollToNotice]
    )

    // NEW: Handler for generating the Automatcher account
    const handleGenerateAutomatcher = useCallback(async () => {
        setIsGenerating(true)
        setSaveNotice(null)

        try {
            if (!window.monoovaGenerateAutomatcherNonce) {
                throw new Error("Security nonce for generating automatcher not available. Please refresh the page.")
            }

            const formData = new FormData()
            formData.append("action", "monoova_generate_automatcher")
            formData.append("nonce", window.monoovaGenerateAutomatcherNonce)

            const response = await fetch(window.ajaxUrl, {
                method: "POST",
                body: formData,
            })

            const result = await response.json()

            if (result.success) {
                setSaveNotice({
                    type: "success",
                    message:
                        result.data.message ||
                        __("Automatcher account generated successfully!", "monoova-payments-for-woocommerce"),
                })
                // Update settings state with new values to refresh the UI
                setSettings(prevSettings => ({
                    ...prevSettings,
                    static_bsb: result.data.bsb,
                    static_account_number: result.data.accountNumber,
                    static_bank_account_name: result.data.accountName,
                }))

                // Scroll to the success notice
                scrollToNotice()
            } else {
                throw new Error(result.data?.message || "Unknown error occurred while generating account.")
            }
        } catch (error) {
            console.error("Error generating Automatcher account:", error)
            setSaveNotice({
                type: "error",
                message:
                    error.message ||
                    __(
                        "Failed to generate Automatcher account. Please check logs.",
                        "monoova-payments-for-woocommerce"
                    ),
            })

            // Scroll to the error notice
            scrollToNotice()
        } finally {
            setIsGenerating(false)
        }
    }, [])

    // Webhook subscription function
    const subscribeToWebhooks = useCallback(
        async (isTestmode = true) => {
            const mode = isTestmode ? "sandbox" : "live"

            // Validate API credentials first
            const validation = validateApiCredentials(isTestmode)
            if (!validation.isValid) {
                setSaveNotice({
                    type: "error",
                    message: `Monoova API client (${validation.mode}) is not available. Please check your configuration on API Credentials and API URLs sections.`,
                })
                scrollToNotice()
                return
            }

            setWebhookStatus(prev => ({
                ...prev,
                [mode]: { ...prev[mode], isConnecting: true, validationError: null },
            }))
            setSaveNotice(null)

            try {
                if (!window.monoovaSubscribeWebhookEventsNonce) {
                    throw new Error(
                        "Security nonce for subscribing to webhook events not available. Please refresh the page."
                    )
                }

                const formData = new FormData()
                formData.append("action", "monoova_subscribe_to_webhook_events")
                formData.append("nonce", window.monoovaSubscribeWebhookEventsNonce)
                formData.append("is_testmode", isTestmode.toString())

                const response = await fetch(window.ajaxUrl, {
                    method: "POST",
                    body: formData,
                })

                const result = await response.json()

                if (result.success) {
                    setSaveNotice({
                        type: "success",
                        message:
                            result.data.message ||
                            __("Webhook subscriptions updated successfully!", "monoova-payments-for-woocommerce"),
                    })

                    // Check webhook status again after successful subscription
                    await checkWebhookStatus(isTestmode)
                    scrollToNotice()
                } else {
                    throw new Error(result.data?.message || "Failed to subscribe to webhooks.")
                }
            } catch (error) {
                console.error("Error subscribing to webhooks:", error)
                setSaveNotice({
                    type: "error",
                    message:
                        error.message ||
                        __("Failed to subscribe to webhooks. Please check logs.", "monoova-payments-for-woocommerce"),
                })
                scrollToNotice()
            } finally {
                setWebhookStatus(prev => ({
                    ...prev,
                    [mode]: { ...prev[mode], isConnecting: false },
                }))
            }
        },
        [checkWebhookStatus, scrollToNotice]
    )

    const handleSettingChange = useCallback((key, value) => {
        setSettings(prevSettings => ({
            ...prevSettings,
            [key]: value,
        }))
    }, [])

    // Create stable onChange handlers using useMemo to prevent recreation on every render
    const onChangeHandlers = useMemo(
        () => ({
            enabled: value => handleSettingChange("enabled", value),
            maccount_number: value => handleSettingChange("maccount_number", value),
            test_api_key: value => handleSettingChange("test_api_key", value),
            live_api_key: value => handleSettingChange("live_api_key", value),
            monoova_payments_api_url_sandbox: value => handleSettingChange("monoova_payments_api_url_sandbox", value),
            monoova_payments_api_url_live: value => handleSettingChange("monoova_payments_api_url_live", value),
            monoova_card_api_url_sandbox: value => handleSettingChange("monoova_card_api_url_sandbox", value),
            monoova_card_api_url_live: value => handleSettingChange("monoova_card_api_url_live", value),
            enable_card_payments: value => handleSettingChange("enable_card_payments", value),
            enable_payid_payments: value => handleSettingChange("enable_payid_payments", value),
            enable_payto_payments: value => handleSettingChange("enable_payto_payments", value),
            enable_express_checkout: value => handleSettingChange("enable_express_checkout", value),
            enable_payto_express_checkout: value => handleSettingChange("enable_payto_express_checkout", value),
            express_checkout_method_priority: value => handleSettingChange("express_checkout_method_priority", value),
            // Card-specific fields
            card_title: value => handleSettingChange("card_title", value),
            card_description: value => handleSettingChange("card_description", value),
            card_testmode: value => handleSettingChange("card_testmode", value),
            card_debug: value => handleSettingChange("card_debug", value),
            capture: value => handleSettingChange("capture", value),
            saved_cards: value => handleSettingChange("saved_cards", value),
            apply_surcharge: value => handleSettingChange("apply_surcharge", value),
            surcharge_amount: value => handleSettingChange("surcharge_amount", value),
            enable_apple_pay: value => handleSettingChange("enable_apple_pay", value),
            enable_google_pay: value => handleSettingChange("enable_google_pay", value),
            order_button_text: value => handleSettingChange("order_button_text", value),
            // PayID-specific fields
            payid_title: value => handleSettingChange("payid_title", value),
            payid_description: value => handleSettingChange("payid_description", value),
            payid_testmode: value => handleSettingChange("payid_testmode", value),
            payid_debug: value => handleSettingChange("payid_debug", value),
            payid_show_reference_field: value => handleSettingChange("payid_show_reference_field", value),
            static_bank_account_name: value => handleSettingChange("static_bank_account_name", value),
            static_bsb: value => handleSettingChange("static_bsb", value),
            static_account_number: value => handleSettingChange("static_account_number", value),
            payment_types: value => handleSettingChange("payment_types", value),
            expire_hours: value => handleSettingChange("expire_hours", value),
            account_name: value => handleSettingChange("account_name", value),
            instructions: value => handleSettingChange("instructions", value),
            // PayTo-specific fields
            payto_title: value => handleSettingChange("payto_title", value),
            payto_description: value => handleSettingChange("payto_description", value),
            payto_testmode: value => handleSettingChange("payto_testmode", value),
            payto_debug: value => handleSettingChange("payto_debug", value),
            payto_purpose: value => handleSettingChange("payto_purpose", value),
            payto_agreement_expiry_days: value => handleSettingChange("payto_agreement_expiry_days", value),
            payto_payee_type: value => handleSettingChange("payto_payee_type", value),
            payto_maximum_amount: value => handleSettingChange("payto_maximum_amount", parseFloat(value) || 1000),
            // PayID UI Style Settings
            payid_checkout_ui_styles: value => handleSettingChange("payid_checkout_ui_styles", value),
            // PayTo UI Style Settings
            payto_checkout_ui_styles: value => handleSettingChange("payto_checkout_ui_styles", value),
        }),
        [handleSettingChange]
    )

    // Enhanced form submission handling
    useEffect(() => {
        // Enhanced form submission interception to prevent WooCommerce from reloading the page
        const handleWooCommerceFormSubmit = async event => {
            const form = event.target

            // Multiple ways to detect the unified gateway form
            const isUnifiedGatewayForm =
                form &&
                (form.querySelector("#monoova-payment-settings-container") ||
                    form.querySelector('input[name="woocommerce_monoova_unified_enabled"]') ||
                    form.querySelector('input[name*="monoova_unified"]') ||
                    window.location.href.includes("section=monoova_unified"))

            if (isUnifiedGatewayForm) {
                event.preventDefault()
                event.stopPropagation()

                // Find the submit button to manage its state
                const submitButton = form.querySelector('input[type="submit"], button[type="submit"], .button-primary')

                // Store original button value if not already stored
                if (submitButton && submitButton.value && !submitButton.getAttribute("data-original-value")) {
                    submitButton.setAttribute("data-original-value", submitButton.value)
                }

                try {
                    // Save settings via our React component
                    await saveSettings()

                    // Reset button state after successful save
                    if (submitButton) {
                        submitButton.classList.remove("is-busy")
                        submitButton.disabled = false
                        if (submitButton.value) {
                            submitButton.value = submitButton.getAttribute("data-original-value") || "Save changes"
                        }
                    }
                } catch (error) {
                    // Reset button state even if save fails
                    if (submitButton) {
                        submitButton.classList.remove("is-busy")
                        submitButton.disabled = false
                        if (submitButton.value) {
                            submitButton.value = submitButton.getAttribute("data-original-value") || "Save changes"
                        }
                    }
                    throw error // Re-throw to maintain error handling
                }

                return false
            }
        }

        // Add event listeners for form submissions
        document.addEventListener("submit", handleWooCommerceFormSubmit, true)

        // Also listen for the WooCommerce settings form submission event
        const wooCommerceForm = document.querySelector("form#mainform")
        if (wooCommerceForm) {
            wooCommerceForm.addEventListener("submit", handleWooCommerceFormSubmit)
        }

        return () => {
            document.removeEventListener("submit", handleWooCommerceFormSubmit, true)
            if (wooCommerceForm) {
                wooCommerceForm.removeEventListener("submit", handleWooCommerceFormSubmit)
            }
        }
    }, [saveSettings]) // Include saveSettings in dependencies

    const tabs = [
        {
            name: "general_settings",
            title: __("General Settings", "monoova-payments-for-woocommerce"),
            content: (
                <GeneralSettingsTab
                    settings={settings}
                    saveNotice={saveNotice}
                    onChangeHandlers={onChangeHandlers}
                    setSaveNotice={setSaveNotice}
                    validationErrors={validationErrors}
                    webhookStatus={webhookStatus}
                    onCheckWebhookStatus={checkWebhookStatus}
                    onSubscribeToWebhooks={subscribeToWebhooks}
                />
            ),
        },
        {
            name: "payment_methods",
            title: __("Payment methods", "monoova-payments-for-woocommerce"),
            content: (
                <PaymentMethodsTab
                    settings={settings}
                    saveNotice={saveNotice}
                    onChangeHandlers={onChangeHandlers}
                    setSaveNotice={setSaveNotice}
                />
            ),
        },
        {
            name: "card_settings",
            title: __("Card settings", "monoova-payments-for-woocommerce"),
            content: (
                <CardSettingsTab
                    settings={settings}
                    saveNotice={saveNotice}
                    onChangeHandlers={onChangeHandlers}
                    setSaveNotice={setSaveNotice}
                    handleSettingChange={handleSettingChange}
                />
            ),
        },
        {
            name: "payid_settings",
            title: __("PayID settings", "monoova-payments-for-woocommerce"),
            content: (
                <PayIDSettingsTab
                    settings={settings}
                    saveNotice={saveNotice}
                    onChangeHandlers={onChangeHandlers}
                    setSaveNotice={setSaveNotice}
                    // Pass the new handler and state as props
                    onGenerateAutomatcher={handleGenerateAutomatcher}
                    isGenerating={isGenerating}
                />
            ),
        },
        {
            name: "payto_settings",
            title: __("PayTo settings", "monoova-payments-for-woocommerce"),
            content: (
                <PayToSettingsTab
                    settings={settings}
                    saveNotice={saveNotice}
                    onChangeHandlers={onChangeHandlers}
                    setSaveNotice={setSaveNotice}
                    onGenerateAutomatcher={handleGenerateAutomatcher}
                    isGenerating={isGenerating}
                />
            ),
        },
    ]

    return (
        <div className="monoova-payment-settings">
            <TabPanel className="monoova-settings-tabs" activeClass="is-active" tabs={tabs}>
                {tab => {
                    return <VStack spacing={6}>{tab.content}</VStack>
                }}
            </TabPanel>
        </div>
    )
}

export default PaymentSettings
