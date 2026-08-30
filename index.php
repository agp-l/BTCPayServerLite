<?php
// index.php - Hlavní směrovač (Front Controller)
declare(strict_types=1);
session_start();
ini_set('display_errors', '1'); // Při vývoji zapnuto
error_reporting(E_ALL);

require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config.php';

use BtcPayLite\UrlManager;
use BtcPayLite\AuthManager;

// Inicializace správce URL
$urlManager = new UrlManager();
// Načtení prvního a druhého segmentu URL
$segment1 = $urlManager->getSegment(0) ?: 'home';
$segment2 = $urlManager->getSegment(1) ?: '';

// ZÍSKÁNÍ AKTIVNÍHO MENU PRO ŠABLONY
$activeMenu = $urlManager->getActiveMenu();

// ==========================================
// ROUTER (Směrovač aplikací)
// ==========================================
// ... (zbytek tvého stávajícího kódu) ...

// ==========================================
// ROUTER (Směrovač aplikací)
// ==========================================

// 1. VĚTEV: ADMINISTRÁTORSKÁ SEKCE (/admin/...)
// 1. VĚTEV: ADMINISTRÁTORSKÁ SEKCE (/admin/...)
if ($segment1 === 'admin') {
    
    // Zabezpečení celé větve
    AuthManager::requireRole('admin', $urlManager->getBaseUrl() . '/login');

    // Pod-směrovač pro to, co je za slovem /admin/
    switch ($segment2) {
        case 'dashboard':
        case '': 
            require __DIR__ . '/admin/dashboard.php';
            break;
            
        case 'wallet':
            require __DIR__ . '/admin/wallet.php';
            break;

        case 'stores': // <--- ZDE JE OPRAVA PRO OBCHODY
            require __DIR__ . '/admin/stores.php';
            break;
            
        case 'invoices':
            require __DIR__ . '/admin/invoices.php';
            break;

        case 'webhooks': 
            require __DIR__ . '/admin/webhooks.php';
            break;

        case 'url_invoices': 
            require __DIR__ . '/admin/url_invoices.php';
            break;

        case 'test_shop': 
            require __DIR__ . '/admin/test_shop.php';
            break;

        case 'test_api_webhook': 
            require __DIR__ . '/admin/test_api_webhook.php';
            break;
            
        default:
            // Neznámá adresa -> přesměrování
            header("Location: " . $urlManager->getBaseUrl() . "/admin/dashboard");
            exit;
    }
}
// 2. VĚTEV: VEŘEJNÁ A KLIENTSKÁ SEKCE
else {
    switch ($segment1) {
        
        // --- VEŘEJNÉ STRÁNKY (Nová složka pages) ---
        case 'home':
        case 'prezentace':
            require __DIR__ . '/pages/prezentace.php'; 
            break;

        // --- KLIENTSKÁ SEKCE (Složka client) ---
        case 'login':
            require __DIR__ . '/client/login.php';
            break;

        case 'registrace':
            require __DIR__ . '/client/registrace.php';
            break;
            
        case 'dashboard':
        case 'client':
            // Sem případně nasměrujeme klientský index, pokud bude mít čistou URL
            require __DIR__ . '/client/index.php';
            break;

        // --- API A EXTERNÍ NÁSTROJE ---
        case 'api':
            require __DIR__ . '/api_stateless.php';
            break;

        case 'pay':
            require __DIR__ . '/pay.php';
            break;

        // --- VÝCHOZÍ PRAVIDLO (404) ---
        default:
            // Pokud uživatel zadá nesmyslnou URL, hodíme ho zpět na hlavní stranu
            header("Location: " . $urlManager->getBaseUrl() . "/"); 
            exit;
    }
}