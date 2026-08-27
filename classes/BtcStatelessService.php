<?php
declare(strict_types=1);

namespace BtcPayLite;

/**
 * VRSTVA 4: Fasáda pro bezstavové (Stateless) faktury.
 * Zastřešuje logiku mezi peněženkou, manažerem faktur a frontendem.
 */
class BtcStatelessService
{
    private array $config;
    private ElectrumWallet $wallet;
    private BtcInvoiceManager $invoiceManager;

    // V čistém OOP přijímáme závislosti (Dependency Injection)
    public function __construct(array $config, ElectrumWallet $wallet, BtcInvoiceManager $invoiceManager)
    {
        $this->config = $config;
        $this->wallet = $wallet;
        $this->invoiceManager = $invoiceManager;
    }

    /**
     * Vytvoří fakturu pro externí API (validuje API klíč).
     */
    public function createInvoiceFromApi(array $input, string $apiKeyProvided): array
    {
        if (empty($apiKeyProvided) || !isset($this->config['api_clients'][$apiKeyProvided])) {
            throw new \Exception("Odmítnuto: Neplatný API klíč nebo neznámý klient.", 401);
        }
        
        $walletName = $this->config['api_clients'][$apiKeyProvided];
        return $this->processInvoiceCreation($input, $walletName);
    }

    /**
     * Vytvoří fakturu pro Administraci (použije přímo zvolenou peněženku).
     */
    public function createInvoiceAsAdmin(array $input, string $walletName): array
    {
        if (empty($walletName)) {
            throw new \Exception("Chyba: Nebyla specifikována peněženka.", 400);
        }
        return $this->processInvoiceCreation($input, $walletName);
    }

    /**
     * Společné jádro pro sanitizaci dat a generování faktury.
     */
    private function processInvoiceCreation(array $input, string $walletName): array
    {
        // 1. Zabezpečení cesty a aktivace peněženky v démonu
        $safeWalletName = basename($walletName);
        $walletPath = dirname($this->config['wallet_path']) . '/' . $safeWalletName;
        $this->wallet->loadWallet($walletPath);

        // 2. Sanitizace a validace vstupů
        $amount = (float)str_replace(',', '.', (string)($input['amount'] ?? '0'));
        $desc = trim($input['description'] ?? '');
        $orderId = trim($input['order_id'] ?? '');
        
        $requestedExp = (int)($input['expiration_minutes'] ?? 15);
        $expirationMinutes = max(10, min(43200, $requestedExp)); // Limit 10 minut až 30 dní

        if ($amount <= 0 || empty($desc)) {
            throw new \Exception("Parametry 'amount' a 'description' jsou povinné a částka musí být > 0.", 400);
        }

        // 3. Generování URL tokenu
        $customData = [
            'order_id' => $orderId,
            'wallet'   => $safeWalletName
        ];

        $res = $this->invoiceManager->createStatelessInvoice($amount, $desc, $customData, $expirationMinutes);

        return [
            'token' => $res['token'],
            'amount' => number_format($amount, 8, '.', ''),
            'description' => $desc,
            'order_id' => $orderId,
            'wallet' => $safeWalletName,
            'expires_in_minutes' => $expirationMinutes
        ];
    }

    /**
     * Připraví kompletní data pro platební stránku zákazníka.
     */
    public function getPaymentPageData(string $token): array
    {
        $invoiceData = $this->invoiceManager->decodeStatelessToken($token);
        $dashboard = new BtcDashboard($this->wallet, dirname($this->config['wallet_path']));
        
        $fiatRate = $dashboard->getFiatPrice('CZK');
        $fiatAmount = $fiatRate > 0 ? round((float)$invoiceData['v'] * $fiatRate, 2) : 0.0;
        
        return [
            'invoice' => $invoiceData,
            'fiat_amount' => $fiatAmount,
            'seconds_remaining' => max(0, (int)$invoiceData['e'] - time())
        ];
    }
    
    /**
     * Zkontroluje stav platby v síti pro AJAX frontend.
     */
    public function checkStatus(string $token): array
    {
        $invoiceData = $this->invoiceManager->decodeStatelessToken($token);
        
        // Před kontrolou se musíme ujistit, že démon sleduje správnou peněženku
        if (!empty($invoiceData['p']['wallet'])) {
            $walletPath = dirname($this->config['wallet_path']) . '/' . basename($invoiceData['p']['wallet']);
            $this->wallet->loadWallet($walletPath);
        }
        
        return $this->invoiceManager->checkStatelessPaymentStatus($token);
    }
}