# Cílená revize návrhu z 9. září 2026

Návrh byl porovnán se skutečnými call-sites, nikoli převzat jako hromadný refaktor.
Prioritou zůstalo dokončení uživatelem požadovaného zapamatování přihlášení.

## Opraveno

- Generic xpub/tpub bez explicitního script type nyní selže před rezervací indexu.
  ypub/upub/zpub/vpub mohou samostatně odvodit politiku z prefixu. XPUB store musí
  mít explicitní platnou politiku vždy, včetně zpub. Factory, registry a metadata
  odmítají prázdnou/neplatnou konfiguraci; nevzniká tichý Electrum fallback.
- Electrum ProvisionedWallet má nadále explicitní xpub/tpub=p2pkh a ověřuje
  výsledné adresy wallet. Externí xpub lze explicitně použít pro všechny tři
  podporované typy; nepřepsali jsme existující DB hodnoty.
- Legacy repair odmítá rozdílnou explicitní politiku již XPUB store. Po GREATEST
  upsertu znovu čte zamčenou shared sekvenci, takže uložený/store/reportovaný floor
  zahrnuje i dřívější vyšší globální index. Již před opravou se shared index nesnižoval.

## Ověřené existující cesty

| Cesta | RPC a zámky |
|---|---|
| XPUB invoice | Lokální derivace a DB sekvence; 0 Electrum RPC, 0 wallet locks. |
| Admin wallet GET, wallet již loaded | list_wallets fast path; getbalance, listaddresses receiving/change, listunspent, onchain_history, podle historie gettransaction, getmpk. Bez mutation locku. |
| Admin nová adresa s XPUB vazbou | Lokální alokace přes WalletReceiveCoordinator. Zbytek HTTP stránky načítá běžná wallet data. |
| Admin nová adresa bez XPUB vazby | Per-wallet lock, ensureLoaded, createnewaddress. Není to automatický XPUB repair. |
| Legacy Electrum invoice | AddressGenerator vlastní per-wallet lock kolem ensureLoaded a mutující RPC. |
| Stateless legacy request | Per-wallet lock pro ensureLoaded + add_request; provider status je walletless. Explicitní legacy status má krátký ensureLoaded guard. |
| Receive sync | Per-wallet lock pro load/check XPUB/receive range + omezené createnewaddress. DB progress je jen vodítko; live rozsah se vždy ověřuje. |
| Payout | Per-wallet lock při přípravě/podpisu; broadcast a stávající stavové/idempotency hranice zachovány. |

Statický průchod withWalletLock: ElectrumAddressGenerator, stateless request/create
/delete, skutečný load v ElectrumWallet, dashboard send, PayoutService, receive sync
jsou mutace nebo příprava mutace. ElectrumWalletManager.closeWallet je explicitní
maintenance. `close_wallet` není na běžné invoice, checkout ani admin GET cestě.

DbAddressIndexStore sdílí sekvenci pro SLIP-0132 aliasy klíče mezi stores.
ReceiveCoordinationTest provádí smíšené souběžné admin/API/stateless alokace a
kontroluje nulové RPC/locks. MultiWalletRoutingTest a MultiTenantWalletTest pokrývají
loaded read fast-path, odlišné wallet locky a současně loaded wallets.

## Co není touto revizí slibováno

- Reálný daemon uživatele nebyl ovládán z tohoto prostředí. Python, oprávnění,
  web/CLI GMP, cron a existující data stále vyžadují deployment kontrolu.
- Některé admin read RPC se opakují v addresses/transactions. Request snapshot je
  možná další optimalizace; neblokuje správnou alokaci a nebyl zaváděn v této změně.
- Při pádu/timeoutu samotného Electrum create může zůstat nepřiřazený nový wallet
  soubor. Automatické mazání po nejednoznačném výsledku vyžaduje prokázání vlastnictví
  souboru; nebylo rozšířeno na slepé mazání. Po chybě public metadata se fresh wallet
  uklízí, existující wallets se odmítají přepsat. Toto zůstává samostatná práce.
- WalletBusyException stále zahrnuje nedostupný lock soubor i obsazený zámek.
  Action-specific diagnostika a deployment kontrola rozlišují potřebné provozní
  kroky; samotná exception hierarchie nebyla měněna.

Změny script policy vyžadují správné existující xpub_script_type. Chybné hodnoty
ručně neodhadujte a nikdy nemažte sekvence již vydaných adres.

## Výsledek ověření

- PASS: kompletní sada 64 souborů s MariaDB před závěrečnou opravou repair floor.
- PASS: nový LegacyRepairPolicyTest spouští skutečné opravné CLI proti MariaDB
  s nahrazenou pouze live wallet inspekcí; floor 100 zůstane 100 při receive count 2,
  rozdílná script policy se neuloží. Celkem nyní 65 testovacích souborů.
- PASS: PHP syntaxe 245 souborů před přidáním závěrečného spuštěného repair testu.
- SKIPPED: živý uživatelův Electrum a změny jeho produkčních dat.
