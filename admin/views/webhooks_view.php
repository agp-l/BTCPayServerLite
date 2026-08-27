<?php
// admin/views/webhooks_view.php
declare(strict_types=1);

$pageTitle = 'Správa Webhooků - BTCPay Lite';
$activeMenu = 'webhooks';
require __DIR__ . '/layout/header.php';
?>

<style>
/* Specifické styly pro výpis webhooků */
.wh-item { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap; }
.wh-info { flex: 1; min-width: 0; }
.wh-url { font-weight: 700; margin: 0 0 10px 0; word-break: break-all; color: #17201a; font-size: 15px; }
.code-box { background: #ffffff; border: 1px solid #e5eae7; padding: 10px; border-radius: 8px; font-family: ui-monospace, monospace; font-size: 12px; word-break: break-all; margin-bottom: 12px; color: #17201a; }
.code-label { font-size: 11px; font-weight: 700; color: #748078; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

/* Tlačítko pro odstranění */
.danger-btn { border: 1px solid #fee2e2; background: #fff0f0; border-radius: 9px; padding: 8px 14px; color: #ef4d4d; text-decoration: none; font-weight: 600; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; transition: 0.2s; }
.danger-btn:hover { background: #ef4d4d; color: #ffffff; border-color: #ef4d4d; }

/* Vertikální uspořádání formuláře */
.form-stack { display: flex; flex-direction: column; gap: 18px; max-width: 480px; }
</style>

<div class="page-header">
    <h1><i class="fa-solid fa-satellite-dish" style="color:#2fd35a;"></i> Správa Webhooků</h1>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-plus-circle" style="color:#20b948;"></i> Přidat Webhook ručně</h2>
    <p style="font-size: 13px; color: #748078; margin-top: -10px; margin-bottom: 20px;">Poznámka: Většina e-shopů si webhook vytvoří automaticky přes API. Zde to můžeš udělat ručně např. pro testování.</p>
    
    <form method="POST" action="webhooks.php">
        <input type="hidden" name="action" value="create">
        
        <div class="form-stack">
            <div class="field" style="margin-bottom: 0;">
                <label>Přiřadit k obchodu</label>
                <div class="input-wrap">
                    <select name="store_id" required>
                        <option value="">-- Vyber obchod --</option>
                        <?php foreach ($stores as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['id']); ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="field" style="margin-bottom: 0;">
                <label>URL adresa (Kam odeslat notifikaci o platbě)</label>
                <div class="input-wrap">
                    <input type="url" name="url" placeholder="https://tvuj-eshop.cz/wc-api/WC_Gateway_BtcPay/" required>
                </div>
            </div>
            
            <button type="submit" class="primary" style="margin-top: 5px;"><i class="fa-solid fa-plus"></i> Vytvořit Webhook</button>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-list" style="color:#748078;"></i> Aktivní Webhooky</h2>
    
    <?php if (empty($webhooks)): ?>
        <p style="color: #748078; font-size: 14px;">Zatím nemáš vytvořené žádné webhooky.</p>
    <?php else: ?>
        <?php foreach ($webhooks as $w): ?>
            <div class="wh-item">
                <div class="wh-info">
                    <div class="code-label">Obchod: <?php echo htmlspecialchars($w['store_name'] ?? 'Neznámý obchod'); ?></div>
                    <h3 class="wh-url"><?php echo htmlspecialchars($w['url']); ?></h3>
                    
                    <div class="code-label">Webhook Secret (Pro ověření podpisů):</div>
                    <div class="code-box"><?php echo htmlspecialchars($w['secret']); ?></div>
                    
                    <div class="code-label">ID Webhooku:</div>
                    <div style="font-size: 12px; color: #748078; font-family: ui-monospace, monospace;"><?php echo htmlspecialchars($w['id']); ?></div>
                </div>
                <form method="POST" style="margin:0;" onsubmit="return confirm('Opravdu smazat tento webhook?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="webhook_id" value="<?php echo htmlspecialchars($w['id']); ?>">
                    <button type="submit" class="danger-btn"><i class="fa-solid fa-trash"></i> Smazat</button>
                </form>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
  // Zobrazení notifikace přes toast ve footer.php
  const toastMsg = "<?php echo addslashes($toastMsg); ?>";
  if (toastMsg.trim() !== '') {
      const t = document.getElementById('toast');
      const tMsg = document.getElementById('toastMsg');
      if (t && tMsg) {
          tMsg.innerHTML = `<i class="fa-solid fa-circle-info"></i> ${toastMsg}`;
          t.classList.add('show');
          setTimeout(() => t.classList.remove('show'), 4000);
      } else {
          alert(toastMsg);
      }
  }
</script>

<?php require __DIR__ . '/layout/footer.php'; ?>