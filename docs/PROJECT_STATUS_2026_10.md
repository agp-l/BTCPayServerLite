# BTC Pay Lite: stav a propojení se simple-store

Revize **5. října 2026**. Výchozí repozitáře: BTCPayServerLite
`1b1f14ec222369ee1a489268b1c2b79f35ba0422`, simple-store
`d8a395d2cd3a2b921b4f258c1ba8457ba78c5203`.

## Závěr

BTC Pay Lite má implementovaný základ on-chain platební brány pro e-shop.
simple-store už obsahuje vlastní BTCPay klient, platební pokusy, callback,
návrat zákazníka a ověřování zaplacené objednávky. Propojení proto nevyžaduje
přepsání ani jednoho systému. Revize našla a opravila dvě skutečné
nekompatibility: cestu pro načtení faktury a povolený formát checkout odkazu.

Pro e-shop používat **databázové Greenfield faktury**. Stateless odkazy nemají
stejnou trvalou evidenci zaplacení. Výplaty a budoucí směnárna mají vlastní
nedokončené části a nejsou podmínkou přijímání objednávek.

Provozní uzavření integrace vyžaduje skutečnou testovací platbu přes Electrum,
ověření veřejného HTTPS callbacku a kontrolu všech workerů na cílovém serveru.
Automatické testy se simulovanými blockchainovými odpověďmi tento důkaz
nenahrazují. Tato revize není úplný bezpečnostní audit ani kapacitní certifikace.

## Co je nyní implementované

| Oblast | Současné chování | Důležité omezení |
|---|---|---|
| Technologie | PHP, Composer, MySQL/MariaDB, Electrum JSON-RPC; CSS a JS v prohlížeči | Node/EJS prototyp odstraněn; PHP ^8.0 deklarované v Composeru, skutečné platformní požadavky ověřit v CLI i webu |
| Instalace a upgrade | Instalátor prvního admina, konfigurace, DB migrace 001–010, preflight a generátor oprávnění | Upgrade nenahrazuje zálohu a obnova vyžaduje původní config, DB, podpisové klíče a wallet data |
| Účty | Admin/client, přihlášení, zapamatování zařízení, reset hesla, řízení registrací | SMTP, oprávnění a relace ověřit na konkrétním hostingu |
| Administrace | Klienti, obchody, faktury, webhooky, peněženka, filtry, změny a vhodné mazání | Nejde o hotovou custodial peněženku přihlašovanou telefonem |
| Peněženky | Provisioning klientské wallet, XPUB režim i kompatibilní Electrum režim, explicitní cesty a wallet locky | Pád mezi vytvořením wallet souboru a DB zápisem stále potřebuje reconciliation |
| Přijímací adresy | Lokální XPUB derivace a atomické sdílené rezervace indexů | Obnova nesmí snížit uložený index; všechny cesty příjmu musejí sdílet stejnou sekvenci |
| Faktury | DB evidence, přesné satoshi, částka/měna/metadata, rezervace resource pro API idempotency | simple-store zatím `Idempotency-Key` neposílá; nejistý POST blokuje další pokus a vyžaduje kontrolu |
| Checkout | Veřejný `/pay?id=…`, QR, BIP21, DB snapshot, návrat do e-shopu | Otevření checkoutu nekontroluje blockchain a nenahrazuje worker |
| Kontrola plateb | Samostatný CLI PaymentWorker, lease, observation mimo DB transakci, stav a webhook outbox atomicky | Stav vychází z aktuální balance; chybí nastavitelné počty potvrzení a reorg politika |
| Webhooky | HMAC-SHA256, trvalá fronta, retry a claim tokeny, ochrana proti SSRF | Doručení může být opakované; pomalé dávky mohou překročit claim lease |
| Receive synchronizace | CLI worker zpřístupňuje rezervované XPUB rozsahy Electru | Vyžaduje vlastní plánování; payment timer jej nespouští |
| Greenfield API | Obchod, faktury, payment methods, webhooky, `token` i `Bearer` | Implementovaná podmnožina; není to plná náhrada všech endpointů BTCPay Serveru |
| Kurzy a směna | Quote služby a výpočet volitelných poplatků | Není hotová účetní/provozní směnárna |
| Payouts | Volitelný modul, ledger, oddělený klíč, limity, uložení podepsané transakce před broadcastem | `InProgress` není potvrzená výplata; chybí plné UTXO rezervace, monitoring a reconciliation |
| Lightning, refundace, pull payments | Nejsou dokončené součásti | Pro tento integrační průchod se nepoužívají |

Přiložené kopie PHP a README se liší od aktuálního GitHub `main`; závěry této
revize vycházejí z repozitářů, ne ze starých kopií. Přiložený živý `config.php`
není součástí publikovaných změn.

## Opravy v tomto průchodu

### Kopírování v BTC Pay Lite

- `assets/admin.css`: opravena cyklická proměnná výběru textu. Výběr má
  konkrétní zelené pozadí a text lze označovat. Opravena i cyklická barva overlaye.
- `assets/admin.js`: společné kopírování přes Clipboard API a náhradní textarea
  postup pro HTTP či zamítnutý přístup. Neúspěch se neskrývá za zprávu o úspěchu;
  dočasné pole se odstraní a obnoví se původní focus/výběr.
- Admin i klient mají u tajného klíče webhooku samostatné kopírovací tlačítko.
  Kopíruje celou skutečnou hodnotu i při maskovaném password poli.
- Kopírování API klíčů, Store ID, odkazů a wallet hodnot používá společný postup;
  odstraněny duplicitní lokální copy handlery.

### Kompatibilita simple-store

- `src/Checkout/BTCPayApiClient.php`: detail faktury nyní načítá přes
  `GET /api/v1/stores/{storeId}/invoices/{invoiceId}`. Původní neomezená cesta
  `/api/v1/invoices/{invoiceId}` v Lite routeru neexistuje.
- `BTCPayPaymentService`: vedle standardního `/i/{invoiceId}` přijímá i Lite
  `/pay?id={invoiceId}`, včetně instalace v podsložce. Odkaz musí odpovídat
  originu instance, její cestě a přesnému ID právě vytvořené faktury.
- Zachovány kontroly obchodu, měny CZK, přesné ceny a čísla objednávky.
  Podepsaný webhook ani návrat zákazníka sám o sobě objednávku nezaplatí:
  e-shop provede autentizovaný API dotaz a čeká na `Settled`.
- Doplněny regresní scénáře Lite checkoutu a škodlivých/neodpovídajících odkazů.
- Opravena zastaralá kontrola nadpisu v `tests/catalog-render.php`. Poslední
  výchozí CI simple-store očekávalo „Vybráno na cestu“, ale aktuální stránka
  už zobrazuje „Náš výběr vybavení“. Produkční text se nemění.

## Ověření a jeho hranice

| Ověření 5. října 2026 | Výsledek a rozsah |
|---|---|
| BTC Pay Lite: `tests/run_all.php` | **68/68 testových souborů prošlo** na PHP 8.3.6 a MariaDB 10.11.14 |
| Samostatné DB scénáře Lite | Všech **11** DB integračních souborů spuštěno a prošlo; žádné DB přeskočení, skutečné izolované databáze |
| Syntaxe Lite | Všech **257** aplikačních/testovacích PHP souborů prošlo |
| simple-store: cílené BTCPay testy | Klient, podpis webhooku, checkout nastavení/dostupnost/render i skutečný DB test prošly; simulovaný HTTP transport v této sadě |
| Lite checkout v DB testu e-shopu | `/i/{id}` i `/lite/pay?id={id}`, opakované zahájení, store-scoped status a settlement prošly; cizí/nesouhlasící odkazy odmítnuty |
| Test původního pádu CI simple-store | Aktualizovaný `catalog-render.php` prošel; změněná pouze zastaralá testová očekávání |
| simple-store: první kompletní CI job lokálně | **43/43 příkazů prošlo**: 36 PHP testů, dva HTTP session/cookie/CSRF skripty a pět JS sad; bez přeskočení. PHP 8.3.6, Node 24.19.0 |
| Syntaxe simple-store | **236** PHP souborů tehdejšího workflow prošlo; nový společný harness má navíc vlastní kontrolu syntaxe |
| Společný `btcpay-lite-integration.php` | Všech **pět skupin scénářů prošlo**: skutečné lokální HTTP a oddělené MariaDB, XPUB derivace, API, callback/return, worker/outbox/HMAC, objednávka/sklad/doklad a zachycený mail |
| Kopírování UI | **12/12** DOM/source kontrol prošlo; skutečný `admin.js`, simulované Clipboard API/execCommand, focus a výběr obnoveny |
| GitHub CI BTC Pay Lite, kód `7e75725` | [PHP checks prošlo](https://github.com/agp-l/BTCPayServerLite/actions/runs/37290081559) v PR #14 |
| GitHub CI simple-store, kód `ee97a8a` | [PHP checkout prošlo](https://github.com/agp-l/simple-store/actions/runs/37290111960): PHP 8.1 i 8.4, MySQL DB a MariaDB upgrade job |
| GitHub CI společného testu | [BTCPay Lite integration prošlo](https://github.com/agp-l/simple-store/actions/runs/37290111846): PHP 8.2, MariaDB 10.11, aktuální Lite `main` |

Společný test vytvořil objednávku za **1 079 Kč** a při testovacím kurzu
1 000 000 Kč/BTC fakturu na **0,00107900 BTC**. Nepotvrzená i poloviční
potvrzená částka ponechaly objednávku čekající; plná potvrzená částka ji přes
skutečný HTTP webhook zaplatila. Opakování nezduplikovalo objednávku, platební
pokus, sklad, účetní doklad ani zachycený mail. Expirace zůstala nezaplacená
a následné simulované potvrzení bylo přijato jako pozdní úhrada.

Fiat kurz a blockchain observations byly řízené testovací odpovědi. Lite router
v testu používá skutečný Greenfield controller s testovacími závislostmi,
webhook transport odesílá skutečné loopback HTTP místo produkčního DNS/TLS
transportu. Veřejné HTTPS, Apache rewrite/Authorization, Electrum a skutečný
SMTP tedy tento test neověřuje. Do simple-store přibyl samostatný GitHub
workflow pro opakovatelné spuštění proti Lite `main`; jeho první GitHub běh
prošel. Test lze spustit také lokálně
podle [návodu e-shopu](https://github.com/agp-l/simple-store/blob/main/docs/btcpay.md).

UI ověření bylo deterministické bez funkčního prohlížeče. Nativní schránka,
výběr myší/dotykem a skutečné mobilní vykreslení nebyly tímto průchodem ověřeny.
PHP testy běžely s ponechaným `E_ALL` a `display_errors=stderr`; knihovny na
PHP 8.3 hlásí deprecations. Při `display_errors=1` mohou tato hlášení narušit
JSON výstup CLI nástrojů, proto diagnostiku směrovat do stderr/logu.

Výchozí BTC Pay Lite `main` měl úspěšné CI ze dne 10. září 2026:
[run 34493840013](https://github.com/agp-l/BTCPayServerLite/actions/runs/34493840013).
Spouštěl PHP 8.2, MariaDB 10.11 a všechny `tests/*Test.php` s aktivními DB scénáři.
V současném projektu je 68 takových souborů; historické počty v pracovních
záznamech nejsou dnešním výsledkem.

Výchozí simple-store `main` měl dne 5. října 2026
[neúspěšné CI](https://github.com/agp-l/simple-store/actions/runs/37286403595):
MySQL databázové testy i MariaDB upgrade job prošly, oba PHP joby zastavila
výše popsaná zastaralá kontrola nadpisu. BTCPay klient a podpis před pádem prošly.
To je stav před těmito změnami, nikoli výsledek nové větve.

Historický záznam z uživatelova serveru prokazuje automatickou expiraci dvou
neuhrazených faktur. Neprokazuje příjem BTC ani webhook do simple-store.
Prázdná úspěšná dávka a `/health` s `synchronized: true` také nejsou důkazem
funkčního blockchain spojení.

## Přesné nastavení propojení

Oba projekty zůstávají samostatné aplikace se samostatnými databázemi.
Komunikují přes HTTP API a podepsaný callback; nesdílejí privátní wallet klíče
ani interní databázové tabulky.

| Pole ve simple-store: Nastavení obchodu → BTCPay Server | Co vyplnit |
|---|---|
| Adresa BTCPay Serveru | Veřejná HTTPS `app_url` BTC Pay Lite, včetně instalační podsložky; nikoli cesta `/api` nebo checkout |
| Store ID | ID samostatného obchodu vytvořeného v Lite pro tento e-shop |
| API klíč | Běžný store API klíč tohoto obchodu; nepoužívat stateless ani payout klíč |
| Tajný klíč webhooku | Secret konkrétního Lite webhooku určeného tomuto e-shopu |
| Veřejná adresa e-shopu | HTTPS kořen simple-store, včetně případné podsložky |
| Zapnout BTCPay | Zapnout až po doplnění nastavení a SQL tabulek |

Postup:

1. Zálohovat konfiguraci a obě DB, aktualizovat kód opravených verzí. Ve
   simple-store otevřít **Databáze → Aktualizovat SQL tabulky**; potřeba je
   `shop_btcpay_payments`. V Lite použít jeho vlastní známé migrace, pokud jsou
   potřebné; neimportovat celé fresh schéma přes používanou DB.
2. V Lite vytvořit/vybrat samostatný obchod s funkční wallet a přijímací adresou.
3. **Ještě před první objednávkou** založit webhook pro
   `https://domena-eshopu.cz/btcpay-callback.php`, s podsložkou, je-li použitá.
   Lite zařazuje jen webhooky založené nejpozději při vzniku faktury; nově
   přidaný webhook nepřebere starší faktury.
4. Zkopírovat hodnoty podle tabulky, uložit a zapnout platební metodu v e-shopu.
   Ověřit, že Apache předává Authorization a routuje `/api/v1/…` do `api.php`.
5. Naplánovat **zvlášť** `payment_worker.php` a `webhook_cron.php`; pro XPUB
   provoz také `wallet_receive_sync.php`. Zkontrolovat sdílené cache/lock cesty,
   oprávnění web/CLI a logy, nejen stav časovače.
6. Vytvořit testovací objednávku. Opakované otevření má vrátit stejnou fakturu;
   invoice metadata musí obsahovat stejné číslo objednávky a cena zůstat v CZK.
7. Projít níže uvedenou skutečnou testovací platbu a uložit výsledky.

Pro běžnou konfiguraci e-shopu používat veřejné HTTPS i při ručním ověřování.
Lite vývojová volba `allow_local_webhooks => true` povoluje jen explicitní
loopback, ne libovolnou privátní LAN. Izolovaný automatický harness má vlastní
testovací adaptér; tím se produkční pravidla neuvolňují.

## Co zbývá udělat, podle priority

| Priorita | Práce | Proč / podmínka dokončení |
|---|---|---|
| 1: integrační provoz | Skutečná testovací platba přes Electrum až do simple-store | Nová faktura → částečná platba → `Processing` → plná potvrzená platba → `Settled` → ověřený webhook → právě jednou zaplacená objednávka |
| 1: platby | Historie přijatých outputs místo samotné balance | `ElectrumBlockchainProvider` dnes čte `getaddressbalance`; platba přijatá a utracená před první observation se může ztratit. Potřeba deduplikace outputs, potvrzení a reorg testů |
| 1: obchodní politika | Počet potvrzení a reakce na reorg | Plný confirmed balance vede na terminální `Settled`; nelze nastavit čekání na 3/6 potvrzení, settled faktury se dál nesledují |
| 1: pozdní platby | Omezený autorizovaný rescan a jasná politika expirace | `Expired` bez platební indikace po 24 h vypadne z pravidelných kontrol; částečně placené `Processing` nesmí samovolně přejít na nezaplaceno |
| 1: instalace/obnova | Reprodukovat čisté nasazení, restart a obnovu | Ověřit podle DEPLOYMENT všechny tři workery, GMP v CLI/webu, nově vytvořené cache/lock soubory a nesnížení XPUB indexu |
| 1: provisioning | Persistentní identita a zotavení vytvoření wallet | Pád po vzniku souboru a před DB dokončením nesmí vyvolat přepsání ani ztrátu existující wallet |
| 2: webhooky | Claimování podle času / prodlužování lease | Celá dávka až 100 delivery se claimne najednou, lease je 300 s a jednotlivý request až 10 s. Pomalý konec dávky může reclaimnout druhý worker; zatím staticky odvozené riziko |
| 2: provoz | Monitorovat i receive/webhook worker a stáří front | PaymentWorkerMonitor neprokazuje zdraví všech workerů; rozlišit prázdný success, poslední skutečnou observation a zapnutý plánovač |
| 2: API | Pravdivé health/server info | Greenfield controller/service vracejí synchronizaci a dostupnost natvrdo; oddělit prostou dosažitelnost API od zdraví Electra a front |
| 2: API | Přesná prezentace payment-methods | Dnes `rate: 1`, prázdné `payments`, před settled nulové `totalPaid` a celé `due`; částečné platby/přeplatky nejsou úplně reprezentované |
| 2: e-shop | Stabilní `Idempotency-Key` a reconciliation nejisté tvorby | simple-store bezpečně blokuje automatické opakování nejasného POST; doplnit obnovu stejného platebního pokusu, nikoli vytvoření další faktury |
| 2: DB | Cíleně rozšířit kontrolu invariantů/backfillů | Aktualizátor neporovnává všechny FK, CHECK, defaults a dokončení backfillů; opravy musí mít známý migration postup |
| 2: provoz | Závislosti, ochrana webserveru, výkon | Prověřit verzovaný vendor a vypnuté Composer advisory blocking; skutečné pravidlo pro config/cache/zálohy v Apache/Nginx, latenci front a RPC při pomalém daemonu |
| Před směnárnou | Společné UTXO rezervace, reconciliation a monitoring payoutů | Admin a více stores téže wallet nesmějí připravit konfliktní spend; nejistý broadcast musí obnovit stejnou transakci, ne sestavit další platbu |
| Před směnárnou | Opravit payout retry kontrakt | Create při existujícím `Prepared`/`AwaitingPayment` jen vrátí záznam, přesto chyba radí retry stejného klíče; approve umí broadcast obnovit. Sjednotit dokumentaci/chování a pádové testy |

Místa pro další vývoj: `ElectrumBlockchainProvider`, `InvoiceStateMachine`,
`PaymentWorker::ELIGIBLE_SQL`, `WebhookProcessor`, `WebhookDeliveryRepository`,
`GreenfieldApiController`, `GreenfieldApiService`, `PayoutService` a
`ElectrumCliWalletProvisioner`. Podrobnosti stávajícího plánu jsou v
[ROADMAP](ROADMAP.md).

## Akceptační test na cílovém serveru

| Scénář | Očekávaný výsledek |
|---|---|
| Nová objednávka a opakované kliknutí na platbu | Jedna invoice pro jeden pokus, stejná CZK cena, stejné číslo objednávky a stejný checkout |
| Návrat zákazníka před zaplacením | Objednávka zůstává čekající |
| Částečná nebo nepotvrzená platba | Lite `Processing`, simple-store stále čeká; zákaz expedice podle paid guard |
| Plná potvrzená platba | Lite `Settled`; callback ověří HMAC a znovu API detail; objednávka přejde na zaplaceno |
| Opakovaný callback | Nezmění první paid timestamp ani nezduplikuje sklad/doklad/mail |
| Falešný podpis či jiný obchod/cena/měna/objednávka | Odmítnuto, objednávka se neoznačí za zaplacenou |
| Nedostupný callback | Delivery zůstane pro retry; po obnově se doručí, nedojde ke ztrátě události |
| Expirace a pozdní platba | Uplatní se výslovně zvolená politika; žádná tichá ztráta platby za hranicí sledování |
| Restart workeru a opakované zpracování | Stav a outbox zůstávají konzistentní, nevznikne druhá platba/událost kvůli pádu |
| Vypnutí nové platební metody v e-shopu | Staré faktury lze stále ověřovat; nevznikají nové |

Při ověření zaznamenat verzi PHP/Electra, commit obou projektů, invoice ID,
číslo testovací objednávky, časy/stavy a výsledek delivery; tajné klíče odstranit.
Změny z [BTC Pay Lite PR #14](https://github.com/agp-l/BTCPayServerLite/pull/14)
a [simple-store PR #1](https://github.com/agp-l/simple-store/pull/1) byly po
schválení vlastníka 5. října 2026 sloučené do `main` obou projektů.
Nasazení na uživatelův server a skutečná platba přes Electrum zůstávají k ověření.

Aktuální návody: [API](API.md), [TESTING](TESTING.md),
[PAYMENT_MONITORING](PAYMENT_MONITORING.md) a
[simple-store BTCPay](https://github.com/agp-l/simple-store/blob/main/docs/btcpay.md).
