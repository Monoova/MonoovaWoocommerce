document.addEventListener("DOMContentLoaded", function () {
    const container = document.getElementById("monoova-payment-instructions")
    if (!container) return

    // --- Copy to Clipboard ---
    container.addEventListener("click", function (e) {
        const button = e.target.closest(".monoova-copy-button")
        if (!button) return

        const targetId = button.dataset.targetId
        const targetElement = container.querySelector(`.copy-target[data-copy-id="${targetId}"]`)
        if (!targetElement) return

        const textToCopy = targetElement.innerText.trim()
        navigator.clipboard
            .writeText(textToCopy)
            .then(() => {})
            .catch(err => console.error("Failed to copy: ", err))
    })

    // --- QR Code Generation ---
    const qrCodeContainer = document.getElementById("monoova-qr-code")
    if (qrCodeContainer) {
        const qrPayload = qrCodeContainer.getAttribute("data-payload")
        if (qrPayload && typeof QRCode !== "undefined") {
            // Clear any previous QR code
            qrCodeContainer.innerHTML = ""
            new QRCode(qrCodeContainer, {
                text: qrPayload,
                width: 180,
                height: 180,
                colorDark: "#000000",
                colorLight: "#ffffff",
                correctLevel: QRCode.CorrectLevel.H,
            })
        }
    }

    // --- Method Switcher ---
    const switcher = document.getElementById("monoova-method-switcher")
    if (switcher) {
        const payidView = document.getElementById("monoova-payid-view")
        const bankView = document.getElementById("monoova-bank-view")
        switcher.addEventListener("change", function () {
            if (this.value === "payid") {
                payidView.style.display = "block"
                bankView.style.display = "none"
            } else {
                payidView.style.display = "none"
                bankView.style.display = "block"
            }
        })
    }

    // --- Polling and Countdown Logic ---
    const orderId = container.dataset.orderId
    const orderKey = container.dataset.orderKey
    const nonce = container.dataset.nonce
    const ajaxUrl = container.dataset.ajaxUrl
    const expiryTimestamp = container.dataset.expiryTimestamp

    const pendingView = document.getElementById("monoova-payment-pending")
    const confirmedView = document.getElementById("monoova-payment-confirmed")
    const expiredView = document.getElementById("monoova-payment-expired")
    const rejectedView = document.getElementById("monoova-payment-rejected")
    const rejectionReasonEl = document.getElementById("monoova-rejection-reason")
    const expiryInfo = document.getElementById("monoova-expiry-info")

    let pollingInterval, countdownInterval

    const stopPolling = () => {
        if (pollingInterval) {
            clearInterval(pollingInterval)
        }
    }

    const updateStatusView = (status, reason = "") => {
        stopPolling()
        pendingView.style.display = "none"
        confirmedView.style.display = "none"
        rejectedView.style.display = "none"
        expiredView.style.display = "none"

        switch (status) {
            case "paid":
                confirmedView.style.display = "block"
                break
            case "failed":
                if (rejectionReasonEl && reason) {
                    if (reason.length === 0) {
                        reason = "Unexpected Payment issue"
                    }
                    rejectionReasonEl.innerText = `Reason: ${reason}`
                }
                rejectedView.style.display = "block"
                break
            case "cancelled":
            case "expired":
                expiredView.style.display = "block"
                break
            default:
                // If status is pending or something else, keep showing pending view
                pendingView.style.display = "block"
                break
        }
    }

    const checkStatus = () => {
        const formData = new FormData()
        formData.append("action", "monoova_check_payment_status")
        formData.append("order_id", orderId)
        formData.append("order_key", orderKey)
        formData.append("nonce", nonce)

        fetch(ajaxUrl, {
            method: "POST",
            body: formData,
        })
            .then(response => response.json())
            .then(data => {
                if (data.success && data.data.status !== "pending") {
                    updateStatusView(data.data.status, data.data.reason)
                }
            })
            .catch(error => console.error("Error polling for payment status:", error))
    }

    const startPolling = () => {
        // Initial check
        checkStatus()
        // Poll every 10 seconds
        pollingInterval = setInterval(checkStatus, 10000)
    }

    const startCountdown = () => {
        if (!expiryTimestamp || expiryTimestamp <= 0 || !expiryInfo) return
        countdownInterval = setInterval(() => {
            const distance = expiryTimestamp * 1000 - new Date().getTime()
            if (distance < 0) {
                clearInterval(countdownInterval)
                updateStatusView("expired")
                return
            }
            const days = Math.floor(distance / (1000 * 60 * 60 * 24))
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60))
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60))
            const seconds = Math.floor((distance % (1000 * 60)) / 1000)

            const expiryLabel = expiryInfo.querySelector(".monoova-expiry-label")
            const expiryTime = expiryInfo.querySelector(".monoova-expiry-time")
            if (expiryLabel && expiryTime) {
                expiryTime.innerHTML = `<strong>${new Date(expiryTimestamp * 1000).toLocaleString()}</strong>`
                let remaining = []
                if (days > 0) remaining.push(`${days} ${days === 1 ? "day" : "days"}`)
                if (hours > 0) remaining.push(`${hours} ${hours === 1 ? "hour" : "hours"}`)
                if (minutes > 0) remaining.push(`${minutes} ${minutes === 1 ? "minute" : "minutes"}`)
                if (remaining.length < 3) remaining.push(`${seconds} ${seconds === 1 ? "second" : "seconds"}`)
                expiryTime.innerHTML += ` (${remaining.join(", ")} remaining)`
            }
        }, 1000)
    }

    startPolling()
    startCountdown()
})
