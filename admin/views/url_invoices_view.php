<?php
// admin/views/url_invoices_view.php
declare(strict_types=1);

$pageTitle = 'Stateless URL Faktury - BTCPay Lite';
$activeMenu = 'url_invoices';
require __DIR__ . '/layout/header.php';
?>

<div class="page-header">
    <h1><i class="fa-solid fa-link" style="color:#2fd35a;"></i> Stateless URL Faktury</h1>
</div>

<!-- Generování faktury -->
<div class="card">
    <div class="card-title">
        <span style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-plus-circle" style="color:#20b948;"></i> Vystavit Stateless Fakturu (URL)</span>
    </div>
    <form id="createForm">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
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
        <!-- Grid s expirací -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
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
</div>

<!-- Ověřovač cizích faktur -->
<div class="card">
    <div class="card-title">
        <span style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-magnifying-glass" style="color:#20b948;"></i> Lupa - Ověřit cizí URL fakturu</span>
    </div>
    <form id="verifyForm">
        <div class="field">
            <label>Vložte odkaz (nebo token) od zákazníka</label>
            <div class="input-wrap">
                <input type="text" id="verifyInput" placeholder="https://..." required>
            </div>
        </div>
        <button type="submit" class="primary" id="btnVerify"><i class="fa-solid fa-radar"></i> Ověřit a detekovat peněženku</button>
    </form>
    <div id="verifyResult" style="display:none; margin-top: 15px; padding: 15px; background: #ffffff; border: 1px solid #e5eae7; border-radius: 8px;"></div>
</div>

<!-- Výpis faktur z prohlížeče -->
<div class="card">
    <div class="card-title">
        <span style="display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-clock-rotate-left" style="color:#748078;"></i> Historie v prohlížeči</span>
        <div style="display:flex; gap:10px;">
            <button class="ghost-btn" onclick="document.getElementById('importFile').click()"><i class="fa-solid fa-file-import"></i> Import</button>
            <input type="file" id="importFile" style="display:none" accept=".json" onchange="importData(event)">
            <button class="ghost-btn" onclick="exportData()"><i class="fa-solid fa-file-export"></i> Export</button>
            <button class="ghost-btn" onclick="clearHistory()" style="color:#ef4d4d; border-color:#fee2e2; background:#fff0f0;"><i class="fa-solid fa-trash"></i> Smazat z paměti</button>
        </div>
    </div>
    <div id="invoiceList"></div>
</div>

<script>
const STORAGE_KEY = 'url_btc_invoices';

const showToast = (msg) => {
    document.getElementById('toastMsg').innerText = msg;
    const t = document.getElementById('toast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3000);
}

const getInvoices = () => JSON.parse(localStorage.getItem(STORAGE_KEY)) || [];
const saveInvoices = (data) => localStorage.setItem(STORAGE_KEY, JSON.stringify(data));

// AJAX volání
async function apiCall(action, formData) {
    formData.append('api_action', action);
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        return await res.json();
    } catch (err) {
        throw new Error('Chyba spojení se serverem.');
    }
}

// VYTVOŘENÍ FAKTURY
document.getElementById('createForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnCreate');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Zpracovávám...';

    const formData = new URLSearchParams();
    formData.append('wallet', document.getElementById('walletSelect').value);
    formData.append('amount', document.getElementById('amount').value);
    formData.append('description', document.getElementById('desc').value);
    formData.append('order_id', document.getElementById('order_id').value);
    formData.append('expiration_minutes', document.getElementById('expiration_minutes').value);

    try {
        const data = await apiCall('create', formData);

        if (data.status === 'ok') {
            const invoices = getInvoices();
            invoices.unshift({
                token: data.token,
                url: data.url,
                amount: data.amount,
                desc: data.desc,
                order_id: data.order_id,
                wallet: data.wallet,
                time: data.time,
                lastStatus: 'unknown'
            });
            saveInvoices(invoices);
            renderInvoices();
            showToast('Faktura vygenerována a uložena.');
            e.target.reset();
        } else { 
            alert('Chyba serveru: ' + data.message); 
        }
    } catch (err) { 
        alert(err.message); 
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-magic"></i> Vygenerovat zabezpečený odkaz';
    }
});

// OVĚŘOVÁNÍ CIZÍCH ODKAZŮ A AUTO-DETEKCE
document.getElementById('verifyForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btnVerify');
    const resDiv = document.getElementById('verifyResult');
    const inputVal = document.getElementById('verifyInput').value;

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Ověřuji...';
    resDiv.style.display = 'none';

    let token = inputVal;
    if (inputVal.includes('inv=')) {
        const urlParams = new URLSearchParams(inputVal.substring(inputVal.indexOf('?')));
        token = urlParams.get('inv') || inputVal;
    }

    const formData = new URLSearchParams();
    formData.append('token', token);

    try {
        const data = await apiCall('check_status', formData);
        
        if (data.status === 'ok') {
            const missingText = data.payment_status === 'underpaid' ? `<div style="color:#d97706; font-weight:700; margin-top:5px;">Chybí doplatit: ${data.missing_amount} BTC</div>` : '';
            resDiv.innerHTML = `
                <div style="margin-bottom:12px;">${getStatusBadge(data.payment_status)}</div>
                <div style="font-size: 13px; color: #17201a; line-height: 1.6;">
                    <strong>Popis:</strong> ${data.desc}<br>
                    <strong>Částka:</strong> ${data.amount} BTC<br>
                    ${data.order_id ? `<strong>Interní ID:</strong> ${data.order_id}<br>` : ''}
                    <strong>Peněženka:</strong> 📁 ${data.wallet}<br>
                    ${missingText}
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <button class="ghost-btn" onclick="saveVerifiedToHistory('${data.token}', '${data.url}', '${data.amount}', '${data.desc}', '${data.order_id}', '${data.wallet}', ${data.time}, '${data.payment_status}')"><i class="fa-solid fa-floppy-disk"></i> Uložit do historie</button>
                    <a href="${data.url}" target="_blank" class="ghost-btn" style="text-decoration:none;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít fakturu</a>
                </div>
            `;
        } else {
            resDiv.innerHTML = `<span style="color:#ef4d4d; font-weight:600;"><i class="fa-solid fa-circle-xmark"></i> ${data.message}</span>`;
        }
    } catch (err) {
        resDiv.innerHTML = `<span style="color:#ef4d4d; font-weight:600;"><i class="fa-solid fa-triangle-exclamation"></i> ${err.message}</span>`;
    } finally {
        resDiv.style.display = 'block';
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-radar"></i> Ověřit a detekovat peněženku';
    }
});

// ULOŽENÍ OVĚŘENÉ FAKTURY DO HISTORIE
window.saveVerifiedToHistory = function(token, url, amount, desc, order_id, wallet, time, lastStatus) {
    const invoices = getInvoices();
    const exists = invoices.find(i => i.token === token);
    if (!exists) {
        invoices.unshift({ token, url, amount, desc, order_id, wallet, time, lastStatus });
        saveInvoices(invoices);
        renderInvoices();
        showToast('Faktura uložena do historie.');
    } else {
        showToast('Tato faktura již v historii je.');
    }
}

// KONTROLA HISTORICKÝCH FAKTUR
window.checkStatusByToken = async function(token, btnId) {
    const invoices = getInvoices();
    const index = invoices.findIndex(i => i.token === token);
    if (index === -1) return;
    
    const inv = invoices[index];
    const btn = document.getElementById(btnId);
    const originalText = btn.innerHTML;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    const formData = new URLSearchParams();
    formData.append('token', inv.token);

    try {
        const data = await apiCall('check_status', formData);
        if (data.status === 'ok') {
            inv.lastStatus = data.payment_status;
            invoices[index] = inv;
            saveInvoices(invoices);
            renderInvoices();
            showToast('Stav faktury aktualizován.');
        } else { 
            alert('Chyba: ' + data.message); 
            btn.innerHTML = originalText; 
        }
    } catch (err) { 
        alert(err.message); 
        btn.innerHTML = originalText; 
    }
}

// Smazání faktury
window.deleteInvoice = function(index) {
    if(confirm('Opravdu smazat tuto fakturu z historie?')) {
        const invoices = getInvoices();
        invoices.splice(index, 1);
        saveInvoices(invoices);
        renderInvoices();
        showToast('Faktura smazána.');
    }
}

// Smazání celé historie
function clearHistory() {
    if(confirm('Tato akce smaže historii lokálních faktur z vašeho prohlížeče. Pokračovat?')) {
        localStorage.removeItem(STORAGE_KEY);
        renderInvoices();
        showToast('Paměť prohlížeče byla vymazána.');
    }
}

// Export
function exportData() {
    const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(localStorage.getItem(STORAGE_KEY) || '[]');
    const a = document.createElement('a');
    a.href = dataStr;
    a.download = "url_invoices_backup.json";
    a.click();
}

// Import
function importData(event) {
    const file = event.target.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = (e) => {
        try {
            const imported = JSON.parse(e.target.result);
            if (Array.isArray(imported)) {
                saveInvoices(imported);
                renderInvoices();
                showToast('Záloha faktur byla úspěšně nahrána.');
            } else { 
                alert('Neplatný formát dat v souboru.'); 
            }
        } catch(err) { 
            alert('Nastala chyba při čtení souboru.'); 
        }
    };
    reader.readAsText(file);
    event.target.value = '';
}

function getStatusBadge(status) {
    const sMap = {
        'paid': 'Zaplaceno',
        'paid_late': 'Zaplaceno (Zpožděně)',
        'pending_mempool': 'V Síti',
        'unpaid': 'Nezaplaceno',
        'expired': 'Vypršela',
        'underpaid': 'Nedoplatek'
    };
    return `<span class="status-badge s-${status}">${sMap[status] || 'Neznámý stav'}</span>`;
}

function renderInvoices() {
    const list = document.getElementById('invoiceList');
    const invoices = getInvoices();

    if (invoices.length === 0) {
        list.innerHTML = '<div style="text-align:center; padding:30px; color:#748078;"><i class="fa-solid fa-inbox" style="font-size:30px; margin-bottom:10px; color:#e5eae7;"></i><br>Zatím nemáte uložené žádné URL faktury.</div>';
        return;
    }

    let html = '';
    invoices.forEach((inv, index) => {
        const d = new Date(inv.time * 1000);
        const orderIdHtml = inv.order_id ? ` &nbsp;&bull;&nbsp; <strong style="color:#17201a;">ID: ${inv.order_id}</strong>` : '';
        html += `
            <div class="invoice-item">
                <div class="invoice-header">
                    <div>
                        <strong style="font-size:15px;">${inv.desc}</strong>
                        <div style="font-size:11px; color:#748078; margin-top:4px;">
                            <i class="fa-regular fa-clock"></i> ${d.toLocaleString('cs-CZ')} &nbsp;&bull;&nbsp; 📁 ${inv.wallet}${orderIdHtml}
                        </div>
                    </div>
                    <div style="text-align:right;">
                        <div class="invoice-amount">${inv.amount} BTC</div>
                        <div style="margin-top:4px;">${getStatusBadge(inv.lastStatus)}</div>
                    </div>
                </div>
                <div style="font-size:11px; background:#ffffff; padding:10px; border:1px solid #e5eae7; border-radius:6px; word-break:break-all; font-family:ui-monospace, monospace;">
                    <a href="${inv.url}" target="_blank" style="color:#17201a; text-decoration:underline; font-weight:600;">${inv.url}</a>
                </div>
                <div class="invoice-actions">
                    <button class="ghost-btn" onclick="navigator.clipboard.writeText('${inv.url}'); showToast('URL zkopírována');"><i class="fa-regular fa-copy"></i> Kopírovat</button>
                    <button class="primary" id="btn-check-${index}" onclick="checkStatusByToken('${inv.token}', 'btn-check-${index}')" style="margin-left:auto;"><i class="fa-solid fa-rotate"></i> Zkontrolovat stav</button>
                    <button class="ghost-btn" onclick="deleteInvoice(${index})" style="color:#ef4d4d; border-color:#fee2e2; background:#fff0f0;" title="Smazat fakturu"><i class="fa-solid fa-trash"></i></button>
                </div>
            </div>
        `;
    });
    list.innerHTML = html;
}

renderInvoices();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>