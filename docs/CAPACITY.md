# Kapacita a ochrana Electra

Provozní model: jedna instance PHP/MySQL, sdílená file cache a zámky, jeden
Electrum endpoint. Počet HTTP čtení a počet blockchain kontrol jsou odlišné.
Projekt nemá prokázanou neomezenou kapacitu ani podporu nezávislých webových uzlů.

## Co vyvolává RPC

| Operace | Blockchain / wallet RPC |
|---|---|
| DB checkout a jeho polling, Greenfield detail faktury | 0; čtou uložený stav |
| Vytvoření XPUB faktury / adresy | 0; lokální derivace a atomická DB sekvence |
| Stateless status s platnou cache | 0 |
| Čerstvá receipt observation prázdné adresy | 1 history RPC |
| Čerstvá receipt observation neprázdné adresy | Nejvýše 4 RPC: historie, dvě neznámé raw TX a balance |
| Doručení webhooku | 0; pouze HTTP callback a DB |
| Admin wallet a receive sync | Vlastní explicitní RPC; nejsou součástí limitu invoice observation |

Jedna adresa se obnovuje nejdříve po 10, 30 nebo 60 minutách podle stáří faktury.
Cache a cooldown tento odstup sdílejí napříč workerem a stateless požadavky.
XPUB sníží zatížení při tvorbě, ale nezruší potřebu sledovat všechny čekající adresy.

## Společná ochrana různých adres

`BlockchainObservationBudget` ve stejné cache sdílí krátký admission lock pro
endpoint. Výchozí limit je **60 zahájených observations v klouzavém okně 60 s**,
nejvýše **2 současně**. Každá obsahuje nejvýše 4 RPC; nedojde k neomezenému
rozběhnutí kontrol ani při stovkách různých stateless tokenů. Cache hit limit
nespotřebovává. Nejde o zámek držený během sítě a jiný endpoint má vlastní budget.

Reservation se ukládá před RPC a zaniká po deklarovaném timeoutu + 5 s, i po pádu.
Při transport/auth/HTTP/protocol chybě se endpoint na 60 s pozastaví. Skutečně
neúspěšně kontrolovaná adresa si navíc ponechá svůj 10–60minutový cooldown.
Lokálně nepřijatá kontrola nezapisuje `last_checked_at`, worker ji odloží o minutu
 a ukončí dávku. Stav faktury tím nemění ani nepředstírá nezaplacenou platbu.
CLI hlásí chybu/limit, aby tlak na frontu nebyl schovaný v prázdném úspěchu.

Volitelně lze nastavit `BTCPAY_BLOCKCHAIN_OBSERVATIONS_PER_MINUTE=60` (1–600).
Stejnou hodnotu a `BTCPAY_BLOCKCHAIN_CACHE_DIR` nastavte webu i workerům. Limit
zvyšujte až podle latency, stáří fronty a použitého Electrum serveru. Nesdílené
lokální filesystémy na více hostech ochranu nespojí. Cache/lock soubory za běhu
nemažte a nepřepínejte cache cestu kvůli obejití limitu.

## Plánovač a skutečná fronta

Výchozí timer zůstává 10 minut. CLI zpracuje nejvýše 100 pokusů / 45 s; musí
zbývat čas na celou bounded observation. Pomalý daemon nebo dosažený budget
znamená menší dávku. **100 × 6 není garantovaných 600 observations za hodinu.**
Při výchozím budgetu a krátké dávce může praktický strop být jen přibližně
60 × 6 = 360 observations/h. Tisíce aktivních faktur tedy vyžadují jiné plánování.

Pro větší frontu vygenerujte minutový tick:

```bash
php bin/deployment.php --payment-systemd=timer --payment-tick=60
```

Jde pouze o výpis systemd jednotky; její instalace je v PAYMENT_MONITORING.
Tick volí zahájení dávky, **nezkracuje 10–60minutové intervaly jedné faktury**.
Alternativou je minutový cron místo dosavadního `*/10`. Instance lock zabraňuje
překrytí CLI/admin dávky, budget dál omezuje součet s webovými observations.

Pro plánování spočítejte potřebu `6 × nové + 2 × středně staré + 1 × starší`
observations/h. Výchozí minutový budget teoreticky dovoluje nejvýše 3600/h;
RPC timeouty, větší historie, PHP, DB a receive backlog tuto mez snižují.
Příklady: 1000 nových nezaplacených faktur potřebuje asi 6000/h, takže výchozí
limit nestačí. 1000 faktur starších než šest hodin potřebuje asi 1000/h.
Jde o plánovací aritmetiku, nikoli změřenou produkční propustnost.

## Co měřit

Admin Kontrola plateb a `php payment_worker.php --check` ukazují počet due,
nejstarší due i včetně nových neskenovaných faktur, zpoždění a propadlé leases.
Zpoždění přes hodinu vyvolá upozornění. Sledujte také chyby a počty scanned v
journalu, RPC latency, růst DB/cache, receive backlog a stáří webhook fronty.

Zátěžové regresní testy používají řízené RPC: 1000 různých studených adres
způsobí nejvýše 60 RPC prázdné balance fixture, zbytek dostane backpressure;
100 souběžných procesů nepřekročí dva aktivní observers. Starší test 100
stejných adres prokazuje single-flight a jeden refresh. DB testy prokazují
jedinečné XPUB indexy a idempotency při souběhu. Tyto důkazy chrání invarianty,
**neprokazují rychlost nebo spolehlivost veřejného Electrum serveru**.

Před širším provozem změřte vlastní daemon/server a fronty při pomalém RPC,
restartu a výpadku. Pro tisíce aktivních faktur preferujte vlastní Electrum
server; plné SPV/merkle ověřování receipt historie současný provider neprovádí.
Další cíle a podmínky dokončení jsou v ROADMAP.
