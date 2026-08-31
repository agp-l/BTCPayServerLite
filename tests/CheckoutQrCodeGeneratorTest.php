<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BtcPayLite\CheckoutQrCodeGenerator;

function qrAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @var list<string> $passes */
$passes = [];
$generator = new CheckoutQrCodeGenerator();
$bip21 = 'bitcoin:bc1qcheckouttestaddress000000000000000000000'
    . '?amount=0.00000001&message=Faktura%20inv_checkout123';
$dataUri = $generator->generateDataUri($bip21);

if (class_exists(Endroid\QrCode\Builder\Builder::class)) {
    qrAssert(is_string($dataUri), 'Installed QR library did not produce a data URI');
    qrAssert(
        str_starts_with($dataUri, 'data:image/svg+xml'),
        'Checkout QR is not a local SVG data URI'
    );
    $passes[] = 'generates a local SVG QR for the complete BIP21 URI';
} else {
    qrAssert($dataUri === null, 'Missing QR dependency did not degrade safely');
    $passes[] = 'degrades safely when the optional QR package is not installed';
}

try {
    $generator->generateDataUri('https://example.com/not-bitcoin');
    throw new RuntimeException('Non-BIP21 QR payload was accepted');
} catch (InvalidArgumentException) {
    $passes[] = 'rejects non-BIP21 QR payloads';
}

$source = file_get_contents(__DIR__ . '/../classes/CheckoutQrCodeGenerator.php');
qrAssert(is_string($source), 'Unable to read QR generator source');
qrAssert(
    !preg_match('/curl_|file_get_contents\s*\(\s*[\'\"]https?:/i', $source),
    'QR generator contains a remote network request'
);
$passes[] = 'does not send payment data to a remote QR service';

foreach ($passes as $pass) {
    echo '[PASS] ' . $pass . PHP_EOL;
}
echo count($passes) . ' checkout QR tests passed.' . PHP_EOL;
