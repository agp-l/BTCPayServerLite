<?php
// client/views/index_view.php
declare(strict_types=1);

$pageTitle = 'Můj účet - BTCPay Lite';
require __DIR__ . '/layout/header.php';
?>

<style>
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; margin-bottom: 30px; }
.stat-card { background: #ffffff; border: 1px solid #e5eae7; border-radius: 16px; padding: 20px; }
.stat-label { font-size: 12px; font-weight: 700; color: #748078; text-transform: uppercase; margin-bottom: 5px; }
.stat-value { font-size: 24px; font-weight: 800; color: #17201a; }

.key-box { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 8px; padding: 10px 15px; font-family: ui-monospace, monospace; font-size: 13px; color: #17201a; margin-top: 5px; margin-bottom: 15px; word-break: break-all; }
.code-label { font-size: 11px; font-weight: 700; color: #748078; text-transform: uppercase; }

table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
th { text-align: left; padding: 12px 10px; color: #748078; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e5eae7; }
td { padding: 14px 10px; border-bottom: 1px solid #f0f4f1; vertical-align: middle; }
.badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-weight: 700; font-size: 11px; }
.badge-New { background: #fef3c7; color: #d97706; }
.badge-Processing { background: #e0f2fe; color: #0284c7; }
.badge-Settled { background: #eafbef; color: #13aa3d; }
.badge-Expired { background: #fee2e2; color: #ef4d4d; }

.wh-item { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap;}
</style>

<div class="page-header">
    <h1><i class="fa-solid fa-house-user" style="color:#2fd35a;"></i> Přehled účtu</h1>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Aktivní E-shopy</div>
        <div class="stat-value"><?php echo $clientStats['total_stores']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Vygenerováno faktur</div>
        <div class="stat-value"><?php echo $clientStats['total_invoices']; ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Úspěšně zaplaceno</div>
        <div class="stat-value" style="color: #13aa3d;"><?php echo $clientStats['paid_invoices']; ?></div>
    </div>
</div>

<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
        <h2 class="card-title" style="margin: 0;"><i class="fa-solid fa-shop" style="color:#20b948;"></i> Moje e-shopy (API Klíče)</h2>
        <button onclick="document.getElementById('newStoreForm').style.display='block'" class="ghost-btn"><i class="fa-solid fa-plus"></i> Nový e-shop</button>
    </div>

    <!-- Nový e-shop (Skrytý formulář) -->
    <div id="newStoreForm" style="display: none; background: #f9fafa; padding: 20px; border-radius: 12px; border: 1px solid #e5eae7; margin-bottom: 25px;">
        <h3 style="margin: 0 0 15px 0; font-size: 14px;">Založit nový e-shop / projekt</h3>
        <form method="POST" style="margin: 0; display: flex; flex-direction: column; gap: 15px; max-width: 400px;">
            <input type="hidden" name="action" value="create_store">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="field" style="margin:0;">
                <label>Název projektu (např. Alza.cz)</label>
                <div class="input-wrap"><input type="text" name="store_name" placeholder="Název vašeho e-shopu" required></div>
            </div>
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="primary"><i class="fa-solid fa-check"></i> Vytvořit e-shop</button>
                <button type="button" class="ghost-btn" onclick="document.getElementById('newStoreForm').style.display='none'">Zrušit</button>
            </div>
        </form>
    </div>

    <?php if (empty($stores)): ?>
        <p style="color: #748078;">Zatím nemáte vytvořen žádný e-shop.</p>
    <?php else: ?>
        <div style="display: grid; gap: 20px;">
            <?php foreach ($stores as $store): ?>
                <div style="border: 1px solid #e5eae7; border-radius: 12px; padding: 20px;">
                    <h3 style="margin: 0 0 15px 0; font-size: 16px; color: #17201a;"><?php echo htmlspecialchars($store['name']); ?></h3>
                    <div class="code-label">Store ID (ID Obchodu):</div>
                    <div class="key-box"><?php echo htmlspecialchars($store['id']); ?></div>
                    <div class="code-label">API Klíč (Zadejte do WooCommerce):</div>
                    <div class="key-box" style="margin-bottom: 0;"><?php echo htmlspecialchars($store['api_key']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-satellite-dish" style="color:#748078;"></i> Správa Webhooků</h2>
    
    <?php if (!empty($stores)): ?>
    <div style="background: #f9fafa; padding: 20px; border-radius: 12px; border: 1px solid #e5eae7; margin-bottom: 25px;">
        <h3 style="margin: 0 0 15px 0; font-size: 14px;">Přidat nový Webhook</h3>
        <form method="POST" style="margin: 0; display: flex; flex-direction: column; gap: 15px; max-width: 400px;">
            <input type="hidden" name="action" value="create_webhook">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="field" style="margin:0;">
                <label>Vyberte obchod</label>
                <div class="input-wrap">
                    <select name="store_id" required>
                        <option value="">-- Vyberte obchod --</option>
                        <?php foreach ($stores as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field" style="margin:0;">
                <label>URL adresa notifikace</label>
                <div class="input-wrap">
                    <input type="url" name="url" placeholder="https://muj-eshop.cz/wc-api/webhook/" required>
                </div>
            </div>
            <button type="submit" class="primary" style="width: fit-content;"><i class="fa-solid fa-plus"></i> Uložit Webhook</button>
        </form>
    </div>
    <?php else: ?>
        <p style="color: #748078; font-size: 13px;">Pro vytvoření webhooku si nejprve musíte založit e-shop.</p>
    <?php endif; ?>

    <h3 style="margin: 0 0 15px 0; font-size: 14px;">Aktivní Webhooky</h3>
    <?php if (empty($webhooks)): ?>
        <p style="color: #748078; font-size: 13px;">Zatím nemáte vytvořené žádné webhooky.</p>
    <?php else: ?>
        <?php foreach ($webhooks as $w): ?>
            <div class="wh-item">
                <div class="wh-info">
                    <div class="code-label">Obchod: <?php echo htmlspecialchars($w['store_name']); ?></div>
                    <h4 style="margin: 0 0 10px 0; font-size: 14px; word-break: break-all;"><?php echo htmlspecialchars($w['url']); ?></h4>
                    <div class="code-label">Secret klíč:</div>
                    <div class="key-box" style="margin-bottom: 0; padding: 6px 10px;"><?php echo htmlspecialchars($w['secret']); ?></div>
                </div>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Opravdu smazat tento webhook?');">
                    <input type="hidden" name="action" value="delete_webhook">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($w['id']); ?>">
                    <button type="submit" class="danger-btn"><i class="fa-solid fa-trash"></i> Smazat</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-file-invoice" style="color:#748078;"></i> Přijaté faktury</h2>
    <?php if (empty($invoices)): ?>
        <p style="color: #748078; font-size: 14px;">Zatím nemáte žádné transakce.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>E-shop</th>
                        <th>ID Faktury</th>
                        <th>Částka</th>
                        <th>Stav</th>
                        <th>Vytvořeno</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <?php $dateStr = is_numeric($inv['created_at']) ? date('d.m.Y H:i', (int)$inv['created_at']) : date('d.m.Y H:i', strtotime($inv['created_at'])); ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($inv['store_name']); ?></strong></td>
                            <td style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars(substr($inv['id'], 0, 15)) . '...'; ?></td>
                            <td><strong><?php echo htmlspecialchars($inv['amount']); ?> BTC</strong></td>
                            <td><span class="badge badge-<?php echo htmlspecialchars($inv['status']); ?>"><?php echo htmlspecialchars($inv['status']); ?></span></td>
                            <td style="color: #748078;"><?php echo $dateStr; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
  const toastMsg = "<?php echo addslashes($toastMsg); ?>";
  if (toastMsg.trim() !== '') {
      const t = document.getElementById('toast');
      const tMsg = document.getElementById('toastMsg');
      if (t && tMsg) {
          tMsg.innerHTML = `<i class="fa-solid fa-circle-info"></i> ${toastMsg}`;
          t.classList.add('show');
          setTimeout(() => t.classList.remove('show'), 4000);
      }
  }
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>