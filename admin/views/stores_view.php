<?php
// admin/views/stores_view.php
declare(strict_types=1);

$pageTitle = 'Správa E-shopů - BTCPay Lite';
$activeMenu = 'stores';
require __DIR__ . '/layout/header.php';
?>

<style>
/* Specifické styly pro výpis obchodů */
.store-item { background: #f9fafa; border: 1px solid #e5eae7; border-radius: 12px; padding: 20px; margin-bottom: 15px; }
.store-title { font-size: 16px; font-weight: 700; margin: 0 0 15px 0; display: flex; align-items: center; gap: 8px; color: #17201a; }
.code-box { background: #ffffff; border: 1px solid #e5eae7; padding: 10px; border-radius: 8px; font-family: ui-monospace, monospace; font-size: 12px; word-break: break-all; margin-bottom: 12px; color: #17201a; }
.code-label { font-size: 11px; font-weight: 700; color: #748078; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

/* Nové vertikální uspořádání formuláře */
.form-stack { display: flex; flex-direction: column; gap: 18px; max-width: 480px; }
</style>

<div class="page-header">
    <h1><i class="fa-solid fa-shop" style="color:#2fd35a;"></i> Správa obchodů (Stores)</h1>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-plus-circle" style="color:#20b948;"></i> Přidat nový e-shop</h2>
    <form method="POST" action="stores.php">
        <input type="hidden" name="action" value="create">
        
        <div class="form-stack">
            <div class="field" style="margin-bottom: 0;">
                <label>Název obchodu (např. Můj E-shop)</label>
                <div class="input-wrap">
                    <input type="text" name="store_name" required>
                </div>
            </div>
            
            <div class="field" style="margin-bottom: 0;">
                <label>Absolutní cesta k souboru peněženky</label>
                <div class="input-wrap">
                    <input type="text" name="wallet_path" placeholder="/home/ag/.../wallets/novy_obchod_wallet" required>
                </div>
            </div>
            
            <button type="submit" class="primary" style="margin-top: 5px;"><i class="fa-solid fa-plus"></i> Vytvořit Store & Vygenerovat API Klíč</button>
        </div>
    </form>
</div>

<div class="card">
    <h2 class="card-title"><i class="fa-solid fa-list" style="color:#748078;"></i> Moje e-shopy</h2>
    
    <?php if (empty($stores)): ?>
        <p style="color: #748078; font-size: 14px;">Zatím zde nemáš žádné obchody.</p>
    <?php else: ?>
        <?php foreach ($stores as $s): ?>
            <div class="store-item">
                <h3 class="store-title"><i class="fa-solid fa-cart-shopping" style="color: #20b948;"></i> <?php echo htmlspecialchars($s['name']); ?></h3>
                
                <div class="code-label">Store ID (Zadej do pluginu):</div>
                <div class="code-box"><?php echo htmlspecialchars($s['id']); ?></div>
                
                <div class="code-label">API Klíč (Zadej do pluginu):</div>
                <div class="code-box"><?php echo htmlspecialchars($s['api_key']); ?></div>
                
                <div class="code-label">Peněženka (Kam chodí BTC):</div>
                <div class="code-box" style="color:#748078; border-style: dashed; background: transparent;"><?php echo htmlspecialchars($s['wallet_path']); ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
  // Zobrazení notifikace pomocí sdíleného toast prvku v patičce
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