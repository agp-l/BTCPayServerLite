# BTC Pay Server Lite

Lehká, samoobslužná **Bitcoin platební brána v PHP**, navržená jako rychlá alternativa k plnohodnotnému BTCPay Serveru.

Projekt poskytuje:

- kompatibilní **Greenfield API** pro napojení e-shopů, například WooCommerce,
- zákaznickou platební bránu,
- administrační rozhraní,
- automatizovaný systém webhooků,
- správu Bitcoinových peněženek,
- komunikaci s Bitcoin sítí prostřednictvím **Electrum Daemonu přes JSON-RPC**.

---

## 📂 1. Struktura systému a umístění dat

Z bezpečnostních důvodů je systém striktně rozdělen na **aplikaci, data a webový frontend**.

### A) Systémové cesty

Tyto adresáře jsou umístěné mimo dosah webového serveru:

| Cesta | Účel |
|---|---|
| `/opt/electrum/` | Zdrojové kódy a běhový motor Electrum |
| `/opt/electrum_config/` | Konfigurační soubory démona |
| `/opt/btcpay_wallets/` | Bezpečný trezor pro klientské peněženky |

Adresář `/opt/btcpay_wallets/` používá speciální oprávnění `g+s`, aby nové soubory mohly spravovat jak PHP aplikace, tak Electrum démon.

### B) Webový frontend (`htdocs`)

| Soubor / složka | Účel |
|---|---|
| `config.php` | Hlavní konfigurace databáze, Electru a bezpečnostních klíčů |
| `api.php` | Zpracování Greenfield API požadavků z e-shopů, generování faktur a webhooků |
| `pay.php` | Zákaznická platební brána pro úhradu faktur |
| `webhook_cron.php` | Kontrola plateb v síti a odesílání webhooků do e-shopů |
| `classes/` | Objektová logika aplikace (OOP) |
| `admin/` | Administrace systému a obchodníků |

---

## 🏗️ 2. Architektura a třídy

Systém je rozdělen do tří logických vrstev, aby byl kód čistý, univerzální a snadno rozšiřitelný.

### Vrstva 1: Kořen komunikace

#### `ElectrumRPC.php`

Zajišťuje čistou a bezpečnou komunikaci prostřednictvím cURL s JSON-RPC serverem Electrum.

Třída neobsahuje Bitcoinovou aplikační logiku. Pouze odesílá RPC požadavky a vrací zpracovaná data nebo vyhazuje výjimky (`Exceptions`).

#### Hlavní metody

- `call($method, $params)` – univerzální odesílač RPC příkazů.
- `setWallet($walletPath)` – přidá cestu k peněžence do RPC URL, aby démon věděl, se kterou peněženkou má operaci provést.

---

### Vrstva 2: Bitcoinový motor

#### `ElectrumWallet.php`

Univerzální Bitcoinová peněženka. Neřeší faktury ani e-shopy – pouze převádí Bitcoinové operace na RPC příkazy Electrum.

#### Hlavní metody

- `loadWallet($walletPath)` – pravidlo **„Highlander“**; zajistí, že je v démonu načtena právě jedna požadovaná peněženka a ostatní se zavřou.
- `getWalletBalance()` – vrací potvrzený i nepotvrzený zůstatek celé peněženky.
- `getAddressBalance($address)` – vrací zůstatek konkrétní adresy.
- `getNewAddress()` – vygeneruje novou přijímací adresu.
- `sendPayment($dest, $amount, $pass, $fee)` – vytvoří, podepíše a odešle transakci do Bitcoinové sítě.

---

### Vrstva 3: Aplikační logika

#### `BtcInvoiceManager.php`

Řídí kompletní životní cyklus plateb pro e-shopy.

#### Hlavní metody

- `createDatabaseInvoice(...)` – uloží novou fakturu do databáze, požádá `ElectrumWallet` o novou adresu a připraví návratová data pro e-shop.
- `checkDatabasePaymentStatus($invoiceId)` – zkontroluje zůstatek na adrese faktury, vyhodnotí její stav a aktualizuje MySQL.

Možné stavy faktury:

- `New`
- `Processing`
- `Settled`
- `Expired`

#### `BtcDashboard.php`

Propojuje surová data z peněženky s formátem připraveným pro administrační UI.

#### Hlavní metody

- `getAddressesData()` – agreguje adresy a jejich zůstatky.
- `getTransactionsHistory()` – vrací formátovanou a dekódovanou historii transakcí.
- `exportKeys()` – bezpečně exportuje seed nebo veřejný `xpub` klíč.

---

## 💻 3. Použití tříd ve vlastních skriptech

Třídy jsou navrženy jako **drop-in komponenty**, takže je lze jednoduše použít i ve vlastních PHP skriptech.

Například pro vytvoření vlastního skriptu pro automatické odesílání výplat:

```php
<?php

// 1. Načtení konfigurace a vrstev
$config = require __DIR__ . '/config.php';

require __DIR__ . '/classes/ElectrumRPC.php';
require __DIR__ . '/classes/ElectrumWallet.php';

// 2. Inicializace RPC motoru
$rpc = new ElectrumRPC(
    $config['rpc_host'],
    $config['rpc_port'],
    $config['rpc_user'],
    $config['rpc_pass']
);

$wallet = new ElectrumWallet($rpc);

// 3. Načtení peněženky
$wallet->loadWallet('/opt/btcpay_wallets/wallet_1');

// 4. Zavolání libovolné akce
$address = $wallet->getNewAddress();
$balance = $wallet->getWalletBalance();

```

---

## ⚙️ 4. Instalace a správa Electrum Daemonu

Systém vyžaduje běžící **Electrum Daemon**, který na pozadí zpracovává RPC příkazy přicházející z PHP aplikace.

### A) Prvotní příprava a oprávnění

Vytvoř systémové složky a nastav správného vlastníka.

V tomto příkladu:

- `ag` = uživatel, pod kterým běží Electrum,
- `daemon` = skupina / uživatel webového serveru.

```bash
# Instalace Electrum
sudo mv /cesta/k/stazenemu/electrum /opt/electrum
sudo chown -R ag:daemon /opt/electrum
sudo chmod -R 755 /opt/electrum

# Konfigurační složka
sudo mkdir -p /opt/electrum_config
sudo chown -R ag:daemon /opt/electrum_config
sudo chmod -R 775 /opt/electrum_config

# Složka pro peněženky
sudo mkdir -p /opt/btcpay_wallets
sudo chown -R ag:daemon /opt/btcpay_wallets
sudo chmod -R 775 /opt/btcpay_wallets

# Setgid – nové soubory zachovají skupinu adresáře
sudo chmod g+s /opt/btcpay_wallets
```

> **Bezpečnostní poznámka:** Adresář `/opt/btcpay_wallets/` by měl zůstat mimo veřejný webový root (`htdocs`), aby nebylo možné stáhnout soubory peněženek přes HTTP.

---

### B) Vytvoření Systemd služby

Aby Electrum Daemon automaticky běžel po restartu serveru, vytvoř:

```bash
sudo nano /etc/systemd/system/electrum.service
```

Obsah služby:

```ini
[Unit]
Description=Electrum RPC Daemon
After=network.target

[Service]
User=ag
Group=daemon
Type=simple
ExecStart=/usr/bin/python3 /opt/electrum/run_electrum -D /opt/electrum_config daemon --rpcport 7777
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

Následně načti konfiguraci systemd a službu spusť:

```bash
sudo systemctl daemon-reload
sudo systemctl enable electrum
sudo systemctl start electrum
```

Kontrola stavu služby:

```bash
sudo systemctl status electrum
```

Logy služby lze sledovat pomocí:

```bash
sudo journalctl -u electrum -f
```

---

## 🛠️ 5. Užitečné CLI příkazy

Při manuálních zásazích je nutné Electrum vždy nasměrovat na naši konfigurační složku pomocí `-D`.

### Vytvoření nové peněženky offline

```bash
python3 /opt/electrum/run_electrum \
    -D /opt/electrum_config \
    create \
    --offline \
    -w "/opt/btcpay_wallets/nova_penezenka"
```

### Zjištění zůstatku

```bash
python3 /opt/electrum/run_electrum \
    -D /opt/electrum_config \
    getbalance \
    -w "/opt/btcpay_wallets/wallet_1"
```

### Generování adresy / payment requestu

```bash
python3 /opt/electrum/run_electrum \
    -D /opt/electrum_config \
    -w "/opt/btcpay_wallets/wallet_1" \
    add_request \
    0.001 \
    -m "Test"
```

---

## 🔐 6. Bezpečnostní principy

Projekt je navržen s důrazem na oddělení webové aplikace od citlivých dat.

### Doporučení

- Peněženky ukládat výhradně mimo `htdocs`.
- Nikdy nevystavovat `/opt/btcpay_wallets/` přes HTTP.
- RPC port Electrumu (`7777`) nevystavovat veřejně do internetu.
- Přístup k RPC chránit autentizací a síťovým firewallem.
- Seed fráze a privátní klíče nikdy neukládat do veřejně dostupných souborů.
- `config.php` chránit před neoprávněným čtením.
- Pravidelně zálohovat databázi i peněženky.
- Při exportu seedů nebo privátních klíčů používat zabezpečené prostředí.
- Webový uživatel by měl mít pouze taková oprávnění, která aplikace skutečně potřebuje.

---

## 🔄 7. Tok platby

Zjednodušený tok platby v systému:

```text
E-shop
   │
   │ Greenfield API
   ▼
api.php
   │
   ▼
BtcInvoiceManager
   │
   ├──► MySQL
   │
   └──► ElectrumWallet
            │
            ▼
       ElectrumRPC
            │
            ▼
      Electrum Daemon
            │
            ▼
       Bitcoin Network
```

Po zaplacení faktury:

```text
Bitcoin Network
      │
      ▼
Electrum Daemon
      │
      ▼
webhook_cron.php
      │
      ▼
BtcInvoiceManager
      │
      ├──► Aktualizace MySQL
      │
      └──► Webhook
             │
             ▼
           E-shop
```

---

## 📌 8. Shrnutí

**BTC Pay Server Lite** poskytuje jednoduchou architekturu Bitcoinové platební brány bez potřeby provozovat kompletní BTCPay Server.

Hlavní komponenty:

```text
PHP Application
├── Greenfield API
├── Payment Gateway
├── Admin Interface
├── Invoice Management
└── Webhook System
        │
        ▼
ElectrumWallet
        │
        ▼
ElectrumRPC
        │
        ▼
Electrum Daemon
        │
        ▼
Bitcoin Network
```

Projekt je vhodný především pro vlastní e-shopy a služby, které potřebují **lehkou, samoobslužnou Bitcoin platební infrastrukturu s PHP backendem a Electrum Daemonem**.



