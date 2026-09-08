# Obchod nelze vytvořit

Obecná hláška sama nepotvrzuje chybu v SQL. Nový systémový nebo první klientský
obchod může nejprve spustit offline Electrum CLI, načíst veřejný klíč a ověřit
přijímací adresy, teprve potom uložit obchod. Další klientský obchod s existující
peněženkou používá její uložené nastavení bez provisioning RPC.

## Nasazení a kontrola skutečného webového prostředí

Po aktualizaci kódu spusťte v kořeni projektu:

```sh
git pull --ff-only
composer install --no-dev --prefer-dist
```

Použijte existující composer.lock, nikoli composer update či ignorování platform
requirements. Knihovny bitcoin-p8/ECC nejsou všechny uložené v Git repozitáři.
Pouhé stažení PHP souborů nedoplňuje Composer závislosti.

Přihlaste se jako admin a otevřete `database_upgrade.php`, sekci **Prostředí pro
vytváření obchodů**. Ukáže skutečné webové PHP, načtené php.ini, uživatele procesu,
GMP, Composer knihovny, dostupnost proc_open a nakonfigurovaných cest. Tato kontrola
nevytváří peněženku, nespouští Electrum a nemění oprávnění adresářů.

Úspěch `php` v terminálu nezaručuje stejné prostředí Apache/XAMPP. Například
`/usr/bin/php8.3` může načítat jiné moduly než webové PHP z `/opt/lampp`.
Rozšíření GMP musí mít PHP, které skutečně vykonává aplikaci. Po jeho správné
instalaci/povolení v daném prostředí restartujte webový server. Verzi a cestu
neodhadujte z příkazu `php --ini`; použijte výpis přímo na administrační stránce.

## Kategorie chyby

- `xpub_gmp_missing`: chybí GMP ve vykonávajícím PHP.
- `xpub_dependencies_missing`: chybí načitatelné bitcoin-p8/ECC knihovny; Composer install.
- `process_disabled`: webové PHP zakazuje proc_open.
- `electrum_executable`: electrum_cli_path není spustitelný pro uživatele PHP.
- `electrum_data_directory` / `wallet_directory`: ověřte cestu a oprávnění skutečného
  uživatele PHP i průchod nadřazenými adresáři. Neřešte to plošným chmod 777.
- `electrum_create_failed`: proces CLI selhal; může jít o Python závislosti či
  oprávnění mimo samotný wallet adresář. Stav read-only kontroly cest není důkaz
  úspěšného spuštění Pythonu/Electra.
- `wallet_public_metadata`: neprošlo offline getmpk/listaddresses nebo ověření XPUB
  přijímací větve. Neupravujte naslepo XPUB ani čítač adres v DB.
- `database_1054` / `database_1146`: chybí sloupec/tabulka, zkontrolujte migrační stránku.
- `database_1452`: nelze uložit referenční vazbu; zkontrolujte existenci uživatele
  a jeho přiřazení, nikoli vypínání foreign_key_checks.

Když problém přetrvá, sdílejte kategorii a neúspěšné body diagnostiky; neposílejte
seed, soukromé klíče, obsah config.php ani raw výstup příkazu Electrum create.
Admin i klient nyní dostávají bezpečný popis příčiny namísto anonymní hlášky.

Kontrola závislostí probíhá před vytvořením nového wallet souboru. Instalátor nově
vyžaduje GMP a XPUB knihovny také. Stávající [Electrum CLI postup](https://github.com/spesmilo/electrum/blob/master/run_electrum)
a [veřejné příkazy create/getmpk/listaddresses](https://github.com/spesmilo/electrum/blob/master/electrum/commands.py)
jsou zachované; bez důkazu nebyla přidána změna hesel nebo RPC fallback.
