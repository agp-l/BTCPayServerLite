<?php

declare(strict_types=1);

namespace BtcPayLite;

/**
 * Composition root for one webhook cron run.
 */
class WebhookCronApplication
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getCronKey(): string
    {
        $cronKey = $this->config['cron_key'] ?? null;
        if (!is_string($cronKey)) {
            throw new WebhookDeliveryException(
                'Cron authentication configuration is invalid.',
                'configure_cron'
            );
        }

        return $cronKey;
    }

    /** @return array<string, mixed> */
    public function run(): array
    {
        $databasePort = $this->integerConfig('db_port', 3306, 1, 65_535);
        $invoiceLimit = $this->integerConfig('webhook_invoice_batch_limit', 100, 1, 500);
        $deliveryLimit = $this->integerConfig('webhook_delivery_batch_limit', 100, 1, 500);

        $database = new Database(
            $this->stringConfig('db_host'),
            $this->stringConfig('db_name'),
            $this->stringConfig('db_user'),
            $this->stringConfig('db_pass', true),
            $databasePort
        );
        $rpc = new ElectrumRPC(
            $this->stringConfig('rpc_host'),
            $this->integerConfig('rpc_port', null, 1, 65_535),
            $this->nullableStringConfig('rpc_user'),
            $this->nullableStringConfig('rpc_pass')
        );
        $wallet = new ElectrumWallet($rpc);
        $invoiceManager = new BtcInvoiceManager(
            $wallet,
            $this->stringConfig('secret_key'),
            $database
        );
        $repository = new WebhookDeliveryRepository($database);
        $endpointPolicy = new WebhookEndpointPolicy(
            null,
            ($this->config['allow_local_webhooks'] ?? false) === true
        );
        $transport = new CurlWebhookTransport($endpointPolicy);
        $processor = new WebhookProcessor(
            $database,
            $wallet,
            $invoiceManager,
            $repository,
            $transport
        );

        return $processor->run($invoiceLimit, $deliveryLimit);
    }

    private function stringConfig(string $key, bool $allowEmpty = false): string
    {
        $value = $this->config[$key] ?? null;
        if (
            !is_string($value)
            || str_contains($value, "\0")
            || (!$allowEmpty && trim($value) === '')
        ) {
            throw new WebhookDeliveryException(
                'Webhook cron configuration is invalid.',
                'configure_cron'
            );
        }

        return $value;
    }

    private function nullableStringConfig(string $key): ?string
    {
        $value = $this->config[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || str_contains($value, "\0")) {
            throw new WebhookDeliveryException(
                'Webhook cron configuration is invalid.',
                'configure_cron'
            );
        }

        return $value;
    }

    private function integerConfig(
        string $key,
        ?int $default,
        int $minimum,
        int $maximum
    ): int {
        $value = $this->config[$key] ?? $default;
        if (is_string($value) && ctype_digit($value)) {
            $value = filter_var($value, FILTER_VALIDATE_INT);
        }
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new WebhookDeliveryException(
                'Webhook cron configuration is invalid.',
                'configure_cron'
            );
        }

        return $value;
    }
}
