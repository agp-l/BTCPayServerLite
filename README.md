# BTC Pay Server Lite

Lehká samoobslužná Bitcoinová platební brána v PHP nad Electrum daemonem. Projekt poskytuje databázové Greenfield API, stateless faktury, checkout, administrační rozhraní a spolehlivé doručování podepsaných webhooků.

> Stav dokumentace: větev `develop/url-routing` po vývojovém refaktoru URL a front controlleru, 31. srpna 2026. Označení „auditováno“ níže znamená, že komponenta prošla samostatnou kontrolou, kontraktními testy a provozním smoke testem tam, kde byl potřeba. Neznamená to, že je dokončený audit celého webového projektu.

## Aktuální stav auditu

### Dokončené oblasti

- transport Electrum JSON-RPC a strukturované chyby,
- práce s Electrum peněženkou,
- přesná reprezentace BTC částek bez `float` aritmetiky v platebním jádru,
- životní cyklus databázových a stateless faktur,
- databázová vrstva, transakce a pojmenované zámky,
- databázové Greenfield API včetně autentizace a store scopingu,
- stateless HTTP API,
- persistentní webhook outbox, retry/backoff, HMAC podpisy a ochrana proti SSRF/DNS rebindingu,
- autentizace `webhook_cron.php`,
- přihlášení, registrace, session/cookie politika, CSRF, role a persistentní throttling,
- důvěryhodný aplikační origin, striktní parsování URL a deklarativní routing,
- skutečné HTTP odpovědi 400/404/405/500 a kanonické interní odkazy,
- databázové preflighty a migrace pro auditované části.

V adresáři `classes/` je 33 tříd a dvě rozhraní. Fokusovaným auditem nebo samostatným vývojovým refaktorem prošlo 34 komponent. Zbývá jedna původní UI třída:

- `BtcDashboard`.

### Hlavní soubory mimo `classes/`

| Soubor / oblast | Stav | Poznámka |
|---|---|---|
| `api.php` | Auditováno | Databázové Greenfield API, Bearer autentizace, přesné částky, store scoping a bezpečné chyby. |
| `api_stateless.php` | Auditováno | Stateless API, omezení vstupu, autentizace, zámek Electrumu a bezpečné HTTP odpovědi. |
| `webhook_cron.php` | Auditováno | Pouze CLI nebo HTTP `POST` s Bearer tokenem; používá persistentní outbox. |
| `.htaccess` | Auditováno pro API | Předávání hlavičky `Authorization` do PHP. |
| `admin/webhooks.php` | Částečně auditováno | Vstup vyžaduje admin roli a registrace používá společnou URL policy; audit celého formuláře a jeho mutací ještě čeká. |
| `checkout/pay.php` | Čeká | Veřejná platební stránka a její výstupní/HTTP hranice nebyly samostatně auditovány. |
| `index.php` | Přepracováno a auditováno | Tenký front controller, deklarativní router, role, bezpečný výběr handleru, kanonické redirecty a jednotné HTTP chyby. |
| `admin/*.php`, `admin/views/` | Částečně auditováno | Přímé vstupy do kontrolovaných admin stránek vyžadují admin roli; CSRF, XSS, validace formulářů a citlivá data ještě čekají. |
| `client/login.php`, `client/registrace.php` a session | Auditováno | CSRF, bezpečné chyby, throttling, cookie/session limity, regenerace ID a POST logout. |
| `client/index.php`, ostatní client views | Částečně auditováno | Role a CSRF hranice mutací jsou hotové; širší audit dashboardu, objektové autorizace a výstupů ještě čeká. |
| `config.php` a deployment | Čeká | Správa tajemství, oprávnění souboru, produkční hodnoty a oddělení prostředí. |
| `sql.sql` | Částečně auditováno | Obsahuje nové schéma; čistá instalace a upgrade z více historických verzí ještě potřebují samostatný test. |
| testovací a pomocné skripty | Čeká | Staré `test_*.php`, simulátory a případné veřejné diagnostické soubory je nutné inventarizovat a odstranit nebo uzamknout. |

## Architektura

Projekt používá Composer PSR-4 autoloading:

```json
{
  "BtcPayLite\\": "classes/"
}
```

Zjednodušený tok databázové faktury:

```text
e-shop
  -> api.php
  -> GreenfieldApiController
  -> GreenfieldApiService
  -> GreenfieldApiRepository / BtcInvoiceManager
  -> Database + ElectrumWallet
  -> ElectrumRPC
  -> Electrum daemon
```

Tok webhooku:

```text
webhook_cron.php
  -> WebhookCronController
  -> WebhookCronApplication
  -> WebhookProcessor
  -> WebhookDeliveryRepository
  -> webhook_deliveries (persistentní outbox)
  -> CurlWebhookTransport
  -> e-shop
```

Událost se nejprve uloží do databáze a až potom odešle. Souběžné workery používají atomické přidělení práce. Dočasné chyby se opakují s prodlužujícími se intervaly; trvalé chyby skončí ve stavu `Dead`. Nově registrovaný webhook nedostává události pro faktury vytvořené před jeho registrací.

## Kompletní přehled tříd

### Základní infrastruktura a Bitcoin

| Komponenta | Odpovědnost | Stav auditu |
|---|---|---|
| `BitcoinAmount` | Neměnná přesná BTC částka uložená v satoshi; převod BTC řetězce, formátování, porovnání a bezpečné sčítání/odčítání. | Auditováno |
| `Database` | Vytváří bezpečné PDO připojení, poskytuje transakce a pojmenované databázové zámky. | Auditováno |
| `DatabaseException` | Strukturovaná chyba databázové operace bez vystavování citlivých detailů. | Auditováno |
| `ElectrumRPC` | Validovaný JSON-RPC 2.0 transport s timeouty, autentizací, kontrolou HTTP/protokolu a wallet scopingem přes `wallet_path`. | Auditováno |
| `ElectrumRPCException` | Rozlišuje transportní, autentizační, HTTP, protokolové a vzdálené RPC chyby. | Auditováno |
| `ElectrumWallet` | Typovaná vrstva nad Electrum RPC: načtení peněženky, zůstatky, adresy, UTXO, transakce, payment requesty a odesílání. | Auditováno |
| `ElectrumWalletException` | Přidává kontext konkrétní operace peněženky. | Auditováno |

### Faktury a stateless režim

| Komponenta | Odpovědnost | Stav auditu |
|---|---|---|
| `BtcInvoiceManager` | Vytváří a načítá databázové faktury, vytváří stateless faktury a bezpečně mění stavy `New`, `Processing`, `Settled`, `Expired`. | Auditováno |
| `BtcInvoiceManagerException` | Strukturovaná chyba životního cyklu faktury. | Auditováno |
| `BtcStatelessTokenCodec` | Podepisuje a ověřuje časově omezené stateless tokeny a odmítá změněný nebo prošlý obsah. | Auditováno |
| `BtcStatelessService` | Aplikační logika stateless API a platební stránky; ověřuje klienta, wallet mapping, vstupy a stav platby. | Auditováno |
| `BtcStatelessServiceException` | Chyba stateless operace s bezpečným HTTP statusem a názvem operace. | Auditováno |
| `BtcStatelessApiController` | HTTP hranice stateless API: metoda, JSON/form data, Bearer token a normalizovaná odpověď. | Auditováno |
| `BtcStatelessAjaxController` | HTTP/AJAX hranice pro kontrolu stavu stateless faktury. | Auditováno |

### Databázové Greenfield API

| Komponenta | Odpovědnost | Stav auditu |
|---|---|---|
| `GreenfieldApiController` | Parsuje bezpečnou cestu požadavku, metodu, JSON a Bearer token; routuje store, invoice a webhook endpointy. | Auditováno |
| `GreenfieldApiService` | Autentizace admin/store klíče, store scoping, přesné částky, vytvoření/načtení faktury a registrace webhooku. | Auditováno |
| `GreenfieldApiRepository` | Úzká PDO hranice pro načtení obchodu a idempotentní registraci webhooku s časem registrace. | Auditováno |
| `GreenfieldApiException` | Bezpečná API chyba s operací a HTTP statusem. | Auditováno |

### Webhooky

| Komponenta | Odpovědnost | Stav auditu |
|---|---|---|
| `WebhookTransport` | Rozhraní jedné podepsané webhook delivery. | Auditováno |
| `CurlWebhookTransport` | cURL transport s omezenými timeouty, bez redirectů, TLS kontrolou, připnutou DNS adresou a úspěchem pouze pro HTTP 2xx. | Auditováno |
| `WebhookEndpointPolicy` | Validuje URL a DNS odpovědi; blokuje credentials, vzdálené HTTP, privátní/rezervované IP a smíšené DNS. Localhost vyžaduje explicitní vývojový opt-in. | Auditováno |
| `WebhookDeliveryRepository` | Persistentní outbox: vyhledání faktur, vytvoření eventu, atomický claim, obnova uvízlého claimu a stavy `Delivered`, `Retry`, `Dead`. | Auditováno |
| `WebhookProcessor` | Kontroluje faktury přes sdílený Electrum zámek, ukládá eventy, podepisuje payloady a řídí retry/backoff. | Auditováno |
| `WebhookCronApplication` | Sestavuje databázi, Electrum, repository, policy, transport a processor z validované konfigurace. | Auditováno |
| `WebhookCronController` | Autentizuje HTTP cron (`POST` + Bearer), dovoluje CLI a vrací jednotný report bez interních chyb. | Auditováno |
| `WebhookDeliveryException` | Nese operaci, retryable příznak a případný HTTP status delivery chyby. | Auditováno |

### UI, autentizace a URL

| Komponenta | Odpovědnost | Stav auditu |
|---|---|---|
| `AuthManager` | Přihlášení, registrace, odhlášení, bezpečné session/cookies, CSRF, časové limity, role a throttling. | Auditováno |
| `AuthUserRepository` | Rozhraní úzké persistence uživatelů a autentizačních pokusů. | Auditováno |
| `PdoAuthUserRepository` | PDO implementace explicitních auth dotazů, binárních identit throttlingu a omezeného úklidu pokusů. | Auditováno |
| `AuthException` | Bezpečná doménová chyba autentizace bez úniku interních detailů. | Auditováno |
| `BtcDashboard` | Připravuje peněženku, adresy, transakce, poplatky, fiat ceny a export klíčů pro administrační UI. | Čeká na fokusovaný audit |
| `UrlManager` | Odděluje důvěryhodný origin od request URI, validuje Host/app_url, dekóduje bezpečné segmenty a skládá kanonické interní URL. | Přepracováno a auditováno |
| `ApplicationRouter` | Deklarativní tabulka přesných cest, HTTP metod, handlerů, aktivního menu a požadovaných rolí. | Přepracováno a auditováno |
| `ApplicationRoute` | Neměnný výsledek routingu pro handler nebo interní redirect. | Přepracováno a auditováno |
| `RouterException` | Bezpečná routovací chyba s HTTP statusem a seznamem metod pro hlavičku `Allow`. | Přepracováno a auditováno |

## Front controller a čisté URL

Hlavní `index.php` používá přesné route bez prefixového porovnávání. Aliasové cesty `/home`, `/prezentace`, `/dashboard` a `/admin` vracejí kanonický redirect. Neznámá cesta vrací 404; známá cesta s nepovolenou metodou vrací 405 a hlavičku `Allow`.

`app_url` je důvěryhodný origin aplikace a má zahrnovat i instalační podadresář, například `http://localhost/BTCPayLite`. Je-li nastavený, odkazy ani redirecty nepoužívají klientský Host header. Routovací segmenty zůstávají daty a HTML escapování se provádí až ve view.

## HTTP vstupní body

### `api.php` – databázové API

Podporované cesty:

- `GET /api/v1/stores/{storeId}`
- `POST /api/v1/stores/{storeId}/invoices`
- `GET /api/v1/stores/{storeId}/invoices/{invoiceId}`
- `POST /api/v1/stores/{storeId}/webhooks`

Autentizace používá `Authorization: Bearer ...`. Částka faktury musí být JSON řetězec s přesnou BTC hodnotou, například `"0.00000001"`; JSON číslo se záměrně odmítá kvůli riziku `float` nepřesnosti.

### `api_stateless.php`

Vytváří stateless fakturu bez databázového invoice záznamu. Přístup je omezen konfigurovanými API klienty a operace s Electrumem používají lokální procesní zámek.

### `webhook_cron.php`

- CLI spuštění nepotřebuje HTTP credentials.
- Webové spuštění povoluje pouze `POST` s `Authorization: Bearer <cron_key>`.
- Query-string klíče a `GET` nejsou podporované.

## Konfigurace

`config.php` není verzovaný a nesmí se zveřejnit. Používané klíče zahrnují:

```php
return [
    'rpc_host' => '127.0.0.1',
    'rpc_port' => 7777,
    'rpc_user' => '...',
    'rpc_pass' => '...',
    'db_host' => '127.0.0.1',
    'db_port' => 3306,
    'db_name' => '...',
    'db_user' => '...',
    'db_pass' => '...',
    'admin_api_key' => '...',
    'secret_key' => '...',
    'cron_key' => '...',
    'app_url' => 'https://pay.example.com',
    'api_clients' => [
        'client-bearer-token' => 'wallet-id',
    ],
];
```

`app_url` nastavte explicitně ve všech nasazeních; nesmí obsahovat credentials, query ani fragment. Volitelný `allow_local_webhooks => true` je určen pouze pro lokální vývoj. V produkci jej vynechte nebo ponechte `false`.

Peněženky musí být mimo web root, například v `/opt/btcpay_wallets/`. Electrum RPC port nemá být veřejně dostupný.

## Databáze a migrace

Produkční schéma používá tabulky `users`, `auth_attempts`, `stores`, `invoices`, `webhooks` a `webhook_deliveries` s indexy a cizími klíči.

Audit přidal tyto jednorázové migrace a read-only kontroly:

- `migrations/20260830_database_preflight.sql`
- `migrations/20260830_harden_database_schema.sql`
- `migrations/20260831_webhook_delivery_preflight.sql`
- `migrations/20260830_add_webhook_deliveries.sql`
- `migrations/20260831_auth_preflight.sql`
- `migrations/20260831_add_auth_attempts.sql`

Každou migrační SQL spusťte nejvýše jednou a až po záloze databáze. Preflight musí skončit bez problémů. Migrační soubory nejsou obecný idempotentní instalační skript.

## Instalace a požadavky

- PHP 8.0 nebo novější,
- rozšíření cURL, JSON a PDO MySQL,
- MariaDB 10.4+ nebo kompatibilní MySQL,
- Composer,
- běžící Electrum daemon s JSON-RPC,
- Apache/Nginx nakonfigurovaný tak, aby PHP dostalo hlavičku `Authorization`.

```bash
composer install
composer dump-autoload
```

Adresáře s peněženkami a zálohami databáze držte mimo `htdocs` a mimo Git.

## Testy

Kontraktní testy jsou samostatné PHP skripty:

```bash
php tests/BitcoinAmountTest.php
php tests/ElectrumWalletTest.php
php tests/BtcInvoiceManagerTest.php
php tests/BtcStatelessServiceTest.php
php tests/DatabaseTest.php
php tests/GreenfieldApiTest.php
php tests/WebhookEndpointPolicyTest.php
php tests/WebhookCronControllerTest.php
php tests/WebhookProcessorTest.php
php tests/WebhookDeliveryRepositoryQueryTest.php
php tests/AuthManagerTest.php
php tests/AuthRepositoryQueryTest.php
php tests/AuthHttpBoundaryTest.php
php tests/UrlManagerTest.php
php tests/ApplicationRouterTest.php
php tests/FrontControllerBoundaryTest.php
php tests/RouteLinkBoundaryTest.php
```

Vedle testů je před nasazením nutný smoke test proti skutečné testovací databázi a Electrum daemonu. Testovací webhooky nikdy nesměřujte na produkční příjemce.

## Bezpečnostní zásady

- Neukládejte seed, xprv, hesla ani API klíče do Gitu nebo logů.
- Nevystavujte Electrum RPC ani adresář peněženek do internetu.
- Webhook secret používejte k ověření hlavičky `Btcpay-Sig` nad nezměněným raw JSON tělem.
- `allow_local_webhooks` nepovolujte v produkci.
- Cron nespouštějte přes URL query parametr; používejte CLI nebo Bearer hlavičku.
- Před migrací vždy vytvořte a ověřte obnovitelnou databázovou zálohu.
- Neošetřené výjimky a odpovědi Electrumu neposílejte klientům.
- Přihlášení a registrace musí používat CSRF token; odhlášení je pouze přes `POST`.
- Session cookie je `HttpOnly`, `SameSite=Lax` a na HTTPS také `Secure`; session má idle i absolutní limit.

## Doporučené pořadí dalšího auditu

1. `BtcDashboard` a `admin/wallet.php`, zejména přesnost částek, vzdálené cenové API, export seed/xprv a CSRF.
2. `checkout/pay.php` a související AJAX/status endpointy.
3. Zbytek `admin/` a `client/` formulářů a views: CSRF, XSS, autorizace objektů a bezpečné mazání.
4. Inventura a odstranění nebo uzamčení starých testovacích/diagnostických skriptů.
5. Čistá instalace z `sql.sql`, upgrade cesta a deployment hardening.

Po dokončení těchto bodů bude možné říct, že je auditovaný celý webový projekt, ne pouze platební jádro a API/webhook hranice.
