<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$entry = file_get_contents($root . '/checkout/pay.php');
$view = file_get_contents($root . '/checkout/views/pay_view.php');
$errorView = file_get_contents($root . '/checkout/views/error_view.php');
$script = file_get_contents($root . '/assets/checkout.js');
$style = file_get_contents($root . '/assets/checkout.css');
$router = file_get_contents($root . '/classes/ApplicationRouter.php');
$qrGenerator = file_get_contents($root . '/classes/CheckoutQrCodeGenerator.php');
$composer = file_get_contents($root . '/composer.json');

foreach ([
    'checkout entry' => $entry,
    'checkout view' => $view,
    'checkout error view' => $errorView,
    'checkout script' => $script,
    'checkout stylesheet' => $style,
    'application router' => $router,
    'checkout QR generator' => $qrGenerator,
    'Composer manifest' => $composer,
] as $name => $source) {
    if (!is_string($source) || $source === '') {
        throw new RuntimeException('Unable to read ' . $name . '.');
    }
}

$checks = [
    'entry delegates to the checkout controller and factory'
        => str_contains($entry, 'DatabaseCheckoutController')
            && str_contains($entry, 'DatabaseCheckoutFactory::fromConfig'),
    'entry contains no direct SQL or manual database lock'
        => !preg_match('/\bSELECT\b|\bUPDATE\b|GET_LOCK|RELEASE_LOCK/i', $entry),
    'entry disables displayed PHP errors'
        => str_contains($entry, "ini_set('display_errors', '0')")
            && !str_contains($entry, "ini_set('display_errors', '1')"),
    'entry sends restrictive checkout security headers'
        => str_contains($entry, 'Content-Security-Policy')
            && str_contains($entry, "default-src 'none'")
            && str_contains($entry, 'Referrer-Policy: no-referrer')
            && str_contains($entry, 'Cache-Control: no-store'),
    'checkout uses no floating-point amount formatting'
        => !str_contains($entry . $view, '(float)')
            && !str_contains($entry . $view, 'number_format'),
    'checkout no longer discloses payment data to a QR service'
        => !str_contains($entry . $view . $script, 'api.qrserver.com')
            && !str_contains($entry . $view . $script, 'googleapis.com')
            && !str_contains($entry . $view . $script, 'cdnjs.cloudflare.com'),
    'checkout pins a PHP 8.0 compatible QR library'
        => str_contains($composer, '"endroid/qr-code": "4.7.0"')
            && str_contains($composer, '"php": "^8.0"'),
    'checkout generates its BIP21 QR locally as SVG'
        => str_contains($qrGenerator, 'SvgWriter')
            && str_contains($qrGenerator, 'generateDataUri')
            && str_contains($view, 'QR kód bitcoinové platby')
            && str_contains($entry, "img-src data:"),
    'view contains no inline executable script or style block'
        => !preg_match('/<style\b/i', $view)
            && !preg_match('/<script(?![^>]*\bsrc=)/i', $view),
    'view escapes invoice-controlled values'
        => substr_count($view, 'htmlspecialchars(') >= 10,
    'client renders server data without innerHTML'
        => !str_contains($script, 'innerHTML')
            && str_contains($script, 'textContent'),
    'client polls same-origin without cached credentials leakage'
        => str_contains($script, "credentials: 'same-origin'")
            && str_contains($script, "cache: 'no-store'"),
    'router accepts only GET and HEAD for checkout'
        => str_contains(
            $router,
            "'/pay' => \$this->page(['GET', 'HEAD'], 'checkout/pay.php', 'pay')"
        ),
];

$passes = 0;
foreach ($checks as $message => $passed) {
    if (!$passed) {
        throw new RuntimeException('Checkout boundary check failed: ' . $message);
    }
    echo '[PASS] ' . $message . PHP_EOL;
    ++$passes;
}

echo $passes . ' checkout HTTP boundary tests passed.' . PHP_EOL;
