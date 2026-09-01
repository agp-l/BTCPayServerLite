<?php

declare(strict_types=1);

$pageTitle = 'Můj účet - BTCPay Lite';
require __DIR__ . '/layout/header.php';

$clientUrl = htmlspecialchars($urlManager->url('/client'), ENT_QUOTES, 'UTF-8');
$statusClasses = [
    'New' => 'badge-New',
    'Processing' => 'badge-Processing',
    'Settled' => 'badge-Settled',
    'Expired' => 'badge-Expired',
];
?>

<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Merchant portal</p>
    <h1>Přehled účtu</h1>
    <p>Spravujte své obchody, integrační klíče, webhooky a poslední přijaté faktury.</p>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>

<section class="stats-grid" aria-label="Statistiky účtu">
  <article class="stat-card">
    <span class="stat-icon"><i class="fa-solid fa-store" aria-hidden="true"></i></span>
    <div class="stat-label">Moje obchody</div>
    <div class="stat-value"><?php echo number_format($clientStats['total_stores'], 0, ',', ' '); ?></div>
    <div class="stat-meta">Aktivní API integrace</div>
  </article>
  <article class="stat-card stat-card-blue">
    <span class="stat-icon"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i></span>
    <div class="stat-label">Vytvořené faktury</div>
    <div class="stat-value"><?php echo number_format($clientStats['total_invoices'], 0, ',', ' '); ?></div>
    <div class="stat-meta">Napříč všemi obchody</div>
  </article>
  <article class="stat-card">
    <span class="stat-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span>
    <div class="stat-label">Zaplacené faktury</div>
    <div class="stat-value"><?php echo number_format($clientStats['paid_invoices'], 0, ',', ' '); ?></div>
    <div class="stat-meta">Potvrzené platby</div>
  </article>
  <article class="stat-card stat-card-amber">
    <span class="stat-icon"><i class="fa-brands fa-bitcoin" aria-hidden="true"></i></span>
    <div class="stat-label">Zůstatek peněženky</div>
    <div class="stat-value code"><?php echo is_array($walletBalance) ? htmlspecialchars(number_format((float) $walletBalance['confirmed'], 8, '.', ''), ENT_QUOTES, 'UTF-8') . ' BTC' : '—'; ?></div>
    <div class="stat-meta"><?php echo htmlspecialchars($walletError ?? 'Živý potvrzený zůstatek', ENT_QUOTES, 'UTF-8'); ?></div>
  </article>
</section>

<section class="card">
  <div class="card-title">
    <span class="card-title-group"><i class="fa-solid fa-store" aria-hidden="true"></i> Obchody a API přístup</span>
  </div>
  <p class="card-subtitle">Všechny vaše obchody používají jednu peněženku účtu; každý má oddělený API klíč.</p>

  <details class="disclosure">
    <summary><i class="fa-solid fa-plus" aria-hidden="true"></i> Vytvořit nový obchod</summary>
    <div class="disclosure-body">
      <form method="post" action="<?php echo $clientUrl; ?>" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="create_store">
        <div class="field"><label for="storeName">Název obchodu</label><div class="input-wrap"><input id="storeName" name="store_name" type="text" maxlength="100" required></div></div>
        <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Vytvořit obchod</button></div>
      </form>
    </div>
  </details>

  <?php if ($stores === []): ?>
    <div class="empty-state"><div><i class="fa-solid fa-store-slash" aria-hidden="true"></i><p>Zatím nemáte vytvořený žádný obchod.</p></div></div>
  <?php else: ?>
    <div class="store-grid">
    <?php foreach ($stores as $store): ?>
      <article class="store-card">
        <div class="store-card-head"><h3><?php echo htmlspecialchars($store['name'], ENT_QUOTES, 'UTF-8'); ?></h3><span class="badge s-paid">Aktivní</span></div>
        <div class="credential">
          <span class="credential-label">Store ID</span>
          <div class="credential-value"><code><?php echo htmlspecialchars($store['id'], ENT_QUOTES, 'UTF-8'); ?></code><button type="button" class="ghost-btn" data-copy="<?php echo htmlspecialchars($store['id'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kopírovat Store ID"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
        </div>
        <div class="credential">
          <span class="credential-label">API klíč</span>
          <div class="credential-value"><input type="password" readonly value="<?php echo htmlspecialchars($store['api_key'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="API klíč"><button type="button" class="ghost-btn" data-reveal aria-label="Zobrazit API klíč"><i class="fa-regular fa-eye" aria-hidden="true"></i></button><button type="button" class="ghost-btn" data-copy="<?php echo htmlspecialchars($store['api_key'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kopírovat API klíč"><i class="fa-regular fa-copy" aria-hidden="true"></i></button></div>
        </div>
      </article>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="merchant-grid">
  <section class="card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-wave-square" aria-hidden="true"></i> Webhooky</span></div>
    <p class="card-subtitle">Notifikace se odesílají pouze na bezpečně ověřené HTTPS endpointy.</p>

    <?php if ($stores !== []): ?>
      <details class="disclosure">
        <summary><i class="fa-solid fa-plus" aria-hidden="true"></i> Přidat webhook</summary>
        <div class="disclosure-body">
          <form method="post" action="<?php echo $clientUrl; ?>" class="form-stack">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_webhook">
            <div class="field"><label for="webhookStore">Obchod</label><div class="input-wrap"><select id="webhookStore" name="store_id" required><option value="">Vyberte obchod</option><?php foreach ($stores as $store): ?><option value="<?php echo htmlspecialchars($store['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($store['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div></div>
            <div class="field"><label for="webhookUrl">HTTPS URL</label><div class="input-wrap"><input id="webhookUrl" name="url" type="url" maxlength="2048" placeholder="https://example.com/webhook" required></div></div>
            <button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Uložit webhook</button>
          </form>
        </div>
      </details>
    <?php endif; ?>

    <?php if ($webhooks === []): ?>
      <div class="empty-state"><p>Žádné aktivní webhooky.</p></div>
    <?php else: ?>
      <div class="webhook-list">
      <?php foreach ($webhooks as $webhook): ?>
        <article class="webhook-item">
          <div class="webhook-main">
            <strong><?php echo htmlspecialchars($webhook['store_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <code title="<?php echo htmlspecialchars($webhook['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($webhook['url'], ENT_QUOTES, 'UTF-8'); ?></code>
            <div class="credential"><span class="credential-label">Podpisový secret</span><div class="credential-value"><input type="password" readonly value="<?php echo htmlspecialchars($webhook['secret'], ENT_QUOTES, 'UTF-8'); ?>"><button type="button" class="ghost-btn" data-reveal aria-label="Zobrazit webhook secret"><i class="fa-regular fa-eye" aria-hidden="true"></i></button></div></div>
          </div>
          <form method="post" action="<?php echo $clientUrl; ?>" data-confirm="Opravdu chcete webhook odstranit?">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="delete_webhook">
            <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($webhook['id'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="danger-btn" aria-label="Odstranit webhook"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
          </form>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Integrace</span></div>
    <p class="card-subtitle">Store ID a API klíč použijte v e-shopovém pluginu. Webhook secret slouží k ověření podpisu přijaté notifikace.</p>
    <div class="alert alert-warning"><i class="fa-solid fa-key" aria-hidden="true"></i><span>API klíče a webhook secrety ukládejte jako hesla. Nevkládejte je do veřejného zdrojového kódu.</span></div>
  </section>
</div>

<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Poslední faktury</span></div>
  <?php if ($invoices === []): ?>
    <div class="empty-state"><p>Zatím nemáte žádné faktury.</p></div>
  <?php else: ?>
    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Obchod</th><th>Faktura</th><th>Částka</th><th>Stav</th><th>Vytvořeno</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): ?>
      <?php $statusClass = $statusClasses[$invoice['status']] ?? 's-unknown'; ?>
      <tr><td><strong><?php echo htmlspecialchars($invoice['store_name'], ENT_QUOTES, 'UTF-8'); ?></strong></td><td><code class="truncate" title="<?php echo htmlspecialchars($invoice['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($invoice['id'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars($invoice['amount'], ENT_QUOTES, 'UTF-8'); ?></code> BTC</td><td><span class="badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($invoice['status'], ENT_QUOTES, 'UTF-8'); ?></span></td><td class="muted"><time datetime="<?php echo date('c', $invoice['created_at']); ?>"><?php echo date('d.m.Y H:i', $invoice['created_at']); ?></time></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<div class="merchant-grid">
  <section class="card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-plug" aria-hidden="true"></i> Moje integrace a e-shopy</span></div>
    <p class="card-subtitle">Integrace se objeví po úspěšném API požadavku s identifikačními hlavičkami pluginu.</p>
    <?php if ($integrations === []): ?>
      <div class="empty-state"><p>Zatím nebyla rozpoznána žádná pojmenovaná integrace.</p></div>
    <?php else: ?>
      <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Plugin</th><th>Verze</th><th>E-shop</th><th>Obchod</th><th>Naposledy</th></tr></thead><tbody>
      <?php foreach ($integrations as $integration): ?><tr><td><strong><?php echo htmlspecialchars((string) $integration['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td><td><?php echo htmlspecialchars((string) ($integration['version'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($integration['shop_origin'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $integration['store_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo date('d.m.Y H:i:s', (int) $integration['last_seen_at']); ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
  <section class="card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-arrow-right-arrow-left" aria-hidden="true"></i> Poslední API požadavky</span></div>
    <?php if ($requests === []): ?>
      <div class="empty-state"><p>Zatím nejsou zaznamenány žádné požadavky obchodů.</p></div>
    <?php else: ?>
      <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Čas</th><th>Obchod</th><th>Metoda</th><th>Cesta</th><th>Stav</th><th>Trvání</th></tr></thead><tbody>
      <?php foreach ($requests as $request): ?><tr><td><?php echo date('d.m.Y H:i:s', (int) $request['created_at']); ?></td><td><?php echo htmlspecialchars((string) $request['store_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars((string) $request['method'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars((string) $request['request_path'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><?php echo (int) $request['http_status']; ?></td><td><?php echo (int) $request['duration_ms']; ?> ms</td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-money-bill-transfer" aria-hidden="true"></i> Moje výběry</span></div>
  <?php if ($payouts === []): ?>
    <div class="empty-state"><p>Zatím nebyl vytvořen žádný výběr.</p></div>
  <?php else: ?>
    <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Čas</th><th>Obchod</th><th>ID</th><th>Cíl</th><th>Částka</th><th>Poplatek</th><th>Stav</th><th>TXID</th></tr></thead><tbody>
    <?php foreach ($payouts as $payout): ?><tr><td><?php echo date('d.m.Y H:i:s', (int) $payout['created_at']); ?></td><td><?php echo htmlspecialchars((string) $payout['store_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars((string) $payout['id'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars((string) $payout['destination'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars((string) $payout['payout_amount'], ENT_QUOTES, 'UTF-8'); ?> BTC</code></td><td><code><?php echo htmlspecialchars((string) $payout['exchange_fee'], ENT_QUOTES, 'UTF-8'); ?> BTC</code></td><td><?php echo htmlspecialchars((string) $payout['state'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars((string) ($payout['txid'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></code></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<script>
(() => {
  const toast = document.getElementById('toast');
  const toastText = document.getElementById('toastMsg');
  const showToast = (message) => {
    if (!toast || !toastText || !message) return;
    toastText.textContent = message;
    toast.classList.add('show');
    window.setTimeout(() => toast.classList.remove('show'), 3000);
  };
  showToast(<?php echo json_encode($toastMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);

  document.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(button.dataset.copy || ''); showToast('Zkopírováno do schránky.'); }
    catch (error) { showToast('Kopírování se nepodařilo.'); }
  }));
  document.querySelectorAll('[data-reveal]').forEach((button) => button.addEventListener('click', () => {
    const input = button.parentElement?.querySelector('input');
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.innerHTML = reveal ? '<i class="fa-regular fa-eye-slash" aria-hidden="true"></i>' : '<i class="fa-regular fa-eye" aria-hidden="true"></i>';
  }));
  document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Potvrdit operaci?')) event.preventDefault();
  }));
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
