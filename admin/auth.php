<?php
// admin/auth.php - shared administrator authorization boundary
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\AuthManager;

AuthManager::requireRole('admin', '../login');
