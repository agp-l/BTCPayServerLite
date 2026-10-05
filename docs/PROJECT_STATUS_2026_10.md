# Současný stav projektu a integrace simple-store

Revize **5. října 2026**, po stabilizaci aktuálního main. Provozovatel uvádí,
že systém funguje; stabilizace zachovává jeho PHP/Electrum/XPUB tok a uzavírá
konkrétní chyby. Historie předchozích integrací je v [HISTORY](HISTORY.md),
aktuální další práce pouze v [ROADMAP](ROADMAP.md).

## Je hlavní jádro hotové?

Hlavní tok přijímání BTC pro e-shop je implementovaný: autorizovaný store,
unikátní lokální XPUB adresa, durable invoice/idempotency, DB checkout,
receipt observation, settlement a transakční webhook outbox. Čtení checkoutu
ani invoice API nevolá Electrum. Souběh, leases a pádové hranice mají skutečné
procesní/DB/HTTP testy. To je funkční invoice jádro, nikoli hotová směnárna
nebo důkaz úplného bezpečnostního auditu.

| Oblast | Ověřená implementace | Zbývající hranice |
|---|---|---|
| XPUB a multi-wallet | Sdílené indexy i aliasy, explicitní scope/policy, per-wallet mutation locks | Externí GUI/CLI musí respektovat receive allocator; provisioning pád před DB potřebuje reconciliation. |
| Observation | Přijaté outputs i po utracení, raw TXID/script kontrola, max 4 RPC, cache a 10/30/60 min | Výška potvrzení je údaj Electra, bez SPV a bez nastavitelného počtu potvrzení; Settled se po reorg nesleduje. |
| Vysoká zátěž | DB-only invoice čtení, default 60 observation starts/min a 2 současně, krátká endpoint pause | Budget chrání upstream, negarantuje délku fronty. HTTP/DB/CPU a receive sync mají vlastní kapacitu; oddělené local cache různých hostů nesdílejí limity. |
| Pozdní platba | 24h auto okno po expiraci, partial déle; admin/CLI rescan konkrétního ID | Rescan je explicitní a bounded, ne neomezený automatický scan celé historie. |
| Webhooky | Claim až těsně před HTTP, HMAC, Retry/Dead, stejný ID po pádu | Receiver musí přijmout opakování a ověřit aktuální invoice/order; health není delivery důkaz. |
| API | Greenfield podmnožina, partial/due/rate z DB, nullable neznámý sync | Někteří plní BTCPay klienti vyžadují jiné endpointy/boolean health; testovat konkrétní integraci. |
| Provoz | Instalátor, migrace 001–011, CLI/manual monitor a queue age, Apache ochrana interních souborů | Dohled receive/webhook, cache retence, cílový restore/HTTPS/Nginx a skutečná kapacita zbývají. |
| Závislosti | Lock/vendor metadata se shodují, plošné advisory bypass odstraněno | Dvě opuštěné knihovny a známé ECC advisories: ověřená náhrada dle DEPENDENCIES. |
| Výplaty | Volitelný ledger a uložená raw TX před broadcastem, výchozí vypnuté | UTXO rezervace, úplná reconciliation, confirmation worker a kompletní service/crash testy nejsou hotové. |

## Výsledek tohoto průchodu

Výchozí main byl `3e8768be40614cd206991c26056a21a614581ee9`, nikoli přiložené
staré kopie. Opraveny webhook leases, souhrnná ochrana Electra, queue lag,
nepravdivý sync, partial payment-methods, HTTP dostupnost CLI health a konkrétní
late rescan. Dokumentace je sjednocená; staré checklisty a pracovní mezistavy
byly odstraněny z aktivních souborů. Migrace, runtime vendor, repair a provozní
skripty zůstávají, protože mají doložené použití.

Aktuální výsledky včetně počtu testů, prostředí a commitů jsou v
[STABILIZATION](STABILIZATION_2026_10.md). Lokální ověření používá skutečnou
MariaDB, procesy, HTTP a Apache, ale řízené blockchain/RPC odpovědi. V tomto
průchodu nebyl zpřístupněn cílový config, Electrum daemon ani produkční DB.
Proto není tvrzena nová reálná platba nebo garantovaná produkční propustnost.
Kapacitní výpočty a meze: [CAPACITY](CAPACITY.md).

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

Pro běžnou konfiguraci e-shopu používat veřejné HTTPS. Místní test obou
aplikací na stejném počítači může používat HTTP: v Lite nastavte například
`app_url => http://localhost/BTCPayLite` a `allow_local_webhooks => true`,
v e-shopu stejnou adresu instance a `http://localhost/simple-store` jako
adresu obchodu. Webhook je `http://localhost/simple-store/btcpay-callback.php`.
Tato volba povoluje jen localhost, 127.0.0.1 a ::1, nikoli privátní LAN.
[Konkrétní místní konfigurace](CONFIGURATION.md#propojení-simple-store-na-localhostu-bez-https)
nevyžaduje certifikát; API klíče a HMAC podpis se dál ověřují.

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

Při nové verzi zopakovat relevantní scénáře na testovací instalaci se skutečným
Electrem. Samotná skutečnost, že objednávka je zaplacená, nerozlišuje callback
od návratu/pollingu; ověřit také konkrétní Delivered záznam a HMAC receiver.

Návody: [API](API.md), [TESTING](TESTING.md), [PAYMENT_MONITORING](PAYMENT_MONITORING.md),
[simple-store BTCPay](https://github.com/agp-l/simple-store/blob/main/docs/btcpay.md).
