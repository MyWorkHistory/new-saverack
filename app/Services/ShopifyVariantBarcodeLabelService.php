<?php

namespace App\Services;

use App\Models\ShopifyProductVariant;
use App\Support\Barcode\QrCodeSvg;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ShopifyVariantBarcodeLabelService
{
    /** Label print size (inches). */
    public const LABEL_WIDTH_IN = 4.0;

    public const LABEL_HEIGHT_IN = 1.5;

    /** @var int ViewBox width at 96 dpi */
    public const LABEL_WIDTH_PX = 384;

    /** @var int ViewBox height at 96 dpi */
    public const LABEL_HEIGHT_PX = 144;

    /**
     * QR payload: barcode if present, otherwise SKU.
     */
    public function qrPayloadForVariant(ShopifyProductVariant $variant): string
    {
        $barcode = trim((string) ($variant->barcode ?? ''));
        if ($barcode !== '') {
            return $barcode;
        }

        return trim((string) ($variant->sku ?? ''));
    }

    /**
     * Bold line on the label: SKU when set, otherwise barcode.
     */
    public function displayCodeForVariant(ShopifyProductVariant $variant): string
    {
        $sku = trim((string) ($variant->sku ?? ''));
        if ($sku !== '') {
            return $sku;
        }

        return trim((string) ($variant->barcode ?? ''));
    }

    /**
     * @deprecated Use qrPayloadForVariant() or displayCodeForVariant().
     */
    public function payloadForVariant(ShopifyProductVariant $variant): string
    {
        return $this->qrPayloadForVariant($variant);
    }

    public function productNameForVariant(ShopifyProductVariant $variant): string
    {
        $variant->loadMissing('product');
        $name = trim((string) ($variant->product->title ?? ''));
        if ($name === '') {
            $name = trim((string) ($variant->title ?? ''));
        }

        return $name !== '' ? $name : 'Product';
    }

    /**
     * Cache key covering SKU, barcode, and product name so label text updates when any change.
     */
    public function labelFingerprint(ShopifyProductVariant $variant): string
    {
        return implode("\0", [
            $this->displayCodeForVariant($variant),
            $this->qrPayloadForVariant($variant),
            $this->productNameForVariant($variant),
        ]);
    }

    /**
     * Ensure a stored label exists for the current variant data. Returns relative storage path.
     */
    public function ensureLabel(ShopifyProductVariant $variant, bool $force = false): ?string
    {
        $qrPayload = $this->qrPayloadForVariant($variant);
        $displayCode = $this->displayCodeForVariant($variant);
        if ($qrPayload === '' && $displayCode === '') {
            return null;
        }

        if ($qrPayload === '') {
            $qrPayload = $displayCode;
        }

        $name = $this->productNameForVariant($variant);
        $fingerprint = $this->labelFingerprint($variant);
        $existingPath = trim((string) ($variant->barcode_label_path ?? ''));
        $existingPayload = trim((string) ($variant->barcode_label_payload ?? ''));

        if (
            ! $force
            && $existingPath !== ''
            && $existingPayload === $fingerprint
            && Storage::disk('public')->exists($existingPath)
        ) {
            return $existingPath;
        }

        $svg = $this->buildLabelSvg($qrPayload, $displayCode, $name);
        $dir = 'shopify/barcode-labels/'.$variant->connection_id;
        $filename = 'variant-'.$variant->id.'.svg';
        $path = $dir.'/'.$filename;

        Storage::disk('public')->put($path, $svg);

        $variant->barcode_label_path = $path;
        $variant->barcode_label_payload = $fingerprint;
        $variant->barcode_label_generated_at = now();
        $variant->save();

        return $path;
    }

    public function publicUrl(ShopifyProductVariant $variant): ?string
    {
        $path = $this->ensureLabel($variant);
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function absolutePath(ShopifyProductVariant $variant): ?string
    {
        $path = $this->ensureLabel($variant);
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->path($path);
    }

    /**
     * Stream a printable PDF label (4in x 1.5in) — same open-in-tab flow as ShipHero inventory.
     */
    public function streamPdf(ShopifyProductVariant $variant): Response
    {
        $qrPayload = $this->qrPayloadForVariant($variant);
        $displayCode = $this->displayCodeForVariant($variant);
        if ($qrPayload === '' && $displayCode === '') {
            throw new \RuntimeException('Add a barcode or SKU before printing a label.');
        }
        if ($qrPayload === '') {
            $qrPayload = $displayCode;
        }

        $productName = $this->productNameForVariant($variant);
        if (mb_strlen($productName) > 52) {
            $productName = rtrim(mb_substr($productName, 0, 49)).'…';
        }

        // 4in x 1.5in at 72 dpi.
        $pageW = (int) round(self::LABEL_WIDTH_IN * 72);
        $pageH = (int) round(self::LABEL_HEIGHT_IN * 72);
        $qrSize = 86;
        $qrLeft = 8;
        $qrTop = round(($pageH - $qrSize) / 2, 1);
        $textLeft = $qrLeft + $qrSize + 10;
        $textW = $pageW - $textLeft - 8;
        $codeTop = round($pageH * 0.38, 1);
        $nameTop = round($pageH * 0.58, 1);

        $pdf = Pdf::loadView('pdf.shopify.variant-barcode-label', [
            'qrDataUri' => QrCodeSvg::dataUri($qrPayload, 200),
            'displayCode' => $displayCode,
            'productName' => $productName,
            'pageW' => $pageW,
            'pageH' => $pageH,
            'qrSize' => $qrSize,
            'qrLeft' => $qrLeft,
            'qrTop' => $qrTop,
            'textLeft' => $textLeft,
            'textW' => $textW,
            'codeTop' => $codeTop,
            'nameTop' => $nameTop,
            'codeFont' => 15,
            'nameFont' => 9,
        ])->setPaper([0, 0, $pageW, $pageH]);

        $filename = 'barcode-label-'.$variant->id.'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    private function buildLabelSvg(string $qrPayload, string $displayCode, string $productName): string
    {
        $w = self::LABEL_WIDTH_PX;
        $h = self::LABEL_HEIGHT_PX;
        $widthIn = self::LABEL_WIDTH_IN;
        $heightIn = self::LABEL_HEIGHT_IN;

        $qrDataUri = QrCodeSvg::dataUri($qrPayload, 108);
        $qrHref = htmlspecialchars($qrDataUri, ENT_QUOTES | ENT_XML1, 'UTF-8');

        $safeDisplay = htmlspecialchars($displayCode, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $safeName = htmlspecialchars($productName, ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (mb_strlen($safeName) > 52) {
            $safeName = htmlspecialchars(mb_substr($productName, 0, 49).'…', ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="{$widthIn}in" height="{$heightIn}in" viewBox="0 0 {$w} {$h}">
  <rect width="{$w}" height="{$h}" fill="#ffffff"/>
  <image href="{$qrHref}" x="10" y="18" width="108" height="108"/>
  <text x="132" y="58" font-family="Arial, Helvetica, sans-serif" font-size="20" font-weight="700" fill="#1e293b">{$safeDisplay}</text>
  <text x="132" y="84" font-family="Arial, Helvetica, sans-serif" font-size="12" font-weight="400" fill="#64748b">{$safeName}</text>
</svg>
SVG;
    }
}
