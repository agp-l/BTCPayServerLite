# Kontrola plateb

V administraci otevřete **Nástroje → Kontrola plateb**. Nejprve v Aktualizaci
systému aplikujte chybějící migrace včetně `010_payment_worker_runtime.sql` a
`011_invoice_received_outputs.sql`. Fresh `sql.sql` je obsahuje.

Tlačítko spouští stejný PaymentWorker jako CLI, přímo v PHP bez shellu. Kontroluje
nejvýše 20 splatných kontrol v dávce s rozpočtem 12 sekund. Před další observation
musí zbývat celý její deklarovaný čas; manuální RPC má timeout nejvýše 2 sekundy.
DB práce může přidat dobu čekání na DB. Další ruční běh je možný po 10 minutách.
Zbytek fronty vyřídí další kliknutí nebo plánovač. GET pouze načítá diagnostiku.
Otevření checkoutu ani vytvoření XPUB faktury worker nespouští.

## Jak se platba z e-shopu označí jako zaplacená

1. E-shop vytvoří fakturu přes API. BTC Pay Lite uloží její částku a přijímací adresu.
2. Plánovač serveru (systemd timer nebo cron) spustí `payment_worker.php`.
   Worker se přes Electrum dotáže na historii adresy a skutečné výstupy transakcí. Nepotvrzená či částečná platba
   znamená `Processing`; celá potvrzená částka znamená `Settled`.
3. Se změnou stavu se do databáze uloží událost pro webhook.
   Samostatně plánovaný `webhook_cron.php` ji doručí e-shopu a při chybě ji zkusí znovu.
4. Simple-store ověří podpis webhooku a aktuální fakturu si ještě načte přes API.
   Teprve ověřený `Settled` se shodnou objednávkou, obchodem a částkou označí
   objednávku jako zaplacenou. Ověření umí spustit také návrat z platební stránky
   a načtení objednávky, takže samotné označení zaplaceno neprokazuje doručení webhooku.

Otevřený běžný checkout obnovuje uložený stav přibližně každých 5 sekund
(15 sekund v neaktivní kartě; při chybách pomaleji). Blockchain nekontroluje.
Platba se proto kontroluje i po zavření prohlížeče, pokud běží serverový plánovač.
Při nastavení timeru podle návodu níže se worker spouští přibližně každých 10 minut.
Skutečný čas závisí i na intervalu webhook workeru
a na potvrzení transakce v bitcoinové síti.

## Úsporné sledování pro vlastní e-shop

| Stáří faktury | Interval další kontroly |
|---|---|
| První hodina | 10 minut |
| Od 1 do 6 hodin | 30 minut |
| Od 6 hodin nebo po expiraci | 60 minut |
| Settled | Další kontrola se neprovádí |

Stejný režim platí pro `New` i `Processing`; zpomalení nezruší rozpoznanou platbu.
Faktury standardně platí 48 hodin. Do checkoutu je doplněna informace o intervalu
a o možném několikahodinovém potvrzování v bitcoinové síti. Zpomalení se týká
on-chain BTC, nikoli Lightning plateb na jiném BTCPay Serveru.

Worker chrání minimální odstup také podle `last_checked_at`. Ani starý timer po
15 sekundách, opakované klikání nebo dřívější `next_check_at` tedy nezpůsobí další
observation stejné faktury před 10 minutami. Chyba se zkouší znovu nejdříve za
10 minut. Provider sdílí cache a cooldown mezi procesy pro stejný endpoint/adresu;
zápis cooldownu proběhne před RPC, takže platí i při pádu procesu.

URL (stateless) faktura používá tento odstup přes provider cache, přestože její
stavový endpoint navštěvuje prohlížeč častěji. Její automatické sledování vyžaduje
otevřenou stránku nebo vlastní volání status API; pro e-shop používejte DB faktury
přes Greenfield API, které sleduje serverový worker i po zavření stránky.

Jedna čekající faktura znamená přibližně 6, 2 nebo 1 obnovení adresy za hodinu.
Prázdná adresa vyžaduje jeden history dotaz. Neprázdná navíc dotaz na zůstatek
a nejvýše dvě dosud neznámé transakce; jedna observation tedy obsahuje nejvýše
čtyři RPC, ne neomezené stahování historie. Již ověřené transakce se z cache
znovu nestahují. Při delší historii pokračuje další plánovaná kontrola.
Počet roste s počtem faktur; nejde o garanci, že libovolný veřejný Electrum server
tuto zátěž dovolí. Electrum samotné navíc udržuje synchronizaci své peněženky.
Webhook worker pouze doručuje uložené události, blockchain znovu nekontroluje.

Historie administrátorské peněženky je samostatný pohled do Electra. Její data
načítá otevření stránky nebo tlačítko **Obnovit**, nikoli checkout polling.
U XPUB obchodů navíc samostatný `wallet_receive_sync.php` registruje přijímací
adresy v Electru. Bez této synchronizace může worker vidět zaplacenou fakturu,
zatímco Electrum ještě danou adresu nemá ve své historii či zůstatku.
Podrobnosti a diagnostika jsou v [historii peněženky](WALLET_HISTORY.md).

CLI běží jednorázově, nejvýše 100 kontrol / 45 sekund (jedno RPC nejvýše 8 sekund,
celá history observation nejvýše 35 sekund včetně lock/cache rezervy).
Bez plánovače se samo znovu nespustí. Instance má společný DB advisory lock pro
CLI i admin dávku; další spuštění se vrátí jako busy. Faktury nadále používají
vlastní persistentní leases a transakční status + webhook outbox.

## Co přehled skutečně dokazuje

CLI a ruční tlačítko mají samostatný poslední běh, výsledek, poslední úspěch/chybu
 a počty kontrol, přechodů a chyb. Prázdná dávka je úspěšný běh. Neověřuje RPC,
pokud žádná faktura není na řadě. Samotný CLI příkaz od administrátora se také
zaznamená jako CLI: web nemůže tvrdit, že je cron či systemd skutečně zapnutý.

CLI starší než 30 minut je opožděné. Running bez instance locku je přerušený
běh. Pád před připojením do DB nemůže zapsat heartbeat; projeví se chybějícím nebo
starým záznamem a výpisem služby. Ukládají se pouze pevné chybové kódy, žádné RPC
odpovědi, hesla nebo traces. Tabulka obsahuje dva poslední běhy, není to auditní
historie všech spuštění. Časy se zobrazují v UTC.

`php payment_worker.php --check` pouze čte DB frontu, propadlé invoice leases a
runtime záznamy. Nezkouší RPC. Chybějící sloupce/tabulky hlásí nutnost migrace.
Běh s neúspěšnými observations vrací CLI exit code 1; busy vrací 0.

## Přijatá platba a pozdější utracení BTC

Produkční worker i URL status používají `ElectrumReceiptBlockchainProvider`.
Z `getaddresshistory` získá aktuální seznam transakcí, z `gettransaction` jejich
raw obsah. `bitcoin-p8` lokálně kontroluje TXID a konkrétní výstupní skript adresy;
duplicitní transakci ani její výstupy nepřičte podruhé. Vrácené vlastní drobné
ze vstupů této faktury nejsou nová úhrada. Přijetí a utracení před první kontrolou
tedy neznamená ztrátu původní platby, i když je současný zůstatek adresy nula.

Potvrzené a nepotvrzené příjmy mají oddělené sloupce `confirmed_output_sats` a
`unconfirmed_output_sats`. Aktuální zůstatek zůstává oddělený. Migrace 011 staré
zůstatky nepřevádí na údaj o příjmu a nemění již zaplacené faktury. Před `Settled`
se seznam transakcí a výšky potvrzení znovu vyhodnocují; odstraněná či nahrazená
mempool platba není zachována jako přijatá částka. `Processing` ovšem zůstane
rozpoznanou rozpracovanou platbou. `Settled` je stejně jako dříve terminální.

Výšky potvrzení z walletless historie **stále závisejí na vybraném Electrum
serveru**; kontrola raw TXID není SPV/Merkle ověření zařazení do bloku. Tato
varianta nenahrazuje vlastní Bitcoin Core/NBXplorer. Platí pro unikátní přijímací
adresy faktur, nikoli opakované používání jedné adresy pro nové faktury. Limit
historie je 100 transakcí a raw transakce 1 MB; překročení či neplatná evidence
kontrolu zastaví a zobrazí chybu, nikoli automaticky zaplacenou fakturu.

## Automatické spouštění pomocí systemd

Generátor pouze vypíše jednotky podle aktuálního adresáře projektu a zadaného
uživatele/PHP. Neinstaluje je a nepotřebuje DB konfiguraci. Následující příklad
odpovídá instalaci AG; na jiném serveru zvolte skutečného worker uživatele a PHP.
Práva ke config.php a var/blockchain připravte podle [nasazení](DEPLOYMENT.md).

```bash
cd /opt/lampp/htdocs/BTCPayLite
php bin/deployment.php --payment-systemd=service --worker-user=ag --php-binary=/usr/bin/php8.3 > /tmp/btcpay-lite-payment-worker.service
php bin/deployment.php --payment-systemd=timer > /tmp/btcpay-lite-payment-worker.timer
cat /tmp/btcpay-lite-payment-worker.service /tmp/btcpay-lite-payment-worker.timer
```

Po kontrole souborů je správce nainstaluje a zapne:

```bash
sudo install -m 644 /tmp/btcpay-lite-payment-worker.service /etc/systemd/system/btcpay-lite-payment-worker.service
sudo install -m 644 /tmp/btcpay-lite-payment-worker.timer /etc/systemd/system/btcpay-lite-payment-worker.timer
sudo systemctl daemon-reload
sudo systemctl enable --now btcpay-lite-payment-worker.timer
sudo systemctl restart btcpay-lite-payment-worker.timer
systemctl status btcpay-lite-payment-worker.timer --no-pager
systemctl list-timers btcpay-lite-payment-worker.timer --no-pager
journalctl -u btcpay-lite-payment-worker.service -n 30 --no-pager
```

Timer spouští novou dávku přibližně každých 10 minut od začátku předchozí; dlouhá dávka
se nepřekrývá. Viz [systemd.timer](https://www.freedesktop.org/software/systemd/man/systemd.timer.html).
Nevytváří se závislost na odhadnutém jménu vaší Electrum nebo MariaDB služby.
Při startu nepřipravené DB/RPC se zaznamená chyba a další běh ji zkusí znovu.
Názvy jednotek jsou pro jednu instanci; více instalací potřebuje vlastní názvy
a odpovídající `Unit=` v timeru. Vlastní BTCPAY_BLOCKCHAIN_CACHE_DIR nastavte také
v prostředí služby, pokud nepoužíváte výchozí projektový var/blockchain.

Alternativa: přes `crontab -e` uživatele workeru nastavte jednu řádku:

```cron
*/10 * * * * /usr/bin/php8.3 /opt/lampp/htdocs/BTCPayLite/payment_worker.php
```

Cron pak kontroluje přibližně jednou za 10 minut. Nenastavujte současně cron i timer.
Zastavení timeru: `sudo systemctl disable --now btcpay-lite-payment-worker.timer`.
Pro migraci vyčkejte i dokončení již běžící služby a dalších workerů.

## Zachovaná platební politika

New = zatím bez zaznamenané platby. Jakákoli detekovaná platba, včetně částečné,
přechází na Processing; ten se nevrací do New/Expired. Plná potvrzená částka
vede na Settled, který je terminální. New bez platby po expiraci přechází na
Expired. Expired se kontroluje ještě 24 hodin, déle pokud existuje platební
indikace. Návrh na jiné zacházení s partial payment není součástí této změny.

Webhooky se atomicky zařadí se změnou stavu, ale doručuje je webhook_cron.php.
Receive sync je další samostatný worker. Jejich heartbeat tato stránka neměří.

## Když se střídají chyby a prázdné úspěšné běhy

Po chybě worker odloží fakturu nejméně o 10 minut. Timer může mezitím vykázat prázdnou
úspěšnou dávku; to nedokazuje funkční RPC. `error_type` proto zůstává až do další
neprázdné dávky bez chyby. Poslední úspěch v tabulce stále znamená dokončení běhu.

Journal nově rozlišuje `cache_directory`, `cache_lock_open`, `cache_lock_timeout`,
`cache_write`, `upstream_backoff`, `rpc_authentication`, `rpc_transport`,
`rpc_timeout`, `rpc_http`, `rpc_protocol`, `rpc_method_unavailable`, `rpc_remote`,
`invalid_balance` a `payment_database`. RPC detaily jsou jen číselné HTTP/RPC/cURL
kódy, databázové jen SQLSTATE. Žádné raw odpovědi či exception zprávy.

Pro `cache_lock_open` nestačí zapisovat do adresáře: worker i PHP uživatel musí
umět otevřít už existující lock soubory. Použijte plán oprávnění z nasazení;
neodstraňujte zámky za běhu. Pro jiné kódy postupujte podle vysvětlení v adminu.

History provider navíc rozlišuje `invalid_history`, `invalid_transaction`,
`invalid_address` a `history_incomplete`. Poslední stav znamená další omezený
krok načítání historie, ne důkaz nezaplacené faktury.

## Větší počet faktur

Výchozí cadence faktury 10/30/60 minut zůstává. Pro odčerpávání větší due fronty
lze generovat timer s `--payment-tick=60`; ten zahájí omezenou dávku každou minutu,
nikoli novou kontrolu každé adresy. Společný endpoint budget dovolí defaultně
60 observations/min a nejvýše dvě současně. Při dosažení limitu dávka skončí,
lokálně odložená faktura nemá smyšlený čas observation. Přehled ukazuje i stáří
první čekající kontroly. [Kapacita](CAPACITY.md) uvádí přesné limity a aritmetiku
pro stovky/tisíce aktivních faktur. `observation_budget`, `observation_concurrency`
a `upstream_circuit_open` jsou provozní backpressure, nikoli potvrzení platby.
