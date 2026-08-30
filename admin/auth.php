<?php
// admin/auth.php - Ochrana administrace (Auditováno)
declare(strict_types=1);

// Bezpečné spuštění session, pokud ještě neběží
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. OCHRANA PROTI "BACK BUTTON" ÚTOKU (Zákaz cachování prohlížečem)
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// 2. STRIKTNÍ KONTROLA ROLE
if (empty($_SESSION['user_id']) || empty($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // Okamžité přesměrování a ukončení skriptu
    header("Location: ../client/login.php");
    exit;
}