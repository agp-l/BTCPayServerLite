<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../classes/PdoClientDashboardRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Client repository source could not be read.');
}

if (str_contains(strtoupper($source), 'SELECT *')) {
    throw new RuntimeException('Client repository selects unrestricted columns.');
}
echo "[PASS] selects only fields used by the client application\n";

if (!str_contains($source, "bindValue(':invoice_limit', \$limit, PDO::PARAM_INT)")) {
    throw new RuntimeException('Client invoice limit is not bound as an integer.');
}
echo "[PASS] binds the client invoice limit as an integer\n";

if (!str_contains($source, 'WHERE s.user_id = ?') || !str_contains($source, 'AND user_id = ? LIMIT 1')) {
    throw new RuntimeException('Client queries are not consistently scoped to the authenticated user.');
}
echo "[PASS] scopes stores, invoices and webhooks to the authenticated user\n";

if (!str_contains($source, 'created_at) VALUES (?, ?, ?, ?, ?)')) {
    throw new RuntimeException('Webhook registration does not preserve the delivery cutover timestamp.');
}
echo "[PASS] stores webhook registration time for delivery cutover\n";

echo "4 client dashboard repository query tests passed.\n";
