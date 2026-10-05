# Ověření změn

[Zpět na README](../README.md)

## Automatická sada

```bash
php tests/run_all.php
```

Runner vyhledává všechny `tests/*Test.php`; není třeba udržovat ruční seznam.
Použijte CLI PHP se závislostmi z composer.lock a rozšířeními, která uvádí
[CI konfigurace](../.github/workflows/php-checks.yml). Procesní testy potřebují
`pcntl`/`posix`, XPUB testy `gmp`/`bcmath`, některé repository testy `pdo_sqlite`.

MySQL/MariaDB scénáře vyžadují `BTCPAY_TEST_MYSQL_HOST` a případně
`BTCPAY_TEST_MYSQL_PORT`, `BTCPAY_TEST_MYSQL_USER`, `BTCPAY_TEST_MYSQL_PASS`.
Testovací účet musí umět vytvořit a smazat izolované testovací databáze. Použijte
samostatný testovací server, nikoli produkční DB účet. Bez nastavení se DB testy
mohou přeskočit; exit code 0 není důkazem jejich provedení. Souhrnný runner
nezobrazuje výstup úspěšných souborů, takže při ověřování podmínek spusťte důležitý
integrační soubor i samostatně.

Příklady cíleného ověření:

```bash
php tests/CorePaymentArchitectureTest.php
php tests/CorePaymentPersistenceTest.php
php tests/CorePaymentMigrationTest.php
php tests/PaymentWorkerHttpBoundaryTest.php
php tests/PaymentWorkerMonitorTest.php
php tests/PaymentFailureDiagnosticsTest.php
php tests/DatabaseUpgradeTest.php
```

Tyto scénáře pokrývají mimo jiné sdílené indexy a souběžnost, walletless status,
per-wallet locking, lease, transakční outbox, stavový automat, HTTP hranice a
bezpečné chybové kódy. Konkrétní testovací podmínky jsou přímo ve zdrojích;
nenahrazujte skutečné procesní/DB scénáře pouze kontrolou názvů metod.

## Provozní důkaz

Testy používají také řízené provider/RPC odpovědi. Před veřejným nasazením ověřte
proti testovací síti a skutečnému Electru vytvoření faktury, částečnou i plnou
platbu, potvrzení a podepsaný webhook včetně opakovaného doručení. Zaznamenejte
verze, konfiguraci s odstraněnými tajemstvími, výsledné stavy a časy. Podrobná
akceptační kritéria jsou v [plánu](ROADMAP.md).

Dne 10. září 2026 uživatel doložil automatický běh na svém serveru se dvěma
observations a dvěma přechody na Expired bez chyby. Jde o ověření konkrétního
provozu; neprokazuje přijatou platbu, webhook ani kapacitu při vysoké zátěži.
`success: true` při `scanned: 0` není ani ověřením blockchain RPC.

## Apache a aktuální důkazy

CI používá PHP 8.2/8.3, MariaDB 10.11 a izolovaný Apache fixture. Místně:

```bash
BTCPAY_TEST_APACHE_BINARY=/usr/sbin/apache2 php tests/ApacheBoundaryTest.php
```

Volitelně nastavte `BTCPAY_TEST_APACHE_MODULES`. Test spustí vlastní Apache na
loopback/volném portu, s falešnými statickými soubory bez produkčního configu.
Ověřuje interní cesty, veřejné routy/assets a Authorization v podadresáři;
PHP vestavěný server tuto ochranu neověřuje. Bez explicitního binary se přeskočí.

Nové regresní testy: BlockchainObservationBudgetTest (1000 různých adres,
rolling window a 100 procesů), WebhookDeliveryLeaseTest (reálná DB a virtuální
pomalá dávka delší než lease), HealthServiceTest, GreenfieldApiTest a late rescan
v PaymentWorkerMonitorTest/DatabaseUpgradeTest. Virtuální čas není reálná rychlost
sítě; process fixture není kapacitní certifikace serveru.

Výsledky tohoto průchodu a jejich hranice: [STABILIZATION](STABILIZATION_2026_10.md).
Historie: [HISTORY](HISTORY.md). Testy opakovat podle skutečně ovlivněných scénářů.
