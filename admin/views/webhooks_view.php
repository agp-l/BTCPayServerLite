<?php

declare(strict_types=1);

$pageTitle = 'Webhooky - BTCPay Lite';
$activeMenu = 'webhooks';
require __DIR__ . '/layout/header.php';

$webhooksUrl = $routeUrl('/admin/webhooks');
?>

<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Notifikace</p>
    <h1>Webhooky</h1>
    <p>Správa podepsaných notifikací o změnách stavu faktur.</p>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>

<section class="card filter-card">
  <form method="get" action="<?php echo $webhooksUrl; ?>" class="filter-bar">
    <div class="field"><label for="webhookClientFilter">Zákazník</label><div class="input-wrap"><select id="webhookClientFilter" name="user_id"><option value="">Všichni zákazníci</option><option value="0" <?php echo $selectedUserId === 0 ? 'selected' : ''; ?>>Systém / bez klienta</option><?php foreach ($clients as $client): ?><option value="<?php echo (int) $client['id']; ?>" <?php echo $selectedUserId === (int) $client['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($client['email'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div></div>
    <div class="field"><label for="webhookStoreFilter">Obchod</label><div class="input-wrap"><select id="webhookStoreFilter" name="store_id"><option value="">Všechny obchody</option><?php foreach ($filterStores as $store): ?><option value="<?php echo htmlspecialchars((string) $store['id'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo $selectedStoreId === $store['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) $store['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div></div>
    <div class="filter-actions"><button type="submit" class="primary"><i class="fa-solid fa-filter"></i> Filtrovat</button><a class="ghost-btn" href="<?php echo $webhooksUrl; ?>">Zrušit</a></div>
  </form>
</section>

<div class="merchant-grid">
  <section class="card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-plus" aria-hidden="true"></i> Přidat webhook</span></div>
    <p class="card-subtitle">Endpoint se před uložením ověří proti SSRF, privátním adresám a nebezpečným DNS odpovědím.</p>
    <?php if ($stores === []): ?>
      <div class="alert alert-warning"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Nejprve vytvořte obchod.</span></div>
    <?php else: ?>
      <form method="post" action="<?php echo $webhooksUrl; ?>" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="create">
        <div class="field"><label for="webhookStore">Obchod</label><div class="input-wrap"><select id="webhookStore" name="store_id" required><option value="">Vyberte obchod</option><?php foreach ($stores as $store): ?><option value="<?php echo htmlspecialchars($store['id'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($store['name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div></div>
        <div class="field"><label for="webhookUrl">HTTPS URL</label><div class="input-wrap"><input id="webhookUrl" type="url" name="url" maxlength="2048" placeholder="https://shop.example/webhook" required></div></div>
        <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Uložit webhook</button></div>
      </form>
    <?php endif; ?>
  </section>

  <section class="card security-card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Bezpečné doručování</span></div>
    <p class="card-subtitle">Webhook secret ověřuje HMAC podpis. Neodesílejte jej v URL ani jej nezveřejňujte ve zdrojovém kódu.</p>
    <div class="alert alert-warning"><i class="fa-solid fa-lock" aria-hidden="true"></i><span>Lokální HTTP endpointy povolujte jen při vývoji pomocí explicitní konfigurace.</span></div>
  </section>
</div>

<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-wave-square" aria-hidden="true"></i> Aktivní webhooky</span><span class="badge s-unknown"><?php echo count($webhooks); ?></span></div>
  <?php if ($webhooks === []): ?>
    <div class="empty-state"><p>Žádné aktivní webhooky.</p></div>
  <?php else: ?>
    <div class="webhook-list">
    <?php foreach ($webhooks as $webhook): ?>
      <article class="webhook-item">
        <div class="webhook-main">
          <strong><?php echo htmlspecialchars($webhook['store_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
          <span class="muted"><?php echo htmlspecialchars((string) $webhook['client_email'], ENT_QUOTES, 'UTF-8'); ?></span>
          <code title="<?php echo htmlspecialchars($webhook['url'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($webhook['url'], ENT_QUOTES, 'UTF-8'); ?></code>
          <div class="credential"><span class="credential-label">Podpisový secret</span><div class="credential-value"><input type="password" readonly value="<?php echo htmlspecialchars($webhook['secret'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Webhook secret"><button type="button" class="ghost-btn" data-reveal aria-label="Zobrazit webhook secret"><i class="fa-regular fa-eye" aria-hidden="true"></i></button></div></div>
          <span class="muted code">ID: <?php echo htmlspecialchars($webhook['id'], ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="muted">Poslední doručení: <?php echo $webhook['last_delivery_at'] === null ? '—' : date('d.m.Y H:i:s', (int) $webhook['last_delivery_at']); ?></span>
        </div>
        <details class="disclosure compact-disclosure webhook-actions">
          <summary><i class="fa-solid fa-pen"></i> Upravit</summary>
          <div class="disclosure-body form-stack">
            <form method="post" action="<?php echo $webhooksUrl; ?>" class="form-stack">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars((string) $webhook['id'], ENT_QUOTES, 'UTF-8'); ?>">
              <div class="field"><label>HTTPS URL</label><div class="input-wrap"><input type="url" name="url" maxlength="2048" value="<?php echo htmlspecialchars((string) $webhook['url'], ENT_QUOTES, 'UTF-8'); ?>" required></div></div>
              <button type="submit" class="ghost-btn"><i class="fa-solid fa-floppy-disk"></i> Uložit URL</button>
            </form>
            <form method="post" action="<?php echo $webhooksUrl; ?>" data-confirm="Vyměnit webhook secret? Příjemce musí okamžitě dostat nový secret.">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="rotate_secret"><input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars((string) $webhook['id'], ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="ghost-btn"><i class="fa-solid fa-rotate"></i> Vyměnit secret</button>
            </form>
          </div>
        </details>
        <form method="post" action="<?php echo $webhooksUrl; ?>" data-confirm="Opravdu chcete webhook odstranit?">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($webhook['id'], ENT_QUOTES, 'UTF-8'); ?>">
          <button type="submit" class="danger-btn"><i class="fa-solid fa-trash" aria-hidden="true"></i> Odstranit</button>
        </form>
      </article>
    <?php endforeach; ?>
    </div>
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
  document.querySelectorAll('[data-reveal]').forEach((button) => button.addEventListener('click', () => {
    const input = button.parentElement?.querySelector('input');
    if (!input) return;
    input.type = input.type === 'password' ? 'text' : 'password';
  }));
  document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Potvrdit operaci?')) event.preventDefault();
  }));
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
