# XPUB-first a multi-wallet: audit navazující na e88594d

Stav: 8. září 2026. Zachován PHP/PDO, `bitcoin-p8`, BlockchainProvider,
PaymentWorker a webhook outbox. Tento průchod navazuje na opravený instalátor
`7989b14` a multi-wallet commit `e88594d`; nejde o jejich revert.

## Rozhodnutí nad posledním commitem

| Změna v e88594d | Rozhodnutí | Důvod |
|---|---|---|
| AdminInvoiceService: explicitní store, odstranění defaultStore/fetchDefaultStore | KEEP | Faktura musí mít konkrétní autorizovaný obchod. |
| AdminOperationsRepository/Service: odstranění prvního store | KEEP | Žádný výběr cizího obchodu podle pořadí. |
| PdoAdminOperationsRepository: konzervativní `electrum` default | FIX toku provisioningu | Default odpovídá SQL, ale nová standardní wallet nyní explicitně ukládá validní XPUB. Samotný default není řešením provisioningu. |
| BtcDashboard: pevný wallet path a přesné balance částky | KEEP | Jiná aktivní wallet nesmí změnit cíl operace ani přesnost částky. |
| BtcDashboard: mutation lock pro adresu/podpis | KEEP | Mutace jedné wallet mají společnou doménu. |
| ElectrumWallet: load race recheck | KEEP | Souběh s externím loaderem nesmí vyvolat falešné selhání; mutace se neopakuje. |
| ElectrumWallet: load při každém admin GET pod exclusive lockem | FIX | Již loaded wallet se ověří read-only; lock je až na první načtení. |
| ElectrumRPC: zákaz daemon příkazu přes wallet scope | KEEP | Lifecycle příkazy nesmějí zdědit wallet kontext. |
| ElectrumWalletManager: close pouze explicitní maintenance | KEEP | Runtime audit nenalezl produkčního volajícího closeWallet. |
| admin/wallet: neplatný výběr odmítnut, přesná balance, typované chyby | KEEP | Nesmí dojít k mutaci jiné výchozí wallet ani k zaměnění chyby autentizace za Offline. |
| admin/users a client/index: explicitní balance wallet path | KEEP | Cíl čtení plyne z přiřazení, nikoliv mutable RPC stavu. |
| WalletBalanceError: transport/auth/wallet/busy rozlišení | KEEP | Offline má znamenat nedostupný transport. |
| Testy multi-wallet/tenant isolation a úpravy fixtures | KEEP + rozšířit | Nové testy pokrývají provisioning, již loaded read a sdílený XPUB. |
| REFACTOR_PLAN a průběžný záznam | KEEP + aktualizovat | Historický plán nesmí přepsat již dokončenou architekturu. |

Chyběla spojnice mezi vytvořením wallet a ukládáním veřejných receive metadat.
Předchozí oprava defaultu pouze zabránila tomu, aby řádek bez XPUB předstíral
XPUB konfiguraci; běžné nově vytvořené obchody však stále vydávaly adresy přes RPC.
Druhá chyba byla exclusive lock i při pouhém ověření již načtené wallet.

## Nový provisioning a tvorba obchodů

`StoreWalletProvisioner::provision()` vrací `ProvisionedWallet`, nikoli samotnou
cestu. `ElectrumCliWalletProvisioner` vytvoří novou wallet offline a jednou spustí
veřejné offline příkazy `getmpk` a `listaddresses --receiving`. Seed z výstupu
`create` se zahazuje. Daemon nemusí wallet kvůli tomuto postupu vůbec načítat.

Veřejný klíč projde Base58/BIP32 validací existujícím generátorem. Prefix z Electra
určuje script type: xpub/tpub je p2pkh, ypub/upub p2sh-p2wpkh a zpub/vpub p2wpkh.
První a poslední receive adresa se porovnají s lokálně odvozenými adresami.
Unsupported/multisig/starý MPK, invalidní klíč či chybná odpověď operaci zastaví.
Žádná z těchto chyb nepřepne faktury potichu na Electrum.

Do `stores` se explicitně ukládá cesta, XPUB, script type, `address_source=xpub`
a počáteční high water za adresami již vytvořenými při provisioningu. Platí pro
admin vytvoření obchodu, první klientský obchod i registraci. Další klientský
obchod převezme uložená metadata své přiřazené wallet z DB bez dalšího exportu.
Původní ownership podmínky INSERTů zůstaly zachované. Změna přiřazení během
provisioningu nesmí uložit veřejný klíč jiné wallet.

SQL default zůstává `electrum` pro neúplné historické řádky. To není preferovaná
business cesta: aplikací nově provisionovaná standardní wallet má skutečný XPUB.
Existující `xpub` store s chybějícím/neplatným klíčem dál končí chybou bez fallbacku.

## Rezervace indexů

Pouhý per-store čítač nestačí, pokud více obchodů sdílí stejnou wallet.
`DbAddressIndexStore` proto rezervuje index v krátké PDO transakci v persistentní
`xpub_address_sequences`. Identita vychází z veřejného klíče a chain code, takže
ani odlišný SLIP-0132 prefix stejného klíče nezaloží nový čítač. Zamyká se řádek
sekvence, nikoli Electrum wallet. Odlišné klíče mohou postupovat souběžně.

Při prvním použití se high water načte z existujících store čítačů pro aliasy klíče.
Další faktury tuto historickou inicializaci neopakují. `xpub_last_index` v obchodě
zůstává high water, ne počtem faktur obchodu. Sekvenci nesmazat při odstranění
obchodu. Neúspěšná/opuštěná rezervace může ponechat mezeru; její index se nevrací.
BIP32 non-hardened rozsah je kontrolovaný.

## Kdy se volá Electrum a kdy ne

| Cesta | Electrum | Exclusive wallet lock |
|---|---|---|
| XPUB invoice | Žádný RPC: store lookup, DB rezervace, lokální derivace, invoice insert | Žádný |
| Nová wallet | Jednorázové offline CLI create + veřejný export | Nový izolovaný soubor; žádný daemon RPC |
| Další obchod existující XPUB wallet | DB metadata | Žádný |
| Admin/client čtení již loaded wallet | list_wallets, balance/UTXO/history dle potřeby | Žádný pro čtení |
| První načtení nebo restart daemonu | list před lockem, znovu pod lockem, load pokud stále chybí | Per-wallet kolem ensure-loaded |
| Explicitní Electrum invoice fallback | ensure-loaded + createnewaddress | Společný per-wallet lock |
| Podpis/request/address mutace | Explicitní wallet path | Per-wallet kolem ensure-loaded + mutace |
| Checkout | Žádný RPC; DB read | Žádný |
| Stateless status | Walletless BlockchainProvider a cache | Žádný wallet lock |
| Explicitní repair | Jednorázový public RPC read konkrétní wallet | Pouze load, pokud není loaded; RPC mimo DB transakci |

`WalletLockManager` používá stejný hash kanonické cesty a stejný flock backend.
Všechny PHP entrypointy sdílející daemon potřebují stejné `BTCPAY_WALLET_LOCK_DIR`
a filesystem podporující sdílené flock. Žádný globální Electrum lock nebyl přidán.
Payout business lock, idempotency rezervace, worker lease a instalátorový DB lock
mají jiný účel a zůstávají. První load může legitimně čekat; běžný loaded GET
už nevstupuje do write locku, proto samotný load wrapper nevyvolá falešné Busy.

Runtime grep ověřil `withWalletLock`, `close_wallet`, `getmpk`, `new ElectrumRPC`
a všechny produkční INSERTy obchodů. Jediný přímý konstruktor RPC je factory.
`close_wallet` zůstal v explicitním maintenance API bez běžného produkčního volajícího.
Admin stále potřebuje balance, UTXO, historii a export/podpis; XPUB jej nenahrazuje.
Veřejný klíč UI skutečně zobrazuje. Dashboard má dosud opakované read seznamy adres
a dekóduje historii; zde nebyla zaváděna agresivní cache ani další UI refaktor.

Explicitní JSON parametr `wallet_path` zůstává. Aktuální upstream command wrapper
jej používá pro výběr wallet; URL query není jeho náhradou. Zdroje:
[Electrum JSON-RPC](https://electrum.readthedocs.io/en/latest/jsonrpc.html),
[commands.py](https://github.com/spesmilo/electrum/blob/master/electrum/commands.py),
[daemon.py](https://github.com/spesmilo/electrum/blob/master/electrum/daemon.py).
Verze daemonu na uživatelově localhost nebyla vzdáleně zjištěna.

## Instalace, migrace a explicitní repair

Pro novou DB použijte současný `sql.sql`, nebo `install.php` bez konfigurace.
Instalátor přijímá prázdnou DB i čistý současný import a vytvoří právě jednoho
aktivního admina se zvoleným emailem a heslem. Není přednastavený admin/password.
Používanou DB po smazání configu nepřevezme: je nutná původní konfigurace.

Při upgradu z e88594d nejprve aplikujte
[`006_shared_xpub_address_sequences.sql`](../migrations/006_shared_xpub_address_sequences.sql)
do stejné DB, na kterou ukazuje config. Nepouštějte celý sql.sql přes používanou DB.
Migrace je aditivní a opakovatelná, nevydává adresy a nemění existující stores.

Pro jednotlivý historický obchod:

```sh
php repair_store_xpub.php --store=store_ID
# Po pozastavení invoice/address writerů sdílejících danou wallet:
php repair_store_xpub.php --store=store_ID --apply --maintenance
```

První příkaz pouze prozkoumá jednu wallet a vypíše plán. Druhý ověří, že se řádek
od čtení nezměnil, zachová store/invoice/receive high water a v krátké transakci
nastaví XPUB metadata i sdílenou sekvenci. Nesmí nahrazovat jiný uložený klíč.
`--maintenance` je výslovné potvrzení provozovatele, že write provoz pozastavil;
příkaz sám web ani externí CLI procesy nevypíná. Neproběhla automatická migrace
všech wallet, scan uživatelova daemonu ani zápis do jeho lokální databáze.

## Ověření a commity

Celý lokální běh s MariaDB 10.11: **57 testovacích souborů prošlo, 0 selhalo**.
GitHub CI stejného zdrojového commitu `12306d7`:
[úspěšný běh](https://github.com/agp-l/BTCPayServerLite/actions/runs/34177553920).
Nové testy byly commitované před spuštěním podle požadavku uživatele.

- 100 paralelních XPUB invoices: 100 unikátních adres/indexů, zakázaný RPC i wallet lock.
- 20 paralelních invoices napříč dvěma stores se stejným XPUB: unikátní indexy, adresy a paths.
- 100 stejných idempotency keys: jediná invoice/adresa/rezervace a přesný replay.
- Reálné PHP procesy/HTTP JSON-RPC fixture: více loaded wallets, read při drženém
  mutation locku, dva souběžné první loady s jedním load RPC, žádný close.
- Reálná DB: tenant isolation, admin/client metadata, další klientský store a jeho zero-RPC invoice.
- Reálný CLI subprocess fixture: offline export, public-only result, neplatná větev,
  unsupported public key, bezpečný cleanup nového nepoužitého souboru.
- Instalátor: nová/prázdná/importovaná DB, admin, HTTP GET/CSRF/POST, zamknutí instalace.
- Původní worker/outbox crash boundary, stateless single-flight a checkout testy prošly.

| Commit | Obsah |
|---|---|
| `7989b14` | Instalátor, první admin a diagnostika |
| `e88594d` | Předchozí multi-wallet routing a tenant opravy |
| `6b74b80` | Již loaded read bez write locku |
| `0893a5c` | XPUB provisioning, metadata, sdílené DB sekvence |
| `1a60544` | Ověření receive větve, CLI repair |
| `dca767a` | Offline provisioning a concurrency testy |
| `12306d7` | Zachování invoice high water v repair a aktualizace load fixture |

## Provozní hranice a návazná práce

**Navazující aktualizace:** admin a instalovaná stateless tvorba jsou nyní připojené
ke společné sekvenci. Přibyl dávkový CLI sync worker a migrace 007. Pro aktuální
postup použijte [dokumentaci koordinace](RECEIVE_COORDINATION.md); následující popis
nezapojeného admin/stateless toku zaznamenává stav před touto aktualizací.


Testy používají skutečnou MariaDB a transport/procesy s deterministickými Electrum
fixtures; nejsou důkazem nasazení proti konkrétnímu uživatelskému daemonu.
Před použitím existující wallet ověřte její backup, script type a receive větev.
Repair CLI dosud nebyl spuštěn proti uživatelově instalaci.

Lokální derivace neprodlužuje automaticky Electrum gap limit ani daemonu neregistruje
každou adresu. Walletless monitoring funguje podle adres faktur, ale wallet balance,
UTXO a budoucí spending mohou vyžadovat zvláštní synchronizaci odvozeného rozsahu.
Na stejné receive větvi nemíchejte nezávislé externí/legacy Electrum address writery
s XPUB čítačem: daemon nezná dosud lokálně rezervované indexy. Admin `new_address`
a stateless `add_request` nejsou do DB XPUB sekvence zapojené. Pro takový souběh je
potřeba následná sjednocená správa receive rozsahu; prozatím oddělte wallets nebo
na XPUB wallet tyto writery nepoužívejte. Samotný výhradní wallet lock tento rozdíl
čítačů neřeší. Historické jiné zdroje adres nepřepínáme bez explicitního repair.

Budoucí směnárna potřebuje také samostatné řešení UTXO rezervací, obnovy odesílání
a účetnictví výběrů. Tento průchod nemění payout ledger ani neprohlašuje tuto
budoucí část za dokončenou. Omezení balance-based observation a late-payment
monitoringu zůstávají popsaná v [core dokumentaci](CORE_PAYMENT_ARCHITECTURE.md).
