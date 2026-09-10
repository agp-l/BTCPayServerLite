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

Historické počty testů jsou v [pracovních záznamech](INSTALLER_MULTI_WALLET_PROGRESS.md).
Tato reorganizace dokumentace nemění PHP, SQL ani platební chování; postačuje
kontrola diffu, místních odkazů a shody návodů se zdroji. Celou sadu opakujte při
změnách, které její scénáře mohou ovlivnit, nebo podle požadavků CI.
