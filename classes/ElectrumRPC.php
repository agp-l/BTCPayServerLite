<?php
declare(strict_types=1);
namespace BtcPayLite;

use Exception;
use stdClass;
/**
 * VRSTVA 1: Nízkoúrovňová komunikace s JSON-RPC serverem.
 */
class ElectrumRPC
{
    private string $rpcUrl;
    private ?string $rpcUser;
    private ?string $rpcPass;
    private int $timeout;

    public function __construct(string $host, int $port, ?string $user = null, ?string $pass = null, int $timeout = 30)
    {
        $this->rpcUrl = "http://{$host}:{$port}/";
        $this->rpcUser = $user;
        $this->rpcPass = $pass;
        $this->timeout = $timeout;
    }

    public function call(string $method, array $params = [])
    {
        $request = [
            'jsonrpc' => '2.0',
            'id'      => mt_rand(1, 1000000), // Bezpečné celočíselné ID
            'method'  => $method,
        ];
        
        // ZÁSADNÍ OPRAVA: Pro metody bez argumentů (jako je history) 
        // klíč 'params' podle specifikace JSON-RPC úplně vynecháme.
        // Tím se 100% vyhneme zmatení Python překladače v Electru.
        if (!empty($params)) {
            $request['params'] = $params;
        }

        $payload = json_encode($request, JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new Exception("Kritická chyba: Nelze zakódovat JSON pro metodu '{$method}'.");
        }

        $ch = curl_init($this->rpcUrl);
        
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $this->timeout,
        ];

        if ($this->rpcUser !== null && $this->rpcPass !== null) {
            $curlOptions[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $curlOptions[CURLOPT_USERPWD] = $this->rpcUser . ':' . $this->rpcPass;
        }

        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);

        if ($response === false) {
            $curlError = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL chyba spojení (Metoda '{$method}'): " . $curlError);
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 && $httpCode !== 401 && $httpCode !== 500) {
             throw new Exception("HTTP chyba {$httpCode} (Metoda '{$method}'). Odpověď: " . substr($response, 0, 200));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            // Teď už přesně víme, která metoda případně zlobí!
            throw new Exception("Neplatná JSON odpověď (Metoda '{$method}'). HTTP: {$httpCode}. Tělo: " . substr($response, 0, 200));
        }

        if (isset($decoded['error']) && $decoded['error'] !== null) {
            $errorMessage = is_array($decoded['error']) 
                ? json_encode($decoded['error'], JSON_UNESCAPED_UNICODE) 
                : (string) $decoded['error'];
                
            throw new Exception("Electrum Chyba ('{$method}'): " . $errorMessage);
        }

        return $decoded['result'] ?? null;
    }
}