<?php
declare(strict_types=1);

namespace BtcPayLite;

/**
 * VRSTVA 4: Kontroler pro AJAX požadavky Administrace.
 * Přijímá HTTP data, předává je službě (Service) a vrací čisté pole pro JSON.
 */
class BtcStatelessAjaxController
{
    private BtcStatelessService $service;
    private string $defaultWallet;
    private string $baseUri;

    public function __construct(BtcStatelessService $service, string $defaultWallet, string $baseUri)
    {
        $this->service = $service;
        $this->defaultWallet = $defaultWallet;
        $this->baseUri = rtrim($baseUri, '/\\');
    }

    /**
     * Zpracuje příchozí $_POST pole a rozdělí úkoly
     */
    public function handleRequest(array $postData): array
    {
        $action = $postData['api_action'] ?? '';

        if ($action === 'create') {
            return $this->handleCreate($postData);
        }

        if ($action === 'check_status') {
            return $this->handleCheckStatus($postData);
        }

        throw new \Exception("Neznámá akce API.", 400);
    }

    /**
     * Zpracování tvorby nové faktury
     */
    private function handleCreate(array $postData): array
    {
        $selectedWallet = $postData['wallet'] ?? $this->defaultWallet;
        
        // Předáme práci Service vrstvě
        $result = $this->service->createInvoiceAsAdmin($postData, $selectedWallet);

        return [
            'status' => 'ok',
            'url' => $this->baseUri . '/url_pay.php?inv=' . $result['token'],
            'token' => $result['token'],
            'amount' => $result['amount'],
            'desc' => $result['description'],
            'order_id' => $result['order_id'],
            'wallet' => $result['wallet'],
            'time' => time(),
            'expires_in_minutes' => $result['expires_in_minutes']
        ];
    }

    /**
     * Zpracování kontroly stavu na blockchainu
     */
/**
     * Zpracování kontroly stavu na blockchainu
     */
    private function handleCheckStatus(array $postData): array
    {
        $token = $postData['token'] ?? '';
        if (empty($token)) {
            throw new \Exception("Chybí token faktury.", 400);
        }

        // Získáme data ze sítě
        $statusData = $this->service->checkStatus($token);
        
        $inv = $statusData['invoice'] ?? [];
        $custom = $inv['p'] ?? [];

        // VÝPOČET CHYBĚJÍCÍ ČÁSTKY
        $expectedAmount = (float)($inv['v'] ?? 0);
        $receivedAmount = (float)($statusData['payment']['received_total'] ?? 0);
        
        // Pokud přišlo méně, než mělo, spočítáme rozdíl (max 0 zabrání záporným číslům)
        $missingAmount = max(0, $expectedAmount - $receivedAmount);

        return [
            'status' => 'ok',
            'payment_status' => $statusData['status'],
            
            // OPRAVA: Vracíme přesně vypočítaný nedoplatek
            'missing_amount' => number_format($missingAmount, 8, '.', ''), 
            
            'amount' => number_format($expectedAmount, 8, '.', ''),
            'desc' => $inv['d'] ?? '',
            'order_id' => $custom['order_id'] ?? '',
            'wallet' => $custom['wallet'] ?? $this->defaultWallet,
            'time' => $inv['t'] ?? time(),
            'url' => $this->baseUri . '/url_pay.php?inv=' . $token,
            'token' => $token
        ];
    }
}