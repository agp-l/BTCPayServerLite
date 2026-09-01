<?php

declare(strict_types=1);

$pageTitle = 'Klienti - BTCPay Lite';
$activeMenu = 'users';
require __DIR__ . '/layout/header.php';
$formatTime = static fn (?int $value): string => $value === null ? '—' : date('d.m.Y H:i:s', $value);
?>
<section class="page-header">
  <div class="page-header-copy"><p class="page-eyebrow">Správa uživatelů</p><h1>Klienti</h1><p>Přihlášení, peněženky, obchody, faktury, výběry a integrační provoz.</p></div>
</section>
<?php if ($pageError !== null): ?><div class="alert alert-error" role="alert"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
<?php if ($toastMsg !== ''): ?><div class="alert alert-success" role="status"><?php echo htmlspecialchars($toastMsg, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

<section class="card">
  <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-users" aria-hidden="true"></i> Klientské účty</span><span class="badge"><?php echo count($clients); ?></span></div>
  <div class="data-table-wrap"><table class="data-table"><thead><tr><th>Klient</th><th>Stav</th><th>Poslední aktivita</th><th>Peněženka</th><th>Obchody</th><th>Faktury</th><th>Výběry</th><th>Požadavky</th><th></th></tr></thead><tbody>
  <?php foreach ($clients as $client): ?>
    <?php
    $walletState = $client['wallet_path'] !== null
        ? 'Přiřazena'
        : ($client['wallet_count'] > 1 ? 'Konflikt' : 'Nepřiřazena');
    ?>
    <tr>
      <td><strong><?php echo htmlspecialchars((string) $client['email'], ENT_QUOTES, 'UTF-8'); ?></strong><div class="muted">#<?php echo (int) $client['id']; ?></div></td>
      <td><span class="badge <?php echo $client['status'] === 'active' ? 's-paid' : 's-failed'; ?>"><?php echo htmlspecialchars((string) $client['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
      <td><?php echo htmlspecialchars($formatTime($client['last_seen_at'] ?? $client['last_login_at']), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><span class="badge <?php echo $client['wallet_path'] !== null ? 's-paid' : 's-failed'; ?>"><?php echo $walletState; ?></span></td>
      <td><?php echo (int) $client['store_count']; ?></td>
      <td><?php echo (int) $client['invoice_count']; ?><div class="muted"><?php echo htmlspecialchars($formatTime($client['last_invoice_at']), ENT_QUOTES, 'UTF-8'); ?></div></td>
      <td><?php echo (int) $client['payout_count']; ?></td>
      <td><?php echo (int) $client['request_count']; ?><div class="muted"><?php echo htmlspecialchars($formatTime($client['last_request_at']), ENT_QUOTES, 'UTF-8'); ?></div></td>
      <td><a class="ghost-btn" href="<?php echo $routeUrl('/admin/users') . '?user_id=' . (int) $client['id']; ?>">Detail</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<?php if (is_array($detail)): ?>
  <?php $client = $detail['client']; ?>
  <section class="page-header">
    <div class="page-header-copy"><p class="page-eyebrow">Detail klienta #<?php echo (int) $client['id']; ?></p><h2><?php echo htmlspecialchars((string) $client['email'], ENT_QUOTES, 'UTF-8'); ?></h2><p>Poslední přihlášení: <?php echo htmlspecialchars($formatTime($client['last_login_at']), ENT_QUOTES, 'UTF-8'); ?> z <?php echo htmlspecialchars((string) ($client['last_login_ip'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></p></div>
    <form method="post" action="<?php echo $routeUrl('/admin/users'); ?>">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="set_status"><input type="hidden" name="user_id" value="<?php echo (int) $client['id']; ?>">
      <input type="hidden" name="status" value="<?php echo $client['status'] === 'active' ? 'suspended' : 'active'; ?>">
      <button type="submit" class="<?php echo $client['status'] === 'active' ? 'danger-btn' : 'primary'; ?>"><?php echo $client['status'] === 'active' ? 'Pozastavit účet' : 'Aktivovat účet'; ?></button>
    </form>
  </section>

  <section class="stats-grid">
    <article class="stat-card"><div class="stat-label">Potvrzený zůstatek</div><div class="stat-value code"><?php echo is_array($detail['wallet_balance']) ? htmlspecialchars(number_format((float) $detail['wallet_balance']['confirmed'], 8, '.', ''), ENT_QUOTES, 'UTF-8') . ' BTC' : '—'; ?></div><div class="stat-meta"><?php echo htmlspecialchars((string) ($detail['wallet_error'] ?? 'Načteno živě z Electrum'), ENT_QUOTES, 'UTF-8'); ?></div></article>
    <article class="stat-card"><div class="stat-label">Nepotvrzený zůstatek</div><div class="stat-value code"><?php echo is_array($detail['wallet_balance']) ? htmlspecialchars(number_format((float) $detail['wallet_balance']['unconfirmed'], 8, '.', ''), ENT_QUOTES, 'UTF-8') . ' BTC' : '—'; ?></div><div class="stat-meta">Peněženka: <?php echo htmlspecialchars((string) ($client['wallet_path'] ?? 'nepřiřazena'), ENT_QUOTES, 'UTF-8'); ?></div></article>
    <article class="stat-card"><div class="stat-label">Webhooky / integrace</div><div class="stat-value"><?php echo (int) $client['webhook_count']; ?> / <?php echo (int) $client['integration_count']; ?></div><div class="stat-meta">Aktivní napojení obchodů</div></article>
  </section>

  <section class="card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-user-shield"></i> Účet a přístup</span></div>
    <p class="card-subtitle">Změna e-mailu ukončí starší relace. Heslo klient mění sám nebo použije bezpečný odkaz pro zapomenuté heslo.</p>
    <div class="management-grid">
      <form method="post" action="<?php echo $routeUrl('/admin/users'); ?>" class="form-stack">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="update_email"><input type="hidden" name="user_id" value="<?php echo (int) $client['id']; ?>">
        <div class="field"><label for="clientEmail">E-mail klienta</label><div class="input-wrap"><input id="clientEmail" type="email" name="email" maxlength="254" value="<?php echo htmlspecialchars((string) $client['email'], ENT_QUOTES, 'UTF-8'); ?>" required autocomplete="off"></div></div>
        <div class="form-actions"><button type="submit" class="primary"><i class="fa-solid fa-floppy-disk"></i> Uložit e-mail</button></div>
      </form>
      <form method="post" action="<?php echo $routeUrl('/admin/users'); ?>" class="form-stack" data-confirm="Opravdu ukončit všechny relace tohoto klienta?">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="revoke_sessions"><input type="hidden" name="user_id" value="<?php echo (int) $client['id']; ?>">
        <p>Klient bude na všech zařízeních odhlášen a musí se znovu přihlásit.</p>
        <div class="form-actions"><button type="submit" class="danger-btn"><i class="fa-solid fa-right-from-bracket"></i> Ukončit všechny relace</button></div>
      </form>
    </div>
  </section>

  <?php
  $walletOptions = [];
  foreach ($detail['stores'] as $store) {
      $walletOptions[(string) $store['wallet_path']] = (string) $store['name'];
  }
  ?>
  <?php if ($walletOptions !== []): ?>
    <section class="card">
      <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-wallet"></i> Hlavní peněženka klienta</span></div>
      <p class="card-subtitle">Lze vybrat pouze peněženku, kterou už používá některý obchod tohoto klienta. Změna nezasahuje do historických faktur.</p>
      <form method="post" action="<?php echo $routeUrl('/admin/users'); ?>" class="filter-bar">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"><input type="hidden" name="action" value="set_wallet"><input type="hidden" name="user_id" value="<?php echo (int) $client['id']; ?>">
        <div class="field"><label for="clientWalletPath">Přiřazený soubor</label><div class="input-wrap"><select id="clientWalletPath" name="wallet_path" required><?php foreach ($walletOptions as $walletPath => $storeName): ?><option value="<?php echo htmlspecialchars($walletPath, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $client['wallet_path'] === $walletPath ? 'selected' : ''; ?>><?php echo htmlspecialchars($storeName . ' — ' . $walletPath, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div></div>
        <div class="filter-actions"><button type="submit" class="primary"><i class="fa-solid fa-floppy-disk"></i> Uložit peněženku</button></div>
      </form>
    </section>
  <?php endif; ?>

  <?php if ($client['wallet_path'] === null && (int) $client['wallet_count'] === 1): ?>
    <section class="card">
      <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>Klient má jednu historickou peněženku, ale ještě není zapsána jako vlastník účtu. Přiřazení nemění cestu žádného obchodu.</span></div>
      <form method="post" action="<?php echo $routeUrl('/admin/users'); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="action" value="adopt_wallet"><input type="hidden" name="user_id" value="<?php echo (int) $client['id']; ?>">
        <button type="submit" class="primary"><i class="fa-solid fa-wallet" aria-hidden="true"></i> Přiřadit jedinou historickou peněženku</button>
      </form>
    </section>
  <?php endif; ?>

  <section class="card"><div class="card-title"><span class="card-title-group"><i class="fa-solid fa-store"></i> Obchody a webhooky</span></div><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Obchod</th><th>Store ID</th><th>Peněženka</th><th>Faktury</th><th>Webhooky</th><th>Poslední faktura</th></tr></thead><tbody>
  <?php foreach ($detail['stores'] as $store): ?><tr><td><strong><?php echo htmlspecialchars((string) $store['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td><td><code><?php echo htmlspecialchars((string) $store['id'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars((string) $store['wallet_path'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><?php echo (int) $store['invoice_count']; ?></td><td><?php echo (int) $store['webhook_count']; ?></td><td><?php echo htmlspecialchars($formatTime($store['last_invoice_at']), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>

  <section class="card"><div class="card-title"><span class="card-title-group"><i class="fa-solid fa-plug"></i> Zjištěné pluginy a e-shopy</span></div><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Integrace</th><th>Verze</th><th>E-shop</th><th>Obchod</th><th>Poprvé</th><th>Naposledy</th></tr></thead><tbody>
  <?php foreach ($detail['integrations'] as $integration): ?><tr><td><?php echo htmlspecialchars((string) $integration['name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($integration['version'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) ($integration['shop_origin'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $integration['store_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($formatTime((int) $integration['first_seen_at']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars($formatTime((int) $integration['last_seen_at']), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>

  <section class="card"><div class="card-title"><span class="card-title-group"><i class="fa-solid fa-arrow-right-arrow-left"></i> Poslední API požadavky</span></div><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Čas</th><th>Obchod</th><th>Metoda</th><th>Cesta</th><th>Stav</th><th>Trvání</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($detail['requests'] as $request): ?><tr><td><?php echo htmlspecialchars($formatTime((int) $request['created_at']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $request['store_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars((string) $request['method'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars((string) $request['request_path'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><?php echo (int) $request['http_status']; ?></td><td><?php echo (int) $request['duration_ms']; ?> ms</td><td><?php echo htmlspecialchars((string) ($request['client_ip'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td></tr><?php endforeach; ?>
  </tbody></table></div></section>

  <section class="card"><div class="card-title"><span class="card-title-group"><i class="fa-solid fa-money-bill-transfer"></i> Poslední výběry</span></div><div class="data-table-wrap"><table class="data-table"><thead><tr><th>Čas</th><th>Obchod</th><th>ID</th><th>Částka</th><th>Poplatek</th><th>Stav</th><th>TXID</th></tr></thead><tbody>
  <?php foreach ($detail['payouts'] as $payout): ?><tr><td><?php echo htmlspecialchars($formatTime((int) $payout['created_at']), ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo htmlspecialchars((string) $payout['store_name'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars((string) $payout['id'], ENT_QUOTES, 'UTF-8'); ?></code></td><td><code><?php echo htmlspecialchars((string) $payout['payout_amount'], ENT_QUOTES, 'UTF-8'); ?> BTC</code></td><td><code><?php echo htmlspecialchars((string) $payout['exchange_fee'], ENT_QUOTES, 'UTF-8'); ?> BTC</code></td><td><?php echo htmlspecialchars((string) $payout['state'], ENT_QUOTES, 'UTF-8'); ?></td><td><code><?php echo htmlspecialchars((string) ($payout['txid'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></code></td></tr><?php endforeach; ?>
  </tbody></table></div></section>
<?php endif; ?>
<script>
document.querySelectorAll('[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => {
  if (!window.confirm(form.dataset.confirm || 'Potvrdit operaci?')) event.preventDefault();
}));
</script>
<?php require __DIR__ . '/layout/footer.php'; ?>
