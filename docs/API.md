# API a integrace

[Zpět na README](../README.md) · [Konfigurace](CONFIGURATION.md)

## Front controller a čisté URL

Hlavní `index.php` používá přesné route bez prefixového porovnávání. Aliasové cesty `/home`, `/prezentace`, `/dashboard` a `/admin` vracejí kanonický redirect. Neznámá cesta vrací 404; známá cesta s nepovolenou metodou vrací 405 a hlavičku `Allow`.

`app_url` je důvěryhodný origin aplikace a má zahrnovat i instalační podadresář, například `http://localhost/BTCPayLite`. Je-li nastavený, odkazy ani redirecty nepoužívají klientský Host header. Routovací segmenty zůstávají daty a HTML escapování se provádí až ve view.

## HTTP vstupní body

### `checkout/pay.php` – databázový checkout

Veřejná cesta `GET /pay?id={invoiceId}` zobrazuje zákazníkovi částku, bitcoinovou adresu, zbývající čas a aktuální stav databázové faktury. Stav se obnovuje přes `GET /pay?id={invoiceId}&action=check`; JSON odpověď obsahuje pouze dynamické platební údaje potřebné pro UI.

Checkout používá přesné osmidesetinné BTC řetězce a bezpečné chybové odpovědi. Čte pouze DB snapshot: nevyžaduje RPC konfiguraci, nevolá Electrum, nezamyká wallet a nemění platební stav. Tlačítko „Otevřít Bitcoin peněženku“ používá lokálně sestavené BIP21 URI. Původní vzdálený generátor QR byl nahrazen lokálním SVG generátorem `endroid/qr-code` 4.7.0, aby adresa, částka a invoice ID neopouštěly aplikaci. Verze je záměrně připnutá kvůli kompatibilitě s PHP 8.0. Greenfield volby `checkout.redirectURL` a `checkout.redirectAutomatically` jsou validované, uložené s fakturou a po potvrzení platby vrátí zákazníka do obchodu.

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
- praktická kapacita závisí na MariaDB, Electrum RPC a frekvenci workeru; souběžnostní testy nejsou měřením kapacity konkrétního nasazení,
- konkrétní plugin je před produkčním použitím nutné ověřit integračním smoke testem, protože může používat další Greenfield endpointy.

Referenční implementace a kontrakty: [Greenfield e-commerce integrace](https://docs.btcpayserver.org/Development/GreenFieldExample/), [oficiální PHP příklad](https://docs.btcpayserver.org/Development/GreenfieldExample-PHP/) a [WooCommerce Greenfield plugin](https://github.com/btcpayserver/woocommerce-greenfield-plugin).

### `api_stateless.php`

Vytváří stateless fakturu bez databázového invoice záznamu. Vytváření je omezené konfigurovanými API klienty. Instalovaná aplikace používá sdílený receive koordinátor a DB rezervaci indexu; samotná XPUB derivace nepotřebuje wallet lock. Electrum mutace používají společný per-wallet zámek. Provider-based status potřebuje pouze token a blockchain provider; nenačítá wallet. Kanonický endpoint je `POST /api/stateless/invoices`; starší `POST /api` zůstává kompatibilní. Veřejná výsledná URL má tvar `/url-invoice?token=...`.

### Samostatné použití stateless jádra

Následující příklad je explicitní samostatný legacy Electrum režim, nikoli doporučené složení instalované aplikace. Nepotřebuje DB, ale nesmí nezávisle přidělovat adresy z větve používané instalovaným XPUB koordinátorem. Instalovaná aplikace používá `BtcStatelessFactory` se sdíleným alokátorem; status zůstává walletless. Viz [koordinace adres](RECEIVE_COORDINATION.md). Přenositelná vrstva používá tyto komponenty:

- `BitcoinAmount`, `ElectrumRPCFactory`, `ElectrumRPC`, `ElectrumWallet`, `WalletLockManager` a jejich výjimky,
- `BlockchainProviderInterface`, `ElectrumBlockchainProvider`, `AddressPaymentObservation`, `BlockchainProviderException`,
- `BtcInvoiceManagerException`, `BtcStatelessTokenCodec`, `BtcStatelessInvoiceGateway`, `BtcStatelessInvoiceManager`,
- volitelně `BtcStatelessService`, `BtcStatelessFactory` a příslušné HTTP controllery,
- `CheckoutQrCodeGenerator` a `endroid/qr-code` pouze pro lokální QR na platební stránce.

Minimální vytvoření faktury přímo z jádra:

```php
$rpc = BtcPayLite\ElectrumRPCFactory::fromConfig($config);
$wallet = new BtcPayLite\ElectrumWallet($rpc);
$provider = new BtcPayLite\ElectrumBlockchainProvider($rpc);
$invoices = new BtcPayLite\BtcStatelessInvoiceManager($wallet, $secretKey, null, $provider);
$result = $invoices->createStatelessInvoice(
    '0.00100000',
    'Ruční faktura e-mailem',
    ['order_id' => 'MAIL-2026-001'],
    60,
    '/secure/wallets/merchant_wallet'
);

$paymentUrl = $publicBaseUrl . '/url-invoice?token=' . rawurlencode($result['token']);
```

`secretKey` musí být stabilní tajný řetězec o délce nejméně 16 bajtů. Jeho změna zneplatní všechny dříve vytvořené odkazy. Token obsahuje platební údaje a jejich podpis, nikoli seed, xprv nebo heslo peněženky.
