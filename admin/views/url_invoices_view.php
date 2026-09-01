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

<section class="stateless-mode-grid" aria-label="Vlastnosti stateless režimu">
    <article class="stateless-mode-item">
        <i class="fa-solid fa-database" aria-hidden="true"></i>
        <div><strong>Bez tabulky faktur</strong><span>Server neukládá částku, popis ani historii vystavených odkazů.</span></div>
    </article>
    <article class="stateless-mode-item">
        <i class="fa-solid fa-signature" aria-hidden="true"></i>
        <div><strong>Podepsaný obsah</strong><span>Změna adresy, částky nebo expirace zneplatní celý token.</span></div>
    </article>
    <article class="stateless-mode-item">
        <i class="fa-solid fa-feather" aria-hidden="true"></i>
        <div><strong>Přenosné jádro</strong><span>Režim používá pouze Electrum, stateless třídy a společné BTC výpočty.</span></div>
    </article>
</section>

<div class="url-invoice-workspace" data-url-invoices-app data-csrf-token="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
<section class="card">
    <div class="card-title">
        <span class="card-title-group"><i class="fa-solid fa-plus-circle" aria-hidden="true"></i> Vystavit URL fakturu</span>
    </div>
    <p class="card-subtitle">Server podepíše parametry faktury a vrátí přenositelný platební odkaz.</p>
    <form id="createForm">
        <div class="form-grid">
            <div class="field">
                <label for="walletSelect">Cílová peněženka</label>
                <div class="input-wrap">
                    <select id="walletSelect" required>
                        <?php foreach ($availableWallets as $w): ?>
                            <option value="<?php echo htmlspecialchars($w); ?>" <?php if($w === $defaultWallet) echo 'selected'; ?>>📁 <?php echo htmlspecialchars($w); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label for="amount">Částka (BTC)</label>
                <div class="input-wrap"><input type="text" id="amount" inputmode="decimal" autocomplete="off" placeholder="0.00100000" required><div class="unit">BTC</div></div>
            </div>
        </div>
        <div class="form-grid form-grid-wide">
            <div class="field">
                <label for="desc">Popis / Název položky</label>
                <div class="input-wrap"><input type="text" id="desc" placeholder="Např. Osobní konzultace" required></div>
            </div>
            <div class="field">
                <label for="order_id">Interní ID (volitelné)</label>
                <div class="input-wrap"><input type="text" id="order_id" placeholder="Např. ORD-123"></div>
            </div>
            <div class="field">
                <label for="expiration_minutes">Expirace platby</label>
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
            <label for="verifyInput">Vložte odkaz (nebo token) od zákazníka</label>
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

<script src="<?php echo $routeUrl('/assets/url-invoices.js'); ?>" defer></script>

<?php require __DIR__ . '/layout/footer.php'; ?>

