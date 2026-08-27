<?php

namespace App\Services;

use App\Models\ShopifyProductVariant;
use App\Support\Barcode\QrCodeSvg;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ShopifyVariantBarcodeLabelService
{
    /**
     * QR / bold text payload: barcode if present, otherwise SKU.
     */
    public function payloadForVariant(ShopifyProductVariant $variant): string
    {
        $barcode = trim((string) ($variant->barcode ?? ''));
        if ($barcode !== '') {
            return $barcode;
        }

        return trim((string) ($variant->sku ?? ''));
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
     * Ensure a stored label exists for the current payload/name. Returns relative storage path.
     */
    public function ensureLabel(ShopifyProductVariant $variant, bool $force = false): ?string
    {
        $payload = $this->payloadForVariant($variant);
        if ($payload === '') {
            return null;
        }

        $name = $this->productNameForVariant($variant);
        $existingPath = trim((string) ($variant->barcode_label_path ?? ''));
        $existingPayload = trim((string) ($variant->barcode_label_payload ?? ''));

        if (
            ! $force
            && $existingPath !== ''
            && $existingPayload === $payload
            && Storage::disk('public')->exists($existingPath)
        ) {
            // Also regenerate if product title changed (payload same but name differs) —
            // compare by regenerating hash in filename is heavy; force when name in path meta missing.
            // Simpler: store payload only; force regenerate from callers when name/sku/barcode change.
            return $existingPath;
        }

        $svg = $this->buildLabelSvg($payload, $name);
        $dir = 'shopify/barcode-labels/'.$variant->connection_id;
        $filename = 'variant-'.$variant->id.'.svg';
        $path = $dir.'/'.$filename;

        Storage::disk('public')->put($path, $svg);

        $variant->barcode_label_path = $path;
        $variant->barcode_label_payload = $payload;
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

    private function buildLabelSvg(string $payload, string $productName): string
    {
        $qrDataUri = QrCodeSvg::dataUri($payload, 220);
        $qrB64 = '';
        if (strpos($qrDataUri, 'base64,') !== false) {
            $parts = explode('base64,', $qrDataUri, 2);
            $qrB64 = $parts[1] ?? '';
        }
        $qrInner = $qrB64 !== '' ? base64_decode($qrB64) : '';
        // Strip xml declaration from nested SVG
        $qrInner = preg_replace('/<\?xml[^>]*\?>/', '', $qrInner) ?? $qrInner;
        $qrInner = trim($qrInner);

        $safePayload = htmlspecialchars($payload, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $safeName = htmlspecialchars($productName, ENT_QUOTES | ENT_XML1, 'UTF-8');
        if (mb_strlen($safeName) > 48) {
            $safeName = htmlspecialchars(mb_substr($productName, 0, 45).'…', ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        // Embed QR as image via data URI to avoid nested SVG namespace issues in some browsers.
        $qrHref = htmlspecialchars($qrDataUri, ENT_QUOTES | ENT_XML1, 'UTF-8');

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="520" height="220" viewBox="0 0 520 220">
  <rect width="520" height="220" fill="#ffffff"/>
  <image href="{$qrHref}" x="24" y="30" width="160" height="160"/>
  <text x="210" y="95" font-family="Arial, Helvetica, sans-serif" font-size="28" font-weight="700" fill="#1e293b">{$safePayload}</text>
  <text x="210" y="130" font-family="Arial, Helvetica, sans-serif" font-size="15" font-weight="400" fill="#64748b">{$safeName}</text>
</svg>
SVG;
    }
}
