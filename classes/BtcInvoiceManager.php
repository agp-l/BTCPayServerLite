<?php
declare(strict_types=1);
namespace BtcPayLite;

use Exception;
/**
 * VRSTVA 3: Obojživelný systém pro kryptoměnové faktury.
 * Podporuje profi databázový režim (BTCPay API) i lehký bezstavový režim (URL HMAC).
 */
class BtcInvoiceManager
{
    private ElectrumWallet $wallet;
    private string $secretKey;
    private ?Database $db;

    public function __construct(ElectrumWallet $wallet, string $secretKey, ?Database $db = null)
    {
        if (empty($secretKey) || strlen($secretKey) < 16) {
            throw new Exception("Kritická chyba: Pro faktury je vyžadován silný secretKey (min. 16 znaků).");
        }
        $this->wallet = $wallet;
        $this->secretKey = $secretKey;
        $this->db = $db;
    }

    // ==============================================================================
    // ČÁST A: STANDARDNÍ BTCPAY DATABÁZOVÝ REŽIM (PROFI SAAS)
    // ==============================================================================

    public function createDatabaseInvoice(string $storeId, float $amountBtc, array $metadata = [], int $expirationMinutes = 15): array
    {
        if ($this->db === null) throw new Exception("Databáze není inicializována.");
        if ($amountBtc <= 0) throw new Exception("Částka musí být větší než nula.");

        $address = $this->wallet->getNewAddress();
        $safeAmount = number_format($amountBtc, 8, '.', '');
        $invoiceId = 'inv_' . substr(bin2hex(random_bytes(16)), 0, 12);
        
        $timeNow = time();
        $expiresAt = $timeNow + ($expirationMinutes * 60);

        // Bezpečnostní kontrola JSON dat
        $jsonMetadata = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        if ($jsonMetadata === false) {
            throw new Exception("Metadata obsahují nepovolené znaky a nelze je uložit.");
        }

        $stmt = $this->db->getPdo()->prepare("
            INSERT INTO invoices (id, store_id, btc_address, amount, status, metadata, created_at, expires_at) 
            VALUES (?, ?, ?, ?, 'New', ?, ?, ?)
        ");
        
        $stmt->execute([
            $invoiceId, $storeId, $address, $safeAmount, $jsonMetadata, $timeNow, $expiresAt
        ]);

        return [
            'id' => $invoiceId,
            'address' => $address,
            'amount' => $safeAmount,
            'status' => 'New',
            'created_at' => $timeNow,
            'expires_at' => $expiresAt,
            'bip21_uri' => $this->generateBip21Uri($address, $safeAmount, 'Faktura ' . $invoiceId)
        ];
    }

    /**
     * Rychlé načtení faktury z DB (pro vykreslení stránky, nezatěžuje Electrum daemon).
     */
    public function getDatabaseInvoice(string $invoiceId): array
    {
        if ($this->db === null) throw new Exception("Databáze není inicializována.");

        $stmt = $this->db->getPdo()->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            throw new Exception("Faktura nenalezena v databázi.");
        }

        $invoice['metadata'] = json_decode($invoice['metadata'] ?? '{}', true);
        $invoice['bip21_uri'] = $this->generateBip21Uri($invoice['btc_address'], number_format((float)$invoice['amount'], 8, '.', ''), 'Faktura ' . $invoiceId);
        
        return $invoice;
    }

    /**
     * Zkontroluje stav v blockchainu a updatuje MySQL. Zátěžová metoda (zavolá Electrum).
     */
    public function checkDatabasePaymentStatus(string $invoiceId): array
    {
        $invoice = $this->getDatabaseInvoice($invoiceId); // Využijeme čtecí metodu zapsanou výše

        $address = $invoice['btc_address'];
        $expectedAmount = (float)$invoice['amount'];
        $expirationTime = (int)$invoice['expires_at'];
        $currentDbStatus = $invoice['status'];
        
        $isExpired = time() > $expirationTime;

        // Dotaz do uzlu
        $balance = $this->wallet->getAddressBalance($address);
        $confirmed = (float)($balance['confirmed']);
        $totalReceived = $confirmed + (float)($balance['unconfirmed']);

        $newStatus = 'New';
        if ($totalReceived >= $expectedAmount) {
            $newStatus = ($confirmed >= $expectedAmount) ? 'Settled' : 'Processing';
        } elseif ($totalReceived > 0) {
            // Bylo zasláno málo. BTCPay používá 'New' a v additionalStatus by dalo 'PaidPartial'
            $newStatus = 'New'; 
        } else {
            $newStatus = $isExpired ? 'Expired' : 'New';
        }

        if ($newStatus !== $currentDbStatus) {
            $updateStmt = $this->db->getPdo()->prepare("UPDATE invoices SET status = ? WHERE id = ?");
            $updateStmt->execute([$newStatus, $invoiceId]);
        }

        return [
            'id' => $invoiceId,
            'status' => $newStatus,
            'invoice' => $invoice,
            'payment' => [
                'total_received'  => number_format($totalReceived, 8, '.', ''),
                'missing_amount'  => number_format(max(0, $expectedAmount - $totalReceived), 8, '.', '')
            ]
        ];
    }

    // ==============================================================================
    // ČÁST B: STATELESS LITE REŽIM (URL TOKENY BEZ DATABÁZE)
    // ==============================================================================

    public function createStatelessInvoice(float $amountBtc, string $description, array $customData = [], int $expirationMinutes = 15): array
    {
        if ($amountBtc <= 0) throw new Exception("Částka faktury musí být větší než nula.");

        $address = $this->wallet->getNewAddress();
        $safeAmount = number_format($amountBtc, 8, '.', '');
        $timeNow = time();

        $payload = [
            'a' => $address,
            'v' => $safeAmount,
            'd' => $description,
            'p' => $customData,
            't' => $timeNow,
            'e' => $timeNow + ($expirationMinutes * 60)
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new Exception("Chyba při kódování dat faktury.");
        if (strlen($json) > 1024) throw new Exception("Custom data jsou příliš velká (Limit URL).");
        
        $base64 = strtr(base64_encode($json), '+/', '-_');
        $signature = hash_hmac('sha256', $base64, $this->secretKey);
        
        return [
            'token' => $base64 . '.' . $signature,
            'bip21_uri' => $this->generateBip21Uri($address, $safeAmount, $description)
        ];
    }

    /**
     * Rychlé načtení z Tokenu (bez volání Electra).
     */
    public function decodeStatelessToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) throw new Exception("Neplatný formát faktury.");

        list($base64, $signature) = $parts;
        $expectedSignature = hash_hmac('sha256', $base64, $this->secretKey);
        
        if (!hash_equals($expectedSignature, $signature)) {
            throw new Exception("Bezpečnostní chyba: Pečeť faktury byla porušena.");
        }

        $json = base64_decode(strtr($base64, '-_', '+/'));
        $data = json_decode($json, true);
        
        if (!is_array($data) || empty($data['a']) || empty($data['v']) || empty($data['e'])) {
            throw new Exception("Faktura obsahuje poškozená data.");
        }

        return $data;
    }

    /**
     * Zkontroluje stav v blockchainu (Zátěžová metoda).
     */
    public function checkStatelessPaymentStatus(string $token): array
    {
        $data = $this->decodeStatelessToken($token);

        $address = $data['a'];
        $expectedAmount = (float)$data['v'];
        $expirationTime = (int)$data['e'];
        $isExpired = time() > $expirationTime;

        $balance = $this->wallet->getAddressBalance($address);
        $confirmed = (float)($balance['confirmed']);
        $totalReceived = $confirmed + (float)($balance['unconfirmed']);

        // U Lite režimu si držíme naše podrobné stavové kódy pro jednoduchý frontend
        if ($totalReceived >= $expectedAmount) {
            $status = ($confirmed >= $expectedAmount) ? 'paid' : 'pending_mempool';
        } elseif ($totalReceived > 0) {
            $status = 'underpaid';
        } else {
            $status = $isExpired ? 'expired' : 'unpaid';
        }

        return [
            'status' => $status,
            'is_expired' => $isExpired,
            'seconds_remaining' => $isExpired ? 0 : ($expirationTime - time()),
            'invoice' => $data,
            'payment' => [
                'total_received'  => number_format($totalReceived, 8, '.', '')
            ],
            'bip21_uri' => $this->generateBip21Uri($address, number_format($expectedAmount, 8, '.', ''), $data['d'] ?? '')
        ];
    }

    // ==============================================================================
    // POMOCNÉ METODY
    // ==============================================================================

    private function generateBip21Uri(string $address, string $amount, string $label): string
    {
        $uri = "bitcoin:{$address}?amount={$amount}";
        if (!empty($label)) {
            $uri .= "&message=" . rawurlencode($label);
        }
        return $uri;
    }
}