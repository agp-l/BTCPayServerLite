<?php

declare(strict_types=1);

namespace BtcPayLite;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\SvgWriter;
use InvalidArgumentException;
use Throwable;

/**
 * Generates a local SVG QR code for a validated BIP21 payment URI.
 *
 * No invoice data is sent over the network. A missing optional Composer
 * dependency degrades to the regular BIP21 wallet link.
 */
final class CheckoutQrCodeGenerator
{
    public function generateDataUri(string $bip21Uri): ?string
    {
        $this->validateBip21Uri($bip21Uri);

        if (!class_exists(Builder::class)) {
            return null;
        }

        try {
            return Builder::create()
                ->writer(new SvgWriter())
                ->data($bip21Uri)
                ->encoding(new Encoding('UTF-8'))
                ->errorCorrectionLevel(new ErrorCorrectionLevelMedium())
                ->size(320)
                ->margin(16)
                ->roundBlockSizeMode(new RoundBlockSizeModeMargin())
                ->foregroundColor(new Color(8, 74, 48))
                ->backgroundColor(new Color(255, 255, 255))
                ->validateResult(false)
                ->build()
                ->getDataUri();
        } catch (Throwable $exception) {
            error_log(sprintf(
                'Checkout QR generation failed: %s (%s)',
                $exception->getMessage(),
                $exception::class
            ));

            return null;
        }
    }

    private function validateBip21Uri(string $bip21Uri): void
    {
        if (
            strlen($bip21Uri) < 20
            || strlen($bip21Uri) > 2_048
            || preg_match('/\Abitcoin:[A-Za-z0-9]{14,100}\?.+\z/D', $bip21Uri) !== 1
            || preg_match('/[\x00-\x1F\x7F]/', $bip21Uri) === 1
        ) {
            throw new InvalidArgumentException('BIP21 URI is invalid.');
        }
    }
}
