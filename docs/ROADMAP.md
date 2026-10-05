# Další práce na jádru a provozu

Stav revize: **5. října 2026**. Úsporné kontroly navazují na `7722727`.
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
- Pro vlastní e-shop je doplněn režim 10/30/60 minut, checkout upozornění a
  receipt provider s lokální kontrolou TXID/výstupů, immutable cache a omezeným
  načítáním historie. Migrace 011 odděluje skutečné příjmy od zůstatku.
  Testy pokrývají utracení před prvním skenem, vlastní change, dělené platby,
  mempool/confirmed/reorg, migraci, persistenci a webhook outbox.
- Instalátor, admin aktualizátor DB, zapamatování přihlášení a admin monitoring
  mají implementaci i cílené testy. Tyto oblasti rozšiřovat podle konkrétní chyby.

## 1. Nejbližší ověření před dalším rozšiřováním

### Celá platba a webhook na skutečné testovací síti

**Důvod:** majitel již potvrdil skutečnou platbu a automatické označení v e-shopu.
Nový receipt provider je ověřen strukturálními transakcemi a skutečnou DB/HTTP
integrací; po nasazení migrace 011 zbývá zopakovat průchod na jeho Electru.
Výpadek/retry webhooku, pozdní platbu a restart ověřit i na cílové instalaci.

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
| Vysoká před širším provozem | Receipt provider kontroluje raw TXID a výstupy, ale výška potvrzení pochází z walletless Electrum historie, nikoli vlastní SPV/Merkle kontroly. | Ověřit proti používané verzi daemonu a skutečné síti; při širším provozu zvážit vlastní Electrum server nebo Bitcoin Core/NBXplorer. Statelessem nenahrazovat durable DB stav e-shopu. |
| Vysoká | `PaymentWorker::ELIGIBLE_SQL`: Expired bez platební indikace vypadá z pravidelných kontrol po 24 h. | Dokumentovaná obchodní politika a omezený explicitní rescan konkrétní faktury/adresy s autorizací a limity; bez neomezeného skenování celé historie. Partial Processing nikdy automaticky nevracet do New. |
| Vysoká | Přerušení offline provisioningu může zanechat wallet soubor bez dokončeného obchodu. Viz cílená revize core. | Persistentní identita operace a bezpečná reconciliation/resume; pádové testy mezi vytvořením souboru a DB commitem. Existující peněženku nikdy nepřepsat či smazat jen podle chybějícího DB řádku. |
| Střední | `PaymentWorkerMonitor` drží poslední CLI/manual běh; nehlídá webhook/receive worker. Empty success není payment observation. | Samostatně zobrazit aktivitu, neprázdnou úspěšnou observation a zdraví front všech tří workerů. Uchování historie musí mít limit/retenci a žádná tajemství. Neodvozovat zapnutý plánovač pouze z jednoho CLI běhu. |
| Střední | Aktualizátor DB neporovnává defaults, FK, CHECK ani dokončení backfillů. | Rozšířit cíleně tam, kde chybějící invariant ohrožuje core, s testy na čistém importu i staré DB. Žádné automatické opravné SQL odhadnuté ze samotného diffu. |
| Střední | `WalletBusyException` stále může směšovat provozní chybu a obsazený lock; některá admin čtení opakují RPC. | Typované příčiny bez raw tajemství; request-scoped read snapshot podle změřených opakovaných volání. Stejnou lock doménu a cache chování ponechat. |

Rozpoznání platby utracené před prvním pozorováním je doplněno přes historii
transakcí, nikoli přejmenováním balance. Zůstává závislost na správné a úplné
historii vybraného Electrum serveru a existující terminální politika Settled.
To samo nedokončuje účetnictví nebo automatické výplaty budoucí směnárny.

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

Node/EJS demo bylo 10. září na výslovný požadavek vlastníka odstraněno včetně
spouštěcích a konfiguračních souborů. PHP šablony a společné assets zůstaly;
pro případné obnovení slouží historie Gitu. Tento bod je uzavřený.

- **Závislosti:** zmapovat původ a advisories zamčených knihoven, důvod verzovaného
  vendor a vypnutého Composer advisory blocking. Zachovat bitcoin-p8 v tomto
  stabilizačním průchodu; případné změny až s kontrolou derivací a kompatibility.
- **Webserver:** ověřit skutečnou ochranu zdrojů, config, runtime cache, záloh a
  ostatních nepublikovatelných souborů v Apache/Nginx. Samotný PHP test nepotvrzuje deployment pravidla.
- **Kapacita:** změřit latenci fronty, počet RPC, velikost DB/cache a chování při
  pomalém Electru. Dnešní 100procesové testy ověřují invarianty, ne garantovanou
  kapacitu celé služby. Před více hosty vyřešit sdílení file locks/cache; oddělené
  lokální filesystémy stejný název adresáře nespojí.
- **Integrace:** první reálný CMS plugin ověřit proti implementované podmnožině
  Greenfield. Nové endpointy přidávat až podle doloženého požadavku integrace.

## Pořadí příštího vývojového průchodu

Nejdřív dokončit provozní důkazy z bodu 1, ověřit nový receipt provider
na cílovém Electrum daemonu a použité síti. Potom uzavřít provisioning crash recovery
a monitoring front. Payout reconciliation a potvrzení řešit jako samostatnou
etapu před směnárnou. Úspěchy průběžně zapsat do historie a odkazovat na commit,
scénář a prostředí; neoznačovat celý projekt jako auditovaný jednou sadou testů.
