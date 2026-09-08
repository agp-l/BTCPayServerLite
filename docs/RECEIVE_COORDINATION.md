# Koordinace přijímacích adres a synchronizace Electra

Aktuální navazující stav po `2915df0`, 8. září 2026.
Tento dokument nahrazuje předchozí omezení, že admin a stateless tvorba nejsou
napojené na DB XPUB sekvenci. Payout ledger, PaymentWorker, webhook outbox,
BlockchainProvider a checkout zůstávají beze změn.

## Jeden vlastník rezervací

| Cesta pro spravovanou XPUB wallet | Vydání adresy | Electrum RPC při vydání |
|---|---|---|
| Greenfield invoice | AddressGeneratorFactory → XpubAddressGenerator → DbAddressIndexStore | 0 |
| Admin new address | WalletReceiveCoordinator → stejná DB sekvence → lokální derivace | 0 |
| Stateless invoice v instalované aplikaci | Lazy WalletReceiveCoordinator → stejná DB sekvence → token v3 | 0 |
| Synchronizace receive rozsahu | Již rezervované indexy pouze zpřístupní Electru | Omezená dávka |

`wallet_receive_ranges` trvale váže kanonický wallet path na veřejný klíč a script
 type. `xpub_address_sequences` zůstává vlastníkem atomického high water stejného
klíče/chain code, i přes různé SLIP-0132 prefixy a více obchodů. Obě tabulky se musí
zálohovat a nesmějí se mazat s obchodem. Konfliktní přiřazení klíče/scriptu selže.
Generátor také ověří, že se konfigurace store nezměnila od načtení jeho snapshotu;
při změně odmítne vydání před spotřebováním indexu.

Admin je autorizovaný rolí a zvolenou konkrétní wallet. Stateless API ověřuje
konfigurovaný klíč a jeho wallet. Greenfield ověřuje konkrétní store API key.
Koordinátor sám nenahrazuje tyto autorizační hranice a nevybírá implicitní obchod.
Adresní rezervace admin/stateless nevytváří řádek `invoices`.

Neúspěšné dokončení HTTP odpovědi může spálit index, který se nevrací. Nemůže tím
vzniknout opětovné přidělení této adresy. Greenfield idempotency navíc zachovává
svou dosavadní obnovu přes resource reservation.

## Stateless a explicitní legacy režim

Token verze 3 obsahuje adresu, částku, expiraci a podepsaná metadata; neobsahuje
smyšlené Electrum request ID. Status vyžaduje BlockchainProvider. Verze 1 a 2
zůstávají čitelné, včetně starších skutečných request IDs. Provider status nikdy
neotevírá alokátor ani jeho DB, nenahrává wallet a nevolá `get_request`.

BtcStatelessFactory připojí alokátor jen v instalované konfiguraci s DB; spojení
vzniká až při tvorbě adresy. Samostatná integrace bez DB zachovává explicitní
Electrum request režim. Musí používat oddělenou wallet, pokud nesdílí koordinátor
instalované aplikace. Žádné rozpoznání XPUB přes RPC se při faktuře nepřidává.

Store výslovně označený `electrum`, jehož wallet již spravuje XPUB koordinátor,
se při pokusu o samostatné Electrum generování odmítne s požadavkem na repair.
Neprobíhá tichá změna deklarovaného zdroje adres. U wallet bez XPUB režimu
zůstává dosavadní Electrum fallback a společný per-wallet mutation lock.

Nulový balance neznamená volnou adresu: může už patřit nezaplacené faktuře.
Instalovaný admin proto automaticky nenabízí takovou adresu jako novou. Existující
akce pro vytvoření adresy vrací skutečně rezervovanou adresu; rozložení UI se nemění.

## Upgrade a spuštění

Při upgradu z `2915df0` aplikujte před nasazením kódu
[`007_wallet_receive_ranges.sql`](../migrations/007_wallet_receive_ranges.sql).
Pokud začínáte ze staršího main, je potřeba nejprve i migrace 006 a předchozí
migrace odpovídající vašemu schématu. Nepřepisujte používanou DB celým `sql.sql`.
Nová databáze může vzniknout z aktuálního `sql.sql` nebo přes instalátor.

### Chyba `Receive synchronization failed: PDOException`

Starší verze vypisovala pouze název výjimky. Chybějící migrace 006/007 mohou tuto
chybu způsobit, ale samotné hlášení nepotvrzuje konkrétní příčinu. Aktualizujte kód
a spusťte kontrolu bez Electrum RPC a bez zápisů do DB:

```sh
git pull --ff-only
php wallet_receive_sync.php --check-db
```

Výstup obsahuje skutečnou databázi vybranou v `config.php`, chybějící tabulky či
sloupce a potřebné soubory migrací. V phpMyAdmin vyberte **tuto databázi**, udělejte
export zálohy a na kartě Import postupně importujte vypsané soubory. Pro instalaci
před receive koordinátorem jde o
[`006_shared_xpub_address_sequences.sql`](../migrations/006_shared_xpub_address_sequences.sql)
a [`007_wallet_receive_ranges.sql`](../migrations/007_wallet_receive_ranges.sql).
Potom zopakujte `--check-db` a při `ok: true` spusťte běžný worker. Tato kontrola
ověřuje DB, nikoli dostupnost daemonu.

Existující neúplná tabulka vyžaduje opravu chybějících sloupců; opakované
`CREATE TABLE IF NOT EXISTS` je nepřidá. Nepoužívejte celý `sql.sql` jako univerzální
upgrade používané databáze. Při jiném selhání hlášení zachová SQLSTATE a číselný
driver code bez zveřejnění SQL hodnot či hesel.

Položky `php_binary` a `php_ini` ukazují skutečné CLI prostředí. Pokud používáte
XAMPP, lze kontrolu spustit jeho PHP přes
`/opt/lampp/bin/php wallet_receive_sync.php --check-db` a porovnat výsledek.

Po nasazení lze spouštět CLI worker například pravidelným serverovým cronem:

```sh
php wallet_receive_sync.php
```

Defaultní dávka zpracuje nejvýše dvě wallets a vytvoří v každé nejvýše 25 chybějících
receive adres. Mutation smyčka kontroluje časový rozpočet 10 sekund. Úvodní
ověření wallet a probíhající RPC mají navíc své nakonfigurované RPC timeouty;
nejde o tvrdý desetisekundový limit celého procesu. Parametry jsou omezené:
nejvýše 20 wallets, 100 adres na wallet a rozpočet 30 sekund. Zvyšte je až podle
měření backlogu a kapacity konkrétního daemonu.

```sh
php wallet_receive_sync.php --limit=2 --max-addresses=25 --budget=10
php wallet_receive_sync.php --wallet=/opt/btcpay_wallets/wallet_1 --max-addresses=25
```

Konkrétní `--wallet` může inicializovat binding existujícího XPUB store a vynutit
kontrolu ihned. Automatický běh vybírá známé bindings s nedoplněným rozsahem;
dokončený rozsah preventivně znovu kontroluje nejpozději po pěti minutách při
pravidelném spouštění. Nové registrace/faktury vytvářejí binding při použití DB
rezervace. Pro starý Electrum store použijte nejprve explicitní repair postup
z [XPUB auditu](XPUB_FIRST_MULTI_WALLET_AUDIT.md).

Worker je CLI-only a přes HTTP vrací 404 ještě před konfigurací. Cron v uživatelově
prostředí nebyl automaticky vytvořen, jeho localhost DB ani daemon nebyly změněny.

## Co worker garantuje

Před mutací znovu načte skutečný MPK a receive adresy. Ověří identitu klíče,
script type a první/poslední lokálně reprodukovanou adresu. Pak používá výhradně
explicitní `createnewaddress` na téže wallet, pod společným per-wallet lockem.
Jiná wallet má jiný lock. Konkurenční sync worker stejnou wallet přeskočí jako
`busy`, aniž by opakoval její RPC práci.

Žádná DB transakce není otevřená během RPC. Progress zapíše po uvolnění mutation
locku; je to pouze informace pro plánování. Po pádu nebo timeoutu se příště čte
skutečný stav daemonu. Nejistý mutující RPC se okamžitě neopakuje. Pokud Electrum
samo rozšíří svůj gap, worker situaci ověří opětovným čtením. Starší obnovený
wallet soubor nesmí být přeskočen jen proto, že DB dříve zaznamenala větší rozsah.

Výsledek `registered` znamená přítomnost potřebných adres v aktuálně načtené
wallet. Neznamená dokončenou blockchain synchronizaci, potvrzené platby nebo
připravené UTXO k utracení. Tyto stavy nadále určuje Electrum/BlockchainProvider.
Worker nevolá `close_wallet`, nemění gap limit, nevytváří faktury ani nepodepisuje.

## Ověření

- 60 skutečně souběžných PHP procesů střídá Greenfield/admin/stateless: 60 různých
  adres, jediná sekvence a žádné wallet RPC při jejich přidělení.
- Původní 100-way XPUB a idempotency testy dál procházejí.
- Tokeny v1/v2/v3 mají walletless status. Produkční factory zvládne status i při
  záměrně neplatné DB konfiguraci, protože DB při statusu neotevírá.
- Testy dávkového limitu, ztracené odpovědi po mutaci, obnovy starší wallet,
  chybného klíče, soupeřícího workeru a persistence po smazání stores.
- Test konfigurace změněné mezi načtením store a rezervací indexu.
- HTTP 404 testy pro payment worker, receive sync a repair.
- Celá sada: 58 souborů prošlo lokálně s MariaDB. Po posledním guardu znovu prošel
  cílený integrační test a syntaxe všech 228 PHP souborů.
- Poslední úplné [GitHub CI ab96acd](https://github.com/agp-l/BTCPayServerLite/actions/runs/34179267951)
  prošlo. Předchozí CI odhalilo nespouštěný HTTP fixture server; test nyní rezervuje
  volný port přes OS a kontroluje připravenost před první RPC operací.

Commity: `a09f6fe` sdílení adres, `9ecf261` sync worker, `e381c5c` integrační testy,
`0bc0e08` ochrana snapshotu a lazy status test, `ab96acd` oprava startu test serveru.

## Provozní hranice

Aplikace nemůže zachytávat libovolné příkazy uživatele s přímým přístupem k daemonu.
Externí Electrum GUI/CLI či standalone integrace bez společné DB nesmějí nezávisle
vydávat adresy ze stejné větve. Spravované produkční cesty aplikace jsou sjednocené;
nízkoúrovňové RPC metody zůstávají explicitními stavebními prvky pro údržbu.

Záloha seedu sama nezachovává informaci o odvozeném rozsahu za gap limitem. Zálohujte
i DB sekvence a wallet soubor; při obnově lze rozsah znovu zaregistrovat workerem.
Synchronizace je záměrně dávkovaná, takže při vysokém počtu faktur může vzniknout
backlog. Nezpomaluje to vydávání adres, ale může zpozdit viditelnost v Electrum
balance/UTXO. Zátěž 100 000 adres proti konkrétnímu daemonu zde ověřena nebyla.

Implementace vychází z [Electrum commands.py](https://github.com/spesmilo/electrum/blob/master/electrum/commands.py)
(`add_request`, `createnewaddress`) a z
[oficiálního vysvětlení gap limitu](https://electrum.readthedocs.io/en/latest/faq.html#what-is-the-gap-limit).
Testy používají skutečnou DB a deterministické Electrum fixtures, nikoli uživatelův
živý daemon. Odesílání pro budoucí směnárnu nadále vyžaduje samostatný audit
rezervace UTXO, broadcast recovery a účetnictví.

## Prázdný výsledek synchronizace

`wallets: []` není seznam peněženek daemonu. Worker vybírá pouze řádky registru
`wallet_receive_ranges`, které mají nedoplněný receive rozsah nebo poslední kontrolu
starší než pět minut. CLI nyní doplní `idle_reason` a `registered_wallets`:

- `no_registered_wallets`: registr je prázdný. Pro existující XPUB store spusťte
  `php wallet_receive_sync.php --wallet=/skutecna/cesta/k/wallet`.
  Cesta musí odpovídat danému store. Tím lze vytvořit jeho binding a ihned ověřit
  receive rozsah; nevzniká nová faktura ani adresní rezervace.
- `no_wallets_due`: registrované wallets nyní nepotřebují práci; opakovaná kontrola
  je splatná po pěti minutách. Konkrétní `--wallet` ji může vyžádat ihned.

Legacy Electrum store bez XPUB vyžaduje nejprve explicitní repair postup; worker
jej sám nepřepíná ani neprochází všechny soubory v adresáři daemonu. Správné DB
schéma samo o sobě registry nenaplní.

Pro budoucí kontrolu a provádění podporovaných migrací použijte administrační
[database_upgrade.php](DATABASE_UPGRADE.md). Běžný `--check-db` kontroluje jen
DB předpoklady receive workeru; migrační stránka porovnává širší schéma aplikace.
