<?php

declare(strict_types=1);

$pageTitle = 'Faktury - BTCPay Lite';
$activeMenu = 'invoices';
require __DIR__ . '/layout/header.php';

$invoicesUrl = $routeUrl('/admin/invoices');
?>

<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Databázový checkout</p>
    <h1>Faktury</h1>
    <p>Vystavujte přesné bitcoinové faktury a spravujte checkout odkazy pro zákazníky.</p>
  </div>
  <div class="page-actions">
    <a href="<?php echo $routeUrl('/admin/url_invoices'); ?>" class="ghost-btn">
      <i class="fa-solid fa-link" aria-hidden="true"></i> Stateless URL faktury
    </a>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>

<div class="management-grid">
  <section class="card">
    <div class="card-title">
      <span class="card-title-group"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Nedávno vytvořené</span>
      <?php if ($invoicesHistory !== []): ?>
        <form method="post" action="<?php echo $invoicesUrl; ?>" data-confirm="Vymazat lokální náhled historie?">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="clear_history">
          <button type="submit" class="danger-btn"><i class="fa-solid fa-trash" aria-hidden="true"></i> Vymazat náhled</button>
        </form>
      <?php endif; ?>
    </div>
    <p class="card-subtitle">Posledních 20 faktur vytvořených v této přihlašovací relaci.</p>
    <?php if ($invoicesHistory === []): ?>
      <div class="empty-state"><div><i class="fa-regular fa-folder-open" aria-hidden="true"></i><p>V této relaci zatím nebyla vytvořená žádná faktura.</p></div></div>
    <?php else: ?>
      <div class="invoice-history">
      <?php foreach ($invoicesHistory as $invoice): ?>
        <article class="invoice-history-item">
          <div class="invoice-history-main">
            <strong><?php echo htmlspecialchars($invoice['desc'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <span class="muted"><time datetime="<?php echo date('c', $invoice['time']); ?>"><?php echo date('d.m.Y H:i:s', $invoice['time']); ?></time></span>
          </div>
          <code><?php echo htmlspecialchars($invoice['amount'], ENT_QUOTES, 'UTF-8'); ?> BTC</code>
          <a href="<?php echo htmlspecialchars($invoice['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener" class="ghost-btn"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Otevřít</a>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <aside class="card management-aside">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Vystavit fakturu</span></div>
    <p class="card-subtitle">Částka se zpracuje jako přesný BTC řetězec, nikdy přes desetinný typ <code>float</code>.</p>
    <form method="post" action="<?php echo $invoicesUrl; ?>" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label for="invoiceAmount">Částka</label>
        <div class="input-wrap"><input id="invoiceAmount" type="text" inputmode="decimal" name="amount" pattern="[0-9]+([.][0-9]{1,8})?" placeholder="0.00100000" required><span class="unit">BTC</span></div>
      </div>
      <div class="field">
        <label for="invoiceDescription">Popis</label>
        <div class="input-wrap"><input id="invoiceDescription" type="text" name="description" maxlength="200" placeholder="Např. Konzultace" required></div>
      </div>
      <div class="field">
        <label for="invoiceOrder">ID objednávky <span class="muted">(volitelné)</span></label>
        <div class="input-wrap"><input id="invoiceOrder" type="text" name="order_id" maxlength="100" placeholder="ORD-2026-001"></div>
      </div>
      <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Vytvořit fakturu</button></div>
    </form>

    <?php if ($newInvoiceUrl !== ''): ?>
      <div class="invoice-result" role="status">
        <span class="invoice-result-icon"><i class="fa-solid fa-check" aria-hidden="true"></i></span>
        <div><strong>Faktura je připravená</strong><a href="<?php echo htmlspecialchars($newInvoiceUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($newInvoiceUrl, ENT_QUOTES, 'UTF-8'); ?></a></div>
        <button type="button" class="ghost-btn" data-copy="<?php echo htmlspecialchars($newInvoiceUrl, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kopírovat checkout odkaz"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
      </div>
    <?php endif; ?>

    <div class="surface-note">
      <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
      <span>Výsledný odkaz vede zákazníka na veřejný checkout s QR kódem a automatickou kontrolou stavu.</span>
    </div>
  </aside>
</div>

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
    try { await navigator.clipboard.writeText(button.dataset.copy || ''); showToast('Odkaz byl zkopírován.'); }
    catch (error) { showToast('Kopírování se nepodařilo.'); }
  }));
  document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Potvrdit operaci?')) event.preventDefault();
  }));
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
