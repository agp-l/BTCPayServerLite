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
    <p>Oddělené platební identity, API přístupy a Electrum peněženky pro každý napojený projekt.</p>
  </div>
  <div class="page-actions">
    <a href="<?php echo $routeUrl('/admin/invoices'); ?>" class="ghost-btn">
      <i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Přejít na faktury
    </a>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span></div>
<?php endif; ?>

<section class="card filter-card">
  <form method="get" action="<?php echo $storesUrl; ?>" class="filter-bar">
    <div class="field"><label for="storesClient">Zákazník</label><div class="input-wrap"><select id="storesClient" name="user_id">
      <option value="">Všichni zákazníci</option>
      <option value="0" <?php echo $selectedUserId === 0 ? 'selected' : ''; ?>>Systém / bez klienta</option>
      <?php foreach ($clients as $client): ?><option value="<?php echo (int) $client['id']; ?>" <?php echo $selectedUserId === (int) $client['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($client['email'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
    </select></div></div>
    <div class="filter-actions"><button type="submit" class="primary"><i class="fa-solid fa-filter"></i> Filtrovat</button><?php if ($selectedUserId !== null): ?><a class="ghost-btn" href="<?php echo $storesUrl; ?>">Zrušit filtr</a><?php endif; ?></div>
  </form>
</section>

<div class="management-grid">
  <section class="card">
    <div class="card-title">
      <span class="card-title-group"><i class="fa-solid fa-layer-group" aria-hidden="true"></i> Aktivní obchody</span>
      <span class="badge s-unknown"><?php echo count($stores); ?></span>
    </div>
    <p class="card-subtitle">Přístupové údaje uchovávejte jako tajné hodnoty na straně serveru vašeho obchodu.</p>

    <?php if ($stores === []): ?>
      <div class="empty-state"><div><i class="fa-solid fa-store-slash" aria-hidden="true"></i><p>Zatím není vytvořený žádný obchod.</p></div></div>
    <?php else: ?>
      <div class="store-grid">
      <?php foreach ($stores as $store): ?>
        <article class="store-card">
          <div class="store-card-head">
            <h3><?php echo htmlspecialchars($store['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
            <span class="badge s-paid">Aktivní</span>
          </div>
          <div class="credential">
            <span class="credential-label">Zákazník</span>
            <div class="credential-value"><strong><?php echo htmlspecialchars((string) $store['client_email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
          </div>
          <div class="credential">
            <span class="credential-label">Store ID</span>
            <div class="credential-value">
              <code><?php echo htmlspecialchars($store['id'], ENT_QUOTES, 'UTF-8'); ?></code>
              <button type="button" class="ghost-btn" data-copy="<?php echo htmlspecialchars($store['id'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kopírovat Store ID"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
            </div>
          </div>
          <div class="credential">
            <span class="credential-label">API klíč</span>
            <div class="credential-value">
              <input type="password" readonly value="<?php echo htmlspecialchars($store['api_key'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="API klíč">
              <button type="button" class="ghost-btn" data-reveal aria-label="Zobrazit API klíč"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
              <button type="button" class="ghost-btn" data-copy="<?php echo htmlspecialchars($store['api_key'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kopírovat API klíč"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
            </div>
          </div>
          <div class="credential">
            <span class="credential-label">Soubor peněženky</span>
            <div class="credential-value"><code><?php echo htmlspecialchars(basename($store['wallet_path']), ENT_QUOTES, 'UTF-8'); ?></code></div>
          </div>
          <div class="store-metrics muted">
            Faktury: <?php echo (int) $store['invoice_count']; ?> · Webhooky: <?php echo (int) $store['webhook_count']; ?> · Výběry: <?php echo (int) $store['payout_count']; ?>
          </div>
          <details class="disclosure compact-disclosure">
            <summary><i class="fa-solid fa-pen"></i> Správa obchodu</summary>
            <div class="disclosure-body form-stack">
              <form method="post" action="<?php echo $storesUrl; ?>" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="rename"><input type="hidden" name="store_id" value="<?php echo htmlspecialchars((string) $store['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="field"><label>Název obchodu</label><div class="input-wrap"><input name="store_name" maxlength="100" value="<?php echo htmlspecialchars((string) $store['name'], ENT_QUOTES, 'UTF-8'); ?>" required></div></div>
                <button type="submit" class="ghost-btn"><i class="fa-solid fa-floppy-disk"></i> Uložit název</button>
              </form>
              <form method="post" action="<?php echo $storesUrl; ?>" data-confirm="Opravdu nahradit API klíč? Stávající integrace se okamžitě odpojí.">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="rotate_api_key"><input type="hidden" name="store_id" value="<?php echo htmlspecialchars((string) $store['id'], ENT_QUOTES, 'UTF-8'); ?>">
                <button type="submit" class="ghost-btn"><i class="fa-solid fa-rotate"></i> Vyměnit API klíč</button>
              </form>
              <?php if ((int) $store['invoice_count'] === 0 && (int) $store['payout_count'] === 0): ?>
                <form method="post" action="<?php echo $storesUrl; ?>" data-confirm="Odstranit prázdný obchod? Jeho webhooky a integrační záznamy budou také odstraněny.">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="store_id" value="<?php echo htmlspecialchars((string) $store['id'], ENT_QUOTES, 'UTF-8'); ?>">
                  <button type="submit" class="danger-btn"><i class="fa-solid fa-trash"></i> Odstranit obchod</button>
                </form>
              <?php else: ?>
                <div class="surface-note"><i class="fa-solid fa-lock"></i><span>Obchod s finanční historií nelze smazat.</span></div>
              <?php endif; ?>
            </div>
          </details>
        </article>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <aside class="card management-aside">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-store" aria-hidden="true"></i> Nový obchod</span></div>
    <p class="card-subtitle">Klientský obchod převezme hlavní peněženku klienta. Systémový obchod dostane vlastní peněženku.</p>
    <form method="post" action="<?php echo $storesUrl; ?>" class="form-stack">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="create">
      <div class="field">
        <label for="storeOwner">Vlastník</label>
        <div class="input-wrap"><select id="storeOwner" name="owner_user_id" required>
          <option value="0">Systém / bez klienta</option>
          <?php foreach ($clients as $client): ?><?php if ($client['status'] === 'active'): ?><option value="<?php echo (int) $client['id']; ?>" <?php echo $selectedUserId === (int) $client['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($client['email'], ENT_QUOTES, 'UTF-8'); ?></option><?php endif; ?><?php endforeach; ?>
        </select></div>
      </div>
      <div class="field">
        <label for="storeName">Název obchodu</label>
        <div class="input-wrap"><input id="storeName" type="text" name="store_name" maxlength="100" autocomplete="organization" placeholder="Např. Hlavní e-shop" required></div>
      </div>
      <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Vytvořit obchod</button></div>
    </form>
    <div class="surface-note">
      <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
      <span>Cesta k peněžence se nikdy nepřebírá z formuláře. Jeden klient má napříč obchody právě jednu hlavní peněženku.</span>
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
  document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.confirm || 'Potvrdit operaci?')) event.preventDefault();
  }));
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
