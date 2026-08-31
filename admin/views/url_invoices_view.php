<?php
// admin/views/url_invoices_view.php
declare(strict_types=1);

$pageTitle = 'Stateless URL Faktury - BTCPay Lite';
$activeMenu = 'url_invoices';
require __DIR__ . '/layout/header.php';
?>

<section class="page-header">
    <div class="page-header-copy">
        <p class="page-eyebrow">Bez databáze</p>
        <h1>Stateless URL faktury</h1>
        <p>Podepsané platební odkazy bez databázového úložiště. Historie zůstává pouze v tomto prohlížeči.</p>
    </div>
    <div class="page-actions">
        <a href="<?php echo $routeUrl('/admin/invoices'); ?>" class="ghost-btn"><i class="fa-solid fa-database" aria-hidden="true"></i> Databázové faktury</a>
    </div>
</section>

<div class="url-invoice-workspace">
<section class="card">
    <div class="card-title">
        <span class="card-title-group"><i class="fa-solid fa-plus-circle" aria-hidden="true"></i> Vystavit URL fakturu</span>
    </div>
    <p class="card-subtitle">Server podepíše parametry faktury a vrátí přenositelný platební odkaz.</p>
    <form id="createForm">
        <div class="form-grid">
            <div class="field">
                <label>Cílová peněženka</label>
                <div class="input-wrap">
                    <select id="walletSelect" required>
                        <?php foreach ($availableWallets as $w): ?>
                            <option value="<?php echo htmlspecialchars($w); ?>" <?php if($w === $defaultWallet) echo 'selected'; ?>>📁 <?php echo htmlspecialchars($w); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Částka (BTC)</label>
                <div class="input-wrap"><input type="text" id="amount" placeholder="0.00100000" required><div class="unit">BTC</div></div>
            </div>
        </div>
        <div class="form-grid form-grid-wide">
            <div class="field">
                <label>Popis / Název položky</label>
                <div class="input-wrap"><input type="text" id="desc" placeholder="Např. Osobní konzultace" required></div>
            </div>
            <div class="field">
                <label>Interní ID (volitelné)</label>
                <div class="input-wrap"><input type="text" id="order_id" placeholder="Např. ORD-123"></div>
            </div>
            <div class="field">
                <label>Expirace platby</label>
                <div class="input-wrap">
                    <select id="expiration_minutes">
                        <option value="15">15 minut (E-shopy)</option>
                        <option value="60">1 hodina</option>
                        <option value="1440">1 den (24 hodin)</option>
                        <option value="10080">1 týden (7 dní)</option>
                        <option value="43200">1 měsíc (30 dní)</option>
                    </select>
                </div>
            </div>
        </div>
        <button type="submit" class="primary" id="btnCreate"><i class="fa-solid fa-magic"></i> Vygenerovat zabezpečený odkaz</button>
    </form>
</section>

<section class="card">
    <div class="card-title">
        <span class="card-title-group"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Ověřit URL fakturu</span>
    </div>
    <p class="card-subtitle">Ověří podpis, platnost a odpovídající peněženku bez uložení faktury do databáze.</p>
    <form id="verifyForm">
        <div class="field">
            <label>Vložte odkaz (nebo token) od zákazníka</label>
            <div class="input-wrap">
                <input type="text" id="verifyInput" placeholder="https://..." required>
            </div>
        </div>
        <button type="submit" class="primary" id="btnVerify"><i class="fa-solid fa-radar"></i> Ověřit a detekovat peněženku</button>
    </form>
    <div id="verifyResult" class="result-panel"></div>
    <div class="surface-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><span>Nikdy nedůvěřujte ručně upravenému odkazu. Platný kryptografický podpis je podmínkou zobrazení údajů.</span></div>
</section>
</div>

<section class="card">
    <div class="card-title">
        <span class="card-title-group"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Historie v prohlížeči</span>
        <div class="history-toolbar">
            <button type="button" class="ghost-btn" data-history-import><i class="fa-solid fa-file-import"></i> Import</button>
            <input type="file" id="importFile" class="visually-hidden" accept=".json,application/json">
            <button type="button" class="ghost-btn" data-history-export><i class="fa-solid fa-file-export"></i> Export</button>
            <button type="button" class="danger-btn" data-history-clear><i class="fa-solid fa-trash"></i> Smazat z paměti</button>
        </div>
    </div>
    <p class="card-subtitle">Lokální pracovní historie se neposílá na server. Export slouží jako přenositelná záloha.</p>
    <div id="invoiceList"></div>
</section>

<script>
(() => {
    'use strict';

    const STORAGE_KEY = 'url_btc_invoices';
    const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const allowedStatuses = new Set(['paid', 'paid_late', 'pending_mempool', 'unpaid', 'expired', 'underpaid', 'unknown']);
    const statusLabels = {
        paid: 'Zaplaceno',
        paid_late: 'Zaplaceno (zpožděně)',
        pending_mempool: 'V síti',
        unpaid: 'Nezaplaceno',
        expired: 'Vypršela',
        underpaid: 'Nedoplatek',
        unknown: 'Neznámý stav'
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
            const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');
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
        if (!data || typeof data !== 'object') throw new Error('Server vrátil neplatnou odpověď.');
        if (!response.ok && typeof data.message !== 'string') throw new Error('Požadavek se nepodařilo dokončit.');
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
            showToast('Fakturu nelze uložit.');
            return;
        }
        const invoices = getInvoices();
        if (invoices.some((item) => item.token === normalized.token)) {
            showToast('Tato faktura již v historii je.');
            return;
        }
        invoices.unshift(normalized);
        saveInvoices(invoices);
        renderInvoices();
        showToast('Faktura byla uložena do historie.');
    };

    const renderVerification = (data) => {
        if (!verifyResult) return;
        verifyResult.replaceChildren();
        verifyResult.style.display = 'block';

        if (data.status !== 'ok') {
            const error = createElement('span', 'error-text');
            error.append(createIcon('fa-circle-xmark'), document.createTextNode(` ${String(data.message || 'Ověření se nezdařilo.')}`));
            verifyResult.append(error);
            return;
        }

        const summary = createElement('div', 'verification-summary');
        summary.append(statusBadge(data.payment_status));
        const values = createElement('div', 'verification-data');
        appendDefinition(values, 'Popis', data.desc || '');
        appendDefinition(values, 'Částka', `${String(data.amount || '')} BTC`);
        if (data.order_id) appendDefinition(values, 'Interní ID', data.order_id);
        appendDefinition(values, 'Peněženka', data.wallet || '');
        if (normalizeStatus(data.payment_status) === 'underpaid') {
            values.append(createElement('div', 'underpaid-note', `Chybí doplatit: ${String(data.missing_amount || '')} BTC`));
        }

        const actions = createElement('div', 'verification-actions');
        const saveButton = createElement('button', 'ghost-btn', ' Uložit do historie');
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
            const openLink = createElement('a', 'ghost-btn', ' Otevřít fakturu');
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
        setButtonState(button, true, 'Kontroluji', 'fa-spinner fa-spin');
        try {
            const formData = new URLSearchParams({ token });
            const data = await apiCall('check_status', formData);
            if (data.status !== 'ok') throw new Error(String(data.message || 'Kontrola stavu se nezdařila.'));
            invoices[index].lastStatus = normalizeStatus(data.payment_status);
            saveInvoices(invoices);
            renderInvoices();
            showToast('Stav faktury byl aktualizován.');
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Kontrola stavu se nezdařila.');
            setButtonState(button, false, 'Zkontrolovat stav', 'fa-rotate');
        }
    };

    const renderInvoices = () => {
        if (!invoiceList) return;
        invoiceList.replaceChildren();
        const invoices = getInvoices();
        if (invoices.length === 0) {
            const empty = createElement('div', 'empty-state');
            const content = createElement('div');
            content.append(createIcon('fa-inbox'), createElement('p', '', 'Zatím nemáte uložené žádné URL faktury.'));
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
            const date = invoice.time > 0 ? new Date(invoice.time * 1000).toLocaleString('cs-CZ') : 'Čas neuveden';
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
            const copyButton = createElement('button', 'ghost-btn', ' Kopírovat');
            copyButton.type = 'button';
            copyButton.prepend(createIcon('fa-copy'));
            copyButton.addEventListener('click', async () => {
                try {
                    await navigator.clipboard.writeText(invoice.url);
                    showToast('URL byla zkopírována.');
                } catch (error) {
                    showToast('Kopírování se nepodařilo.');
                }
            });

            const checkButton = createElement('button', 'primary push-right', ' Zkontrolovat stav');
            checkButton.type = 'button';
            checkButton.prepend(createIcon('fa-rotate'));
            checkButton.addEventListener('click', () => checkStatusByToken(invoice.token, checkButton));

            const deleteButton = createElement('button', 'danger-btn');
            deleteButton.type = 'button';
            deleteButton.title = 'Smazat fakturu';
            deleteButton.setAttribute('aria-label', `Smazat fakturu ${invoice.desc}`);
            deleteButton.append(createIcon('fa-trash'));
            deleteButton.addEventListener('click', () => {
                if (!window.confirm('Opravdu smazat tuto fakturu z historie?')) return;
                const current = getInvoices();
                current.splice(index, 1);
                saveInvoices(current);
                renderInvoices();
                showToast('Faktura byla smazána.');
            });
            actions.append(copyButton, checkButton, deleteButton);
            item.append(header, urlBox, actions);
            invoiceList.append(item);
        });
    };

    createForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.getElementById('btnCreate');
        setButtonState(button, true, 'Zpracovávám', 'fa-spinner fa-spin');
        const formData = new URLSearchParams({
            wallet: document.getElementById('walletSelect')?.value || '',
            amount: document.getElementById('amount')?.value || '',
            description: document.getElementById('desc')?.value || '',
            order_id: document.getElementById('order_id')?.value || '',
            expiration_minutes: document.getElementById('expiration_minutes')?.value || '15'
        });
        try {
            const data = await apiCall('create', formData);
            if (data.status !== 'ok') throw new Error(String(data.message || 'Fakturu se nepodařilo vytvořit.'));
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
            if (!invoice) throw new Error('Server vrátil neplatná data faktury.');
            const invoices = getInvoices();
            invoices.unshift(invoice);
            saveInvoices(invoices);
            renderInvoices();
            showToast('Faktura byla vygenerována a uložena.');
            createForm.reset();
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Fakturu se nepodařilo vytvořit.');
        } finally {
            setButtonState(button, false, 'Vygenerovat zabezpečený odkaz', 'fa-wand-magic-sparkles');
        }
    });

    verifyForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = document.getElementById('btnVerify');
        const input = document.getElementById('verifyInput')?.value || '';
        let token = input;
        try {
            const candidate = new URL(input);
            token = candidate.searchParams.get('inv') || input;
        } catch (error) {
            token = input;
        }
        setButtonState(button, true, 'Ověřuji', 'fa-spinner fa-spin');
        if (verifyResult) {
            verifyResult.style.display = 'none';
            verifyResult.replaceChildren();
        }
        try {
            renderVerification(await apiCall('check_status', new URLSearchParams({ token })));
        } catch (error) {
            renderVerification({ status: 'error', message: error instanceof Error ? error.message : 'Ověření se nezdařilo.' });
        } finally {
            setButtonState(button, false, 'Ověřit fakturu', 'fa-shield-halved');
        }
    });

    document.querySelector('[data-history-import]')?.addEventListener('click', () => importFile?.click());

    document.querySelector('[data-history-clear]')?.addEventListener('click', () => {
        if (!window.confirm('Smazat lokální historii URL faktur z tohoto prohlížeče?')) return;
        window.localStorage.removeItem(STORAGE_KEY);
        renderInvoices();
        showToast('Paměť prohlížeče byla vymazána.');
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
            showToast('Soubor je příliš velký.');
            importFile.value = '';
            return;
        }
        const reader = new FileReader();
        reader.addEventListener('load', () => {
            try {
                const parsed = JSON.parse(String(reader.result || '[]'));
                if (!Array.isArray(parsed)) throw new Error('Neplatný formát.');
                const invoices = parsed.map(normalizeInvoice).filter(Boolean).slice(0, 500);
                if (parsed.length > 0 && invoices.length === 0) throw new Error('Záloha neobsahuje platné faktury.');
                saveInvoices(invoices);
                renderInvoices();
                showToast('Záloha faktur byla importována.');
            } catch (error) {
                showToast(error instanceof Error ? error.message : 'Soubor se nepodařilo načíst.');
            } finally {
                importFile.value = '';
            }
        });
        reader.readAsText(file);
    });

    renderInvoices();
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
