# Aktualizace databáze z prohlížeče

Aktualizujte projekt přes `git pull --ff-only`, přihlaste se jako administrátor
běžným přihlášením aplikace a v menu vyberte **Nástroje → Aktualizace systému**.
Pro místní XAMPP instalaci například:
`http://localhost/BTCPayLite/admin/database_upgrade`.
Původní `database_upgrade.php` zůstává přesměrováním, včetně zachování POST starých formulářů.
Obsluhu má `admin/database_upgrade.php`, HTML šablonu `admin/views/database_upgrade_view.php`;
stránka používá společné admin menu, styly a ověřování účtu.
Nástroj nyní aktualizuje databázové schéma; kód projektu se dále aktualizuje přes Git.
Nástroj vyžaduje existující config a platný aktivní admin účet; novou instalaci
nadále vytváří `install.php`. Používá pouze DB, nikoli Electrum konfiguraci/RPC.

Otevření stránky provede kontrolu databáze vybrané v config.php. Neprovádí migrace.
Zobrazí rozdíly proti aktuálnímu `sql.sql`, stav migrací a rozbalitelné skutečné SQL.
Porovnává tabulky, InnoDB, typy a NULL sloupců, požadované indexy a explicitní
COLLATE. Výchozí hodnoty, cizí klíče, CHECK, triggery a správnost datových backfillů
zatím do této kontroly nespadají. Nejde o úplné potvrzení sémantické shody schématu.
Dodatečné tabulky či sloupce nemaže a nevytváří odhadnuté opravné ALTER příkazy.

## Provedení změny

1. Exportujte zálohu používané databáze v phpMyAdmin.
2. Zastavte API zápisy a payment, webhook, payout i receive workery; stránka je
   sama nezastaví. Během změn nespouštějte jiné ruční migrace.
3. Zkontrolujte cílovou databázi a SQL nabídnuté migrace. Potvrďte zálohu a zastavení
   zápisů, potom použijte tlačítko konkrétní čekající migrace.
4. Po dokončení se znovu zkontroluje schéma a aktualizuje nabídka dalších kroků.
   Spouštějte je postupně v závislostním pořadí. Poté obnovte provoz aplikace.

Automatický katalog zahrnuje 001–009. Částečně přítomné změny automatické spuštění
blokují. Starší historické soubory a neznámé nové migrace mají ruční postup podle
komentářů a preflightu souboru; abecední pořadí souborů není migrační plán.
Budoucí automatická migrace potřebuje výslovný záznam předpokladů a strukturálních
znaků v `DatabaseMigrationManager::CATALOG`, aktualizaci sql.sql a integrační test.
Do již použitých souborů migrací nedopisujte nové změny; přidejte nový soubor.

## Historie a přerušení

Tabulka `schema_migrations` vznikne při prvním spuštění nástrojem nebo migrací 008.
Nový sql.sql ji obsahuje. Zaznamenává SHA-256 souboru, stav Running/Applied/Failed,
ID admina, časy a poslední úspěšný SQL krok. Neobsahuje hesla ani raw SQL chyby.

„Struktura přítomna“ znamená detekované strukturální znaky starší ruční instalace;
nástroj nedoplňuje smyšlenou historii ani nedokazuje provedení datových UPDATE.
„Provedeno nástrojem“ vyžaduje vlastní úspěšný záznam. Změněný checksum či
nedokončený záznam blokuje replay. Kontrola struktury zůstává samostatná.

[MariaDB ukládá DDL implicitním commitem](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit).
Jedna transakce tedy nemůže vrátit celý soubor migrace. Nástroj drží po dobu běhu
[GET_LOCK zámek, který přežívá COMMIT](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock),
a zapisuje Running ještě před SQL. Při pádu mezi SQL a zápisem jeho dokončení
je poslední krok nejistý; automaticky se neopakuje. Neodstraňujte journal řádek
jen pro odblokování tlačítka. Nejprve porovnejte konkrétní kroky a data, dokončete
ručně pod údržbou nebo obnovte ověřenou zálohu. Žádný rollback se nepředstírá.

HTTP hranice kontroluje platnou admin relaci, aktuální roli/status/session version
v DB a CSRF. Změna plánu mezi zobrazením a odesláním vyžaduje obnovení stránky.
Dva procesy nemohou přes tento nástroj současně migrovat stejnou databázi.
Pokud stará DB neumí již samotné přihlášení, obnovte ji administrátorsky přes
phpMyAdmin; stránka neposkytuje veřejný obchvat autentizace.
