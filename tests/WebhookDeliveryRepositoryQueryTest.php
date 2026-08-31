<?php

declare(strict_types=1);

/**
 * Guards the SQL predicates that prevent a newly registered webhook from
 * receiving invoices created before that registration.
 */
$source = file_get_contents(dirname(__DIR__) . '/classes/WebhookDeliveryRepository.php');
if (!is_string($source)) {
    throw new RuntimeException('Webhook delivery repository source could not be read.');
}

$predicates = [
    "JOIN webhooks AS webhook ON webhook.store_id = invoice.store_id\n                    AND webhook.created_at <= invoice.created_at",
    "WHERE webhook.store_id = ?\n                            AND webhook.created_at <= invoice.created_at",
];

foreach ($predicates as $predicate) {
    if (!str_contains($source, $predicate)) {
        throw new RuntimeException('Webhook registration cutover predicate is missing.');
    }
}

echo "2 webhook registration cutover checks passed." . PHP_EOL;
