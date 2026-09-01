# BTC Pay Server Lite

Lehká samoobslužná Bitcoinová platební brána v PHP nad Electrum daemonem. Projekt poskytuje databázové Greenfield API, stateless faktury, checkout, administrační rozhraní a spolehlivé doručování podepsaných webhooků.

> Stav dokumentace: správa uživatelů a provozní dohled, 1. září 2026. Produkční nasazení stále vyžaduje zálohu, migrace a smoke test proti skutečné databázi, poštovnímu systému a Electrum daemonu.

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
- správa klientských a administračních účtů, změna a jednorázová obnova hesla, pozastavení účtu a zneplatnění starších relací,
- jedna kanonická peněženka na klienta, admin přehled klientů a živých zůstatků,
- provozní metadata API požadavků, rozpoznané pluginy/e-shopy a klientský přehled faktur, výběrů a integrací,
- široký responzivní design systém administrace v grafitově černé a fialové paletě a samostatný design klientského portálu,
- databázové preflighty a migrace pro auditované části.

Třídy a rozhraní v `classes/` jsou rozdělené podle odpovědností; další kontrola se proto soustředí hlavně na deployment a integrační smoke test.

## Správa uživatelů a tenantů

Aplikace rozlišuje role `admin` a `client`. Veškeré klientské dotazy a změny obchodů, faktur, webhooků, výběrů, integrací a API provozu jsou omezené přes `stores.user_id`; Store ID z formuláře samo o sobě nikdy neopravňuje k přístupu.

Administrátor může:

- filtrovat dashboard, obchody, faktury a webhooky podle klienta a obchodu,
- zobrazit poslední přihlášení a aktivitu klienta, API požadavky, rozpoznané pluginy/e-shopy, faktury, webhooky a výběry,
- aktivovat nebo pozastavit účet, změnit klientův e-mail a ukončit všechny jeho relace,
- přiřadit hlavní peněženku pouze z cest, které už patří obchodům daného klienta,
- vytvořit obchod pro vybraného klienta nebo systémový obchod bez klienta,
- přejmenovat obchod, vyměnit jeho API klíč, upravit webhook URL a vyměnit webhook secret,
- změnit stav nezaplacené faktury; stav `Settled` nelze nastavit ani přepsat ručně,
- vypnout nebo znovu povolit veřejnou registraci v nastavení administrace.

Klient ve vlastním portálu vidí samostatné sekce Přehled, Obchody, Faktury, Webhooky, Výběry a Aktivita. Může filtrovat vlastní data, vytvářet a přejmenovávat své obchody, vyměňovat jejich API klíče, spravovat webhooky a měnit heslo. Všechny tyto změny používají CSRF ochranu a repository dotazy současně ověřují přihlášeného vlastníka.

Jeden klient má v `client_wallets` právě jednu kanonickou peněženku. Všechny jeho nově vytvořené obchody tuto peněženku znovu použijí; admin naproti tomu může ovládat všechny klientské i systémové peněženky. Faktury a výběry se vždy vážou na konkrétní obchod, a tím i na jeho vlastníka.

Mazání je záměrně omezené. Webhook lze odstranit, ale obchod lze smazat pouze tehdy, pokud nemá žádné faktury ani výběry. Finanční historie a zaplacené stavy se nemažou ani nepřepisují z administračního rozhraní.

### Hlavní soubory mimo `classes/`

| Soubor / oblast | Stav | Poznámka |
|---|---|---|
| `api.php` | Přepracováno | Kompatibilní podmnožina BTCPay Greenfield API pro pluginy: `token` i `Bearer` autentizace, server/API-key discovery, store/payment-method informace, faktury, webhooky, store scoping a bezpečné chyby. |
| `api_stateless.php` | Auditováno | Stateless API, omezení vstupu, autentizace, zámek Electrumu a bezpečné HTTP odpovědi. |
| `webhook_cron.php` | Auditováno | Pouze CLI nebo HTTP `POST` s Bearer tokenem; používá persistentní outbox. |
| `.htaccess` | Auditováno pro API a instalaci | Předávání hlavičky `Authorization` do PHP a zákaz stažení konfigurace, instalačního zámku a dočasných souborů s tajemstvími. |
| `admin/stores.php`, `admin/invoices.php`, `admin/webhooks.php` | Přepracováno a auditováno | Tenké controllery, jednotná oprávnění a CSRF, validované služby, přesné BTC částky a společná webhook URL policy. |
| `checkout/pay.php`, `checkout/views/`, `assets/checkout.*` | Přepracováno a auditováno | Tenký veřejný controller pro databázové faktury, bezpečný JSON status endpoint, přesné částky, lokálně generovaný BIP21 QR kód, zelený responzivní design a žádné předávání platebních dat externí službě. |
| `index.php` | Přepracováno a auditováno | Tenký front controller, deklarativní router, role, bezpečný výběr handleru, kanonické redirecty a jednotné HTTP chyby. |
| `admin/dashboard.php`, `admin/wallet.php` | Přepracováno a auditováno | Tenké controllery, explicitní oprávnění, validace, CSRF, bezpečné chyby a oddělené repository/service vrstvy. |
| `admin/views/`, `assets/admin.css` | Přepracováno | Sdílený široký a responzivní dark design systém dashboardu, peněženky, obchodů, faktur, webhooků a URL faktur; primární akce používají fialový akcent a provozní stavy vlastní sémantické barvy. |
| `admin/url_invoices.php`, `admin/views/url_invoices_view.php` | Přepracováno | Admin generátor používá samostatnou factory bez databáze, CSRF, procesní zámek, bezpečné JSON chyby a oddělený JavaScript pro lokální historii. |
| `admin/url_pay.php`, `admin/views/url_pay_view.php` | Přepracováno | Veřejný checkout podepsaného tokenu bez admin session, databázového invoice záznamu a externích QR služeb; podporuje kanonické i starší odkazy. |
| `client/login.php`, registrace a obnova hesla | Přepracováno | CSRF, throttling, bezpečné session, změna hesla, jednorázový 30minutový reset token a jednotná odpověď bez zjišťování existence účtu. |
| `client/index.php`, klientský dashboard | Přepracováno | Oddělené sekce a filtry; user-scoped obchody, přejmenování a výměna API klíče, jedna peněženka účtu, živý zůstatek, faktury, výběry, webhooky, integrace a API provoz. |
| `admin/users.php`, `admin/settings.php` | Nově implementováno | Správa klientů, e-mailu a relací, pozastavení účtu, bezpečné přiřazení peněženky, přehled zůstatků/provozu a vypnutí veřejných registrací. |
| `install.php`, `InstallationManager` | Implementováno | Jednorázový webový instalátor kontroluje prostředí, vytvoří prázdnou databázi, nahraje schéma, založí admin účet a atomicky zapíše privátní konfiguraci. |
| `config.php` a deployment | Částečně auditováno | Instalátor generuje náhodná tajemství a oprávnění `0600`; produkční proxy, zálohy a oddělení prostředí musí stále nastavit provozovatel. |
| `sql.sql` | Auditováno pro čistou instalaci | Schéma není svázané s pevným názvem databáze a nevytváří ukázkové credentials. Upgrade historických instalací nadále používá ruční migrace. |
| `examples/btcpay_lite_api_tester.php` | Implementováno | Samostatná ukázka pro e-shop, směnárnu a budoucí CMS plugin: všechny podporované API požadavky, JSON diagnostika, faktury, payouty a podepsaný webhook receiver. |
| testovací a pomocné skripty | Uklizeno | Veřejný debugger, stará registrace, neautentizovaný simulátor a zastaralé ruční testy byly odstraněny. |

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
| `GreenfieldApiController` | Parsuje bezpečnou cestu, metodu a JSON; přijímá BTCPay schéma `Authorization: token` i kompatibilní `Bearer` a routuje discovery, store, payment-method, invoice a webhook endpointy. | Přepracováno |
| `GreenfieldApiService` | Autentizace admin/store klíče, deklarace oprávnění, store scoping, přesné BTC částky, převod podporované fiat měny, checkout volby, faktury a webhooky. | Přepracováno |
| `GreenfieldApiRepository` | Úzká PDO hranice pro nalezení obchodu podle ID/API klíče, výpis webhooků a jejich idempotentní registraci s časem registrace. | Přepracováno |
| `GreenfieldApiException` | Bezpečná API chyba s operací a HTTP statusem. | Auditováno |
| `ExchangeQuoteService` | Počítá krátkodobé fiat/BTC nabídky v celých satoshi a odděleně vyčísluje směnárenský poplatek. | Nově implementováno |
| `PayoutRepository` | Persistentní výplatní ledger, idempotence, optimistická revize a uchování podepsané transakce před broadcastem. | Nově implementováno |
| `PayoutService` | Samostatně autentizovaná hranice odchozích BTC plateb s limitem jedné výplaty, denním limitem a dvoukrokovým schválením. | Nově implementováno |
| `PayoutException` | Bezpečná doménová chyba výplatní operace s HTTP statusem. | Nově implementováno |

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
| `AdminInvoiceService` | Validuje přesnou BTC částku a koordinuje administrační vytvoření faktury pro explicitně vybraný obchod. | Přepracováno a auditováno |
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

Checkout používá přesné osmidesetinné BTC řetězce, společný databázový zámek pro Electrum a bezpečné chybové odpovědi. Tlačítko „Otevřít Bitcoin peněženku“ používá lokálně sestavené BIP21 URI. Původní vzdálený generátor QR byl nahrazen lokálním SVG generátorem `endroid/qr-code` 4.7.0, aby adresa, částka a invoice ID neopouštěly aplikaci. Verze je záměrně připnutá kvůli kompatibilitě s PHP 8.0. Greenfield volby `checkout.redirectURL` a `checkout.redirectAutomatically` jsou validované, uložené s fakturou a po potvrzení platby vrátí zákazníka do obchodu.

### `api.php` – kompatibilní Greenfield podmnožina

Cílem není vydávat aplikaci za celý BTCPay Server, ale implementovat stabilní podmnožinu jeho [Greenfield API](https://docs.btcpayserver.org/API/Greenfield/v1/), kterou používají běžné e-shopové integrace. Aktuálně jsou podporované tyto cesty:

- `GET /api/v1/health` (bez autentizace)
- `GET /api/v1/server/info`
- `GET /api/v1/api-keys/current`
- `GET /api/v1/stores/{storeId}`
- `GET /api/v1/stores/{storeId}/payment-methods`
- `POST /api/v1/stores/{storeId}/invoices`
- `POST /api/v1/stores/{storeId}/exchange/quotes`
- `GET|POST /api/v1/stores/{storeId}/payouts` (volitelný výplatní modul)
- `GET|POST /api/v1/payouts/{payoutId}` (detail a schválení výplaty)
- `GET /api/v1/stores/{storeId}/invoices/{invoiceId}`
- `GET /api/v1/stores/{storeId}/invoices/{invoiceId}/payment-methods`
- `GET /api/v1/stores/{storeId}/webhooks`
- `POST /api/v1/stores/{storeId}/webhooks`

Primární autentizace kompatibilní s oficiálním klientem je `Authorization: token <api-key>`; kvůli starším klientům zůstává podporované také `Authorization: Bearer <api-key>`. Odpověď aktuálního API klíče záměrně deklaruje pouze skutečně implementovaná oprávnění, takže plugin nemá nabýt dojmu, že jsou dostupné refundace nebo pull payments.

Pro pojmenovaný přehled integrací může e-shop posílat `X-BTCPay-Plugin-Name`, `X-BTCPay-Plugin-Version` a `X-BTCPay-Shop-URL`. Z URL se ukládá pouze origin (schéma, host a volitelný port). Bez těchto hlaviček se stále zaznamená metoda, cesta, HTTP stav, trvání, čas a příslušný obchod; nikdy autorizační hlavička ani tělo požadavku.

Vytvoření faktury přijímá přesnou částku jako JSON řetězec a `currency`. Pro `BTC` a `SAT` probíhá převod bez `float`; podporované fiat měny se převádějí přes nakonfigurovaný tržní provider. Volby `checkout.redirectURL`, `checkout.redirectAutomatically` a `checkout.expirationMinutes` jsou zachované. Výsledná odpověď obsahuje BTCPay kompatibilní `checkoutLink`, stav, metadata a on-chain payment method `BTC-CHAIN`.

Webhooky používají události `InvoiceProcessing`, `InvoiceSettled` a `InvoiceExpired`. Raw JSON tělo je podepsané HMAC-SHA256 v hlavičce `BTCPay-Sig: sha256=...`; payload obsahuje `deliveryId`, `webhookId`, `storeId`, `invoiceId`, `type` a `timestamp`.

### Samostatný API tester a základ CMS pluginu

Soubor `examples/btcpay_lite_api_tester.php` nemá žádnou závislost na třídách projektu ani na Composeru. Lze jej zkopírovat jako jediný soubor na jiné PHP 8.0+ HTTPS hostingové prostředí s rozšířením cURL. V horním bloku `$CONFIG` se nastaví:

- URL BTCPay Server Lite a Store ID,
- běžný store API klíč pro faktury, kurz a webhooky,
- samostatný payout API klíč pro výběry,
- volitelný stateless API klíč,
- přístupové heslo k testeru, identita CMS pluginu a veřejná webhook URL.

Po přihlášení přes HTTP Basic nabízí tester katalog všech podporovaných endpointů, společnou read-only diagnostiku a jednotlivá volání. Každá provedená akce vypíše formátovaný JSON s HTTP metodou, URL, hlavičkami, payloadem, použitelným cURL příkladem a kompletní odpovědí serveru. API klíče, webhook secret, wallet heslo a raw transakce jsou ve výpisu automaticky skryté.

Třída `BtcPayLiteExampleClient` uvnitř souboru ukazuje přímo metody, které lze převést do WordPress/WooCommerce, PrestaShop, OpenCart, Magento nebo vlastního CMS pluginu. Část `request()` je společný transport; metody `createInvoice()`, `getInvoice()`, `createPayout()` a ostatní jsou konkrétní mapování endpointů a JSON schémat.

Stejný soubor může fungovat jako testovací webhook receiver na URL `?webhook=1`. Podpis `Btcpay-Sig` ověřuje nad nezměněným raw JSON tělem a poslední platnou událost ukládá mimo veřejný adresář do dočasného systémového adresáře. Akce „Show last verified webhook“ ji zobrazí v testeru.

Payout operace vždy používají jiný klíč než faktury. Vytvoření payoutu bez schválení pouze založí stav `AwaitingApproval`. Přímé vytvoření a odeslání i následné schválení jsou ve vzorovém souboru ve výchozím stavu zakázané; vyžadují současně `enable_live_payout_actions => true` a ručně zadanou potvrzovací frázi `SEND REAL BTC`. Nejdříve je ověřte na testnet/regtest.

#### Směnárenské nabídky a odchozí BTC výplaty

Kurzová cesta přijímá desetinnou částku jako JSON řetězec a vrací hrubou BTC částku, směnárenský poplatek a čistou výplatu. Fiat má nejvýše dvě desetinná místa; BTC výsledek se počítá v celých satoshi.

```bash
curl -X POST "$APP_URL/api.php/api/v1/stores/$STORE_ID/exchange/quotes" \
  -H "Authorization: token $STORE_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{"amount":"500.00","currency":"CZK"}'
```

Výplatní API je po instalaci vypnuté. Používá samostatný klíč pro každý obchod, který nesmí být shodný s běžným store API klíčem. Každé vytvoření vyžaduje unikátní `Idempotency-Key`; jeho bezpečné opakování vrátí tutéž výplatu a zabraňuje dvojímu odeslání při síťovém retry.

```bash
curl -X POST "$APP_URL/api.php/api/v1/stores/$STORE_ID/payouts" \
  -H "Authorization: token $PAYOUT_API_KEY" \
  -H "Idempotency-Key: exchange-order-2026-000001" \
  -H "Content-Type: application/json" \
  --data '{"destination":"bc1q...","amount":"500.00","currency":"CZK","approved":false}'
```

Výchozí stav je `AwaitingApproval`. Následné `POST /api/v1/payouts/{payoutId}` s tělem `{"revision":0}` výplatu schválí, podepíše a odešle. Před broadcastem se podepsaná raw transakce uloží do ledgeru; při dočasné chybě se má opakovat stejný požadavek, nikoli vytvářet výplata s novým idempotency klíčem. Volba `"approved":true` je určena pouze pro plně automatizované, silně omezené integrace.

Aktuální stav `InProgress` znamená, že Electrum přijal broadcast. Automatický potvrzovací worker a přechod na `Completed` budou doplněny v další etapě spolu s pull payments a refundacemi.

#### Aktuální hranice kompatibility

- podporována je pouze Bitcoin on-chain platební metoda `BTC-CHAIN`,
- hlavní kompatibilní režim pluginu je přesměrování na checkout; BTCPay modal skript zatím není implementován,
- přímé on-chain výplaty mají kompatibilní část Greenfield kontraktu; refundace, pull payments, potvrzovací worker, Lightning Network a správa výplatních klíčů přes UI zatím nejsou vystavené,
- doručení webhooků vyžaduje pravidelné spouštění `webhook_cron.php`,
- praktická kapacita závisí na MariaDB, Electrum RPC a frekvenci workeru; současná architektura je vhodná hlavně pro malé a středně zatížené obchody, rezervace a interní platební systémy,
- konkrétní plugin je před produkčním použitím nutné ověřit integračním smoke testem, protože může používat další Greenfield endpointy.

Referenční implementace a kontrakty: [Greenfield e-commerce integrace](https://docs.btcpayserver.org/Development/GreenFieldExample/), [oficiální PHP příklad](https://docs.btcpayserver.org/Development/GreenfieldExample-PHP/) a [WooCommerce Greenfield plugin](https://github.com/btcpayserver/woocommerce-greenfield-plugin).

### `api_stateless.php`

Vytváří stateless fakturu bez databázového invoice záznamu. Přístup je omezen konfigurovanými API klienty a operace s Electrumem používají lokální procesní zámek. Kanonický endpoint je `POST /api/stateless/invoices`; starší `POST /api` zůstává kompatibilní. Veřejná výsledná URL má tvar `/url-invoice?token=...`.

### Samostatné použití stateless jádra

Pro lehkou instalaci není potřeba `Database`, PDO, databázové tabulky, webhook worker ani standardní checkout. Přenositelná vrstva používá tyto komponenty:

- `BitcoinAmount`, `ElectrumRPC`, `ElectrumWallet` a jejich výjimky,
- `BtcInvoiceManagerException`, `BtcStatelessTokenCodec`, `BtcStatelessInvoiceGateway`, `BtcStatelessInvoiceManager`,
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
    'password_reset_from' => 'no-reply@example.com',
    'wallet_path' => '/opt/btcpay_wallets/admin_wallet',
    'electrum_cli_path' => '/opt/electrum/run_electrum',
    'electrum_data_dir' => '/opt/electrum_config',
    'store_wallets_dir' => '/opt/btcpay_wallets',
    'allow_local_webhooks' => false,
    'exchange_fee_bps' => 200, // 2,00 %
    'payout_api_enabled' => false,
    'payout_api_keys' => [
        'store-id' => 'samostatny-nahodny-klic-alespon-32-znaku',
    ],
    'payout_wallet_passwords' => [
        'store-id' => 'heslo-electrum-walletu',
    ],
    'payout_max_btc' => '0.01000000',
    'payout_daily_limit_btc' => '0.05000000',
    'api_clients' => [
        'client-bearer-token' => 'wallet-id',
    ],
];
```

`payout_api_enabled` ponechte `false`, dokud není provedena migrace, nastaven samostatný dlouhý náhodný klíč, ověřena záloha walletu a vyzkoušen testnet/regtest smoke test. Výplatní klíč uchovávejte pouze na serveru směnárny; klientský prohlížeč jej nikdy nesmí znát. Limity nastavujte jako přesné BTC řetězce.

`app_url` nastavte explicitně ve všech nasazeních; nesmí obsahovat credentials, query ani fragment. Pro wallet nástroje jsou podporované nové klíče `electrum_cli_path`, `electrum_data_dir`, `store_wallets_dir` i kompatibilní starší názvy `electrum_cli`, `electrum_data_directory`, `wallet_directory`. Volitelný `allow_local_webhooks => true` je určen pouze pro lokální vývoj. V produkci jej vynechte nebo ponechte `false`.

`password_reset_from` musí být platná adresa odesílatele a server musí mít funkční PHP `mail()`/MTA. Resetovací token se do databáze ukládá pouze jako SHA-256, platí 30 minut, je jednorázový a po změně hesla zvýší verzi relace.

Peněženky musí být mimo web root, například v `/opt/btcpay_wallets/`. Electrum RPC port nemá být veřejně dostupný.

## Databáze a migrace

Produkční schéma navíc používá `app_settings`, `client_wallets`, `password_reset_tokens`, `api_request_log` a `store_integrations`. Request log neukládá autorizační hlavičky, API klíče ani těla požadavků.

Audit přidal tyto jednorázové migrace a read-only kontroly:

- `migrations/20260830_database_preflight.sql`
- `migrations/20260830_harden_database_schema.sql`
- `migrations/20260831_webhook_delivery_preflight.sql`
- `migrations/20260830_add_webhook_deliveries.sql`
- `migrations/20260831_auth_preflight.sql`
- `migrations/20260831_add_auth_attempts.sql`
- `migrations/20260901_payout_preflight.sql`
- `migrations/20260901_add_payouts.sql`
- `migrations/20260901_user_accounts_preflight.sql`
- `migrations/20260901_add_user_accounts.sql`
- `migrations/20260901_add_request_monitoring.sql`

Projekt zatím nepoužívá automatický migration runner. Upgrade existující databáze se provádí ručním spuštěním SQL souborů v uvedeném pořadí. Před změnou vytvořte a ověřte obnovitelnou zálohu.

```bash
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME" \
  < migrations/20260901_user_accounts_preflight.sql

mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME" \
  < migrations/20260901_add_user_accounts.sql

mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME" \
  < migrations/20260901_add_request_monitoring.sql
```

Preflight nic nemění. Vypíše klienty s více peněženkami, sdílenou cestou nebo bez obchodu; tyto případy se nesmí automaticky přepisovat. Nové registrace zapisují jednu kanonickou peněženku. U starého klienta s jedinou cestou ji může admin explicitně převzít v detailu klienta.

Po migraci ověřte alespoň přítomnost tabulek a výchozí registrační politiky:

```sql
SHOW TABLES LIKE 'client_wallets';
SHOW TABLES LIKE 'password_reset_tokens';
SHOW TABLES LIKE 'api_request_log';
SHOW TABLES LIKE 'store_integrations';
SELECT setting_key, setting_value FROM app_settings
WHERE setting_key = 'registration_enabled';
```

## Instalace a požadavky

- PHP 8.0 nebo novější,
- rozšíření cURL, JSON a PDO MySQL,
- MariaDB 10.4+ nebo kompatibilní MySQL,
- Composer,
- běžící Electrum daemon s JSON-RPC,
- Apache/Nginx nakonfigurovaný tak, aby PHP dostalo hlavičku `Authorization`.

```bash
composer install
composer dump-autoload --optimize
```

### Nová instalace přes web

1. Naklonujte projekt, spusťte `composer install` a nastavte web root na adresář projektu.
2. Webovému uživateli dočasně povolte vytvořit `config.php` v kořeni projektu. Adresáře peněženek ponechte mimo web root.
3. Otevřete kořenovou URL aplikace. Pokud `config.php` neexistuje, aplikace vás přesměruje na `install.php`.
4. Zadejte připojení k prázdné MySQL/MariaDB databázi, veřejnou URL, první admin účet a cesty k Electrumu. Volba „Vytvořit databázi“ vyžaduje oprávnění `CREATE`; jinak databázi vytvořte předem a volbu vypněte.
5. Po úspěchu se přihlaste vytvořeným admin účtem. Instalátor je automaticky uzamčen existencí `config.php` a při dalším otevření přesměruje na přihlášení.

Instalátor přijímá pouze prázdnou databázi. Nejde o upgrade nástroj a nikdy se nesmí spouštět nad existující produkční databází. Schéma vytvoří všechny tabulky, výchozí registrační politiku a přesně jeden aktivní účet s rolí `admin`; žádný ukázkový obchod ani veřejný API klíč se nevytváří. `admin_api_key`, podpisový `secret_key` a `cron_key` se generují kryptograficky na serveru.

Nenainstalovanou instanci nenechávejte volně dostupnou z internetu: první návštěvník instalačního formuláře by mohl založit vlastní administrační účet. Instalaci dokončete za firewallem, přes VPN nebo s dočasným omezením přístupu v Apache/Nginx a teprve potom aplikaci zveřejněte. Produkční formulář otevírejte výhradně přes HTTPS.

Pokud PHP nemůže zapsat do kořene projektu, vytvořte `config.php` ručně podle sekce Konfigurace nebo dočasně upravte vlastnictví adresáře. Po instalaci ponechte soubor čitelný pouze pro uživatele PHP; instalátor se pokusí nastavit režim `0600`. Soubor nikdy necommitujte – je zahrnutý v `.gitignore`.

Apache ochranu tajných instalačních souborů obsahuje v `.htaccess`. U Nginx přidejte ekvivalentní zákaz a teprve potom obecné PHP pravidlo:

```nginx
location ~ /(?:config\.php|\.install\.lock|\.btcpay-config-) {
    deny all;
    return 404;
}
```

Pro ruční čistou instalaci nejprve vytvořte databázi a spusťte schéma proti explicitně zvolenému názvu:

```bash
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" -p "$DB_NAME" < sql.sql
```

Admin účet pak vytvořte pouze s hashem z `password_hash(..., PASSWORD_DEFAULT)`; webový instalátor je doporučená cesta, protože validaci a bezpečný zápis provede automaticky.

Adresáře s peněženkami a zálohami databáze držte mimo `htdocs` a mimo Git.

## Testy

Kontraktní testy jsou samostatné PHP skripty:

```bash
php tests/BitcoinAmountTest.php
php tests/ExchangeQuoteServiceTest.php
php tests/ElectrumWalletTest.php
php tests/BtcInvoiceManagerTest.php
php tests/BtcStatelessInvoiceManagerTest.php
php tests/BtcStatelessFactoryTest.php
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
php tests/AdminManagementServiceTest.php
php tests/AdminInvoiceServiceTest.php
php tests/ClientRegistrationServiceTest.php
php tests/ClientRegistrationBoundaryTest.php
php tests/UserAccountServiceTest.php
php tests/PasswordResetMailerTest.php
php tests/AdminUserServiceTest.php
php tests/WalletBalanceErrorTest.php
php tests/InstallationManagerTest.php
php tests/InstallerHttpBoundaryTest.php
php tests/ApiTesterExampleTest.php
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
- Výplatní API aktivujte až po migraci a testnet/regtest ověření; používejte samostatný klíč, nízký limit jedné výplaty, denní limit a unikátní idempotency klíč každé obchodní objednávky.
- Stav `InProgress` nepovažujte za potvrzenou transakci; potvrzení je nutné ověřit samostatným workerem nebo v peněžence.
- Přihlášení a registrace musí používat CSRF token; odhlášení je pouze přes `POST`.
- Session cookie je `HttpOnly`, `SameSite=Lax` a na HTTPS také `Secure`; session má idle i absolutní limit.

## Doporučené pořadí dalšího auditu

1. Přesun produkčních tajemství z `config.php` do prostředí nebo secret manageru a kontrola hlaviček na skutečné reverse proxy.
2. Smoke test webového instalátoru na podporované MariaDB/MySQL a automatizovaná upgrade cesta pro historické databáze.
3. Samostatná verzovaná dokumentace a první konkrétní CMS plugin postavený nad `BtcPayLiteExampleClient`.

Po dokončení těchto bodů bude možné říct, že je auditovaný celý webový projekt, ne pouze platební jádro a API/webhook hranice.
