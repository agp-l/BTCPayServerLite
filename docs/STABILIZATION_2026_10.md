# Stabilizace platební brány — říjen 2026

Výchozí revize: `3e8768be40614cd206991c26056a21a614581ee9`.
Tento záznam ukládá ověřená zjištění a postup aktuálního průchodu. Aktuální
provozní návody a ROADMAP mají přednost před historickými pracovními zápisy.

## Strategie

Zachovat fungující PHP/Electrum/XPUB architekturu. Checkout a API čtení mají
číst uložený stav; blockchain sleduje samostatný omezený worker. Zachovat
atomickou rezervaci adres, invoice leases a transakční webhook outbox.
Nezavádět novou peněženku, Lightning ani směnárnu během stabilizace.

Pořadí práce:

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

## Zjištění z aktuálního kódu

| Oblast | Zjištění | Další krok |
|---|---|---|
| Platební jádro | XPUB derivace je lokální, checkout čte DB, worker ukládá stav a outbox v jedné transakci. Receipt provider už rozpoznává utracené příjmy. | Zachovat hranice a doplnit cílené ověření. |
| Webhooky | Processor rezervuje celou dávku před prvním HTTP requestem. 100 požadavků s timeoutem 10 s přesahuje 300s lease posledních záznamů. | Rezervovat vždy až těsně před doručením. |
| Kapacita | Single-flight/cache chrání stejnou adresu, ale ne součet dotazů různých adres. CLI má 100 kontrol / 45 s a doporučený timer 10 minut. | Změřit RPC a čekání; přidat společnou ochranu/diagnostiku podle výsledků. |
| API health | Controller vrací synchronized=true a server info fullySynched=true bez měření. | Oddělit dosažitelnost API od doloženého stavu blockchainu, bez RPC při pollingu. |
| API platby | Payment-methods vrací rate=1, nulové partial payments a celou splatnou částku až do Settled. | Použít uložené integer satoshi a původní cenu/měnu. |
| Dokumentace | README uvádí katalog 001–010, ale kód obsahuje 011. Starší stavový přehled stále plánuje již implementovaný receipt provider; architektura uvádí zastaralou 2s cache. | Jedna aktuální ROADMAP, odstranit rozporné instrukce. |
| Webserver | Root .htaccess chrání config, ale chybí explicitní zákaz runtime cache, vendor, testů, SQL a záloh. | Ověřit a doplnit Apache pravidla i Nginx postup. |
| Výplaty | Vypnuté ve výchozím stavu; monitoring potvrzení a společné UTXO rezervace nejsou dokončené. | Neoznačovat za hotovou směnárnu; samostatná etapa. |

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
21 interních cest i zachovaných assets, checkoutu, veřejné dokumentace, API
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
