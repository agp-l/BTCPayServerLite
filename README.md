# BTC Pay Server Lite

Lehká samoobslužná Bitcoinová platební brána v PHP nad Electrum daemonem. Projekt poskytuje databázové Greenfield API, stateless faktury, checkout, administrační rozhraní a spolehlivé doručování podepsaných webhooků.

> Stav dokumentace: větev `main` po refaktoru dashboardů, checkoutu a administračního rozhraní, 31. srpna 2026. Označení „auditováno“ níže znamená, že komponenta prošla samostatnou kontrolou a kontraktními testy; produkční nasazení stále vyžaduje smoke test proti skutečné databázi a Electrum daemonu.

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
- `BtcDashboard`, přesné wallet výpočty a oddělený HTTP provider tržních dat,
- administrační dashboard, peněženka, obchody, faktury a webhooky s repository/service vrstvou,
- klientský dashboard a registrace s objektovým store scopingem, transakcemi a bezpečným provisioningem peněženek,
- široký responzivní design systém administrace v grafitově černé a fialové paletě a samostatný design klientského portálu,
- databázové preflighty a migrace pro auditované části.

V adresáři `classes/` je 60 tříd a rozhraní. Původní velké UI třídy a dashboard controllery jsou nyní rozdělené podle odpovědností; další audit se proto soustředí hlavně na zbývající vstupní body a deployment.

### Hlavní soubory mimo `classes/`

| Soubor / oblast | Stav | Poznámka |
|---|---|---|
| `api.php` | Auditováno | Databázové Greenfield API, Bearer autentizace, přesné částky, store scoping a bezpečné chyby. |
| `api_stateless.php` | Auditováno | Stateless API, omezení vstupu, autentizace, zámek Electrumu a bezpečné HTTP odpovědi. |
| `webhook_cron.php` | Auditováno | Pouze CLI nebo HTTP `POST` s Bearer tokenem; používá persistentní outbox. |
| `.htaccess` | Auditováno pro API | Předávání hlavičky `Authorization` do PHP. |
| `admin/stores.php`, `admin/invoices.php`, `admin/webhooks.php` | Přepracováno a auditováno | Tenké controllery, jednotná oprávnění a CSRF, validované služby, přesné BTC částky a společná webhook URL policy. |
| `checkout/pay.php`, `checkout/views/`, `assets/checkout.*` | Přepracováno a auditováno | Tenký veřejný controller pro databázové faktury, bezpečný JSON status endpoint, přesné částky, lokálně generovaný BIP21 QR kód, zelený responzivní design a žádné předávání platebních dat externí službě. |
| `index.php` | Přepracováno a auditováno | Tenký front controller, deklarativní router, role, bezpečný výběr handleru, kanonické redirecty a jednotné HTTP chyby. |
| `admin/dashboard.php`, `admin/wallet.php` | Přepracováno a auditováno | Tenké controllery, explicitní oprávnění, validace, CSRF, bezpečné chyby a oddělené repository/service vrstvy. |
| `admin/views/`, `assets/admin.css` | Přepracováno | Sdílený široký a responzivní dark design systém dashboardu, peněženky, obchodů, faktur, webhooků a URL faktur; primární akce používají fialový akcent a provozní stavy vlastní sémantické barvy. |
| `admin/url_invoices.php`, `admin/views/url_invoices_view.php` | Přepracováno | Admin generátor používá samostatnou factory bez databáze, CSRF, procesní zámek, bezpečné JSON chyby a oddělený JavaScript pro lokální historii. |
| `admin/url_pay.php`, `admin/views/url_pay_view.php` | Přepracováno | Veřejný checkout podepsaného tokenu bez admin session, databázového invoice záznamu a externích QR služeb; podporuje kanonické i starší odkazy. |
| `client/login.php`, `client/registrace.php` a session | Přepracováno a auditováno | CSRF, bezpečné chyby, throttling, cookie/session limity, regenerace ID, POST logout a transakční registrace se samostatnou peněženkou. |
| `client/index.php`, klientský dashboard | Přepracováno a auditováno | Tenký controller, user-scoped repository, bezpečný wallet provisioner, CSRF a escapované responzivní views. |
| `config.php` a deployment | Čeká | Správa tajemství, oprávnění souboru, produkční hodnoty a oddělení prostředí. |
| `sql.sql` | Částečně auditováno | Obsahuje nové schéma; čistá instalace a upgrade z více historických verzí ještě potřebují samostatný test. |
| testovací a pomocné skripty | Částečně uklizeno | Veřejný debugger, stará registrace a zastaralé ruční testy byly odstraněny. `eshop_simulator.php` bude přepracován na univerzální integrační ukázku mimo web root v `tools/`. |

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
| `BtcInvoiceManager` | Vytváří a načítá databázové faktury a bezpečně mění stavy `New`, `Processing`, `Settled`, `Expired`; původní stateless metody zachovává jako kompatibilní delegující fasádu. | Auditováno |
| `BtcInvoiceManagerException` | Strukturovaná chyba životního cyklu faktury. | Auditováno |
| `CheckoutRepository` | Read-only rozhraní pro nalezení peněženky vlastnící databázovou fakturu. | Auditováno |
| `PdoCheckoutRepository` | Parametrizovaný omezený JOIN faktury a obchodu bez načítání nepotřebných nebo citlivých sloupců. | Auditováno |
| `DatabaseCheckoutService` | Validuje invoice ID, normalizuje přesný platební stav a skládá prezentačně neutrální checkout view model. | Auditováno |
| `DatabaseCheckoutFactory` | Sestavuje checkout z validované konfigurace a spouští kontrolu Electrumu pod sdíleným databázovým zámkem. | Auditováno |
| `DatabaseCheckoutController` | HTTP hranice veřejného checkoutu pro HTML a minimální JSON status odpověď; povoluje pouze `GET` a `HEAD`. | Auditováno |
| `CheckoutException` | Veřejně bezpečná checkout chyba s HTTP statusem a stabilním názvem operace. | Auditováno |
| `CheckoutQrCodeGenerator` | Lokálně generuje zelený SVG QR kód z validovaného BIP21 URI; při chybějící knihovně bezpečně zachová běžný wallet odkaz. | Auditováno |
| `BtcStatelessInvoiceGateway` | Minimální kontrakt pro vytvoření, dekódování a kontrolu stateless faktury bez vazby na databázový manager. | Auditováno |
| `BtcStatelessInvoiceManager` | Přenosné jádro bez `Database`/PDO: rezervuje Electrum request, vytváří BIP21, podepisuje token a vyhodnocuje přesné BTC platby. | Auditováno |
| `BtcStatelessTokenCodec` | Podepisuje, ověřuje a verzuje omezené stateless tokeny; změněný obsah odmítá a čas platnosti předává stavové vrstvě. | Auditováno |
| `BtcStatelessFactory` | Sestaví Electrum wallet, stateless manager a service pouze z konfigurace, bez připojení k databázi. | Auditováno |
| `BtcStatelessService` | Aplikační logika stateless API a platební stránky; ověřuje klienta, wallet mapping, vstupy a stav platby. Závisí jen na stateless gateway. | Auditováno |
| `BtcStatelessServiceException` | Chyba stateless operace s bezpečným HTTP statusem a názvem operace. | Auditováno |
| `BtcStatelessApiController` | HTTP hranice stateless API: metoda, JSON/form data, Bearer token a normalizovaná odpověď. | Auditováno |
| `BtcStatelessAjaxController` | Admin HTTP/AJAX hranice pro vytvoření a kontrolu stateless faktury; vrací kanonické veřejné URL. | Auditováno |
| `BtcStatelessCheckoutController` | Read-only veřejná hranice pro platební view model a minimální polling odpověď. | Auditováno |

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

### Dashboardy, UI, autentizace a URL

| Komponenta | Odpovědnost | Stav auditu |
|---|---|---|
| `AdminDashboardRepository` | Rozhraní read-only dat potřebných pro administrační statistiky a přehled faktur. | Přepracováno a auditováno |
| `PdoAdminDashboardRepository` | PDO implementace agregací dashboardu s explicitními dotazy a omezeným výpisem faktur. | Přepracováno a auditováno |
| `AdminDashboardService` | Skládá normalizovaný view model administračního dashboardu bez SQL v controlleru. | Přepracováno a auditováno |
| `AdminOperationsRepository` | Rozhraní persistence pro administrační správu obchodů a webhooků. | Přepracováno a auditováno |
| `PdoAdminOperationsRepository` | PDO implementace omezených seznamů a parametrizovaných změn obchodů a webhooků. | Přepracováno a auditováno |
| `AdminOperationsService` | Validuje a koordinuje administrační vytvoření obchodu, bezpečný wallet provisioning a správu webhooků. | Přepracováno a auditováno |
| `AdminOperationsFactory` | Sestavuje administrační služby z validované konfigurace včetně kompatibility starších názvů wallet klíčů. | Přepracováno a auditováno |
| `AdminOperationsException` | Bezpečná doménová chyba administrační operace s odpovídajícím HTTP statusem. | Přepracováno a auditováno |
| `AdminInvoiceService` | Validuje přesnou BTC částku a koordinuje administrační vytvoření a odstranění faktury. | Přepracováno a auditováno |
| `BitcoinMarketDataProvider` | Rozhraní pro tržní cenu BTC a doporučené fee rates. | Přepracováno a auditováno |
| `HttpBitcoinMarketDataProvider` | Omezený cURL klient tržních API s TLS kontrolou, timeouty a validací odpovědí. | Přepracováno a auditováno |
| `BtcDashboard` | Typovaná aplikační fasáda administrační peněženky; vrací strojová data, používá přesné satoshi a oddělený market provider. | Přepracováno a auditováno |
| `ClientDashboardRepository` | Rozhraní user-scoped persistence obchodů, faktur a webhooků klientského portálu. | Přepracováno a auditováno |
| `PdoClientDashboardRepository` | PDO implementace s objektovým scopingem podle přihlášeného uživatele a omezenými výpisy. | Přepracováno a auditováno |
| `ClientDashboardService` | Validuje klientské operace, skládá dashboard a koordinuje vytvoření obchodu, walletu a webhooku. | Přepracováno a auditováno |
| `ClientDashboardException` | Bezpečná doménová chyba klientského dashboardu s HTTP statusem. | Přepracováno a auditováno |
| `ClientRegistrationService` | Koordinuje uživatele, obchod a samostatnou peněženku; při selhání persistence bezpečně uklidí nepoužitý wallet soubor. | Přepracováno a auditováno |
| `StoreWalletProvisioner` | Rozhraní vytvoření samostatné peněženky a bezpečného úklidu nepoužitého wallet souboru. | Přepracováno a auditováno |
| `ElectrumCliWalletProvisioner` | Spouští Electrum bez shellu, s timeoutem a kontrolou cesty a oprávnění; seed neuchovává a úklid omezuje na spravované wallet soubory. | Přepracováno a auditováno |
| `AuthManager` | Přihlášení, registrace, odhlášení, bezpečné session/cookies, CSRF, časové limity, role a throttling. | Auditováno |
| `AuthUserRepository` | Rozhraní úzké persistence uživatelů a autentizačních pokusů. | Auditováno |
| `PdoAuthUserRepository` | PDO implementace explicitních auth dotazů, binárních identit throttlingu a omezeného úklidu pokusů. | Auditováno |
| `AuthException` | Bezpečná doménová chyba autentizace bez úniku interních detailů. | Auditováno |
| `UrlManager` | Odděluje důvěryhodný origin od request URI, validuje Host/app_url, dekóduje bezpečné segmenty a skládá kanonické interní URL. | Přepracováno a auditováno |
| `ApplicationRouter` | Deklarativní tabulka přesných cest, HTTP metod, handlerů, aktivního menu a požadovaných rolí. | Přepracováno a auditováno |
| `ApplicationRoute` | Neměnný výsledek routingu pro handler nebo interní redirect. | Přepracováno a auditováno |
| `RouterException` | Bezpečná routovací chyba s HTTP statusem a seznamem metod pro hlavičku `Allow`. | Přepracováno a auditováno |

## Front controller a čisté URL

Hlavní `index.php` používá přesné route bez prefixového porovnávání. Aliasové cesty `/home`, `/prezentace`, `/dashboard` a `/admin` vracejí kanonický redirect. Neznámá cesta vrací 404; známá cesta s nepovolenou metodou vrací 405 a hlavičku `Allow`.

`app_url` je důvěryhodný origin aplikace a má zahrnovat i instalační podadresář, například `http://localhost/BTCPayLite`. Je-li nastavený, odkazy ani redirecty nepoužívají klientský Host header. Routovací segmenty zůstávají daty a HTML escapování se provádí až ve view.

## HTTP vstupní body

### `checkout/pay.php` – databázový checkout

Veřejná cesta `GET /pay?id={invoiceId}` zobrazuje zákazníkovi částku, bitcoinovou adresu, zbývající čas a aktuální stav databázové faktury. Stav se obnovuje přes `GET /pay?id={invoiceId}&action=check`; JSON odpověď obsahuje pouze dynamické platební údaje potřebné pro UI.

Checkout používá přesné osmidesetinné BTC řetězce, společný databázový zámek pro Electrum a bezpečné chybové odpovědi. Tlačítko „Otevřít Bitcoin peněženku“ používá lokálně sestavené BIP21 URI. Původní vzdálený generátor QR byl nahrazen lokálním SVG generátorem `endroid/qr-code` 4.7.0, aby adresa, částka a invoice ID neopouštěly aplikaci. Verze je záměrně připnutá kvůli kompatibilitě s PHP 8.0.

### `api.php` – databázové API

Podporované cesty:

- `GET /api/v1/stores/{storeId}`
- `POST /api/v1/stores/{storeId}/invoices`
- `GET /api/v1/stores/{storeId}/invoices/{invoiceId}`
- `POST /api/v1/stores/{storeId}/webhooks`

Autentizace používá `Authorization: Bearer ...`. Částka faktury musí být JSON řetězec s přesnou BTC hodnotou, například `"0.00000001"`; JSON číslo se záměrně odmítá kvůli riziku `float` nepřesnosti.

### `api_stateless.php`

Vytváří stateless fakturu bez databázového invoice záznamu. Přístup je omezen konfigurovanými API klienty a operace s Electrumem používají lokální procesní zámek. Kanonický endpoint je `POST /api/stateless/invoices`; starší `POST /api` zůstává kompatibilní. Veřejná výsledná URL má tvar `/url-invoice?token=...`.

### Samostatné použití stateless jádra

Pro lehkou instalaci není potřeba `Database`, PDO, databázové tabulky, webhook worker ani standardní checkout. Přenositelná vrstva používá tyto komponenty:

- `BitcoinAmount`, `ElectrumRPC`, `ElectrumWallet` a jejich výjimky,
- `BtcStatelessTokenCodec`, `BtcStatelessInvoiceGateway`, `BtcStatelessInvoiceManager`,
- volitelně `BtcStatelessService`, `BtcStatelessFactory` a příslušné HTTP controllery,
- `CheckoutQrCodeGenerator` a `endroid/qr-code` pouze pro lokální QR na platební stránce.

Minimální vytvoření faktury přímo z jádra:

```php
$rpc = new BtcPayLite\ElectrumRPC($host, $port, $user, $password);
$wallet = new BtcPayLite\ElectrumWallet($rpc);
$wallet->loadWallet('/secure/wallets/merchant_wallet');

$invoices = new BtcPayLite\BtcStatelessInvoiceManager($wallet, $secretKey);
$result = $invoices->createStatelessInvoice(
    '0.00100000',
    'Ruční faktura e-mailem',
    ['order_id' => 'MAIL-2026-001', 'wallet' => 'merchant_wallet'],
    60
);

$paymentUrl = $publicBaseUrl . '/url-invoice?token=' . rawurlencode($result['token']);
```

`secretKey` musí být stabilní tajný řetězec o délce nejméně 16 bajtů. Jeho změna zneplatní všechny dříve vytvořené odkazy. Token obsahuje platební údaje a jejich podpis, nikoli seed, xprv nebo heslo peněženky.

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
    'wallet_path' => '/opt/btcpay_wallets/admin_wallet',
    'electrum_cli_path' => '/opt/electrum/run_electrum',
    'electrum_data_dir' => '/opt/electrum_config',
    'store_wallets_dir' => '/opt/btcpay_wallets',
    'allow_local_webhooks' => false,
    'api_clients' => [
        'client-bearer-token' => 'wallet-id',
    ],
];
```

`app_url` nastavte explicitně ve všech nasazeních; nesmí obsahovat credentials, query ani fragment. Pro wallet nástroje jsou podporované nové klíče `electrum_cli_path`, `electrum_data_dir`, `store_wallets_dir` i kompatibilní starší názvy `electrum_cli`, `electrum_data_directory`, `wallet_directory`. Volitelný `allow_local_webhooks => true` je určen pouze pro lokální vývoj. V produkci jej vynechte nebo ponechte `false`.

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
composer update endroid/qr-code --with-all-dependencies
composer dump-autoload --optimize
```

Adresáře s peněženkami a zálohami databáze držte mimo `htdocs` a mimo Git.

## Testy

Kontraktní testy jsou samostatné PHP skripty:

```bash
php tests/BitcoinAmountTest.php
php tests/ElectrumWalletTest.php
php tests/BtcInvoiceManagerTest.php
php tests/BtcStatelessInvoiceManagerTest.php
php tests/DatabaseCheckoutServiceTest.php
php tests/CheckoutRepositoryQueryTest.php
php tests/CheckoutHttpBoundaryTest.php
php tests/CheckoutQrCodeGeneratorTest.php
php tests/BtcStatelessServiceTest.php
php tests/StatelessCheckoutBoundaryTest.php
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
php tests/AdminDashboardServiceTest.php
php tests/AdminDashboardRepositoryQueryTest.php
php tests/HttpBitcoinMarketDataProviderTest.php
php tests/BtcDashboardTest.php
php tests/AdminUiBoundaryTest.php
php tests/ClientDashboardServiceTest.php
php tests/ClientDashboardRepositoryQueryTest.php
php tests/ClientUiBoundaryTest.php
php tests/AdminOperationsServiceTest.php
php tests/AdminOperationsRepositoryQueryTest.php
php tests/AdminManagementBoundaryTest.php
php tests/AdminInvoiceServiceTest.php
php tests/ClientRegistrationServiceTest.php
php tests/ClientRegistrationBoundaryTest.php
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

1. Přepracování `eshop_simulator.php` na univerzální integrační ukázku v `tools/` pro externí e-shopy a další systémy.
2. `config.php`, přesun tajemství do prostředí, oprávnění souborů a produkční security headers.
3. Čistá instalace z `sql.sql`, upgrade cesta a deployment hardening.

Po dokončení těchto bodů bude možné říct, že je auditovaný celý webový projekt, ne pouze platební jádro a API/webhook hranice.
