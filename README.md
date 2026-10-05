# BTCPay Server Lite

PHP platební brána pro Bitcoin on-chain faktury nad Electrem a MySQL/MariaDB.
Obsahuje administraci, klientský portál, databázový checkout, podmnožinu
Greenfield API a podepsané webhooky. Veřejná dokumentace běží na `/dokumentace`.

**Stav 5. října 2026:** hlavní tok přijímání plateb je implementovaný a má
procesní, databázové a HTTP testy. XPUB faktura vzniká lokálně bez Electrum RPC;
checkout a čtení invoice API používají DB. Blockchain sleduje omezený worker.
Při vysoké návštěvnosti tedy každý návštěvník nespouští kontrolu v Electru.
Souhrnný počet různých adres chrání společný budget a omezení souběhu.
To je ochrana upstreamu, nikoli záruka neomezené kapacity nebo krátké fronty.

[Současný stav a integrace](docs/PROJECT_STATUS_2026_10.md) ·
[Kapacita a limity](docs/CAPACITY.md) ·
[Další práce](docs/ROADMAP.md) ·
[Ověření tohoto průchodu](docs/STABILIZATION_2026_10.md)

## Kde začít

| Potřebuji | Návod / nástroj |
|---|---|
| Instalovat, přenést nebo obnovit server | [Nasazení](docs/DEPLOYMENT.md) |
| Aktualizovat existující DB | Admin **Nástroje → Aktualizace systému**, [migrace](docs/DATABASE_UPGRADE.md) |
| Zapnout nebo zkontrolovat platby | Admin **Nástroje → Kontrola plateb**, [workery](docs/PAYMENT_MONITORING.md) |
| Ověřit konkrétní pozdní platbu | Admin kontrola podle ID / `php payment_worker.php --invoice=inv_ID` |
| Připojit e-shop | [API](docs/API.md), [simple-store](docs/PROJECT_STATUS_2026_10.md) |
| Testovat obě aplikace na localhostu | [Místní HTTP konfigurace](docs/CONFIGURATION.md#propojení-simple-store-na-localhostu-bez-https) |
| Opravit provisioning nebo oprávnění | [Diagnostika](docs/STORE_CREATION_TROUBLESHOOTING.md), `php bin/deployment.php --check` |
| Opravit nebo synchronizovat XPUB wallet | [Koordinace adres](docs/RECEIVE_COORDINATION.md) |
| Zůstat přihlášený | [Relace a 30denní zařízení](docs/SESSION_LOGIN.md) |

## Instalace a aktualizace

Aplikace používá PHP a Composer. Deklarované minimum je PHP 8.0; CI ověřuje
PHP 8.2 a 8.3. Platformní požadavky ověřte zvlášť v CLI i webovém PHP: PDO MySQL,
cURL, GMP a další rozšíření zamčených knihoven. Pro wallet operace a monitoring
potřebujete Electrum; samotná XPUB derivace jej nevolá. MySQL/MariaDB musí
používat InnoDB. [Závislosti a známá omezení](docs/DEPENDENCIES.md).

```bash
composer install --no-dev --prefer-dist
composer check-platform-reqs --no-dev
```

1. Připravte prostředí podle [DEPLOYMENT](docs/DEPLOYMENT.md), včetně ochrany
   interních souborů webserverem a skutečných účtů web/CLI/Electrum.
2. Bez `config.php` otevřete `install.php`, přístupný jen správci. Instalátor
   přijímá prázdnou DB nebo čistý současný import [sql.sql](sql.sql) a vytvoří
   prvního admina z vámi zadaného e-mailu a hesla. Pevné výchozí heslo neexistuje.
3. Vygenerujte a zkontrolujte oprávnění přes `bin/deployment.php --permissions`.
   Web a CLI musí sdílet cache a zámky, včetně přístupu ke stávajícím souborům.
4. Vytvořte obchod, adresu a fakturu; zapněte samostatně všechny tři workery.

Existující instalaci aktualizujte v plánované údržbě přes `git pull --ff-only`
a `composer install` se stávajícím lockfilem. Před migrací zálohujte DB,
zastavte zápisy a workery a vyčkejte běžící dávky. Admin aktualizátor podporuje
katalog **001–011**. Pro receipt monitoring je nutná
**011_invoice_received_outputs.sql**; samotný nový rescan další migraci nepotřebuje.
Celé `sql.sql` neimportujte přes používanou DB. Podrobnosti a přerušené DDL:
[DATABASE_UPGRADE](docs/DATABASE_UPGRADE.md).

Zálohujte společně původní config a podpisové klíče, DB sekvence a wallet soubory
mimo veřejný web. Smazání configu není obnova. Runtime lock soubory za provozu
nemažte. Git aktualizace sama nenastaví váš Linux timer ani nenasadí změny na server.

Node.js, npm ani Bun nejsou součástí aplikačního backendu. PHP šablony jsou
v modulech a prohlížečové CSS/JavaScript v `assets/`. Verzovaný `vendor` zůstává
pro současný způsob distribuce; zdrojem verzí je `composer.lock`.

## Provoz a zatížení Electra

| Vstupní bod | Úloha | Doporučené výchozí plánování |
|---|---|---|
| `payment_worker.php` | Observation, invoice stav, atomický webhook outbox | Každých 10 minut; volitelný minutový tick pro větší frontu |
| `webhook_cron.php` | Doručení outboxu, HMAC a retry | Každou minutu |
| `wallet_receive_sync.php` | Zpřístupnění rezervovaných XPUB adres Electru | Každou minutu |

Payment a receive jsou CLI-only. Webhook cron podporuje také autorizovaný
HTTP POST; běžný provoz používá CLI. Payment timer nespouští ostatní workery.

```bash
php payment_worker.php --check
php wallet_receive_sync.php --check-db
```

Tyto příkazy čtou DB bez blockchain RPC. `scanned: 0` potvrzuje prázdnou dávku,
nikoli funkční Electrum. Admin sleduje poslední CLI/manual běh a stáří fronty;
nehádá, zda je nainstalovaný timer. `health` HTTP potvrzuje dosažitelnost API,
synchronizace je neznámá (`null`). Cílená CLI RPC diagnostika:
`php bin/health_check.php --json`.

Kontrola jedné faktury má cadence **10 / 30 / 60 minut** podle stáří; po
Settled končí. Provider sdílí per-address cache, single-flight a cooldown.
Dále povolí nejvýše **60 nových observations za rolling 60 sekund a dvě současně**
pro jeden endpoint a společnou cache. Jedna receipt observation má nejvýše
čtyři RPC; transportní/auth chyby krátce pozastaví nové observations.
Stejné cesty a prostředí musí používat všechny procesy. Admin wallet a receive
sync mají vlastní RPC mimo tento invoice budget. [Výpočty a hranice](docs/CAPACITY.md).

Checkout polling čte DB přibližně po 5 sekundách a neskenuje blockchain.
Pro více návštěvníků dimenzujte také PHP/DB a omezte HTTP požadavky na webserveru.
Tisíc nových faktur kontrolovaných po 10 minutách není totéž co tisíc otevřených
checkoutů. Fronta může růst i tehdy, když budget správně chrání Electrum.

## Platební kontrakt

XPUB indexy jsou společné pro stejný veřejný klíč i přes více obchodů a jeho
prefixové aliasy. Index se po chybě nevrací. Wallet mutace mají explicitní cestu
a per-wallet lock. Observation probíhá mimo DB transakci; worker pak atomicky
ověří lease, uloží observation/stav/outbox a uvolní lease.

Receipt provider ověřuje raw TXID a výstupy adresy a rozpozná příjem i po
utracení před prvním skenem. Vlastní vrácené drobné nejsou další úhrada.
Potvrzené příjmy, nepotvrzené příjmy a aktuální balance jsou oddělené.
Výšky potvrzení stále pocházejí z Electrum serveru: nejde o vlastní SPV důkaz.

`New` může přejít do `Processing`, `Expired` nebo `Settled`. Částečná či
nepotvrzená platba vede do `Processing`, který se nevrací na New. Expired se
může při pozdní platbě změnit. Settled je terminální; pozdější reorg se u něj
nesleduje. Neuhrazený Expired se automaticky sleduje 24 h po expiraci, známá
partial platba déle. Pro platbu za hranicí okna použijte konkrétní rescan.
[Podrobná architektura](docs/CORE_PAYMENT_ARCHITECTURE.md).

## Integrace a dokončení

Pro e-shop používejte databázové Greenfield faktury, ne stateless odkazy.
Výchozí expirace je 48 hodin. API podporuje `token` i `Bearer`, přesné BTC
řetězce a trvalou idempotency rezervaci. Webhook je oznámení: receiver ověří
HMAC a načte důvěryhodný invoice detail, obchod, cenu, měnu a objednávku před
idempotentním označením jako zaplaceno. HTTP/API health není tento důkaz.

Volitelný payout modul je výchozí **vypnutý**. InProgress znamená přijetí
broadcastu, ne potvrzenou výplatu. Společné UTXO rezervace, úplná reconciliation
payoutů, potvrzovací worker, Lightning, refundace a pull payments nejsou dokončené.
Projekt proto není hotová automatická směnárna ani úplně auditovaný payment stack.
Aktuální práce a podmínky dokončení jsou pouze v [ROADMAP](docs/ROADMAP.md).

```bash
php tests/run_all.php
```

DB a Apache scénáře vyžadují vlastní testovací nastavení; bez něj se mohou
přeskočit. [TESTING](docs/TESTING.md) a [aktuální checkpoint](docs/STABILIZATION_2026_10.md)
rozlišují skutečné DB/procesy/HTTP, řízené RPC a neověřené cílové prostředí.
[Dokumentace](docs/README.md) · [Historie dokončené práce](docs/HISTORY.md) · [Licence](LICENSE)
