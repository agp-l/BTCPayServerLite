# Kontrola plateb

V administraci otevřete **Nástroje → Kontrola plateb**. Nejprve v Aktualizaci
systému aplikujte `010_payment_worker_runtime.sql`. Fresh `sql.sql` ji obsahuje.

Tlačítko spouští stejný PaymentWorker jako CLI, přímo v PHP bez shellu. Kontroluje
nejvýše 20 splatných kontrol v dávce s rozpočtem 12 sekund. Před další observation
musí zbývat celý její deklarovaný čas; manuální RPC má timeout nejvýše 5 sekund.
DB práce může přidat dobu čekání na DB. Další ruční běh je možný po 15 sekundách.
Zbytek fronty vyřídí další kliknutí nebo plánovač. GET pouze načítá diagnostiku.
Otevření checkoutu ani vytvoření XPUB faktury worker nespouští.

CLI běží jednorázově, nejvýše 100 kontrol / 45 sekund (RPC nejvýše 30 sekund).
Bez plánovače se samo znovu nespustí. Instance má společný DB advisory lock pro
CLI i admin dávku; další spuštění se vrátí jako busy. Faktury nadále používají
vlastní persistentní leases a transakční status + webhook outbox.

## Co přehled skutečně dokazuje

CLI a ruční tlačítko mají samostatný poslední běh, výsledek, poslední úspěch/chybu
 a počty kontrol, přechodů a chyb. Prázdná dávka je úspěšný běh. Neověřuje RPC,
pokud žádná faktura není na řadě. Samotný CLI příkaz od administrátora se také
zaznamená jako CLI: web nemůže tvrdit, že je cron či systemd skutečně zapnutý.

CLI starší než 120 sekund je opožděné. Running bez instance locku je přerušený
běh. Pád před připojením do DB nemůže zapsat heartbeat; projeví se chybějícím nebo
starým záznamem a výpisem služby. Ukládají se pouze pevné chybové kódy, žádné RPC
odpovědi, hesla nebo traces. Tabulka obsahuje dva poslední běhy, není to auditní
historie všech spuštění. Časy se zobrazují v UTC.

`php payment_worker.php --check` pouze čte DB frontu, propadlé invoice leases a
runtime záznamy. Nezkouší RPC. Chybějící sloupce/tabulky hlásí nutnost migrace.
Běh s neúspěšnými observations vrací CLI exit code 1; busy vrací 0.

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
systemctl status btcpay-lite-payment-worker.timer --no-pager
systemctl list-timers btcpay-lite-payment-worker.timer
journalctl -u btcpay-lite-payment-worker.service -n 30 --no-pager
```

Timer spouští novou dávku přibližně 15 sekund po dokončení předchozí; dlouhá dávka
se nepřekrývá. Viz [systemd.timer](https://www.freedesktop.org/software/systemd/man/systemd.timer.html).
Nevytváří se závislost na odhadnutém jménu vaší Electrum nebo MariaDB služby.
Při startu nepřipravené DB/RPC se zaznamená chyba a další běh ji zkusí znovu.
Názvy jednotek jsou pro jednu instanci; více instalací potřebuje vlastní názvy
a odpovídající `Unit=` v timeru. Vlastní BTCPAY_BLOCKCHAIN_CACHE_DIR nastavte také
v prostředí služby, pokud nepoužíváte výchozí projektový var/blockchain.

Alternativa: přes `crontab -e` uživatele workeru nastavte jednu řádku:

```cron
* * * * * /usr/bin/php8.3 /opt/lampp/htdocs/BTCPayLite/payment_worker.php
```

Cron pak kontroluje přibližně jednou za minutu. Nenastavujte současně cron i timer.
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
