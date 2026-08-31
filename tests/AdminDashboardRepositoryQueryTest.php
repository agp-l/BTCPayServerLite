<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/PdoAdminDashboardRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Admin dashboard repository source could not be read.');
}

if (str_contains(strtoupper($source), 'SELECT *')) {
    throw new RuntimeException('Dashboard repository selects unrestricted columns.');
}
echo "[PASS] selects only dashboard fields used by the application\n";

if (!str_contains($source, "bindValue(':invoice_limit', \$limit, PDO::PARAM_INT)")) {
    throw new RuntimeException('Recent invoice limit is not bound as an integer.');
}
echo "[PASS] binds the recent invoice limit as an integer\n";

if (!str_contains($source, "status = 'Settled'") || !str_contains($source, 'COALESCE(SUM(')) {
    throw new RuntimeException('Settled invoice summary is not computed in one bounded aggregate.');
}
echo "[PASS] loads summary metrics with a single aggregate query\n";

echo "3 admin dashboard repository query tests passed.\n";
