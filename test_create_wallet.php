<?php
// test_create_wallet.php
declare(strict_types=1);

echo "<h2>Test automatického vytvoření peněženky</h2>";

// 1. Vymyslíme ID pro nového klienta
$storeId = 'store_' . substr(bin2hex(random_bytes(4)), 0, 8);
$walletPath = '/opt/btcpay_wallets/' . $storeId . '_wallet';

echo "Cílová cesta: <strong>" . $walletPath . "</strong><br>";

// 2. Příprava příkazu pro Linux
// - Používáme 'electrum create'
// - 2>&1 přesměruje chybové hlášky, abychom je viděli v PHP
// Nová a finální cesta k Electrum!
// Nový příkaz s parametrem -D
$command = "python3 /opt/electrum/run_electrum -D /opt/electrum_config create --offline -w " . escapeshellarg($walletPath) . " 2>&1";
echo "Spouštím příkaz: <code>" . htmlspecialchars($command) . "</code><br><br>";

// 3. Spuštění příkazu a odchycení výsledku
$output = shell_exec($command);

// 4. Ověření, zda se soubor skutečně vytvořil
if (file_exists($walletPath)) {
    echo "<h3 style='color: green;'>✅ Úspěch! Peněženka byla fyzicky vytvořena.</h3>";
    
    // Zkusíme upravit práva nového souboru, aby ho Electrum daemon (ag) mohl 100% číst
    chmod($walletPath, 0664); 
    
    echo "<strong>Výpis z terminálu:</strong><br>";
    echo "<pre style='background: #eee; padding: 10px; border-radius: 5px;'>" . htmlspecialchars((string)$output) . "</pre>";
} else {
    echo "<h3 style='color: red;'>❌ Chyba! Peněženka se nevytvořila.</h3>";
    echo "<strong>Důvod (výpis z terminálu):</strong><br>";
    echo "<pre style='background: #fee; padding: 10px; border-radius: 5px; color: red;'>" . htmlspecialchars((string)$output) . "</pre>";
    echo "<p>Možný problém: PHP možná nezná cestu k příkazu 'electrum'. Zkus v kódu změnit 'electrum create...' na '/usr/local/bin/electrum create...' nebo cestu, kde je electrum nainstalováno.</p>";
}
?>