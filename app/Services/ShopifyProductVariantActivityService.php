<?php

namespace App\Services;

use App\Models\ShopifyProduct;
use App\Models\ShopifyProductVariant;
use App\Models\ShopifyProductVariantActivity;
use App\Models\User;

class ShopifyProductVariantActivityService
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function record(
        ShopifyProductVariant $variant,
        string $type,
        string $title,
        ?string $detail = null,
        ?User $actor = null,
        ?string $actorLabel = null,
        ?array $meta = null
    ): ShopifyProductVariantActivity {
        $label = $actorLabel;
        if ($label === null || $label === '') {
            if ($actor !== null) {
                $label = trim((string) ($actor->name ?? '')) ?: 'User';
            } else {
                $label = 'System';
            }
        }

        return ShopifyProductVariantActivity::query()->create([
            'shopify_variant_id' => (int) $variant->id,
            'type' => $type,
            'title' => $title,
            'detail' => $detail,
            'meta' => $meta,
            'actor_user_id' => $actor !== null ? (int) $actor->id : null,
            'actor_label' => $label,
        ]);
    }

    public function recordCreated(ShopifyProductVariant $variant, ?User $actor = null): ShopifyProductVariantActivity
    {
        return $this->record(
            $variant,
            ShopifyProductVariantActivity::TYPE_CREATED,
            'Product Created',
            null,
            $actor,
            $actor === null ? 'System' : null
        );
    }

    /**
     * Compare before/after snapshots and write product-field timeline events.
     *
     * @param  array{sku?:string|null, product_title?:string|null, barcode?:string|null, weight?:float|null, weight_unit?:string|null, length?:float|null, width?:float|null, height?:float|null, dimension_unit?:string|null}  $before
     * @param  array{sku?:string|null, product_title?:string|null, barcode?:string|null, weight?:float|null, weight_unit?:string|null, length?:float|null, width?:float|null, height?:float|null, dimension_unit?:string|null}  $after
     */
    public function recordFieldChanges(
        ShopifyProductVariant $variant,
        array $before,
        array $after,
        ?User $actor = null
    ): void {
        $beforeSku = trim((string) ($before['sku'] ?? ''));
        $afterSku = trim((string) ($after['sku'] ?? ''));
        if ($beforeSku !== $afterSku) {
            $label = $afterSku !== '' ? $afterSku : '—';
            $this->record(
                $variant,
                ShopifyProductVariantActivity::TYPE_SKU,
                'SKU Updated to: '.$label,
                null,
                $actor
            );
        }

        $beforeTitle = trim((string) ($before['product_title'] ?? ''));
        $afterTitle = trim((string) ($after['product_title'] ?? ''));
        if ($beforeTitle !== $afterTitle && $afterTitle !== '') {
            $this->record(
                $variant,
                ShopifyProductVariantActivity::TYPE_NAME,
                'Product Name Updated to: '.$afterTitle,
                null,
                $actor
            );
        }

        $beforeBarcode = trim((string) ($before['barcode'] ?? ''));
        $afterBarcode = trim((string) ($after['barcode'] ?? ''));
        if ($beforeBarcode !== $afterBarcode) {
            $label = $afterBarcode !== '' ? $afterBarcode : '—';
            $this->record(
                $variant,
                ShopifyProductVariantActivity::TYPE_BARCODE,
                'Barcode updated to: '.$label,
                null,
                $actor
            );
        }

        $beforeWeight = $this->normalizeNumber($before['weight'] ?? null);
        $afterWeight = $this->normalizeNumber($after['weight'] ?? null);
        $beforeWUnit = strtoupper(trim((string) ($before['weight_unit'] ?? '')));
        $afterWUnit = strtoupper(trim((string) ($after['weight_unit'] ?? '')));
        if ($beforeWeight !== $afterWeight || $beforeWUnit !== $afterWUnit) {
            $unit = $afterWUnit !== '' ? strtolower($afterWUnit) : 'lbs';
            $display = $afterWeight !== null
                ? rtrim(rtrim(number_format($afterWeight, 4, '.', ''), '0'), '.').' '.$unit
                : '—';
            $this->record(
                $variant,
                ShopifyProductVariantActivity::TYPE_WEIGHT,
                'Weight Updated to: '.$display,
                null,
                $actor
            );
        }

        $beforeL = $this->normalizeNumber($before['length'] ?? null);
        $beforeW = $this->normalizeNumber($before['width'] ?? null);
        $beforeH = $this->normalizeNumber($before['height'] ?? null);
        $afterL = $this->normalizeNumber($after['length'] ?? null);
        $afterW = $this->normalizeNumber($after['width'] ?? null);
        $afterH = $this->normalizeNumber($after['height'] ?? null);
        $beforeDUnit = strtoupper(trim((string) ($before['dimension_unit'] ?? '')));
        $afterDUnit = strtoupper(trim((string) ($after['dimension_unit'] ?? '')));
        if (
            $beforeL !== $afterL
            || $beforeW !== $afterW
            || $beforeH !== $afterH
            || $beforeDUnit !== $afterDUnit
        ) {
            $this->record(
                $variant,
                ShopifyProductVariantActivity::TYPE_DIMENSIONS,
                'Dimensions Updated',
                null,
                $actor,
                null,
                [
                    'length' => $afterL,
                    'width' => $afterW,
                    'height' => $afterH,
                    'unit' => $afterDUnit !== '' ? $afterDUnit : null,
                ]
            );
        }
    }

    public function recordProductTypeChange(
        ShopifyProductVariant $variant,
        string $beforeKind,
        string $afterKind,
        ?User $actor = null
    ): void {
        $before = ShopifyProduct::normalizeCrmProductKind($beforeKind);
        $after = ShopifyProduct::normalizeCrmProductKind($afterKind);
        if ($before === $after) {
            return;
        }

        $this->record(
            $variant,
            ShopifyProductVariantActivity::TYPE_PRODUCT_TYPE,
            'Product Type Updated to: '.ShopifyProduct::crmProductKindLabel($after),
            null,
            $actor,
            null,
            ['from' => $before, 'to' => $after]
        );
    }

    public function recordBundleUpdated(ShopifyProductVariant $variant, ?User $actor = null, ?string $detail = null): void
    {
        $this->record(
            $variant,
            ShopifyProductVariantActivity::TYPE_BUNDLE_UPDATED,
            'Bundle Updated',
            $detail,
            $actor
        );
    }

    public function recordProductInfoSynced(ShopifyProductVariant $variant, ?User $actor = null): void
    {
        $this->record(
            $variant,
            ShopifyProductVariantActivity::TYPE_SYNC_INFO,
            'Product Info Synced from Shopify',
            null,
            $actor
        );
    }

    public function recordInventoryPushed(ShopifyProductVariant $variant, int $pushed, ?User $actor = null): void
    {
        $this->record(
            $variant,
            ShopifyProductVariantActivity::TYPE_PUSH_INVENTORY,
            $pushed > 0
                ? 'Inventory Pushed to Shopify ('.$pushed.' location'.($pushed === 1 ? '' : 's').')'
                : 'Inventory Push Attempted',
            null,
            $actor,
            null,
            ['pushed' => $pushed]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function timelineFor(ShopifyProductVariant $variant): array
    {
        $rows = ShopifyProductVariantActivity::query()
            ->where('shopify_variant_id', (int) $variant->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        if ($rows->isEmpty()) {
            $this->recordCreated($variant);
            $rows = ShopifyProductVariantActivity::query()
                ->where('shopify_variant_id', (int) $variant->id)
                ->orderByDesc('id')
                ->limit(100)
                ->get();
        }

        return $rows->map(static function (ShopifyProductVariantActivity $row) {
            return [
                'id' => $row->id,
                'type' => $row->type,
                'title' => $row->title,
                'detail' => $row->detail,
                'meta' => $row->meta,
                'actor_label' => $row->actor_label,
                'actor_user_id' => $row->actor_user_id,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ];
        })->values()->all();
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 4);
    }
}
