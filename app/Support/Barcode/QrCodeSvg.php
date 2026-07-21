<?php

namespace App\Support\Barcode;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * DomPDF-friendly QR code as an SVG data URI (no GD/Imagick required).
 */
final class QrCodeSvg
{
    public static function dataUri(string $value, int $size = 200): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = ' ';
        }

        $renderer = new ImageRenderer(
            new RendererStyle($size, 0),
            new SvgImageBackEnd()
        );
        $svg = (new Writer($renderer))->writeString($value);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
