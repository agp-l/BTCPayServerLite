# Závislosti a jejich údržba

Revize 5. října 2026. `composer.lock` obsahuje 11 runtime balíčků. Metadata
`vendor/composer/installed.json` mají stejné verze a source reference všech 11.
Composer validate a offline install dry-run prošly; nebyl změněn lockfile ani
kryptografická implementace. PHP 8.3 může v těchto knihovnách hlásit deprecations;
webový JSON nesmí být znečištěn display_errors (použít log/stderr).

| Balíček / pravidlo | Současný stav | Postup |
|---|---|---|
| shanelic/bitcoin-p8 v1.0.5 | Používaný Bitcoin stack pro XPUB/raw TX | Zachovat při stabilizaci; kompatibilní náhradu ověřit proti derivation a receipt fixtures. |
| mdanter/ecc v0.5.0 | Opuštěný; dvě zveřejněná upozornění na kryptografické side-channels | GHSA-346h-749j-r28w a GHSA-3494-cfwf-56hw / CVE-2024-33851. Upstream doporučuje paragonie/ecc; nelze jen přepsat závislost bez ověření API a PHP požadavků. |
| fgrosse/phpasn1 v2.0.2 | Composer jej označuje jako opuštěný | Zahrnout do stejného ověřeného upgrade Bitcoin stacku. |
| endroid/qr-code 4.7.0 | Připnutá verze, lokální SVG bez odesílání adresy třetí straně | Zachovat současnou kompatibilitu; upgrade společně s PHP support matrix. |
| Composer advisory blocking | Plošné block=false odstraněno | Budoucí resolution nesmí ignorovat všechny advisories; výjimka musí mít konkrétní důvod a rozsah. |
| Verzovaný vendor | Součást současné distribuce z rozhodnutí vlastníka | Neodstraňovat, dokud existuje ověřený release/Composer postup pro současné nasazení. Composer.lock je zdroj verzí, vendor se ručně neopravuje. |

Původní oznámení bezpečnostního týmu:
[Paragon Initiative, 24. 4. 2024](https://www.openwall.com/lists/oss-security/2024/04/24/4).
Advisory záznamy: [GHSA-346h-749j-r28w](https://github.com/advisories/GHSA-346h-749j-r28w),
[GHSA-3494-cfwf-56hw](https://github.com/advisories/GHSA-3494-cfwf-56hw).

Aplikační PHP nyní používá veřejnou XPUB derivaci a parsování transakcí; podpis
spendu provádí Electrum RPC. V `classes/` nebyl nalezen lokální ECDSA signer ani
odvozování private key ze seedu. To omezuje přímou relevanci popsaného secret
side-channel, **neprokazuje bezpečnost celého stacku** a není důvodem náhradu odkládat
při rozšiřování funkcí. Knihovnu pro tajné scalars nepoužívat v nových PHP funkcích.

Úplný `composer audit --locked` se v tomto prostředí nedokončil: Packagist API
vypršelo na síťovém timeoutu. Výše uvedená konkrétní upozornění byla ověřena
z primárního oznámení a veřejných advisory záznamů; nejde o úplný seznam všech
problémů. Na cílovém prostředí spustit:

```bash
composer validate --no-check-publish
composer check-platform-reqs --no-dev
composer audit --locked
```

Náhrada musí zachovat adresy pro xpub/ypub/zpub i testnet aliasy, všechny script
policies, společnou identitu sekvence, satoshi precision a TXID/raw serialization.
Doložit upgrade i rollback a nepřepočítávat již vydané adresy. Viz [ROADMAP](ROADMAP.md).
