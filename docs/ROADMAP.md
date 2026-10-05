# Další práce a podmínky dokončení

Revize **5. října 2026**. Jediný aktuální plán projektu.
[Současný stav](PROJECT_STATUS_2026_10.md) · [Audit a výsledky](STABILIZATION_2026_10.md)

Zachovat fungující PHP/Electrum/XPUB systém. Novou práci odvozovat z aktuálního
kódu, konkrétní závady a testu, nikoli z historických checklistů. Každý krok
má samostatný commit a jasný důkaz. Produkční data, indexy ani původní klíče
nepřepisovat jako součást úklidu.

## Uzavřeno v jádru

- Lokální XPUB derivace, společná DB sekvence a receive koordinace; explicitní
  wallet scope a mutation locks; žádný RPC při XPUB tvorbě nebo DB checkoutu.
- Invoice leases, observation mimo DB transakci, atomický stav + webhook outbox,
  trvalá invoice idempotency a původní non-regressing state machine.
- Receipt monitoring včetně již utracených příjmů a vlastního change, migrace 011,
  bounded historie/raw TX, 10/30/60min cadence a per-address cache.
- Společný endpoint budget a omezení souběhu různých adres; krátká pause při
  transport/auth výpadku, queue age a volitelný minutový scheduler tick.
- Webhook claim až před doručením; pomalá dávka nespotřebuje lease pozdějších událostí.
- Pravdivý HTTP health, DB payment-methods partial/due/rate a cílený rescan jedné
  pozdní faktury se stejnými leases/outboxem a minimálně desetiminutovým odstupem.
- Apache ochrana interních souborů s testem skutečného serveru; nové provozní návody,
  jednotná veřejná dokumentace a odstranění zastaralých plánů.

Tyto body znovu hromadně nepřepisovat. Main neznamená automatické nasazení na
uživatelův server. Systém aktuálně funguje podle provozovatele; následující
ověření se týká změněné verze a náročnějších podmínek.

## P0: ověřit nasazenou verzi a skutečnou kapacitu

| Práce | Podmínka dokončení |
|---|---|
| Platba a webhook na používaném Electru / testovací síti | Zaznamenat commit a verze; partial → Processing, plný confirmed receipt → Settled, HMAC + autentizované API ověření v e-shopu, právě jednou paid/sklad/doklad. Zopakovat utracení před prvním skenem, late rescan, nedostupný callback, retry a restart. |
| Kapacita cílové instalace | Změřit HTTP/DB latenci, oldest due age, RPC count/latenci a receive backlog pro reprezentativní počet nových i starších invoices; zpomalit RPC a vypnout daemon. Budget ochrání upstream, fronta se po obnově musí vyprázdnit podle stanoveného SLA. Viz CAPACITY. |
| Reprodukovatelné nasazení a obnova | Čisté prostředí projde DEPLOYMENT bez improvizovaných oprav; GMP/Composer v CLI i webu, tři plánovače, nový cache/lock soubor čitelný oběma účty, restart a obnova nesníží XPUB index ani nezneplatní staré tokeny. |
| Webserver na cílovém hostingu | Zvenku interní URL vrací 403/404 bez obsahu; checkout/API/Authorization fungují. Apache fixture je ověřený, konkrétní Nginx/HTTPS konfiguraci ověřit zvlášť. |

Pro veřejné CMS integrace ověřit nullable health hodnoty; Lite vědomě neprohlašuje
neověřenou synchronizaci za true. Předchozí simple-store testy jsou historie,
nikoli důkaz kompatibility libovolného pluginu nebo nové produkční kapacity.

## P1: odstranit zbývající konstrukční slabiny

| Oblast | Konkrétní práce a akceptace |
|---|---|
| Confirmation / reorg | Navrhnout explicitní počet potvrzení a následnou reconciliation po settlement. Doložit chain tip/confirmation evidence, chování při reorg a účetní korekci; nevracet historické Settled na New. Vlastní Electrum/Bitcoin Core či SPV důvěru zvolit před rozšířením finančního rizika. |
| Provisioning po pádu | Persistentní ID operace, owned path a resume/reconciliation mezi create wallet a DB commitem; SIGKILL/timeout testy. Neznámý existující wallet soubor se nikdy automaticky nepřepíše ani nesmaže. |
| Závislosti | Náhrada opuštěného mdanter/ecc a fgrosse/phpasn1 v kompatibilním Bitcoin stacku; ověřit stejné adresy pro všechny script/prefix/network varianty, raw TX/QR a rollback. Žádné ruční editace vendor. Konkrétní advisories a současný rozsah jsou v DEPENDENCIES. |
| Všechny fronty | Přidat bounded heartbeat/queue age receive a webhook workerů, poslední skutečnou observation odlišit od prázdného běhu. Žádné secrets; jasná retence a alerty při stagnaci. |
| Cache a HTTP zátěž | Retence raw TX/cache s bezpečným odstraněním pouze neaktivních dat, měření inode/disk růstu; live lockfiles nemazat. Reverzní proxy/API limity pro CPU/DB a per-client zátěž, odděleně od Electrum budgetu. |
| Obnova DB | Cíleně ověřit FK/CHECK/defaults/backfill tam, kde chrání core; upgrade ze skutečné staré DB a nezávislý restore test. Nepřidávat odhadnuté automatické ALTER podle obecného diffu. |
| E-shop idempotency | Ve simple-store doplnit stabilní Idempotency-Key a reconciliation nejistého create. To je samostatný repozitář; nezaměňovat nový pokus za retry stejné operace. |

## P2: údržba podle měření

Omezit duplicitní admin wallet RPC pomocí snapshotu jednoho requestu; doplnit
strukturovanou diagnostiku lock I/O proti běžnému busy; definovat support matrix
PHP a distribuční balíček, než se odstraní verzovaný vendor. Další Greenfield
endpointy přidávat podle doložené potřeby integrace. Původní Node demo i staré
refactor checklisty jsou uzavřené; nevracejí se do aktivního plánu.

## Samostatná etapa před zapnutím směnárny / automatických payoutů

Payout modul zůstává vypnutý. Vlastní service nemá samostatnou kompletní
persistence/crash sadu srovnatelnou s invoice jádrem; samotný route test nestačí.

1. Společná rezervace UTXO přes admin i všechny stores téže wallet; dva writers
   nesmějí připravit konfliktní spend. Testy souběhu, limity a uvolnění rezervací.
2. Payout state machine a reconciliation nejistého broadcastu/restartu vždy
   nad stejnou uloženou transakcí. Create replay vrací stávající záznam; obnovu
   broadcastu provádí approve s aktuální revision, nikdy nový idempotency klíč.
3. Bounded confirmation worker a chování při reorg/replacement; InProgress
   není Completed. Skutečný testnet/regtest průchod i pád mezi RPC a DB zápisem.
4. Teprve potom účetnictví směny, refundace, pull payments a další UI.

## Pořadí příštího průchodu

Nasadit aktuální main a uzavřít P0 na cílové testovací instalaci. Paralelně v
samostatné větvi připravit kompatibilní náhradu kryptografických závislostí;
sloučit až po derivation/receipt regresích. Potom provisioning recovery a dohled
všech front. Payouty řešit odděleně. Výsledky zapisovat s commitem, scénářem a
prostředím, bez tvrzení o neověřených serverech nebo neomezeném výkonu.
