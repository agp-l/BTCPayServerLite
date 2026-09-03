(() => {
    'use strict';

    const app = document.querySelector('[data-url-invoices-app]');
    if (!(app instanceof HTMLElement)) return;

    const STORAGE_KEY = 'url_btc_invoices_v2';
    const LEGACY_STORAGE_KEY = 'url_btc_invoices';
    const CSRF_TOKEN = app.dataset.csrfToken || '';
    const allowedStatuses = new Set(['paid', 'paid_late', 'pending_mempool', 'unpaid', 'expired', 'underpaid', 'unknown']);
    const statusLabels = {
        paid: 'Paid',
        paid_late: 'Paid (Late)',
        pending_mempool: 'In Mempool',
        unpaid: 'Unpaid',
        expired: 'Expired',
        underpaid: 'Underpaid',
        unknown: 'Unknown'
    };

    const toast = document.getElementById('toast');
    const toastMessage = document.getElementById('toastMsg');
    const createForm = document.getElementById('createForm');
    const verifyForm = document.getElementById('verifyForm');
    const verifyResult = document.getElementById('verifyResult');
    const invoiceList = document.getElementById('invoiceList');
    const importFile = document.getElementById('importFile');

    const createElement = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text) node.textContent = text;
        return node;
    };

    const createIcon = (className) => {
        const icon = createElement('i', `fa-solid ${className}`);
        icon.setAttribute('aria-hidden', 'true');
        return icon;
    };

    const setButtonState = (button, busy, label, icon) => {
        if (!button) return;
        button.disabled = busy;
        button.replaceChildren(createIcon(icon), document.createTextNode(` ${label}`));
    };

    const showToast = (message) => {
        if (!toast || !toastMessage || !message) return;
        toastMessage.textContent = String(message);
        toast.classList.add('show');
        window.setTimeout(() => toast.classList.remove('show'), 3000);
    };

    const safeHttpUrl = (value) => {
        try {
            const url = new URL(String(value), window.location.href);
            return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : null;
        } catch (error) {
            return null;
        }
    };

    const normalizeStatus = (value) => {
        const status = String(value || 'unknown');
        return allowedStatuses.has(status) ? status : 'unknown';
    };

    const normalizeInvoice = (value) => {
        if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
        const token = String(value.token || '').slice(0, 8192);
        const url = safeHttpUrl(value.url);
        const amount = String(value.amount || '').slice(0, 64);
        const desc = String(value.desc || '').slice(0, 200);
        const orderId = String(value.order_id || '').slice(0, 100);
        const wallet = String(value.wallet || '').slice(0, 255);
        const time = Number.isFinite(Number(value.time)) ? Math.max(0, Math.trunc(Number(value.time))) : 0;

        if (!token || !url || !amount || !desc || !wallet) return null;
        return {
            token,
            url,
            amount,
            desc,
            order_id: orderId,
            wallet,
            time,
            lastStatus: normalizeStatus(value.lastStatus)
        };
    };

    const getInvoices = () => {
        try {
            const stored = window.localStorage.getItem(STORAGE_KEY)
                ?? window.localStorage.getItem(LEGACY_STORAGE_KEY)
                ?? '[]';
            const parsed = JSON.parse(stored);
            return Array.isArray(parsed) ? parsed.map(normalizeInvoice).filter(Boolean).slice(0, 500) : [];
        } catch (error) {
            return [];
        }
    };

    const saveInvoices = (invoices) => {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(invoices.map(normalizeInvoice).filter(Boolean).slice(0, 500)));
    };

    const statusBadge = (statusValue) => {
        const status = normalizeStatus(statusValue);
        return createElement('span', `status-badge s-${status}`, statusLabels[status]);
    };

    const apiCall = async (action, formData) => {
        formData.set('api_action', action);
        formData.set('csrf_token', CSRF_TOKEN);
        const response = await window.fetch(window.location.href, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            body: formData
        });
        const data = await response.json().catch(() => null);
        if (!data || typeof data !== 'object') throw new Error('Server returned invalid response.');
        if (!response.ok && typeof data.message !== 'string') throw new Error('Request could not be completed.');
        return data;
    };

    const appendDefinition = (container, label, value) => {
        const row = createElement('div', 'verification-row');
        row.append(createElement('strong', '', label), createElement('span', '', String(value)));
        container.append(row);
    };

    const saveVerifiedToHistory = (invoice) => {
        const normalized = normalizeInvoice(invoice);
        if (!normalized) {
            showToast('Unable to save invoice.');
            return;
        }
        const invoices = getInvoices();
        if (invoices.some((item) => item.token === normalized.token)) {
            showToast('This invoice is already in history.');
            return;
        }
        invoices.unshift(normalized);
        saveInvoices(invoices);
        renderInvoices();
        showToast('Invoice saved to history.');
    };

    const renderVerification = (data) => {
        if (!verifyResult) return;
        verifyResult.replaceChildren();
        verifyResult.style.display = 'block';

        if (data.status !== 'ok') {
            const error = createElement('span', 'error-text');
            error.append(createIcon('fa-circle-xmark'), document.createTextNode(` ${String(data.message || 'Verification failed.')}`));
            verifyResult.append(error);
            return;
        }

        const summary = createElement('div', 'verification-summary');
        summary.append(statusBadge(data.payment_status));
        const values = createElement('div', 'verification-data');
        appendDefinition(values, 'Description', data.desc || '');
        appendDefinition(values, 'Amount', `${String(data.amount || '')} BTC`);
        if (data.order_id) appendDefinition(values, 'Order ID', data.order_id);
        appendDefinition(values, 'Wallet', data.wallet || '');
        if (normalizeStatus(data.payment_status) === 'underpaid') {
            values.append(createElement('div', 'underpaid-note', `Missing payment: ${String(data.missing_amount || '')} BTC`));
        }

        const actions = createElement('div', 'verification-actions');
        const saveButton = createElement('button', 'ghost-btn', ' Save to History');
        saveButton.type = 'button';
        saveButton.prepend(createIcon('fa-floppy-disk'));
        saveButton.addEventListener('click', () => saveVerifiedToHistory({
            token: data.token,
            url: data.url,
            amount: data.amount,
            desc: data.desc,
            order_id: data.order_id,
            wallet: data.wallet,
            time: data.time,
            lastStatus: data.payment_status
        }));
        actions.append(saveButton);

        const verifiedUrl = safeHttpUrl(data.url);
        if (verifiedUrl) {
            const openLink = createElement('a', 'ghost-btn', ' Open Invoice');
            openLink.href = verifiedUrl;
            openLink.target = '_blank';
            openLink.rel = 'noopener';
            openLink.prepend(createIcon('fa-arrow-up-right-from-square'));
            actions.append(openLink);
        }

        verifyResult.append(summary, values, actions);
    };

    const checkStatusByToken = async (token, button) => {
        const invoices = getInvoices();
        const index = invoices.findIndex((invoice) => invoice.token === token);
        if (index < 0) return;
        setButtonState(button, true, 'Checking', 'fa-spinner fa-spin');
        try {
            const formData = new URLSearchParams({ token });
            const data = await apiCall('check_status', formData);
            if (data.status !== 'ok') throw new Error(String(data.message || 'Status check failed.'));
            invoices[index].lastStatus = normalizeStatus(data.payment_status);
            saveInvoices(invoices);
            renderInvoices();
            showToast('Invoice status updated.');
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Status check failed.');
            setButtonState(button, false, 'Check Status', 'fa-rotate');
        }
    };

    const renderInvoices = () => {
        if (!invoiceList) return;
        invoiceList.replaceChildren();
        const invoices = getInvoices();
        if (invoices.length === 0) {
            const empty = createElement('div', 'empty-state');
            const content = createElement('div');
            content.append(createIcon('fa-inbox'), createElement('p', '', 'No stored URL invoices found.'));
            empty.append(content);
            invoiceList.append(empty);
            return;
        }

        invoices.forEach((invoice, index) => {
            const item = createElement('article', 'invoice-item');
            const header = createElement('div', 'invoice-header');
            const main = createElement('div');
            main.append(createElement('strong', 'invoice-description', invoice.desc));
            const meta = createElement('div', 'invoice-meta');
            const date = invoice.time > 0 ? new Date(invoice.time * 1000).toLocaleString('en-US') : 'Time not specified';
            meta.textContent = `${date} · ${invoice.wallet}${invoice.order_id ? ` · ID: ${invoice.order_id}` : ''}`;
            main.append(meta);

            const amount = createElement('div', 'invoice-amount-block');
            amount.append(createElement('div', 'invoice-amount', `${invoice.amount} BTC`));
            const status = createElement('div', 'invoice-status');
            status.append(statusBadge(invoice.lastStatus));
            amount.append(status);
            header.append(main, amount);

            const urlBox = createElement('div', 'invoice-url');
            const link = createElement('a', '', invoice.url);
            link.href = invoice.url;
            link.target = '_blank';
            link.rel = 'noopener';
            urlBox.append(link);

            const actions = createElement('div', 'invoice-actions');
            const copyButton = createElement('button', 'ghost-btn', ' Copy');
            copyButton.type = 'button';
            copyButton.prepend(createIcon('fa-copy'));
            copyButton.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(invoice.url);
                    showToast('URL copied to clipboard.');
                } catch (error) {
                    showToast('Failed to copy URL.');
                }
            });

            const checkButton = createElement('button', 'primary push-right', ' Check Status');
            checkButton.type = 'button';
            checkButton.prepend(createIcon('fa-rotate'));
            checkButton.addEventListener('click', () => checkStatusByToken(invoice.token, checkButton));

            const deleteButton = createElement('button', 'danger-btn');
            deleteButton.type = 'button';
            deleteButton.title = 'Delete invoice';
            deleteButton.setAttribute('aria-label', `Delete invoice ${invoice.desc}`);
            deleteButton.append(createIcon('fa-trash'));
            deleteButton.addEventListener('click', () => {
                if (!window.confirm('Delete this invoice from local history?')) return;
                const current = getInvoices();
                current.splice(index, 1);
                saveInvoices(current);
                renderInvoices();
                showToast('Invoice deleted.');
            });
            actions.append(copyButton, checkButton, deleteButton);
            item.append(header, urlBox, actions);
            invoiceList.append(item);
        });
    };

    createForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.getElementById('btnCreate');
        setButtonState(button, true, 'Processing', 'fa-spinner fa-spin');
        const formData = new URLSearchParams({
            wallet: document.getElementById('walletSelect')?.value || '',
            amount: document.getElementById('amount')?.value || '',
            description: document.getElementById('desc')?.value || '',
            order_id: document.getElementById('order_id')?.value || '',
            expiration_minutes: document.getElementById('expiration_minutes')?.value || '15'
        });
        try {
            const data = await apiCall('create', formData);
            if (data.status !== 'ok') throw new Error(String(data.message || 'Failed to create invoice.'));
            const invoice = normalizeInvoice({
                token: data.token,
                url: data.url,
                amount: data.amount,
                desc: data.desc,
                order_id: data.order_id,
                wallet: data.wallet,
                time: data.time,
                lastStatus: 'unknown'
            });
            if (!invoice) throw new Error('Server returned invalid invoice data.');
            const invoices = getInvoices();
            invoices.unshift(invoice);
            saveInvoices(invoices);
            renderInvoices();
            showToast('Invoice generated and saved.');
            createForm.reset();
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Failed to create invoice.');
        } finally {
            setButtonState(button, false, 'Generate Secure Link', 'fa-wand-magic-sparkles');
        }
    });

    verifyForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.getElementById('btnVerify');
        const input = document.getElementById('verifyInput')?.value || '';
        let token = input;
        try {
            const candidate = new URL(input);
            token = candidate.searchParams.get('token') || candidate.searchParams.get('inv') || input;
        } catch (error) {
            token = input;
        }
        setButtonState(button, true, 'Verifying', 'fa-spinner fa-spin');
        if (verifyResult) {
            verifyResult.style.display = 'none';
            verifyResult.replaceChildren();
        }
        try {
            renderVerification(await apiCall('check_status', new URLSearchParams({ token })));
        } catch (error) {
            renderVerification({ status: 'error', message: error instanceof Error ? error.message : 'Verification failed.' });
        } finally {
            setButtonState(button, false, 'Verify Invoice', 'fa-shield-halved');
        }
    });

    document.querySelector('[data-history-import]')?.addEventListener('click', () => importFile?.click());

    document.querySelector('[data-history-clear]')?.addEventListener('click', () => {
        if (!window.confirm('Delete local invoice history from this browser?')) return;
        window.localStorage.removeItem(STORAGE_KEY);
        window.localStorage.removeItem(LEGACY_STORAGE_KEY);
        renderInvoices();
        showToast('Browser storage cleared.');
    });

    document.querySelector('[data-history-export]')?.addEventListener('click', () => {
        const blob = new Blob([JSON.stringify(getInvoices(), null, 2)], { type: 'application/json' });
        const downloadUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = downloadUrl;
        link.download = 'url_invoices_backup.json';
        link.click();
        URL.revokeObjectURL(downloadUrl);
    });

    importFile?.addEventListener('change', () => {
        const file = importFile.files?.[0];
        if (!file) return;
        if (file.size > 2 * 1024 * 1024) {
            showToast('File is too large.');
            importFile.value = '';
            return;
        }
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            try {
                const parsed = JSON.parse(String(reader.result || '[]'));
                if (!Array.isArray(parsed)) throw new Error('Invalid format.');
                const invoices = parsed.map(normalizeInvoice).filter(Boolean).slice(0, 500);
                if (parsed.length > 0 && invoices.length === 0) throw new Error('Backup does not contain valid invoices.');
                saveInvoices(invoices);
                renderInvoices();
                showToast('Invoice backup imported.');
            } catch (error) {
                showToast(error instanceof Error ? error.message : 'Failed to load file.');
            } finally {
                importFile.value = '';
            }
        });
        reader.readAsText(file);
    });

    renderInvoices();
})();
