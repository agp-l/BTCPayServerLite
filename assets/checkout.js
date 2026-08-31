(() => {
    'use strict';

    const app = document.getElementById('checkout-app');
    if (!app) {
        return;
    }

    const parsedSeconds = Number.parseInt(app.dataset.secondsRemaining || '0', 10);
    const config = {
        statusUrl: app.dataset.statusUrl || '',
        initialStatus: app.dataset.initialStatus || 'Expired',
        secondsRemaining: Number.isSafeInteger(parsedSeconds) ? parsedSeconds : 0,
    };

    const statusMeta = {
        New: ['Čekáme na platbu', 'new'],
        Processing: ['Platba přijata, čekáme na potvrzení', 'processing'],
        Settled: ['Platba potvrzena', 'settled'],
        Expired: ['Platnost faktury vypršela', 'expired'],
    };

    const statusBadge = document.getElementById('status-badge');
    const statusLabel = document.getElementById('status-label');
    const paymentPanel = document.getElementById('payment-panel');
    const successPanel = document.getElementById('success-panel');
    const timer = document.getElementById('checkout-timer');
    const walletLink = document.getElementById('wallet-link');
    const partialNotice = document.getElementById('partial-notice');
    const missingAmount = document.getElementById('missing-amount');
    const toast = document.getElementById('copy-toast');

    let currentStatus = typeof config.initialStatus === 'string'
        && Object.prototype.hasOwnProperty.call(statusMeta, config.initialStatus)
        ? config.initialStatus
        : 'Expired';
    let secondsRemaining = Number.isInteger(config.secondsRemaining)
        ? Math.max(0, config.secondsRemaining)
        : 0;
    let toastTimeout = 0;
    let pollTimeout = 0;
    let pollFailures = 0;

    const formatTime = (seconds) => {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainder = seconds % 60;
        if (hours > 0) {
            return hours + ':' + String(minutes).padStart(2, '0')
                + ':' + String(remainder).padStart(2, '0');
        }
        return minutes + ':' + String(remainder).padStart(2, '0');
    };

    const renderTimer = () => {
        if (!timer) {
            return;
        }
        if (currentStatus === 'Settled') {
            timer.hidden = true;
            return;
        }

        timer.hidden = false;
        timer.textContent = currentStatus === 'Expired' || secondsRemaining <= 0
            ? 'Čas pro platbu vypršel.'
            : 'Zbývající čas: ' + formatTime(secondsRemaining);
    };

    const renderStatus = (data) => {
        if (!data || typeof data.status !== 'string'
            || !Object.prototype.hasOwnProperty.call(statusMeta, data.status)
        ) {
            return false;
        }

        currentStatus = data.status;
        if (Number.isInteger(data.seconds_remaining)) {
            secondsRemaining = Math.max(0, data.seconds_remaining);
        }

        const [label, tone] = statusMeta[currentStatus];
        if (statusBadge) {
            statusBadge.className = 'status-badge status-' + tone;
        }
        if (statusLabel) {
            statusLabel.textContent = label;
        }

        const settled = currentStatus === 'Settled';
        const expired = currentStatus === 'Expired';
        if (paymentPanel) {
            paymentPanel.classList.toggle('is-hidden', settled);
        }
        if (successPanel) {
            successPanel.classList.toggle('is-visible', settled);
        }
        if (walletLink && expired) {
            walletLink.classList.add('is-disabled');
            walletLink.setAttribute('aria-disabled', 'true');
            walletLink.setAttribute('tabindex', '-1');
            walletLink.removeAttribute('href');
        }

        const partial = data.additional_status === 'PaidPartial' && !settled;
        if (partialNotice) {
            partialNotice.classList.toggle('is-visible', partial);
        }
        if (missingAmount && typeof data.missing_amount === 'string') {
            missingAmount.textContent = data.missing_amount + ' BTC';
        }

        renderTimer();
        return true;
    };

    const showToast = (message) => {
        if (!toast) {
            return;
        }
        window.clearTimeout(toastTimeout);
        toast.textContent = message;
        toast.classList.add('is-visible');
        toastTimeout = window.setTimeout(() => {
            toast.classList.remove('is-visible');
        }, 1800);
    };

    const fallbackCopy = (value) => {
        const input = document.createElement('textarea');
        input.value = value;
        input.setAttribute('readonly', '');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();

        let copied = false;
        try {
            copied = document.execCommand('copy');
        } catch (_error) {
            copied = false;
        }
        input.remove();
        return copied;
    };

    const copyValue = async (value) => {
        if (value === '') {
            return;
        }

        let copied = false;
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                copied = true;
            } catch (_error) {
                copied = false;
            }
        }
        if (!copied) {
            copied = fallbackCopy(value);
        }

        showToast(copied ? 'Zkopírováno' : 'Kopírování se nezdařilo');
    };

    document.querySelectorAll('[data-copy-value], [data-copy-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const directValue = button.getAttribute('data-copy-value');
            const targetId = button.getAttribute('data-copy-target');
            const target = targetId ? document.getElementById(targetId) : null;
            const value = directValue !== null
                ? directValue
                : (target ? target.textContent || '' : '');
            void copyValue(value.trim());
        });
    });

    const schedulePoll = () => {
        if (currentStatus === 'Settled' || currentStatus === 'Expired') {
            return;
        }

        const baseDelay = document.hidden ? 15000 : 5000;
        const failureDelay = Math.min(30000, baseDelay * Math.max(1, pollFailures));
        window.clearTimeout(pollTimeout);
        pollTimeout = window.setTimeout(poll, failureDelay);
    };

    const poll = async () => {
        if (currentStatus === 'Settled' || currentStatus === 'Expired') {
            return;
        }
        if (typeof config.statusUrl !== 'string' || config.statusUrl === '') {
            return;
        }

        const abortController = typeof AbortController === 'function'
            ? new AbortController()
            : null;
        const abortTimeout = abortController
            ? window.setTimeout(() => abortController.abort(), 8000)
            : 0;

        try {
            const response = await fetch(config.statusUrl, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {'Accept': 'application/json'},
                signal: abortController ? abortController.signal : undefined,
            });
            if (!response.ok) {
                throw new Error('Checkout status HTTP ' + response.status);
            }

            const data = await response.json();
            if (!renderStatus(data)) {
                throw new Error('Checkout status payload is invalid');
            }
            pollFailures = 0;
        } catch (_error) {
            pollFailures = Math.min(6, pollFailures + 1);
        } finally {
            if (abortTimeout) {
                window.clearTimeout(abortTimeout);
            }
            schedulePoll();
        }
    };

    window.setInterval(() => {
        if (currentStatus !== 'Settled' && currentStatus !== 'Expired'
            && secondsRemaining > 0
        ) {
            --secondsRemaining;
        }
        renderTimer();
    }, 1000);

    renderStatus({
        status: currentStatus,
        additional_status: partialNotice && partialNotice.classList.contains('is-visible')
            ? 'PaidPartial'
            : 'None',
        seconds_remaining: secondsRemaining,
        missing_amount: missingAmount ? missingAmount.textContent.replace(/\s*BTC$/, '') : '',
    });
    schedulePoll();
})();
