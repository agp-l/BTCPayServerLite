# Další práce na jádru a provozu

Stav revize: **10. září 2026**, výchozí kód `8191c32`.
[README](../README.md) · [Architektura](CORE_PAYMENT_ARCHITECTURE.md)

Toto je plán, nikoli seznam dokončených oprav. Zachováváme PHP, bitcoin-p8,
Electrum RPC factory, DB index reservation, invoice leases, provider a webhook
outbox. Jednotlivé kroky mají mít malý commit, vlastní ověření a provozní návod.

## Co už není potřeba znovu přepisovat

- XPUB faktury používají lokální derivaci a společnou atomickou DB sekvenci.
- Wallet mutace mají explicitní cestu a společný per-wallet file lock.
- Provider status je walletless a souběžné kontroly sdílejí cache/single-flight.
- PaymentWorker zapisuje observation, stav, outbox a uvolnění lease transakčně.
- Checkout čte DB; WebhookProcessor doručuje události vytvořené workerem.
- Instalátor, admin aktualizátor DB, zapamatování přihlášení a admin monitoring
  mají implementaci i cílené testy. Tyto oblasti rozšiřovat podle konkrétní chyby.

## 1. Nejbližší ověření před dalším rozšiřováním

### Celá platba a webhook na skutečné testovací síti

**Důvod:** na serveru je doložen funkční timer a expirace dvou faktur, nikoli
zaplacená faktura nebo doručený webhook. Automatické testy používají také fixtures.

**Hotovo, až:** vznikne XPUB faktura, částečná platba ji převede do Processing,
plná potvrzená částka do Settled a testovací receiver ověří HMAC webhooku. Další
poll stav nevrátí zpět. Při nedostupném receiveru zůstane delivery ve frontě a po
obnově se doručí; receiver zvládne opakování stejného delivery ID. Zvlášť ověřit
pozdní platbu a restart workeru. Nevyžaduje novou paralelní payment logiku.

### Reprodukce instalace a obnovy

**Důvod:** původní závady byly rozdílné PHP a práva uživatelů web/CLI. Generátor
ACL existuje, ale je třeba prokázat celý postup bez oprav improvizovaných v chatu.

**Hotovo, až:** nový testovací server projde pouze návodem DEPLOYMENT: Composer,
GMP obou PHP, admin, obchod, adresa, faktura, všechny tři workery, migrace a obnova
config/DB/wallet. Po restartu i při nově vytvořených cache/lock souborech fungují
oba účty. Obnova nesmí snížit XPUB index ani zneplatnit původní tokeny.

## 2. Další opravy jádra podle rizika

| Priorita | Otevřená věc a místo v kódu | Podmínka dokončení |
|---|---|---|
| Vysoká | `ElectrumBlockchainProvider` čte aktuální balance, ne kumulativní outputs. Přijetí a utracení před první observation může platbu skrýt. `Settled` chrání jen již uložený stav. | Navrhnout a proti konkrétní verzi Electra ověřit historii outputs, deduplikaci, potvrzení a reorg. Změřit RPC náklady a zachovat single-flight. Rozhodnout i omezenou persistenci stateless stavu; nepřejmenovat balance na received bez změny výpočtu. |
| Vysoká | `PaymentWorker::ELIGIBLE_SQL`: Expired bez platební indikace vypadá z pravidelných kontrol po 24 h. | Dokumentovaná obchodní politika a omezený explicitní rescan konkrétní faktury/adresy s autorizací a limity; bez neomezeného skenování celé historie. Partial Processing nikdy automaticky nevracet do New. |
| Vysoká | Přerušení offline provisioningu může zanechat wallet soubor bez dokončeného obchodu. Viz cílená revize core. | Persistentní identita operace a bezpečná reconciliation/resume; pádové testy mezi vytvořením souboru a DB commitem. Existující peněženku nikdy nepřepsat či smazat jen podle chybějícího DB řádku. |
| Střední | `PaymentWorkerMonitor` drží poslední CLI/manual běh; nehlídá webhook/receive worker. Empty success není payment observation. | Samostatně zobrazit aktivitu, neprázdnou úspěšnou observation a zdraví front všech tří workerů. Uchování historie musí mít limit/retenci a žádná tajemství. Neodvozovat zapnutý plánovač pouze z jednoho CLI běhu. |
| Střední | Aktualizátor DB neporovnává defaults, FK, CHECK ani dokončení backfillů. | Rozšířit cíleně tam, kde chybějící invariant ohrožuje core, s testy na čistém importu i staré DB. Žádné automatické opravné SQL odhadnuté ze samotného diffu. |
| Střední | `WalletBusyException` stále může směšovat provozní chybu a obsazený lock; některá admin čtení opakují RPC. | Typované příčiny bez raw tajemství; request-scoped read snapshot podle změřených opakovaných volání. Stejnou lock doménu a cache chování ponechat. |

Observation z historie je předpoklad pro spolehlivé sledování při současném
utrácení BTC, zvlášť pro budoucí směnárnu. Nejde jen o kosmetické názvy DTO.
Do uzavření tohoto bodu neslibovat spolehlivé rozpoznání platby utracené před
prvním pozorováním.

## 3. Výplaty před budoucí směnárnou

Současný payout ledger ukládá podepsanou transakci před broadcastem, ale
`InProgress` není potvrzená výplata. Neaktivovat automatickou směnárnu jen na
základě existence payout endpointu.

1. Prověřit souběžné rezervace UTXO napříč všemi cestami utrácení téže wallet,
   obnovu po pádu a limity. Nesmí vznikat dvě operace utrácející stejný vstup.
2. Dopsat reconciliation stejné uložené transakce při nejistém výsledku broadcastu;
   retry nesmí sestavit druhou platbu. Ověřit stav daemonu i DB po restartu.
3. Přidat vlastní bounded monitoring potvrzení, explicitní payout state machine
   a chování při reorg / nahrazené transakci. Oddělit od invoice state machine.
4. Až potom navazovat účetní evidenci směny, refundace, pull payments a další UI.

Hotovo znamená testy souběhu a pádů plus průchod skutečnou testovací sítí,
nikoli pouze úspěšný návrat RPC broadcast.

## 4. Úklid a kapacita v samostatných krocích

- **Node/EJS prototyp:** `server.js`, `src/`, `views/`, package/bun soubory jsou
  samostatná aplikace s in-memory daty a demo přihlášením. PHP ji nevolá, ale má
  vlastní npm entrypoint. Přesunout do jasně odděleného demo balíčku nebo archivu
  až po ověření, zda jej používá preview/publikování; tento úklid jej nemaže.
- **Závislosti:** zmapovat původ a advisories zamčených knihoven, důvod verzovaného
  vendor a vypnutého Composer advisory blocking. Zachovat bitcoin-p8 v tomto
  stabilizačním průchodu; případné změny až s kontrolou derivací a kompatibility.
- **Webserver:** ověřit skutečnou ochranu zdrojů, config, runtime cache, záloh a
  demo souborů v Apache/Nginx. Samotný PHP test nepotvrzuje deployment pravidla.
- **Kapacita:** změřit latenci fronty, počet RPC, velikost DB/cache a chování při
  pomalém Electru. Dnešní 100procesové testy ověřují invarianty, ne garantovanou
  kapacitu celé služby. Před více hosty vyřešit sdílení file locks/cache; oddělené
  lokální filesystémy stejný název adresáře nespojí.
- **Integrace:** první reálný CMS plugin ověřit proti implementované podmnožině
  Greenfield. Nové endpointy přidávat až podle doloženého požadavku integrace.

## Pořadí příštího vývojového průchodu

Nejdřív dokončit provozní důkazy z bodu 1, současně připravit konkrétní návrh
historických observations z bodu 2. Potom uzavřít provisioning crash recovery
a monitoring front. Payout reconciliation a potvrzení řešit jako samostatnou
etapu před směnárnou. Úspěchy průběžně zapsat do historie a odkazovat na commit,
scénář a prostředí; neoznačovat celý projekt jako auditovaný jednou sadou testů.
