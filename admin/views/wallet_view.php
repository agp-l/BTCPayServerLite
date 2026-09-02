<?php

declare(strict_types=1);

$pageTitle = 'Peněženka - BTCPay Lite';
$activeMenu = 'wallet';
require __DIR__ . '/layout/header.php';

$walletUrl = static function (array $query = []) use ($urlManager): string {
    return htmlspecialchars($urlManager->url('/admin/wallet', $query), ENT_QUOTES, 'UTF-8');
};
$walletAction = $walletUrl(['w' => $currentWalletName]);
$copyPayload = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
?>

<section class="page-header">
  <div class="page-header-copy">
    <p class="page-eyebrow">Electrum wallet</p>
    <h1>Peněženka</h1>
    <p>Bezpečné odesílání plateb, příjem bitcoinů a kontrola historie aktivní peněženky.</p>
  </div>
  <div class="page-actions">
    <form method="get" action="<?php echo $walletUrl(); ?>">
      <?php if ($hideEmpty): ?><input type="hidden" name="hide_empty" value="1"><?php endif; ?>
      <div class="input-wrap">
        <select name="w" aria-label="Aktivní peněženka" onchange="this.form.submit()">
          <?php foreach ($availableWallets as $walletName): ?>
            <option value="<?php echo htmlspecialchars($walletName, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $walletName === $currentWalletName ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($walletName, ENT_QUOTES, 'UTF-8'); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <a href="<?php echo $walletUrl(['w' => $currentWalletName, 'hide_empty' => $hideEmpty ? '1' : '0']); ?>" class="ghost-btn" aria-label="Obnovit data">
      <i class="fa-solid fa-rotate-right" aria-hidden="true"></i> Obnovit
    </a>
  </div>
</section>

<?php if ($pageError !== null): ?>
  <div class="alert alert-error" role="alert">
    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
    <span><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></span>
  </div>
<?php endif; ?>

<section class="wallet-hero">
  <div class="wallet-hero-copy">
    <div class="wallet-kicker"><i class="fa-brands fa-bitcoin" aria-hidden="true"></i> Potvrzený zůstatek</div>
    <div class="wallet-balance" id="balanceValue"><?php echo htmlspecialchars($balanceFormatted, ENT_QUOTES, 'UTF-8'); ?> <span>BTC</span></div>
    <div class="wallet-fiat" id="fiatValue">
      <?php echo htmlspecialchars($fiatText, ENT_QUOTES, 'UTF-8'); ?>
      <?php if ($fiatValueStr !== ''): ?> · <?php echo htmlspecialchars($fiatValueStr, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
    </div>
  </div>
  <div class="wallet-hero-meta">
    <span class="connection-pill"><?php echo htmlspecialchars($connStatus, ENT_QUOTES, 'UTF-8'); ?></span>
    <span class="wallet-name" title="<?php echo htmlspecialchars($currentWalletName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($currentWalletName, ENT_QUOTES, 'UTF-8'); ?></span>
    <button type="button" class="ghost-btn" id="toggleBalance"><i class="fa-solid fa-eye" aria-hidden="true"></i> Skrýt zůstatek</button>
  </div>
</section>

<div class="wallet-grid">
  <section class="card">
    <div class="card-title">
      <span class="card-title-group"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Přijmout bitcoin</span>
    </div>
    <p class="card-subtitle">Použijte doporučenou prázdnou přijímací adresu.</p>

    <div class="address-panel">
      <span class="muted">Aktuální přijímací adresa</span>
      <code class="address-value" id="receiveAddress"><?php echo htmlspecialchars($receiveAddress, ENT_QUOTES, 'UTF-8'); ?></code>
      <div class="address-actions">
        <button type="button" class="ghost-btn icon-btn" data-copy="<?php echo $copyPayload($receiveAddress); ?>" aria-label="Kopírovat přijímací adresu" title="Kopírovat přijímací adresu">
          <i class="fa-regular fa-copy" aria-hidden="true"></i>
        </button>
        <form method="post" action="<?php echo $walletAction; ?>">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
          <input type="hidden" name="action" value="new_address">
          <button type="submit" class="primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nová adresa</button>
        </form>
      </div>
    </div>
  </section>

  <section class="card">
    <div class="card-title">
      <span class="card-title-group"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Odeslat platbu</span>
    </div>
    <p class="card-subtitle">Částka se odesílá přesně jako BTC desetinný řetězec.</p>

    <form method="post" action="<?php echo $walletAction; ?>" class="form-stack" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="send">
      <div class="field">
        <label for="recipient">Bitcoin adresa příjemce</label>
        <div class="input-wrap"><input id="recipient" name="to" type="text" maxlength="128" spellcheck="false" required></div>
      </div>
      <div class="field">
        <label for="amount">Částka</label>
        <div class="input-wrap"><input id="amount" name="amount" type="text" inputmode="decimal" maxlength="32" placeholder="0.00000000" required><span class="unit">BTC</span></div>
        <div class="quick-actions">
          <button type="button" class="ghost-btn" data-amount="0.25">25 %</button>
          <button type="button" class="ghost-btn" data-amount="0.50">50 %</button>
          <button type="button" class="ghost-btn" data-amount="1">Maximum</button>
        </div>
      </div>
      <div class="field">
        <label for="feeRate">Poplatek sítě</label>
        <div class="input-wrap"><input id="feeRate" name="fee" type="number" min="1" max="10000" value="<?php echo $feeMed; ?>" required><span class="unit">sat/vB</span></div>
        <div class="fee-presets">
          <button type="button" class="ghost-btn" data-fee="<?php echo $feeLow; ?>">Úsporný · <?php echo $feeLow; ?></button>
          <button type="button" class="ghost-btn" data-fee="<?php echo $feeMed; ?>">Běžný · <?php echo $feeMed; ?></button>
          <button type="button" class="ghost-btn" data-fee="<?php echo $feeHigh; ?>">Prioritní · <?php echo $feeHigh; ?></button>
        </div>
        <div class="form-hint" id="feeEstimate"></div>
      </div>
      <div class="field">
        <label for="walletPassword">Heslo peněženky</label>
        <div class="input-wrap"><input id="walletPassword" name="password" type="password" maxlength="1024" autocomplete="current-password"></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="primary"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Odeslat transakci</button>
      </div>
      <?php if ($sendResult !== ''): ?>
        <div class="alert <?php echo $sendSucceeded ? 'alert-success' : 'alert-error'; ?>" role="status">
          <i class="fa-solid <?php echo $sendSucceeded ? 'fa-circle-check' : 'fa-circle-xmark'; ?>" aria-hidden="true"></i>
          <span><?php echo htmlspecialchars($sendResult, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
      <?php endif; ?>
    </form>
  </section>
</div>

<section class="card">
  <div class="card-title">
    <span class="card-title-group"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Historie transakcí</span>
  </div>
  <p class="card-subtitle">Nejnovější transakce jsou řazené podle času a zobrazují stav potvrzení.</p>

  <?php if ($finalTxs === []): ?>
    <div class="empty-state"><div><i class="fa-regular fa-folder-open" aria-hidden="true"></i><p>Peněženka zatím nemá žádné transakce.</p></div></div>
  <?php else: ?>
    <div class="transaction-list">
    <?php foreach ($finalTxs as $transaction): ?>
      <?php $direction = $transaction['isInc'] ? 'incoming' : 'outgoing'; ?>
      <article class="transaction-item <?php echo $direction; ?>">
        <span class="transaction-icon <?php echo $direction; ?>"><i class="fa-solid <?php echo $transaction['isInc'] ? 'fa-arrow-down' : 'fa-arrow-up'; ?>" aria-hidden="true"></i></span>
        <div class="transaction-main">
          <strong><?php echo $transaction['isInc'] ? 'Přijatá platba' : 'Odeslaná platba'; ?></strong>
          <small><?php echo htmlspecialchars($transaction['timeStr'], ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
        <div class="transaction-amount <?php echo $direction; ?>"><?php echo htmlspecialchars($transaction['valStr'], ENT_QUOTES, 'UTF-8'); ?> BTC</div>
        <div class="transaction-status"><?php echo htmlspecialchars($transaction['confText'], ENT_QUOTES, 'UTF-8'); ?></div>
        <details class="transaction-details">
          <summary>Zobrazit technické detaily</summary>
          <div class="transaction-output">
            <code><?php echo htmlspecialchars($transaction['txid'], ENT_QUOTES, 'UTF-8'); ?></code>
            <a href="https://mempool.space/tx/<?php echo rawurlencode($transaction['txid']); ?>" target="_blank" rel="noopener" class="action-link">Mempool <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i></a>
          </div>
          <?php foreach ($transaction['outputs'] as $output): ?>
            <div class="transaction-output">
              <code><?php echo htmlspecialchars($output['address'], ENT_QUOTES, 'UTF-8'); ?></code>
              <span><strong><?php echo htmlspecialchars($output['value'], ENT_QUOTES, 'UTF-8'); ?> BTC</strong><br><span class="muted"><?php echo htmlspecialchars($output['label'], ENT_QUOTES, 'UTF-8'); ?></span></span>
            </div>
          <?php endforeach; ?>
        </details>
      </article>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="wallet-grid">
  <section class="card">
    <div class="card-title">
      <span class="card-title-group"><i class="fa-solid fa-address-book" aria-hidden="true"></i> Adresy peněženky</span>
      <form method="get" action="<?php echo $walletUrl(); ?>">
        <input type="hidden" name="w" value="<?php echo htmlspecialchars($currentWalletName, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="hide_empty" value="<?php echo $hideEmpty ? '0' : '1'; ?>">
        <button type="submit" class="ghost-btn"><?php echo $hideEmpty ? 'Zobrazit všechny' : 'Skrýt prázdné'; ?></button>
      </form>
    </div>

    <?php if ($finalAddresses === []): ?>
      <div class="empty-state"><p>Žádné adresy k zobrazení.</p></div>
    <?php else: ?>
      <div class="address-list">
      <?php foreach ($finalAddresses as $address): ?>
        <div class="address-row <?php echo $address['hasFunds'] ? 'has-utxo' : 'is-empty'; ?>">
          <span class="address-row-icon"><i class="fa-brands fa-bitcoin" aria-hidden="true"></i></span>
          <div class="address-row-main">
            <code title="<?php echo htmlspecialchars($address['address'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($address['address'], ENT_QUOTES, 'UTF-8'); ?></code>
            <small>
              <span><?php echo $address['type'] === 'change' ? 'Vratná adresa' : 'Přijímací adresa'; ?></span>
              <span aria-hidden="true">·</span>
              <span class="address-funds <?php echo $address['hasFunds'] ? 'has-utxo' : 'is-empty'; ?>"><?php echo $address['hasFunds'] ? 'obsahuje UTXO' : 'bez zůstatku'; ?></span>
            </small>
          </div>
          <div class="address-row-value">
            <strong><?php echo htmlspecialchars($address['valStr'], ENT_QUOTES, 'UTF-8'); ?> BTC</strong>
            <button type="button" class="ghost-btn icon-btn" data-copy="<?php echo $copyPayload($address['address']); ?>" aria-label="Kopírovat adresu" title="Kopírovat adresu"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card security-card">
    <div class="card-title"><span class="card-title-group"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Klíče peněženky</span></div>
    <div class="security-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>Seed ani privátní klíč nikomu neposílejte. Po opuštění této stránky už nebudou zobrazené.</span></div>

    <div class="field">
      <label>Master public key</label>
      <div class="key-box"><?php echo $mpk !== '' ? htmlspecialchars($mpk, ENT_QUOTES, 'UTF-8') : 'Nedostupné'; ?></div>
      <?php if ($mpk !== ''): ?><button type="button" class="ghost-btn icon-btn" data-copy="<?php echo $copyPayload($mpk); ?>" aria-label="Kopírovat veřejný klíč" title="Kopírovat veřejný klíč"><i class="fa-regular fa-copy" aria-hidden="true"></i></button><?php endif; ?>
    </div>

    <form method="post" action="<?php echo $walletAction; ?>" class="form-stack" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="export_keys">
      <div class="field">
        <label for="exportPassword">Heslo pro export privátních klíčů</label>
        <div class="input-wrap"><input id="exportPassword" name="export_password" type="password" maxlength="1024" autocomplete="off"></div>
      </div>
      <button type="submit" class="danger-btn"><i class="fa-solid fa-key" aria-hidden="true"></i> Zobrazit citlivé klíče</button>
    </form>

    <?php if ($exportedSeed !== ''): ?>
      <div class="secret-output"><strong>Seed fráze</strong><code><?php echo htmlspecialchars($exportedSeed, ENT_QUOTES, 'UTF-8'); ?></code></div>
    <?php endif; ?>
    <?php if ($exportedXprv !== ''): ?>
      <div class="secret-output"><strong>Master private key</strong><code><?php echo htmlspecialchars($exportedXprv, ENT_QUOTES, 'UTF-8'); ?></code></div>
    <?php endif; ?>
  </section>
</div>

<script>
(() => {
  const toastElement = document.getElementById('toast');
  const toastMessage = document.getElementById('toastMsg');
  const showToast = (message) => {
    if (!toastElement || !toastMessage || !message) return;
    toastMessage.textContent = message;
    toastElement.classList.add('show');
    window.setTimeout(() => toastElement.classList.remove('show'), 2800);
  };

  const serverMessage = <?php echo json_encode($toastMsg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  showToast(serverMessage);

  document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(button.dataset.copy || '');
        showToast('Zkopírováno do schránky.');
      } catch (error) {
        showToast('Kopírování se nepodařilo.');
      }
    });
  });

  const balance = document.getElementById('balanceValue');
  const fiat = document.getElementById('fiatValue');
  const toggle = document.getElementById('toggleBalance');
  if (balance && fiat && toggle) {
    const balanceMarkup = balance.innerHTML;
    const fiatText = fiat.textContent;
    let hidden = false;
    toggle.addEventListener('click', () => {
      hidden = !hidden;
      balance.innerHTML = hidden ? '•••••••• <span>BTC</span>' : balanceMarkup;
      fiat.textContent = hidden ? 'Zůstatek je skrytý' : fiatText;
      toggle.innerHTML = hidden
        ? '<i class="fa-solid fa-eye" aria-hidden="true"></i> Zobrazit zůstatek'
        : '<i class="fa-solid fa-eye-slash" aria-hidden="true"></i> Skrýt zůstatek';
    });
  }

  const amountInput = document.getElementById('amount');
  const confirmedSats = <?php echo (int) round($balanceConfirmed * 100000000); ?>;
  document.querySelectorAll('[data-amount]').forEach((button) => {
    button.addEventListener('click', () => {
      if (!amountInput) return;
      const ratio = Number(button.dataset.amount || 0);
      amountInput.value = ratio === 1
        ? '!'
        : ((Math.floor(confirmedSats * ratio) / 100000000).toFixed(8));
    });
  });

  const feeInput = document.getElementById('feeRate');
  const feeEstimate = document.getElementById('feeEstimate');
  const updateFee = () => {
    if (!feeInput || !feeEstimate) return;
    const rate = Math.max(1, Number(feeInput.value) || 1);
    const sats = Math.round(rate * 140);
    feeEstimate.textContent = `Orientační poplatek pro běžnou transakci: ${sats} sat`;
  };
  document.querySelectorAll('[data-fee]').forEach((button) => {
    button.addEventListener('click', () => {
      if (feeInput) feeInput.value = button.dataset.fee || '1';
      updateFee();
    });
  });
  if (feeInput) feeInput.addEventListener('input', updateFee);
  updateFee();
})();
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>
