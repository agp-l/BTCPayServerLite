<?php

declare(strict_types=1);

use BtcPayLite\BtcStatelessCheckoutController;
use BtcPayLite\BtcStatelessService;

require dirname(__DIR__) . '/vendor/autoload.php';

final class StatelessCheckoutTestService extends BtcStatelessService
{
    public function __construct()
    {
    }

    public function getPaymentPageData(string $token): array
    {
        return [
            'status' => 'underpaid',
            'is_expired' => false,
            'seconds_remaining' => 321,
            'invoice' => [
                'a' => 'bc1qcheckout',
                'v' => '0.00000005',
                'd' => 'Portable invoice',
                'p' => ['order_id' => 'MAIL-42'],
                't' => 1_700_000_000,
                'e' => 1_700_000_900,
            ],
            'payment' => [
                'received_total' => '0.00000003',
                'missing_amount' => '0.00000002',
            ],
            'bip21_uri' => 'bitcoin:bc1qcheckout?amount=0.00000005&label=Portable%20invoice',
        ];
    }
}

function checkoutBoundaryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$passes = [];
$controller = new BtcStatelessCheckoutController(new StatelessCheckoutTestService());
$status = $controller->paymentStatus('signed-token');

checkoutBoundaryAssert($status['status'] === 'underpaid', 'Checkout changed the payment status.');
checkoutBoundaryAssert($status['received_amount'] === '0.00000003', 'Checkout changed received satoshis.');
checkoutBoundaryAssert($status['missing_amount'] === '0.00000002', 'Checkout changed missing satoshis.');
$passes[] = 'maps exact public payment status data';

$root = dirname(__DIR__);
$adapter = file_get_contents($root . '/admin/url_pay.php');
$view = file_get_contents($root . '/admin/views/url_pay_view.php');
$script = file_get_contents($root . '/assets/stateless-checkout.js');
$style = file_get_contents($root . '/assets/stateless-checkout.css');
$adminView = file_get_contents($root . '/admin/views/url_invoices_view.php');
$adminScript = file_get_contents($root . '/assets/url-invoices.js');

foreach ([$adapter, $view, $script, $style, $adminView, $adminScript] as $source) {
    checkoutBoundaryAssert(is_string($source), 'A stateless checkout source file is missing.');
}

checkoutBoundaryAssert(
    !str_contains($adapter, 'AuthManager::requireRole'),
    'Signed public invoice still requires an admin session.'
);
checkoutBoundaryAssert(
    !preg_match('/api\.qrserver|cdnjs|fonts\.googleapis/', $adapter . $view),
    'Checkout still sends invoice or browser data to a third party.'
);
checkoutBoundaryAssert(
    !preg_match('/<style\b|<script>(?!\s*<\?php)/i', $view),
    'Checkout view contains inline CSS or JavaScript.'
);
checkoutBoundaryAssert(
    str_contains($adapter, "frame-ancestors 'none'"),
    'Checkout CSP does not prevent framing.'
);
$passes[] = 'keeps the public checkout private and self-contained';

checkoutBoundaryAssert(
    !str_contains($adminView, 'const CSRF_TOKEN'),
    'Admin stateless view still embeds its application JavaScript.'
);
checkoutBoundaryAssert(
    !preg_match('/\binnerHTML\b|insertAdjacentHTML|\beval\s*\(/', $adminScript),
    'Admin stateless script contains an unsafe HTML sink.'
);
checkoutBoundaryAssert(
    str_contains($adminScript, "searchParams.get('token')")
        && str_contains($adminScript, "searchParams.get('inv')"),
    'Admin verifier does not support current and legacy links.'
);
$passes[] = 'separates the admin stateless view from safe interactions';

foreach ($passes as $pass) {
    echo '[PASS] ' . $pass . PHP_EOL;
}
echo count($passes) . ' stateless checkout boundary tests passed.' . PHP_EOL;

