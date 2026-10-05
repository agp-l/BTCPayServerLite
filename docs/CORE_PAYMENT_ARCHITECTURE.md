# Současná architektura platebního jádra

Revize 5. října 2026. Tento dokument popisuje současný kód; další práce je
pouze v [ROADMAP](ROADMAP.md). [README](../README.md) · [Kapacita](CAPACITY.md)

## Vlastníci operací

| Operace | Vlastník a invariant |
|---|---|
| XPUB adresa | `DbAddressIndexStore` / `WalletReceiveCoordinator`: lokální derivace, sdílená sekvence, žádný Electrum RPC |
| Legacy adresa | `ElectrumAddressGenerator`: explicitní wallet a per-wallet mutation lock |
| Pozorování adresy | `ElectrumReceiptBlockchainProvider`: walletless síťové RPC, cache, single-flight a společný budget |
| Invoice stav | `PaymentWorker` / `InvoiceStateMachine`: persistentní lease a atomický observation + status + outbox commit |
| API a DB checkout | Repository / `InvoicePaymentPresentation`: pouze čtení uloženého stavu, žádný blockchain RPC |
| Stateless status | Ověřený token + stejný provider; může obnovit cache, nemá DB invoice ani trvalé Settled |
| Webhook | `WebhookProcessor`: samostatné doručení, claim až před HTTP, stálý delivery ID při retry |
| Receive range | `WalletReceiveSyncWorker`: bounded doplnění již rezervovaných adres do konkrétní wallet |
| Podpis / spend | Explicitní Electrum wallet služba; oddělené od invoice settlement |

## Tvorba a souběžnost

XPUB identita vychází z public key a chain code, ne pouze z prefixu xpub/zpub.
Stejný klíč sdílí high water přes všechny stores i instalované admin/stateless
alokace. Generic xpub/tpub potřebuje explicitní script policy; její chybějící
hodnota nesmí potichu změnit typ adresy. Konfliktní konfigurace nebo změněný
store snapshot se odmítne před rezervací. Indexy se nevracejí ani po smazání store.

API idempotency autentizuje store před replay. Pending/Completed/Failed a trvalá
resource reservation zajišťují stejné invoice ID a adresu při opakování téhož
klíče/payloadu; jiný obsah vrací 409. Invoice insert a dokončená response jsou
v jedné DB transakci. Nejednoznačné legacy operace se automaticky neopakují.

Wallet mutace sdílejí flock podle kanonické explicitní cesty. Loaded read používá
read-only fast path; load se pod lockem znovu ověří. Běžná faktura ani checkout
nevolají close_wallet. Nezávislé wallet locky se nesmějí změnit na jeden globální
mutation lock. Všichni writers musí sdílet filesystem s funkčním flock a DB
sekvence. Externí GUI/CLI bez koordinátoru z téže receive větve adresy nevydává.

## Observation a omezení upstreamu

Provider používá `getaddresshistory`, nejvýše dva nové `gettransaction` a u
neprázdné historie `getaddressbalance`. Celkem nejvýše čtyři RPC na observation,
historie nejvýše 100 TX, raw hex nejvýše 2 000 000 znaků (1 MB). Parsování lokálně
ověří TXID, skript a částku. Cache raw transakcí dovolí postupný bounded progress;
neúplná/neplatná historie nesmí vytvořit falešný nulový snapshot ani settlement.

Potvrzené/nepotvrzené received outputs zahrnují i již utracené příjmy. Vlastní
change z invoice vstupů se znovu nepřičítá. Current balance a signed mempool
delta mají oddělený význam. Žádný historický balance se migrací nepřejmenuje na
received outputs. Agregátní snapshot není trvalý tx/vout účetní ledger.

Per-address cache/cooldown a invoice schedule mají 10/30/60 minut. Stejná adresa
se při souběhu neobnoví vícekrát. Endpoint budget ve stejné cache navíc omezuje
různé adresy: default 60 starts / rolling 60 s, max dva observers, krátký admission
flock bez síťového I/O uvnitř. Transport/auth/HTTP/protocol chyba otevře 60s pause.
Admission limit odloží worker o minutu bez smyšleného last_checked_at a ukončí
jeho dávku. Po pádu zůstane start započtený a active lease sám propadne.

Výchozí CLI dávka má 100 invoices / 45 s; observation musí celá zapadat do
zbývajícího rozpočtu (default max 35 s). Instance runner lock zabraňuje souběhu
CLI/admin dávek téže DB. To není wallet mutation lock. DB transakce se otevře
až po RPC, znovu ověří invoice token/lease a uloží observation, přechod i outbox.
Pád před commitem neponechá settlement bez události. Při chybě/propadnutí lease
nevlastnící proces nesmí změnu zapsat. [Cadence a provoz](PAYMENT_MONITORING.md).

## Platební politika a důvěra

| Stav | Povolený vývoj |
|---|---|
| New | Processing při partial/mempool, Settled při plném confirmed receipt, Expired při nezaplacené expiraci |
| Processing | Zůstává rozpracovaný, nebo Settled; nevrací se do New ani se nezruší ztrátou mempool platby |
| Expired | Pozdní Processing/Settled; automaticky 24 h po expiraci nebo déle při platební indikaci |
| Settled | Terminální; automaticky se znovu neskenuje |

Aktuálně stačí plná částka s kladnou výškou v Electrum historii. Nelze nastavit
3/6 potvrzení a walletless provider neověřuje Merkle proof / chain tip. Lokální
TXID kontrola dokazuje identitu transakce, ne její potvrzení v nejdelším chainu.
Před settlement se receipt historie znovu vyhodnocuje; pozdější reorg Settled
invoice současná politika neřeší. Tuto smlouvu neměnit zpětně bez návrhu politiky.

Neuhrazený Expired za 24h oknem lze explicitně zkontrolovat přes admin nebo
CLI `--invoice=ID`. Jen jeden řádek, stále lease/budget/min 10 min; chybějící ID
nespustí jinou dávku. Potvrzený late příjem používá stejný outbox. Stateless
nemá takový durable serverový stav; pro účetnictví e-shopu používejte DB invoices.

HTTP health / server info hlásí neznámou synchronizaci (`null`), bez RPC.
Payment-methods zobrazuje persisted partial/received/due a efektivní původní rate.
`payments: []` nevymýšlí jednotlivé transakce. Receiver ověří raw HMAC i aktuální
invoice v API; události se mohou opakovat nebo dorazit opožděně.

## Webhooky, receive a obnova

Webhook událost vzniká se změnou invoice v transakci. Processor ji claimne až
bezprostředně před HTTP, ne celou pomalou dávku najednou. Lease je 300 s, timeout
transportu 10 s, soft start budget cron dávky 45 s. Po pádu se obnoví stejné ID
a payload; receiver musí být idempotentní. Delivered/Dead a Retry nejsou invoice
stavy. Doručení může přijít vícekrát i po úspěšném HTTP, pokud se výsledek nezapsal.

Receive sync řeší viditelnost adres v Electrum wallet, nikoli invoice settlement.
Po restartu/timeoutu čte skutečný receive rozsah, DB progress je pouze vodítko.
Registered neznamená synchronized. Zálohujte sekvence, bindings, původní config
a wallet, ne jen seed. [Provoz a repair](RECEIVE_COORDINATION.md).

Migrations a source se nasazují pod údržbou dle [DATABASE_UPGRADE](DATABASE_UPGRADE.md).
Nová DB používá fresh sql.sql. Konfigurace, wallet, cache a zálohy nesmějí být
veřejné. Rozdělené local cache na více hostech neposkytují společný budget ani
single-flight; stejný název adresáře sám nic nesdílí.

Hlavní invoice tok existuje. Neuzavřené hranice: cílová kapacita/real Electrum,
nastavitelná confirmation/reorg politika, provisioning crash reconciliation,
receive/webhook observability a retence cache. Payouts navíc potřebují UTXO
rezervace, monitoring potvrzení a recovery testy; zůstávají výchozí vypnuté.
