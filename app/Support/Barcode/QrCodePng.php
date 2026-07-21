<?php

namespace App\Support\Barcode;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * DomPDF-friendly QR code as a PNG data URI.
 */
final class QrCodePng
{
    public static function dataUri(string $value, int $size = 200): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = ' ';
        }

        $qrCode = QrCode::create($value)
            ->setSize($size)
            ->setMargin(0);

        $result = (new PngWriter())->write($qrCode);

        return $result->getDataUri();
    }
}
