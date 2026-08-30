<?php
// client/login.php (Zabezpečený, plně objektový kontroler)
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/../config.php';

use BtcPayLite\Database;
use BtcPayLite\AuthManager;

$db = new Database($config['db_host'], $config['db_name'], $config['db_user'], $config['db_pass']);
$auth = new AuthManager($db);

// Odhlášení jedním čistým voláním metody
if (isset($_GET['logout'])) {
    $auth->logout();
    header("Location: login.php");
    exit;
}

$error = '';

// Přihlášení
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $user = $auth->login($_POST['email'] ?? '', $_POST['password'] ?? '');
        
        // Směrovač podle role
        header("Location: " . ($user['role'] === 'admin' ? '../admin/index.php' : 'index.php'));
        exit;
        
    } catch (\Exception $e) {
        // OBRANA PROTI BRUTE-FORCE: Umělé zpoždění odpovědi
        sleep(1); 
        // Vypíše pouze naše kontrolované chybové hlášky z AuthManageru
        $error = $e->getMessage();
        
    } catch (\Throwable $e) {
        // OBRANA PROTI ÚNIKU DAT: Zachytí kritické systémové chyby (např. výpadek databáze)
        sleep(1);
        error_log("Kritická chyba v login.php: " . $e->getMessage()); // Zapíše skrytou chybu do server logu
        $error = "Došlo k interní systémové chybě. Zkuste to prosím později."; // Uživatel vidí jen toto
    }
}

// Načtení šablony
require __DIR__ . '/views/login_view.php';