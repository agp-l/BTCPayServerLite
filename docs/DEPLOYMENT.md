# Opakovatelné nasazení BTCPay Lite

Tento dokument nahrazuje ruční skládání kroků z chatu. Platí pro Linux a současné
PHP/MySQL/Electrum řešení. Neinstaluje nový serverový stack. Všechny soubory jsou
v Gitu; místní `config.php`, wallet soubory a databáze se do Gitu neukládají.

## 1. Zapište prostředí cílového serveru

Na současném počítači bylo ověřeno:

| Úloha | Hodnota |
|---|---|
| Projekt | `/opt/lampp/htdocs/BTCPayLite` |
| Web PHP | XAMPP 8.0.30, `/opt/lampp/etc/php.ini` |
| Webový systémový účet | `daemon` |
| CLI PHP | `/usr/bin/php8.3`, `/etc/php/8.3/cli/php.ini` |
| CLI worker účet | `ag` |
| Electrum CLI | `/opt/electrum/run_electrum` |
| Electrum data | `/opt/electrum_config` |
| Peněženky | `/opt/btcpay_wallets` |

Na novém serveru tyto hodnoty upravte. Uživatele běžícího Electrum daemonu
ověřte například `ps -eo user,args | grep '[r]un_electrum'`; nelze odvozovat
ze jména webového účtu. V níže uvedeném příkladu `--electrum-user=ag` nahraďte
skutečným účtem daemonu. Webový účet ukazuje `database_upgrade.php` po přihlášení
admina. Před instalací jej zjistíte z konfigurace vašeho Apache/PHP-FPM.

## 2. PHP a Composer

Ověřte obě PHP, která budete používat:

```bash
php -v
php --ini
php --ri gmp
/opt/lampp/bin/php -v
/opt/lampp/bin/php --ri gmp
```

Pro systémové PHP 8.3 na Debian/Ubuntu/Pop!_OS:

```bash
sudo apt update
sudo apt install php8.3-gmp php8.3-mysql php8.3-curl php8.3-xml php8.3-mbstring php8.3-zip acl
```

Číslo balíčku musí odpovídat používané verzi PHP. XAMPP používá vlastní binárky;
instalace `php8.3-gmp` neopraví jeho PHP 8.0.30. Pro zopakování opravy tohoto XAMPP:

```bash
sudo apt install build-essential autoconf libgmp-dev curl xz-utils acl
cd /opt/lampp/htdocs/BTCPayLite
sh bin/install_xampp_gmp.sh /opt/lampp
sudo /opt/lampp/lampp restartapache
```

Skript je záměrně omezený na 8.0.30: ověřuje SHA-256 zdrojů, použije XAMPP
phpize/php-config, otestuje modul před instalací a před změnou php.ini vytvoří
zálohu. Při již zapnutém GMP nic neinstaluje. Jde o reprodukci stávajícího
prostředí, nikoli doporučení staré PHP verze pro nový veřejný server.
Pro jinou verzi použijte odpovídající balíček nebo zdroje a vývojové nástroje
přesně té verze. Nekopírujte gmp.so mezi PHP 8.3 a 8.0.

V adresáři projektu jako jeho správce (bez sudo):

```bash
composer install --no-dev --prefer-dist
composer check-platform-reqs --no-dev
```

`install` používá composer.lock. Nepoužívejte `composer update` ani
`--ignore-platform-req=ext-gmp` jako opravu chybějícího GMP. Ověření Composerem
platí pro PHP, pod kterým jste ho spustili; webové PHP ověřte zvlášť diagnostikou.

## 3. Electrum a databáze

Nainstalujte a zaznamenejte konkrétní verzi Electra a jeho Python závislosti podle
[oficiálního projektu](https://github.com/spesmilo/electrum#development-version-git-clone).
Pokud používáte virtualenv, `electrum_cli_path` musí ukazovat na spustitelný soubor
nebo wrapper, který použije tento interpret i z Apache. Aktivace virtualenv pouze
v terminálu není konfigurací webového serveru. Zachovejte funkční verzi a její
instalační postup v provozních záznamech; tento nástroj Python ani daemon neinstaluje.

Nová instance: omezte přístup k instalátoru na správce, vytvořte prázdnou databázi,
povolte PHP dočasně vytvořit config.php a spusťte `install.php`. Vyplníte vlastní
první admin účet; výchozí heslo neexistuje. Po instalaci odeberte dočasné oprávnění
zápisu do zdrojového kódu. Cesty Electra a wallet adresáře uložte do config.php.

Přenos existující instance: obnovte zálohu DB, **původní config.php včetně secret_key**
a wallet soubory. Nová instalace klíčů není obnova. Wallet cesty musí souhlasit i
s uloženými vazbami. Před přesunem cest naplánujte jejich konzistentní migraci;
pouhá změna config.php nepřepíše DB registry.

Aktualizace existující DB: exportujte zálohu, zastavte zápisy/workery a použijte
`database_upgrade.php` dle [návodu migrací](DATABASE_UPGRADE.md). Celé sql.sql patří
jen do nové prázdné DB. Aktualizátor porovnává podporované části schématu a ukazuje
rozsah kontroly; nenahrazuje datovou migraci nebo obnovu ze zálohy.

## 4. Oprávnění: vygenerovat, zkontrolovat, spustit

Po vytvoření config.php generátor převezme cesty přímo z něj. Účty vždy zadejte
podle serveru. Spouštějte generátor pod běžným správcem projektu; sudo je potřeba
až pro výsledný shell skript, nikoliv pro vykonání PHP konfigurace.

```bash
cd /opt/lampp/htdocs/BTCPayLite
umask 077
permissions_file=$(mktemp /tmp/btcpay-permissions.XXXXXXXX.sh)
php bin/deployment.php --permissions --web-user=daemon --worker-user=ag --electrum-user=ag > "$permissions_file" && cat "$permissions_file"
```

Po kontrole zobrazených cest a účtů, ve stejném terminálu:

```bash
sudo sh "$permissions_file"
rm -- "$permissions_file"
```

Pokud čerstvý instalátor vytvořil config.php pod účtem `daemon` s režimem 0600,
správce `ag` jej ještě nepřečte. V kroku generování použijte
`sudo -u daemon /usr/bin/php8.3 bin/deployment.php --permissions --web-user=daemon --worker-user=ag --electrum-user=ag > "$permissions_file"`
a pokračujte kontrolou a spuštěním skriptu. PHP konfigurace se tím vykoná pod
webovým účtem, nikoliv jako root; výsledný plán přidá CLI účtu právo čtení.

Skript lze opakovat. Povolí přístup ke stávajícím zámkům i dědění ACL nových souborů;
stejně připraví sdílenou blockchain cache a managed wallet adresář. Nemění vlastníka,
nemaže lockfile, nemění SQL a nevolá Electrum. Přístup do daemon dat je pro PHP jen
ke čtení; offline vytváření wallet má vlastní dočasný adresář. Zvoleným účtům se
udělí přístup k peněženkám, proto sem nepatří jiné účty ani e-maily klientů.

Při vlastním `BTCPAY_WALLET_LOCK_DIR` / `BTCPAY_BLOCKCHAIN_CACHE_DIR` musí mít web,
CLI i generátor stejné hodnoty. Nesdílené mounty nebo odlišné adresáře lock doménu
nespojí. Pokud rodičovská cesta nemá právo průchodu, zkontrolujte `namei -l CESTA`;
generátor nemění oprávnění celé `/opt`, domovských adresářů ani všech wallet souborů
rekurzivně. Ručně pojmenované wallet soubory mimo default config vyžadují vlastní ACL.

## 5. Kontrola a provoz

```bash
php bin/deployment.php --check
/opt/lampp/bin/php bin/deployment.php --check
php wallet_receive_sync.php --check-db
```

První dvě kontroly nevytvářejí peněženku ani nevolají RPC. Vypisují skutečné PHP,
php.ini, účet, hash composer.lock a zjištěné problémy. Nulový exit code potvrzuje
jen popsané lokální kontroly, nikoli funkční DB, daemon, oprávnění jiného účtu nebo
zabezpečení webserveru. Otevřete také `database_upgrade.php`: webové prostředí se
může lišit od obou CLI kontrol. Kontrola zámků ověřuje práva, nikoli jejich obsazenost.

Po spuštění daemonu můžete cíleně ověřit jeho verzi pod webovým účtem:

```bash
sudo -u daemon /opt/electrum/run_electrum -D /opt/electrum_config version
```

Tento příkaz komunikuje s daemonem a může vyžadovat přístup k jeho lockfile.
Nepřidávejte zde `--offline`; Electrum offline příkazy ve stejném datovém adresáři
odmítá, pokud tam existuje daemon lock. Aplikace pro offline provisioning používá
izolovaný adresář. Pro kontrolu verze nikdy nepotřebujete tisknout seed.

Naplánujte pod zvoleným worker účtem pravidelné CLI spouštění (např. každou minutu,
přizpůsobte požadované latenci a dávkám):

```cron
* * * * * /usr/bin/php8.3 /opt/lampp/htdocs/BTCPayLite/payment_worker.php
* * * * * /usr/bin/php8.3 /opt/lampp/htdocs/BTCPayLite/webhook_cron.php
* * * * * /usr/bin/php8.3 /opt/lampp/htdocs/BTCPayLite/wallet_receive_sync.php
```

Zajistěte zachycení výstupů/chyb cronem a rotaci případných logů. Receive worker
zpracovává registrované XPUB rozsahy, neobjevuje automaticky všechny daemon wallets.
Ověřte vytvoření obchodu, adresy, faktury a průchod platby/webhooku v testovacím
prostředí. Pro veřejné nasazení nakonfigurujte HTTPS a ochranu config, wallet,
záloh a runtime adresářů podle použitého webserveru.

## Při každém dalším nasazení

Uchovejte mimo veřejný web zálohy config/DB/wallet, použitý commit (`git rev-parse HEAD`),
verzi Electra a výstup `php bin/deployment.php --check`. Nástroj nevypisuje hesla
ani klíče, ale obsahuje místní cesty a účty. Na cílovém serveru znovu vytvořte plán
oprávnění z jeho konfigurace; starý skript nekopírujte slepě na jiné cesty.

Zdroje: [PHP phpize](https://www.php.net/manual/en/install.pecl.phpize.php),
[PHP php-config](https://www.php.net/manual/en/install.pecl.php-config.php),
[Composer CLI](https://getcomposer.org/doc/03-cli.md#check-platform-reqs),
[Electrum CLI](https://electrum.readthedocs.io/en/latest/cmdline.html).

### Dohled a systemd timer pro platby

Admin **Nástroje → Kontrola plateb** ukazuje skutečné běhy a umožní ruční kontrolu.
Generátor `bin/deployment.php --payment-systemd=service|timer` a přesné instalační
kroky jsou v [Kontrole plateb](PAYMENT_MONITORING.md). Nezapínejte cron i timer současně.
