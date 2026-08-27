<?php
// admin/views/invoices_view.php
declare(strict_types=1);

$pageTitle = 'DB Faktury - BTCPay Lite';
$activeMenu = 'invoices';
require __DIR__ . '/layout/header.php';
?>

<style>
/* Specifické styly pouze pro výpis DB faktur */
.result-box { background: #eafbef; border: 1px solid #2fd35a; border-radius: 10px; padding: 15px; margin-top: 20px; font-size: 13px; }
.result-box a { color: #20b948; font-weight: 600; word-break: break-all; text-decoration: none; display: block; margin-top: 5px; }
.result-box a:hover { text-decoration: underline; }

.invoice-item { padding: 15px 0; border-bottom: 1px solid #e5eae7; display: flex; justify-content: space-between; align-items: center; gap: 15px; flex-wrap: wrap; }
.invoice-item:last-child { border-bottom: 0; padding-bottom: 0; }
.invoice-amount { font-family: ui-monospace, monospace; font-weight: 700; color: #20b948; font-size: 13px; }
</style>

<div class="page-header">
    <h1><i class="fa-solid fa-database" style="color:#2fd35a;"></i> Databázové faktury</h1>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-file-invoice-dollar" style="color:#20b948;"></i> Vystavit fakturu do DB</h2>
    <form method="POST" action="invoices.php">
        <input type="hidden" name="action" value="create">
        
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div class="field">
                <label>Částka k zaplacení</label>
                <div class="input-wrap">
                    <input type="text" name="amount" placeholder="0.00100000" required>
                    <div class="unit">BTC</div>
                </div>
            </div>
            <div class="field">
                <label>Popis / Název položky</label>
                <div class="input-wrap">
                    <input type="text" name="description" placeholder="Např. Konzultace" required>
                </div>
            </div>
        </div>
        
        <div class="field">
            <label>Interní ID objednávky (volitelné)</label>
            <div class="input-wrap">
                <input type="text" name="order_id" placeholder="Např. ORD-2026-001">
            </div>
        </div>
        <button type="submit" class="primary" style="width: auto; padding: 12px 20px;"><i class="fa-solid fa-plus"></i> Uložit a vygenerovat odkaz</button>
    </form>

    <?php if (!empty($newInvoiceUrl)): ?>
        <div class="result-box">
            <div style="color: #17201a; font-weight: 700; margin-bottom: 5px;"><i class="fa-solid fa-link"></i> Odkaz pro zákazníka:</div>
            <a href="<?php echo htmlspecialchars($newInvoiceUrl); ?>" target="_blank"><?php echo htmlspecialchars($newInvoiceUrl); ?></a>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($invoicesHistory)): ?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;"><i class="fa-solid fa-clock-rotate-left" style="color:#748078;"></i> Nedávno vytvořené (v této relaci)</h2>
        <form method="POST" action="invoices.php" style="margin:0;">
            <input type="hidden" name="action" value="clear_history">
            <button type="submit" class="ghost-btn" style="padding: 6px 10px; font-size: 11px; color: #ef4d4d; background: #fff0f0; border: none;"><i class="fa-solid fa-trash"></i> Smazat náhled</button>
        </form>
    </div>
    <div>
        <?php foreach (array_reverse($invoicesHistory) as $inv): ?>
            <div class="invoice-item">
                <div>
                    <strong><?php echo htmlspecialchars($inv['desc']); ?></strong> <span class="invoice-amount"><?php echo htmlspecialchars((string)$inv['amount']); ?> BTC</span><br>
                    <small style="color:#748078;"><i class="fa-regular fa-clock"></i> <?php echo date('H:i:s j.n.Y', $inv['time']); ?></small>
                </div>
                <a href="<?php echo htmlspecialchars($inv['url']); ?>" target="_blank" class="ghost-btn" style="padding: 6px 12px; font-size: 12px;"><i class="fa-solid fa-arrow-up-right-from-square"></i> Otevřít</a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
  // Zobrazení notifikace pomocí sdíleného toast prvku v patičce
  const toastMsg = "<?php echo addslashes($toastMsg); ?>";
  if (toastMsg.trim() !== '') {
      const t = document.getElementById('toast');
      const tMsg = document.getElementById('toastMsg');
      if (t && tMsg) {
          tMsg.innerHTML = `<i class="fa-solid fa-circle-info"></i> ${toastMsg}`;
          t.classList.add('show');
          setTimeout(() => t.classList.remove('show'), 3000);
      } else {
          alert(toastMsg);
      }
  }
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>