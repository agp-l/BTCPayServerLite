# BTCPay Server Lite

PHP aplikace pro Bitcoin on-chain faktury, obchody a platební odkazy nad Electrem a MySQL/MariaDB. Obsahuje administraci, klientskou část, DB checkout, podmnožinu Greenfield API a podepsané webhooky.

**Stav k 10. září 2026:** platební jádro má oddělenou tvorbu adres, pozorování blockchainu, změny stavů a doručování webhooků. XPUB faktury rezervují index v DB a odvozují adresu lokálně. Další práce a konkrétní hranice ověření jsou v [plánu vývoje](docs/ROADMAP.md); projekt není označen jako kompletně auditovaný.

## Kde začít

| Potřebuji | Návod / nástroj |
|---|---|
| Novou instalaci nebo přenos na jiný server | [Opakovatelné nasazení](docs/DEPLOYMENT.md) |
| Aktualizovat existující databázi | Admin **Nástroje → Aktualizace systému**, [návod](docs/DATABASE_UPGRADE.md) |
| Zapnout nebo ověřit kontrolu plateb | Admin **Nástroje → Kontrola plateb**, [systemd / cron](docs/PAYMENT_MONITORING.md) |
| Vyřešit chybu vytvoření obchodu | [Diagnostika provisioningu](docs/STORE_CREATION_TROUBLESHOOTING.md) |
| Připojit e-shop nebo API klienta | [API a příklady](docs/API.md) |
| Zkontrolovat konfiguraci a oprávnění | [Konfigurace](docs/CONFIGURATION.md), `php bin/deployment.php --check` |
| Pochopit rezervace adres a obnovu XPUB | [Koordinace adres](docs/RECEIVE_COORDINATION.md) |
| Zůstat přihlášený | Volba **Zůstat přihlášený na tomto zařízení 30 dní**, [relace](docs/SESSION_LOGIN.md) |

## Instalace

Produkční aplikace používá PHP a Composer. PHP 8.0 je deklarované minimum kódu; ověřte rozšíření a závislosti v **CLI i webovém PHP**. Potřebujete PDO MySQL, cURL a GMP pro XPUB, databázi MySQL/MariaDB a pro wallet operace Electrum. Composer kontroluje zbývající požadavky zamčených knihoven. Na místním XAMPP bylo ověřeno webové PHP 8.0.30 a oddělené CLI PHP 8.3; nejde o doporučení těchto konkrétních verzí pro nový server.

```bash
composer install --no-dev --prefer-dist
composer check-platform-reqs --no-dev
```

1. Postupujte podle [nasazení](docs/DEPLOYMENT.md): připravte webserver, DB, PHP, Electrum a skutečné systémové účty.
2. Bez `config.php` otevřete `install.php`. Instalátor přijímá prázdnou DB nebo čistý import současného [sql.sql](sql.sql). První admin vznikne z e-mailu a hesla, které zadáte; pevné výchozí heslo ani ukázkový obchod se nevytváří. Instalace sama nevyžaduje běžící Electrum.
3. Pomocí `bin/deployment.php --permissions` vygenerujte a zkontrolujte oprávnění pro web, CLI a Electrum podle svého serveru. Web a CLI musí sdílet zámky i blockchain cache a mít přístup také k již existujícím souborům.
4. Vytvořte obchod, ověřte adresu a fakturu. Zapněte samostatné workery níže; otevření checkoutu je nenahrazuje.

Instalátor zpřístupněte jen správci. Konfiguraci, původní podpisové klíče, databázi a wallet soubory zálohujte společně mimo veřejný web. Smazání `config.php` není upgrade ani obnova používané instalace. [Postup obnovy a oprávnění](docs/DEPLOYMENT.md) zahrnuje rozdílné účty Apache a workeru.

V repozitáři zůstává také samostatný Node/EJS prototyp (`server.js`, `src/`, `views/`, `package.json`). Používá demonstrativní data v paměti a není PHP platebním backendem. `npm start` není instalační ani testovací postup pro tuto aplikaci.

## Aktualizace existující instance

```bash
git pull --ff-only
composer install --no-dev --prefer-dist
composer check-platform-reqs --no-dev
```

Kód aktualizujte v plánované údržbě. Před DB migrací vytvořte obnovitelnou zálohu, zastavte zápisy a workery a vyčkejte dokončení běžících dávek. V adminu otevřete **Nástroje → Aktualizace systému** (`/admin/database_upgrade`). Starý odkaz `database_upgrade.php` zůstává přesměrováním.

Nástroj porovnává podporované části schématu s `sql.sql` a nabízí jednotlivé známé migrace 001–010. Samotné otevření nic nemění. Historické migrace, částečně provedené změny a datové backfilly mohou vyžadovat ruční postup; podrobnosti jsou v [návodu aktualizace DB](docs/DATABASE_UPGRADE.md). Celé `sql.sql` neimportujte přes používanou DB. Aktualizátor DB nestahuje nový kód z GitHubu.

Po aktualizaci zkontrolujte oprávnění a diagnostiku a obnovte workery. `config.php` není verzovaný; zachovejte svou konfiguraci a klíče.

## Provoz workerů

| Vstupní bod | Odpovědnost | Jak jej ověřit |
|---|---|---|
| `payment_worker.php` | Blockchain observation, invoice stav a atomické zařazení webhooků | Admin Kontrola plateb, CLI `--check`, journal služby |
| `webhook_cron.php` | Doručení již zařazených webhooků a retry | Výsledky doručení a log spouštění |
| `wallet_receive_sync.php` | Postupné zpřístupnění rezervovaných XPUB adres Electru | CLI výsledek, `--check-db`, [receive průvodce](docs/RECEIVE_COORDINATION.md) |

Payment a receive worker jsou CLI-only. Webhook cron podporuje také autorizované HTTP POST s Bearer cron klíčem; pro běžný provoz použijte CLI. Každý worker potřebuje vlastní plánování. Systemd timer pro platby nevytváří plánování zbývajících dvou workerů.

```bash
php payment_worker.php --check
php wallet_receive_sync.php --check-db
```

Tyto kontroly čtou DB bez blockchain RPC. `wallets: []` s `no_registered_wallets` znamená, že receive worker nemá registrované rozsahy; nevyhledává všechny peněženky daemonu. Existující obchody připojte postupem v [koordinaci adres](docs/RECEIVE_COORDINATION.md).

Automatické spouštění plateb nastavte podle [systemd návodu](docs/PAYMENT_MONITORING.md). Admin tlačítko provede omezenou dávku stejným workerem. Informace o nedávném CLI běhu není přímou kontrolou zapnutého systemd/cron. **`success: true` a `scanned: 0` potvrzuje pouze dokončení prázdné dávky, nikoli funkční blockchain RPC.** Chyby jsou rozlišené bezpečnými kódy, například `cache_directory` nebo `rpc_authentication`.

Na uživatelské instalaci bylo 10. září potvrzeno automatické zpracování dvou neuhrazených faktur: `scanned: 2`, `expired: 2`, `failed: 0`. Po opravě sdílené cache tedy běží časovač, observation i zápis stavu. Tento výsledek ještě neověřuje příjem skutečné platby ani doručení webhooku.

## Vlastníci platebních operací

| Operace | Vlastník |
|---|---|
| Tvorba adresy | `AddressGenerator` a sdílená DB rezervace XPUB indexu / receive koordinátor |
| Blockchain observation | `BlockchainProvider`, per-address cache a single-flight |
| Monitoring a stav faktury | `PaymentWorker` a `InvoiceStateMachine` |
| Checkout | DB repository a presentation; žádné RPC ani změna stavu |
| Stateless status | Ověřený token + provider/cache, bez loaded wallet |
| Wallet mutace | Nejnižší mutation service, explicitní wallet path a společný per-wallet lock |
| Webhook delivery | `WebhookProcessor`, odděleně od kontroly plateb |

Blockchain RPC běží mimo DB transakci. Po observation worker v jedné krátké transakci ověří lease, znovu načte fakturu, uloží observation, provede povolený přechod, zařadí outbox a uvolní lease. XPUB tvorba faktury nevolá Electrum a nezískává wallet mutation lock. [Architektura](docs/CORE_PAYMENT_ARCHITECTURE.md) a [receive koordinace](docs/RECEIVE_COORDINATION.md) popisují provozní podmínky, včetně sdílených cest.

`New` může přejít na `Processing`, `Expired` nebo `Settled`. Jakákoli zjištěná částečná platba vede na `Processing`; ten se nevrací na `New`. `Expired` může při pozdní platbě přejít na `Processing` nebo `Settled`. `Settled` je terminální. Expired faktury se běžně kontrolují ještě 24 hodin, déle při platební indikaci.

Observation ukládá integer satoshi jako **aktuální confirmed balance a podepsanou mempool delta**, nikoli kumulativní historicky přijaté outputs. Terminální stav chrání už uložené `Settled`; omezení před první observation a další kroky popisuje [plán](docs/ROADMAP.md).

## Integrace a hranice podpory

Greenfield API implementuje podmnožinu pro BTC-CHAIN faktury, checkout a webhooky. Podporuje `Authorization: token …` i `Bearer …`. Idempotency tvorby faktury používá trvalou rezervaci resource; stejný klíč s jiným obsahem vrátí 409. Přehled endpointů a samostatný PHP tester jsou v [API dokumentaci](docs/API.md).

Volitelný payout modul je ve výchozím stavu vypnutý. `InProgress` znamená přijetí broadcastu, nikoli potvrzení transakce. Potvrzovací worker, refundace, pull payments a Lightning nejsou dokončené součásti tohoto systému. Příprava budoucí směnárny má vlastní body v plánu; současný stav není její hotové jádro.

## Vývoj a ověření

```bash
php tests/run_all.php
```

[Průvodce testy](docs/TESTING.md) popisuje požadavky, izolované DB a rozdíl mezi mock testem, concurrency testem a ověřením proti Electru. Úspěšný běh bez nastavení integrační DB nemusí provést DB scénáře. Přesné historické výsledky jsou zachované v pracovních záznamech.

[Dokumentace a historie](docs/README.md) · [Další práce podle priority](docs/ROADMAP.md) · [Licence](LICENSE)
