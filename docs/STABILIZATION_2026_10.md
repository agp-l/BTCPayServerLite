# Stabilizace platební brány — říjen 2026

Výchozí revize: `3e8768be40614cd206991c26056a21a614581ee9`.
Tento záznam ukládá ověřená zjištění a postup aktuálního průchodu. Aktuální
provozní návody a ROADMAP mají přednost před historickými pracovními zápisy.

## Strategie

Zachovat fungující PHP/Electrum/XPUB architekturu. Checkout a API čtení mají
číst uložený stav; blockchain sleduje samostatný omezený worker. Zachovat
atomickou rezervaci adres, invoice leases a transakční webhook outbox.
Nezavádět novou peněženku, Lightning ani směnárnu během stabilizace.

Pořadí provedeného průchodu:

1. Přečíst aktuální dokumentaci a cesty RPC, spustit současné testy s izolovanou DB.
2. Opravit předčasné rezervace webhooků a ověřit pomalé doručování i obnovu po pádu.
3. Prověřit souhrnnou zátěž různých adres, chování fronty a diagnostiku kapacity;
   interval jedné adresy musí zůstat nejméně 10 minut.
4. Odstranit nepravdivé health údaje a prezentovat skutečně pozorované úhrady.
5. Sjednotit README, provozní návody a veřejnou PHP dokumentaci s implementací.
   Historické plány nesmějí být zdrojem dalších úprav.
6. Úklid omezit na doložené nepoužívané soubory. Zachovat migrace, kompatibilní
   vstupní body a runtime závislosti, dokud je ověřování nenahradí.
7. Každý funkční krok ověřit a samostatně zapsat do main; nakonec zapsat výsledky
   a zbývající rizika bez tvrzení o neověřené produkční kapacitě.

## Výchozí zjištění auditu

Tabulka zachycuje závady výchozí revize 3e8768b. Webhook claim, endpoint budget,
API/health a ochrana Apache jsou již opravené; podrobnosti a důkazy níže.
Není to aktuální seznam otevřených úkolů. Ten je pouze v ROADMAP.

| Oblast | Výchozí zjištění | Výsledek tohoto průchodu |
|---|---|---|
| Platební jádro | Lokální XPUB, DB checkout, leases/outbox a receipt provider již existovaly. | Zachovány a ověřeny; nepřestavováno podle starých plánů. |
| Webhooky | Upfront batch claims mohly v pomalé dávce spotřebovat lease před odesláním. | Opraveno claimem jedné delivery těsně před HTTP; DB lease/crash test. |
| Kapacita | Ochrana stejné adresy neomezovala součet různých adres. | Společný endpoint budget, max souběh, pause, queue lag a dokumentované plánovací meze. |
| API health | Nezměřený sync byl hlášen jako úspěšný. | Neznámé nullable hodnoty bez RPC; pravdivá CLI diagnostika. |
| API platby | Partial/doplatek a původní fiat rate se nepromítaly do payment-methods. | Uložená payment presentation a cena; přesné received/due, bez vymyšlených TX rows. |
| Dokumentace | Staré katalogy, cache intervaly a již splněné plány byly rozporné. | Jednotné aktuální návody, ROADMAP a veřejná PHP stránka; mezistavy odstraněny. |
| Webserver | Interní runtime/code/zálohy neměly souhrnný zákaz. | Apache ochrana ověřena skutečným serverem, Nginx postup popsaný. |
| Pozdní platby | Neuhrazený Expired za 24h oknem nešlo cíleně kontrolovat workerem. | Admin/CLI rescan jednoho ID se stejným lease/outboxem a min 10 min. |
| Výplaty | Výchozí vypnuté, bez úplného UTXO/reconciliation/confirmation řešení. | Vypnutí zachováno, opraven retry popis; další finanční práce pouze v ROADMAP. |

## Ověření

Ověření probíhá v PHP 8.3.6 a izolované MariaDB 10.11.14. Skutečný Electrum
a skutečná platba na cílovém serveru nejsou součástí lokálního testu.

## Checkpoint: webhooky

Processor nyní rezervuje jednu událost až bezprostředně před HTTP. Zpoždění
předchozích požadavků nespotřebovává lease dalších záznamů. Cron používá
45sekundový rozpočet pro zahajování doručení; probíhající request a DB/DNS
práce mohou doběhnout později. Payload, HMAC, delivery ID i retry lhůty zůstávají.

Ověření: 6 processor testů a 5 controller testů prošlo. Nový DB scénář
pokrývá pomalou dávku přes 300 s, zotavení lease a odmítnutí starého vlastníka.
První úplný lokální průchod: 68/72 souborů prošlo; čtyři chyby souvisejí
s nepřipraveným testovacím PHP session adresářem, který je nyní opraven.
Testovací MariaDB 10.11.14 je izolovaná, skutečný Electrum není připojen.

## Checkpoint: společná ochrana blockchain observation

Přidán endpoint budget 60 zahájení v rolling 60 s a nejvýše 2 současně, sdílený
mezi procesy. Transport/auth/HTTP/protocol chyby pozastaví nové kontroly na 60 s.
Cache hit nic nespotřebovává; per-address 10/30/60 minut zůstává. Worker při
lokálním limitu ukončí dávku, uvolní lease a odloží pokus o minutu bez zápisu
last_checked_at. Přibyla queue age v adminu a volitelný minutový scheduler tick
pro vyšší provoz; výchozí tick 10 minut se nemění.

Ověření: rolling hranice, sdílení procesu, pád observeru, circuit recovery;
1000 různých cold požadavků → 60 řízených RPC, 940 deferrals (fixture 0,08 s);
100 souběžných PHP procesů → nejvýše dva aktivní observers. DB cadence test
prošel včetně odložení bez smyšlené observation. Generator a monitor testy prošly.
Instalátorový HTTP test po opravě testovací session cesty prošel. Kapacita není
změřená proti skutečnému Electru: limity a plánovací příklady jsou v CAPACITY.

Úplná sada po obou opravách: **74 testovacích souborů prošlo, 0 selhalo**,
včetně reálné DB, HTTP hranic, více procesů a všech migrací. Opravené nastavení
session adresáře bylo pouze v izolovaném testovacím prostředí.

## Checkpoint: API a diagnostika

HTTP health a server info vracejí neznámou synchronizaci jako null, bez RPC.
Payment-methods a additionalStatus používají uloženou payment presentation:
partial, receipts i doplatek; rate vychází z původní uložené ceny. Nejsou
vymyšleny jednotlivé transaction rows. Základní monitoringTime zahrnuje 24 h
po expiraci. CLI health je nepřístupný přes HTTP, ověřuje existenci core tabulek,
správné Retry/Processing/Dead stavy a getinfo síť místo nepodporovaného
daemon_status. Raw chyby nejsou ve výstupu diagnostiky.

Ověření: **75 testovacích souborů prošlo, 0 selhalo** s izolovanou MariaDB.
Nové případy pokrývají partial receipt, utracený příjem, přeplatek, legacy Settled,
uložený fiat kurz, nulová RPC čtení health a HTTP zákaz CLI diagnostiky.
Synchronizace skutečného Electra ani kompatibilita všech CMS tím nejsou prokázány.

## Checkpoint: veřejná hranice webserveru

`.htaccess` blokuje interní adresáře, dotfiles, SQL/log/cache/wallet soubory
a zálohy před front controllery. Skutečný izolovaný Apache 2.4.58 prošel testem
22 interních cest i zachovaných assets, checkoutu, veřejné dokumentace, API
a Authorization v podadresáři. Test používá pouze falešné statické soubory,
nikoli produkční konfiguraci. V CI je přidaný Apache test a PHP 8.2/8.3 matice;
checkout používá současnou verzi 7.0.1. Nginx postup je popsaný, nebyl živě ověřen.

## Checkpoint: konkrétní pozdní platba

Admin CSRF POST a CLI `payment_worker.php --invoice=ID` nyní kontrolují pouze
jeden explicitní non-Settled řádek, i po uplynutí neuhrazeného 24h late okna.
Používají stejný worker, invoice lease, endpoint budget a atomický outbox.
Minimální 10min odstup zůstává; chybějící ID neskenuje jiné faktury. Žádná nová
migrace ani paralelní settlement logika. Testy ověřují skutečnou DB, starý Expired,
Settled guard, min interval a HTTP CSRF/role hranice.

## Checkpoint: úklid a dokumentace

README, architektura, současný stav, ROADMAP i veřejná PHP dokumentace nyní
popisují stejnou implementaci. Odstraněny čtyři staré pracovní deníky/audity
a původní archivovaný refactor checklist; dokončené výsledky shrnuje HISTORY
a plné texty zůstávají v Git historii. Repair návod byl nejprve přenesen do
RECEIVE_COORDINATION, aby úklid neodstranil provozní postup. Migrace, vendor,
kompatibilní vstupní body a používané deployment/repair skripty zachovány.

Smazán doloženě nepoužívaný private nullableStringConfig v webhook aplikaci.
Payout retry hláška i návody nyní rozlišují create replay od approve aktuální
revision; finanční chování ani výchozí vypnutí se nemění. Lock/vendor metadata
11 balíčků souhlasí, Composer validate a offline install dry-run prošly.
Plošné advisory block=false odstraněno. mdanter/ecc a fgrosse/phpasn1 jsou
opuštěné; dvě konkrétní ECC advisories ověřeny z primárního oznámení a databáze.
Úplný online Composer audit se nedokončil kvůli Packagist timeoutu; žádné tvrzení
o nulových zranitelnostech. DEPENDENCIES ukládá fakta a podmínky kompatibilní náhrady.

Veřejná stránka opravuje stateless status/default 48 h, nullable health, partial
API, worker/capacity popis a povinné ověření invoice/order po HMAC. Příklady
nevydávají samotný callback za zaplacení. Další práce pouze v ROADMAP.

## Závěrečné lokální ověření

- **76 testovacích souborů prošlo, 0 selhalo**, PHP 8.3.6, izolovaná MariaDB
  10.11.14 a Apache 2.4.58; DB i Apache prostředí bylo explicitně zapnuté.
- **268** tracked aplikačních/testovacích PHP souborů prošlo syntaxí.
- **92** místních Markdown odkazů má existující cíle; staré deníky nemají
  zbývající reference v aktuální dokumentaci.
- Veřejná PHP stránka se vykreslila s URL v podadresáři; všechny navigační anchors
  a oba PHP příklady prošly kontrolou/syntaxí. Nebyl proveden nový mobilní
  browser vizuální test; CSS rozložení se neměnilo.
- Composer validate a offline install dry-run prošly, lockfile/vendor verze
  zachovány. Online audit limit viz DEPENDENCIES. `git diff --check` bez chyby.

První 76-file běh odhalil jediný zastaralý source-string test očekávající starý
Apache pattern. Byl nahrazen skutečným Apache pokrytím config/temp/backup cest;
finální úplný běh již prošel. Reálná cílová platba, host capacity, Nginx/HTTPS
ani payout service/crash audit nejsou tímto výsledkem prokázány.
