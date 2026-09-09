# Přihlášení a zapamatování zařízení

Po aktualizaci kódu otevřete `database_upgrade.php` a spusťte migraci
`009_remembered_logins.sql`. Potom se jednou přihlaste s volbou
**Zůstat přihlášený na tomto zařízení 30 dní**. Volba platí pro admin i klienta.

Běžná relace končí po 8 hodinách nečinnosti nebo 12 hodinách od přihlášení.
Bez zaškrtnutí je cookie stále pouze relační. S volbou se po zániku PHP relace
při dalším GET/HEAD automaticky založí nová přihlášená relace. Zapamatování končí
30 dní od přihlášení heslem; návštěvy tento konec donekonečna neposouvají.

Nejde o dlouho platné PHP session ID. Oddělený token má náhodný selector a validator,
v DB je pouze hash validatoru. Při obnově se validator mění; předchozí hash je
uznán 30 sekund kvůli paralelním požadavkům, které nesmějí přepsat nový cookie.
Token je v HttpOnly/SameSite=Lax cookie (Secure při HTTPS). Nikde není heslo.

Obnova ověřuje aktivní účet, aktuální roli a session_version. Změna/reset hesla
nebo zneplatnění relací tak zruší i možnost obnovy. Odhlášení zruší daný device
token, session i cookie. Pokud při odhlášení selže DB, relace/cookie se odstraní,
ale aplikace výslovně hlásí, že DB token nedokázala odvolat.

POST požadavky nemohou obnovit přihlášení samy: při zaniklé relaci obnovte stránku
a formulář odešlete znovu. Kontrola CSRF a oprávnění se neobchází. Zapamatování
používejte na vlastním zařízení; export seedu a podpis nadále používají dosavadní
ověření hesla peněženky.

File sessions používají soukromý podadresář PHP session.save_path, rozlišený podle
instalace a procesového UID. GC lifetime odpovídá 12hodinové relaci. To izoluje
běžný úklid PHP od jiných aplikací se sdíleným úložištěm a kratší životností.
Externí správu Redis/Memcached handleru aplikace nepřepisuje. Při aktualizaci bude
potřeba jedno nové přihlášení kvůli změně umístění původních session souborů.

Pro veřejný provoz musí web správně rozpoznávat HTTPS i za reverzní proxy.
Na více webových uzlech je třeba sdílená správa PHP sessions; nový token v DB
nenahrazuje správnou konfiguraci load balanceru. Nepřihlašujte více instalací
na stejném hostname se stejnou cookie doménou/cestou bez oddělení cookies.

Ověření: unit a DB token testy, migrace z předchozího schématu a HTTP test ztráty
PHP session, rotace, odhlášení a odmítnutí automatické obnovy POST.
Zdroj návrhu: [PHP session security](https://www.php.net/manual/en/session.security.ini.php).
