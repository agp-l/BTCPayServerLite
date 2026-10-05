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

Probíhá příprava PHP a izolované MariaDB. Dosavadní závěry jsou revize zdrojů,
nikoli výsledky zátěžového testu nebo test skutečné platby na cílovém serveru.
Záznam bude doplněn po jednotlivých ověřených krocích.
