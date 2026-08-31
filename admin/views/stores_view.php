<?php

declare(strict_types=1);

$pageTitle = 'Obchody - BTCPay Lite';
$activeMenu = 'stores';
require __DIR__ . '/layout/header.php';

$storesUrl = $routeUrl('/admin/stores');
?>

<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Integrace</p>
    <h1>Obchody</h1>
    <p>Každý obchod získá vlastní Electrum peněženku a samostatný API klíč.</p>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>

<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-store" aria-hidden="true"></i> Nový obchod</span></div>
  <p class="card-subtitle">Peněženka se vytvoří řízeně v nakonfigurovaném adresáři; serverová cesta se nezadává ručně.</p>
  <form method="post" action="<?php echo $storesUrl; ?>" class="form-stack compact-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="create">
    <div class="field">
      <label for="storeName">Název obchodu</label>
      <div class="input-wrap"><input id="storeName" type="text" name="store_name" maxlength="100" autocomplete="organization" required></div>
    </div>
    <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Vytvořit obchod</button></div>
  </form>
</section>

<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Aktivní obchody</span><span class="badge s-unknown"><?php echo count($stores); ?></span></div>
  <?php if ($stores === []): ?>
    <div class="empty-state"><div><i class="fa-solid fa-store-slash" aria-hidden="true"></i><p>Zatím není vytvořený žádný obchod.</p></div></div>
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
        <div class="credential"><span class="credential-label">Soubor peněženky</span><div class="credential-value"><code><?php echo htmlspecialchars(basename($store['wallet_path']), ENT_QUOTES, 'UTF-8'); ?></code></div></div>
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
  document.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
    try { await navigator.clipboard.writeText(button.dataset.copy || ''); showToast('Zkopírováno do schránky.'); }
    catch (error) { showToast('Kopírování se nepodařilo.'); }
  }));
  document.querySelectorAll('[data-reveal]').forEach((button) => button.addEventListener('click', () => {
    const input = button.parentElement?.querySelector('input');
    if (!input) return;
    const reveal = input.type === 'password';
    input.type = reveal ? 'text' : 'password';
    button.setAttribute('aria-label', reveal ? 'Skrýt API klíč' : 'Zobrazit API klíč');
  }));
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
