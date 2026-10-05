# Konfigurace

[Zpět na README](../README.md) · [Nasazení a oprávnění](DEPLOYMENT.md)

Příklad ukazuje názvy voleb, není hotovou konfigurací. Tajné hodnoty vytváří instalátor; při obnově zachovejte původní klíče.


`config.php` není verzovaný a nesmí se zveřejnit. Používané klíče zahrnují:

```php
return [
    'rpc_host' => '127.0.0.1',
    'rpc_port' => 7777,
    'rpc_user' => '...',
    'rpc_pass' => '...',
    'rpc_wallet_param_key' => 'wallet_path', // upstream; explicitně 'wallet' pro kompatibilní adapter
    'rpc_timeout' => 30,
    'rpc_connect_timeout' => 5,
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => '...',
    'db_user' => '...',
    'db_pass' => '...',
    'admin_api_key' => '...',
    'secret_key' => '...',
    'cron_key' => '...',
    'app_url' => 'https://pay.example.com',
    'password_reset_from' => 'no-reply@example.com',
    'wallet_path' => '/opt/btcpay_wallets/admin_wallet',
    'electrum_cli_path' => '/opt/electrum/run_electrum',
    'electrum_data_dir' => '/opt/electrum_config',
    'store_wallets_dir' => '/opt/btcpay_wallets',
    'allow_local_webhooks' => false,
    'exchange_fee_bps' => 200, // 2,00 %
    'payout_api_enabled' => false,
    'payout_api_keys' => [
        'store-id' => 'samostatny-nahodny-klic-alespon-32-znaku',
    ],
    'payout_wallet_passwords' => [
        'store-id' => 'heslo-electrum-walletu',
    ],
    'payout_max_btc' => '0.01000000',
    'payout_daily_limit_btc' => '0.05000000',
    'api_clients' => [
        'client-bearer-token' => 'wallet-id',
    ],
];
```

`payout_api_enabled` ponechte `false`, dokud není provedena migrace, nastaven samostatný dlouhý náhodný klíč, ověřena záloha walletu a vyzkoušen testnet/regtest smoke test. Výplatní klíč uchovávejte pouze na serveru směnárny; klientský prohlížeč jej nikdy nesmí znát. Limity nastavujte jako přesné BTC řetězce.

`app_url` nastavte explicitně ve všech nasazeních; nesmí obsahovat credentials, query ani fragment. Pro wallet nástroje jsou podporované nové klíče `electrum_cli_path`, `electrum_data_dir`, `store_wallets_dir` i kompatibilní starší názvy `electrum_cli`, `electrum_data_directory`, `wallet_directory`. Volitelný `allow_local_webhooks => true` je určen pouze pro lokální vývoj. V produkci jej vynechte nebo ponechte `false`.

## Propojení simple-store na localhostu bez HTTPS

V existujícím `config.php` upravte jen tyto dvě položky; ostatní nastavení a klíče zachovejte:

```php
'app_url' => 'http://localhost/BTCPayLite',
'allow_local_webhooks' => true,
```

V administraci **simple-store → Nastavení obchodu → BTCPay Server** nastavte:

| Pole | Příklad pro XAMPP |
|---|---|
| Adresa instance BTCPay | `http://localhost/BTCPayLite` |
| Adresa tohoto obchodu | `http://localhost/simple-store` |
| URL webhooku vytvořeného v Lite | `http://localhost/simple-store/btcpay-callback.php` |

Použijte skutečné názvy vašich instalačních složek a případně port, například `http://localhost:8080/BTCPayLite`. Adresa instance v e-shopu a `app_url` v Lite musí být stejné. Pro test lze také použít `127.0.0.1` nebo IPv6 `http://[::1]`; certifikát není potřeba. Podpis webhooku i ověřování API klíče zůstávají aktivní.

Webhook vytvořte před objednávkou, jeho tajný klíč vložte do e-shopu a spusťte také `webhook_cron.php`; kontrola platby pomocí `payment_worker.php` sama webhook neodesílá. Na XAMPP lze jednorázově doručit připravené události:

```bash
cd /opt/lampp/htdocs/BTCPayLite
/opt/lampp/bin/php webhook_cron.php
```

Obě aplikace musí běžet na stejném počítači jako Lite a jeho webhook worker. `localhost` z jiného serveru ukazuje na ten jiný server. HTTP výjimka se nevztahuje na veřejné domény ani adresy v LAN. Při přesunu na hosting nastavte HTTPS a `allow_local_webhooks` vraťte na `false`. Samotná aktualizace z Gitu vaši místní volbu v ignorovaném `config.php` nezmění.

`password_reset_from` musí být platná adresa odesílatele a server musí mít funkční PHP `mail()`/MTA. Resetovací token se do databáze ukládá pouze jako SHA-256, platí 30 minut, je jednorázový a po změně hesla zvýší verzi relace.

Peněženky musí být mimo web root, například v `/opt/btcpay_wallets/`. Electrum RPC port nemá být veřejně dostupný.


`config.php` musí přečíst webové PHP i účet CLI workerů. Pro sdílené runtime adresáře nastavte stejné `BTCPAY_WALLET_LOCK_DIR` a `BTCPAY_BLOCKCHAIN_CACHE_DIR` ve všech procesech; výchozí jsou `var/locks` a `var/blockchain`. Postup ACL je v návodu nasazení.

Zapamatování přihlášení a jeho limity popisuje [správa relací](SESSION_LOGIN.md).

## Společný limit blockchain kontrol

`BTCPAY_BLOCKCHAIN_OBSERVATIONS_PER_MINUTE` omezuje dohromady worker a
stateless cache misses téhož endpointu. Výchozí hodnota je 60, povolený rozsah
1–600; současně běží nejvýše dvě observations. Nastavení patří do prostředí
PHP/CLI, nikoli do config.php. Všichni observers musí sdílet cache i tuto hodnotu.
Podrobnosti a rozdíl mezi scheduler tickem a kontrolou faktury: [CAPACITY](CAPACITY.md).
