<?php

namespace App\Services;

use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Support\Str;

/**
 * Old-beta-compatible CSV importer for billing invoices.
 */
final class InvoiceChargeImportParser
{
    private function isBasicBox6x9x1(string $raw): bool
    {
        $s = strtolower(trim($raw));
        if ($s === '') {
            return false;
        }
        // Flexible match for: "Basic box (6 x 9 x 1)" with optional spaces/parens and ×.
        return preg_match('/\bbasic\s*box\b.*\b6\s*[x×]\s*9\s*[x×]\s*1\b/i', $s) === 1;
    }

    private function containsManuallyFulfilled(string $raw): bool
    {
        return stripos($raw, 'manually fulfilled') !== false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parseFile(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Could not read CSV file.');
        }

        try {
            $headerRow = fgetcsv($fh);
            if ($headerRow === false || $headerRow === [null] || count($headerRow) === 0) {
                throw new \RuntimeException('CSV is empty.');
            }

            $map = $this->mapHeaders($headerRow);
            $isChargeSummary = isset($map['charge_name'], $map['charge_subtotal'])
                && (isset($map['charge_type_new']) || isset($map['charge_type']));

            if (
                ! $isChargeSummary
                && ! isset($map['billing_category'], $map['fee'])
                && ! (isset($map['charge_type']) || isset($map['charge_type_new']))
            ) {
                $seen = array_values(array_filter(array_map(function ($raw) {
                    return $this->normalizeHeaderKey((string) $raw);
                }, $headerRow)));
                $hint = $seen !== [] ? ' Found headers: '.implode(', ', $seen).'.' : '';
                throw new \RuntimeException('Could not detect billing CSV columns.'.$hint);
            }

            $lines = [];
            while (($row = fgetcsv($fh)) !== false) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $line = $isChargeSummary
                    ? $this->parseChargeSummaryRow($row, $map)
                    : $this->parseLegacyRow($row, $map);
                if ($line !== null) {
                    $lines[] = $this->attachOrderMetadata($line, $this->shipmentOrderNumber($row, $map));
                }
            }

            return $lines;
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param list<string|null> $headerRow
     * @return array<string, int>
     */
    private function mapHeaders(array $headerRow): array
    {
        $index = [];

        foreach ($headerRow as $i => $raw) {
            $key = $this->normalizeHeaderKey((string) $raw);
            if ($key === '') {
                continue;
            }
            $keySansSuffix = $this->stripTrailingHeaderScope($key);
            $candidates = array_values(array_unique(array_filter([$key, $keySansSuffix])));

            foreach ($this->headerAliases() as $field => $aliases) {
                if (isset($index[$field])) {
                    continue;
                }
                foreach ($candidates as $candidate) {
                    if (in_array($candidate, $aliases, true)) {
                        $index[$field] = (int) $i;
                        break 2;
                    }
                }
            }
        }

        if (! isset($index['charge_type_new']) && isset($index['charge_type'])) {
            $index['charge_type_new'] = $index['charge_type'];
        }
        if (! isset($index['charge_type']) && isset($index['charge_type_new'])) {
            $index['charge_type'] = $index['charge_type_new'];
        }

        return $index;
    }

    /**
     * @return array<string, list<string>>
     */
    private function headerAliases(): array
    {
        return [
            'order_date' => ['date (order)', 'order date', 'date'],
            'billing_category' => [
                'category (charge)', 'category', 'fee type',
                'category (fee type)', 'category (fee_type)',
            ],
            'fee' => ['fee (charge)', 'fee(charge)', 'fee', 'type', 'fee type'],
            'charge_name' => ['charge name'],
            'charge_type_new' => [
                'charge type',
                'charge type (charge)', 'charge type(charge)',
                'type (charge)', 'type(charge)',
            ],
            'avg_rate' => [
                'avg rate', 'average rate',
                'unit rate (charge)', 'unit rate(charge)', 'unit rate (to charge)',
                'rate (charge)', 'unit rate',
            ],
            'charge_qty' => [
                'charge qty', 'charge quantity', 'charge count',
                'qty', 'quantity',
                'quantity (charge)', 'quantity(charge)', 'quantity (to charge)',
                'qty (charge)', 'qty(charge)', 'qty (to charge)',
                'quantity to charge', 'qty to charge',
            ],
            'charge_subtotal' => [
                'charge subtotal', 'subtotal',
                'subtotal (charge)', 'total (charge)', 'line total (charge)', 'amount (charge)',
            ],
            'charge_type' => [
                'charge type', 'charge type (charge)', 'charge_type', 'chargetype',
                'type of charge', 'charge type(charge)', 'type (charge)',
            ],
            'quantity' => [
                'quantity', 'quantity (charge)', 'quantity(charge)', 'quantity (to charge)',
                'qty', 'qty (charge)', 'qty(charge)', 'qty (to charge)',
            ],
            'unit_rate' => [
                'unit rate (charge)', 'unit rate(charge)', 'unit rate (to charge)',
                'unit rate', 'unit_rate', 'unit price',
            ],
            'total' => ['total (charge)', 'total', 'amount', 'line total', 'amount (charge)'],
            'carrier' => ['carrier (shipment)', 'carrier'],
            'box' => ['box (shipment)', 'box'],
            'ad_hoc_name' => ['name', 'description', 'item', 'item name', 'description (charge)'],
            'label_charge' => ['label (charge)', 'label'],
            'fee_charge' => ['fee (charge)'],
            'charge_sku' => ['sku', 'sku (product)', 'product sku'],
            'shipment_order_number' => ['order # (shipment)', 'order# (shipment)', 'order number (shipment)', 'order #', 'order number'],
            'name_product' => ['name (product)', 'product name'],
            'qty_product' => ['units order', 'units ordered', 'quantity (product)', 'qty (product)'],
            'price_product' => ['price (product)', 'unit price (product)', 'price'],
            'total_product' => ['total (product)', 'amount (product)', 'line total (product)'],
        ];
    }

    private function normalizeHeaderKey(string $h): string
    {
        $h = trim($h);
        if (str_starts_with($h, "\xEF\xBB\xBF")) {
            $h = substr($h, 3);
        }
        $h = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $h) ?? $h;
        $h = preg_replace('/^\$+/u', '', $h) ?? $h;
        $h = preg_replace('/\p{Z}+/u', ' ', $h) ?? $h;
        $h = strtolower(trim($h));
        $h = str_replace(['_', '-', '.'], ' ', $h);
        $h = preg_replace('/\s+/', ' ', $h) ?? '';
        $h = preg_replace('/\s*\(\s*/', ' (', $h) ?? $h;
        $h = preg_replace('/\s*\)\s*/', ') ', $h) ?? $h;

        return trim((string) preg_replace('/\s+/', ' ', $h));
    }

    private function stripTrailingHeaderScope(string $header): string
    {
        $base = trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', $header));
        return $base !== '' ? $base : $header;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $map
     * @return array<string, mixed>|null
     */
    private function parseChargeSummaryRow(array $row, array $map): ?array
    {
        $chargeName = $this->cell($row, $map['charge_name'] ?? -1);
        $chargeTypeRaw = $this->cell($row, $map['charge_type_new'] ?? ($map['charge_type'] ?? -1));
        $categoryRaw = $this->cell($row, $map['billing_category'] ?? -1);
        $feeRaw = $this->cell($row, $map['fee'] ?? -1);
        $descriptionRaw = $this->cell($row, $map['ad_hoc_name'] ?? -1);
        $qty = $this->parseQty($this->cell($row, $map['charge_qty'] ?? -1));
        $rateCents = $this->parseMoneyToCents($this->cell($row, $map['avg_rate'] ?? -1));
        $subtotalCents = $this->parseMoneyToCents($this->cell($row, $map['charge_subtotal'] ?? -1));
        if (
            $chargeName === '' && $chargeTypeRaw === '' && $categoryRaw === '' && $feeRaw === '' && $descriptionRaw === ''
            && $qty <= 0 && $rateCents <= 0 && $subtotalCents <= 0
        ) {
            return null;
        }
        if ($qty <= 0 && $rateCents > 0 && $subtotalCents > 0) {
            $qty = $subtotalCents / $rateCents;
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }
        if ($qty > 0 && abs($qty - round($qty, 0)) < 0.02) {
            $qty = (float) round($qty, 0);
        }
        if ($rateCents <= 0 && $qty > 0 && $subtotalCents > 0) {
            $rateCents = (int) round($subtotalCents / $qty);
        }
        $lineTotalCents = $subtotalCents > 0 ? $subtotalCents : (int) round($qty * $rateCents);
        $sku = $this->cell($row, $map['charge_sku'] ?? -1);
        $orderNumber = $this->shipmentOrderNumber($row, $map);
        if ($this->isStorageChargeContext($categoryRaw, $feeRaw, $chargeName, $chargeTypeRaw, $descriptionRaw)) {
            return $this->attachOrderMetadata(
                $this->buildStorageChargeItem(
                    $descriptionRaw,
                    $chargeName,
                    $chargeTypeRaw,
                    $qty,
                    $rateCents,
                    $lineTotalCents,
                    $this->trimmedSkuOrNull($sku)
                ),
                $orderNumber
            );
        }
        if ($this->isReturnLabelCharge($chargeName, $chargeTypeRaw)) {
            return $this->attachOrderMetadata(
                $this->buildItem(
                    InvoiceLineCategory::POSTAGE,
                    'Return Label',
                    $chargeName !== '' ? $chargeName : 'Return Label',
                    $qty,
                    $rateCents,
                    $lineTotalCents,
                    null,
                    'postage:return-label',
                    $chargeTypeRaw,
                    $this->trimmedSkuOrNull($sku)
                ),
                $orderNumber
            );
        }

        $item = $this->mapChargeSummaryPrimary($chargeName, $chargeTypeRaw, $categoryRaw, $descriptionRaw, $sku, $qty, $rateCents, $lineTotalCents);
        if ($item !== null) {
            return $this->attachOrderMetadata($item, $orderNumber);
        }

        $item = $this->mapChargeSummaryRowByHeuristics($chargeName, $chargeTypeRaw, $descriptionRaw, $sku, $qty, $rateCents, $lineTotalCents);
        if ($item !== null) {
            return $this->attachOrderMetadata($item, $orderNumber);
        }

        return $this->attachOrderMetadata(
            $this->buildChargeSummaryFallbackItem($chargeName, $chargeTypeRaw, $categoryRaw, $descriptionRaw, $sku, $qty, $rateCents, $lineTotalCents),
            $orderNumber
        );
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $map
     * @return array<string, mixed>|null
     */
    private function parseLegacyRow(array $row, array $map): ?array
    {
        $legacyChargeName = $this->firstNonEmpty([
            $this->cell($row, $map['charge_name'] ?? -1),
            $this->cell($row, $map['label_charge'] ?? -1),
            $this->cell($row, $map['ad_hoc_name'] ?? -1),
            $this->cell($row, $map['fee_charge'] ?? -1),
            $this->cell($row, $map['fee'] ?? -1),
        ]) ?? '';
        $legacyChargeType = $this->cell($row, $map['charge_type'] ?? -1);
        if ($this->isReturnLabelCharge($legacyChargeName, $legacyChargeType)) {
            $qty = $this->parseQty($this->cell($row, $map['quantity'] ?? -1));
            $unitRate = $this->parseMoneyToCents($this->cell($row, $map['unit_rate'] ?? -1));
            $total = $this->parseMoneyToCents($this->cell($row, $map['total'] ?? -1));
            if ($total === 0 && $qty !== 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
            if ($unitRate === 0 && $qty !== 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
            if ($qty === 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);
            if ($qty === 0.0) $qty = 1.0;

            return $this->buildItem(
                InvoiceLineCategory::POSTAGE,
                'Return Label',
                $legacyChargeName !== '' ? $legacyChargeName : 'Return Label',
                $qty,
                $unitRate,
                $total,
                null,
                'postage:return-label',
                $legacyChargeType
            );
        }

        $feeType = $this->getFeeType($row, $map);
        $chargeTypeLower = strtolower(trim((string) $legacyChargeType));
        $isExplicitPickFulfillmentCharge = preg_match('/\b(first[_\s]*pick[_\s]*charge|pick[_\s]*remainder[_\s]*charge)\b/i', $chargeTypeLower) === 1;
        $materialText = implode(' ', array_filter([
            $legacyChargeName,
            $legacyChargeType,
            $this->cell($row, $map['box'] ?? -1),
            $this->cell($row, $map['ad_hoc_name'] ?? -1),
            $this->cell($row, $map['label_charge'] ?? -1),
            $this->cell($row, $map['fee_charge'] ?? -1),
        ]));
        if ($this->isPackagingMaterialText($materialText) && ! $isExplicitPickFulfillmentCharge) {
            $feeType = 'Packaging';
        }
        if ($feeType === null) {
            $feeType = $this->inferFeeType($row, $map);
        }
        if ($feeType === null) {
            return $this->parseAdHocFallbackRow($row, $map);
        }

        if ($feeType === 'Returns') {
            return $this->parseLegacyReturnsRow($row, $map);
        }
        if ($feeType === 'Fulfillment') {
            return $this->parseLegacyFulfillmentRow($row, $map);
        }
        if ($feeType === 'Postage') {
            return $this->parseLegacyPostageRow($row, $map);
        }
        if ($feeType === 'Packaging' || $feeType === 'Inserts') {
            return $this->parseLegacyPackagingRow($row, $map, $feeType);
        }
        if ($feeType === 'Product (On-Demand)') {
            return $this->parseLegacyOnDemandRow($row, $map);
        }

        return $this->parseLegacyAdHocCategoryRow($row, $map, $feeType);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapChargeSummaryPrimary(string $chargeName, string $chargeTypeRaw, string $billingCategoryRaw, string $descriptionRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        $t = strtolower(trim($chargeTypeRaw));
        $categoryHint = $this->normalizeBillingCategoryFromCsv($billingCategoryRaw);

        if ($this->isStorageChargeContext($billingCategoryRaw, '', $chargeName, $chargeTypeRaw, $descriptionRaw)) {
            return $this->buildStorageChargeItem($descriptionRaw, $chargeName, $chargeTypeRaw, $qty, $rateCents, $lineTotalCents, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if ($this->billingCategoryRawImpliesOnDemand($billingCategoryRaw)) {
            return $this->buildOnDemandItem($chargeName, $chargeTypeRaw, $skuFromColumn, $qty, $rateCents, $lineTotalCents);
        }
        // Respect explicit CSV category first. Some exports use "shipping_label" style
        // charge types for non-postage rows, which should not override Packaging category.
        if ($categoryHint === 'Packaging') {
            $pkg = $this->packagingDisplayName($chargeName !== '' ? $chargeName : ($descriptionRaw !== '' ? $descriptionRaw : 'Other'));
            return $this->buildItem(
                InvoiceLineCategory::PACKAGING,
                $pkg,
                $chargeName !== '' ? $chargeName : $descriptionRaw,
                $qty,
                $rateCents,
                $lineTotalCents,
                null,
                'packaging:'.$this->slug($pkg),
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if ($categoryHint === 'Postage') {
            $carrier = $this->postageServiceName($chargeName !== '' ? $chargeName : 'Other', $chargeTypeRaw);
            return $this->buildItem(
                InvoiceLineCategory::POSTAGE,
                'Postage ('.$carrier.')',
                $chargeName,
                $qty,
                $rateCents,
                $lineTotalCents,
                null,
                'postage',
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if (strpos($t, 'shipping_label') !== false) {
            if ($this->containsManuallyFulfilled($chargeName.' '.$chargeTypeRaw)) {
                return $this->buildItem(
                    InvoiceLineCategory::POSTAGE,
                    'Manual Label',
                    $chargeName !== '' ? $chargeName : 'Manual Label',
                    $qty,
                    0,
                    0,
                    null,
                    'postage:manual-label',
                    $chargeTypeRaw,
                    $this->trimmedSkuOrNull($skuFromColumn)
                );
            }
            if ($this->isReturnLabelCharge($chargeName, $chargeTypeRaw)) {
                return $this->buildItem(InvoiceLineCategory::POSTAGE, 'Return Label', $chargeName, $qty, $rateCents, $lineTotalCents, null, 'postage:return-label', $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
            }
            $carrier = $this->postageServiceName($chargeName !== '' ? $chargeName : 'Other', $chargeTypeRaw);
            return $this->buildItem(InvoiceLineCategory::POSTAGE, 'Postage ('.$carrier.')', $chargeName, $qty, $rateCents, $lineTotalCents, null, 'postage', $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if (strpos($t, 'box_charge') !== false) {
            if ($this->isBasicBox6x9x1($chargeName)) {
                $item = $this->buildItem(
                    InvoiceLineCategory::PACKAGING,
                    'Box Not Selected',
                    $chargeName !== '' ? $chargeName : 'Box Not Selected',
                    $qty,
                    $rateCents,
                    $lineTotalCents,
                    null,
                    'packaging:box-not-selected',
                    $chargeTypeRaw,
                    $this->trimmedSkuOrNull($skuFromColumn)
                );
                $item['metadata'] = ['box_not_selected' => true];
                return $item;
            }
            $pkg = $this->packagingDisplayName($chargeName !== '' ? $chargeName : 'Other');
            return $this->buildItem(InvoiceLineCategory::PACKAGING, $pkg, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'packaging:'.$this->slug($pkg), $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if ((strpos($t, 'order_value_charge') !== false || $t === 'inserts') && $this->isExplicitInsertLikeText($chargeName.' '.$chargeTypeRaw)) {
            return $this->buildItem(InvoiceLineCategory::PACKAGING, 'Inserts', 'Inserts', $qty, $rateCents, $lineTotalCents, null, 'packaging:inserts', $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if (strpos($t, 'first_return_charge') !== false || strpos($t, 'return_remainder_charge') !== false) {
            $isAdditional = strpos($t, 'return_remainder_charge') !== false;
            return $this->buildItem(
                InvoiceLineCategory::RETURNS,
                $isAdditional ? 'Returns (Additional Items)' : 'Returns (First Item)',
                $chargeName,
                $qty,
                $rateCents,
                $lineTotalCents,
                $isAdditional ? 'additional' : 'first',
                $isAdditional ? 'returns:additional' : 'returns:first',
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if (strpos($t, 'first_pick_charge') !== false || strpos($t, 'pick_remainder_charge') !== false) {
            if ($this->looksLikeOnDemandProfile($chargeName, $chargeTypeRaw)) {
                return $this->buildOnDemandItem($chargeName, $chargeTypeRaw, $skuFromColumn, $qty, $rateCents, $lineTotalCents);
            }
            $isAdditional = strpos($t, 'pick_remainder_charge') !== false;
            $profile = trim($chargeName);
            if ($profile === '' || in_array(strtolower($profile), ['fulfillment', 'default'], true)) {
                $display = $isAdditional ? 'Fulfillment (Additional Pick)' : 'Fulfillment (First Pick)';
            } else {
                $display = 'Fulfillment ('.$profile.')';
            }
            return $this->buildItem(
                InvoiceLineCategory::FULFILLMENT,
                $display,
                $chargeName,
                $qty,
                $rateCents,
                $lineTotalCents,
                $isAdditional ? 'additional' : 'first',
                'fulfillment:'.$this->slug($display),
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if ($this->isOnDemandChargeTypeOrCategory($t, $chargeTypeRaw)) {
            return $this->buildOnDemandItem($chargeName, $chargeTypeRaw, $skuFromColumn, $qty, $rateCents, $lineTotalCents);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapChargeSummaryRowByHeuristics(string $chargeName, string $chargeTypeRaw, string $descriptionRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        $hay = strtolower(trim($chargeName.' '.$chargeTypeRaw.' '.$descriptionRaw));
        if ($hay === '') {
            return null;
        }

        if ($this->isStorageChargeContext('', '', $chargeName, $chargeTypeRaw, $descriptionRaw)) {
            return $this->buildStorageChargeItem($descriptionRaw, $chargeName, $chargeTypeRaw, $qty, $rateCents, $lineTotalCents, $this->trimmedSkuOrNull($skuFromColumn));
        }

        if (preg_match('/\b(shipping_label|shipping label|postage|mail class|priority mail|parcel select|ground advantage|media mail|first[- ]class parcel|endicia|stamps?\.com|shipstation|shippo|easy_post|easy post|usps|ups|fedex|dhl|ontrac|lasership|pitney|flat rate|intl|international|zone|delivery confirmation)\b/i', $hay)) {
            if ($this->containsManuallyFulfilled($chargeName.' '.$chargeTypeRaw)) {
                return $this->buildItem(
                    InvoiceLineCategory::POSTAGE,
                    'Manual Label',
                    $chargeName !== '' ? $chargeName : 'Manual Label',
                    $qty,
                    0,
                    0,
                    null,
                    'postage:manual-label',
                    $chargeTypeRaw,
                    $this->trimmedSkuOrNull($skuFromColumn)
                );
            }
            if ($this->isReturnLabelCharge($chargeName, $chargeTypeRaw)) {
                return $this->buildItem(InvoiceLineCategory::POSTAGE, 'Return Label', $chargeName, $qty, $rateCents, $lineTotalCents, null, 'postage:return-label', $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
            }
            $carrier = $this->postageServiceName($chargeName !== '' ? $chargeName : 'Other', $chargeTypeRaw);
            return $this->buildItem(InvoiceLineCategory::POSTAGE, $carrier, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'postage', $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if ($this->isPackagingMaterialText($hay)) {
            $pkg = $this->packagingDisplayName($chargeName !== '' ? $chargeName : 'Other');
            return $this->buildItem(InvoiceLineCategory::PACKAGING, $pkg, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'packaging:'.$this->slug($pkg), $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if ($this->isExplicitInsertLikeText($hay)) {
            return $this->buildItem(InvoiceLineCategory::PACKAGING, 'Inserts', 'Inserts', $qty, $rateCents, $lineTotalCents, null, 'packaging:inserts', $chargeTypeRaw, $this->trimmedSkuOrNull($skuFromColumn));
        }
        if (preg_match('/\b(amazon prep|amazon_prep)\b/i', $hay)) {
            return $this->buildItem(
                InvoiceLineCategory::FULFILLMENT,
                'Amazon Prep',
                $chargeName !== '' ? $chargeName : 'Amazon Prep',
                $qty,
                $rateCents,
                $lineTotalCents,
                null,
                'fulfillment:amazon-prep',
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if (preg_match('/\b(return|rma|restock|reverse logistics)\b/i', $hay)) {
            $isAdditional = strpos(strtolower($chargeTypeRaw), 'return_remainder') !== false
                || preg_match('/\b(remainder|additional items|additional|extra|subsequent)\b/i', $hay);
            if (preg_match('/\bfirst\b/i', $hay) && ! preg_match('/\bfirst class\b/i', $hay)) {
                $isAdditional = false;
            }
            return $this->buildItem(
                InvoiceLineCategory::RETURNS,
                $isAdditional ? 'Returns (Additional Items)' : 'Returns (First Item)',
                $chargeName,
                $qty,
                $rateCents,
                $lineTotalCents,
                $isAdditional ? 'additional' : 'first',
                $isAdditional ? 'returns:additional' : 'returns:first',
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if (preg_match('/\b(pick|fulfill|picker|picking|pick_pack|pick & pack|unit pick|bundle fee|kit fee|assembly)\b/i', $hay)) {
            $isAdditional = strpos(strtolower($chargeTypeRaw), 'pick_remainder') !== false
                || strpos(strtolower($chargeTypeRaw), 'remainder') !== false
                || preg_match('/\b(additional|remainder)\b/i', $hay);
            return $this->buildItem(
                InvoiceLineCategory::FULFILLMENT,
                $isAdditional ? 'Fulfillment (Additional Pick)' : 'Fulfillment (First Pick)',
                $chargeName,
                $qty,
                $rateCents,
                $lineTotalCents,
                $isAdditional ? 'additional' : 'first',
                $isAdditional ? 'fulfillment:additional-pick' : 'fulfillment:first-pick',
                $chargeTypeRaw,
                $this->trimmedSkuOrNull($skuFromColumn)
            );
        }
        if (preg_match('/\b(skincare|on[- ]?demand|product)\b/i', $hay)) {
            return $this->buildOnDemandItem($chargeName, $chargeTypeRaw, $skuFromColumn, $qty, $rateCents, $lineTotalCents);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildChargeSummaryFallbackItem(string $chargeName, string $chargeTypeRaw, string $billingCategoryRaw, string $descriptionRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        if ($lineTotalCents === 0 && $rateCents === 0 && abs($qty) < 0.0001) {
            return null;
        }

        if ($this->isStorageChargeContext($billingCategoryRaw, '', $chargeName, $chargeTypeRaw, $descriptionRaw)) {
            return $this->buildStorageChargeItem($descriptionRaw, $chargeName, $chargeTypeRaw, $qty, $rateCents, $lineTotalCents, $this->trimmedSkuOrNull($skuFromColumn));
        }

        $display = trim($chargeName) !== '' ? trim($chargeName) : $this->humanizeChargeTypeSlug($chargeTypeRaw);
        if ($display === '' || strtolower($display) === 'charge') {
            $display = 'Imported charge';
        }
        $category = $this->normalizeBillingCategoryFromCsv($billingCategoryRaw);
        if ($category === 'Product (On-Demand)') {
            return $this->buildOnDemandItem($chargeName, $chargeTypeRaw, $skuFromColumn, $qty, $rateCents, $lineTotalCents);
        }

        return $this->buildItem(
            $this->mapLegacyCategoryLabelToKey($category),
            $display,
            $chargeName !== '' ? $chargeName : $display,
            $qty,
            $rateCents,
            $lineTotalCents,
            null,
            $this->defaultGroupKeyFor($this->mapLegacyCategoryLabelToKey($category), $display),
            $chargeTypeRaw,
            $this->trimmedSkuOrNull($skuFromColumn)
        );
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     */
    private function getFeeType(array $row, array $index): ?string
    {
        $get = function (string $key) use ($row, $index): string {
            return strtolower($this->cell($row, $index[$key] ?? -1));
        };

        $try = function (string $val): ?string {
            $val = trim((string) preg_replace('/\s+/', ' ', $val));
            if ($val === 'fulfillment') return 'Fulfillment';
            if ($val === 'postage') return 'Postage';
            if ($val === 'inserts') return 'Inserts';
            if ($val === 'packaging') return 'Packaging';
            if (strpos($val, 'receiving') !== false) return 'Receiving';
            if (in_array($val, ['skincare', 'skin care', 'product (on-demand)', 'product (on demand)', 'on-demand', 'on demand'], true)) return 'Product (On-Demand)';
            if ($val === 'returns') return 'Returns';
            if ($val === 'storage' || strpos($val, 'storage') !== false) return 'Storage';
            if ($val === 'order_value_charge' || strpos($val, 'order_value_charge') !== false) return 'Inserts';
            if (strpos($val, 'bubble wrap') !== false || strpos($val, 'kraft paper') !== false) return 'Packaging';
            if ($val === 'ad hoc' || $val === 'ad_hoc' || strpos($val, 'ad_hoc') !== false || strpos($val, 'ad hoc') !== false) return 'Ad Hoc';
            if ($val === 'bank fee' || $val === 'bank_fee' || str_replace([' ', '_'], '', $val) === 'bankfee') return 'Bank Fee';
            if (in_array($val, ['duties & taxes', 'duties and taxes', 'duties_taxes'], true) || str_replace([' ', '_'], '', $val) === 'duties&taxes') return 'Duties & Taxes';
            if (strpos($val, 'amazon prep') !== false || strpos($val, 'amazon_prep') !== false) return 'Fulfillment';
            if (strpos($val, 'photo') !== false) return 'Ad Hoc';
            if (strpos($val, 'scion cbd') !== false || strpos($val, 'scion cbo') !== false || strpos($val, 'cbd oil') !== false) return 'Product (On-Demand)';
            return null;
        };

        foreach (['billing_category', 'fee'] as $key) {
            $val = $get($key);
            if ($val !== '') {
                $type = $try($val);
                if ($type !== null) {
                    return $type;
                }
            }
        }

        $blob = $get('billing_category') !== '' ? $get('billing_category') : $get('fee');
        if ($blob !== '') {
            $norm = $this->normalizeBillingCategoryFromCsv($blob);
            if (in_array($norm, ['Fulfillment', 'Postage', 'Packaging', 'Returns', 'Bank Fee', 'Duties & Taxes', 'Ad Hoc', 'Product (On-Demand)', 'Receiving'], true)) {
                return $norm;
            }
        }

        return null;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     */
    private function inferFeeType(array $row, array $index): ?string
    {
        $get = function (string $key, string $default = '') use ($row, $index): string {
            return $this->cell($row, $index[$key] ?? -1) ?: $default;
        };

        $ct = strtolower($get('charge_type'));
        if ($get('carrier') !== '') return 'Postage';
        if ($get('box') !== '') return 'Packaging';
        if (
            strpos($ct, 'receiv') !== false
            || strpos(strtolower($get('billing_category')), 'receiv') !== false
            || strpos(strtolower($get('fee')), 'receiv') !== false
        ) return 'Receiving';
        if (strpos($ct, 'order_value_charge') !== false || $ct === 'inserts') {
            $rowBlob = strtolower(implode(' ', array_map(function ($v): string {
                return trim((string) $v);
            }, $row)));
            $rowBlob = (string) preg_replace('/\s+/', ' ', $rowBlob);
            if ($this->isExplicitInsertLikeText($rowBlob)) {
                return 'Inserts';
            }
            if (strpos($rowBlob, 'amazon prep') !== false || strpos($rowBlob, 'amazon_prep') !== false) {
                return 'Fulfillment';
            }
        }
        if (strpos($ct, 'first_return_charge') !== false || strpos($ct, 'return_remainder_charge') !== false) return 'Returns';
        if (strpos($ct, 'first') !== false || strpos($ct, 'remainder') !== false || strpos($ct, 'additional') !== false || $ct === 'first_pick_charge' || $ct === 'pick_remainder_charge') return 'Fulfillment';
        if (strpos($ct, 'ad_hoc') !== false || strpos($ct, 'ad hoc') !== false) return 'Ad Hoc';
        if (strpos($ct, 'amazon prep') !== false || strpos($ct, 'amazon_prep') !== false) return 'Fulfillment';
        if ($ct === 'bank fee' || $ct === 'bank_fee' || strpos($ct, 'bank fee') !== false) return 'Bank Fee';
        if (strpos($ct, 'duties') !== false && (strpos($ct, 'tax') !== false || strpos($ct, 'taxes') !== false)) return 'Duties & Taxes';
        if (
            strpos($ct, 'scion cbd') !== false
            || strpos($ct, 'scion cbo') !== false
            || strpos($ct, 'cbd oil') !== false
            || strpos(strtolower($get('billing_category')), 'scion cbd') !== false
            || strpos(strtolower($get('billing_category')), 'scion cbo') !== false
            || strpos(strtolower($get('fee')), 'scion cbd') !== false
            || strpos(strtolower($get('fee')), 'scion cbo') !== false
            || strpos(strtolower($get('fee')), 'cbd oil') !== false
            || $get('charge_sku') !== ''
            || $get('name_product') !== ''
            || strpos(strtolower($get('billing_category')), 'skincare') !== false
            || strpos(strtolower($get('fee')), 'skincare') !== false
        ) {
            return 'Product (On-Demand)';
        }
        $rowBlob = strtolower(implode(' ', array_map(function ($v): string {
            return trim((string) $v);
        }, $row)));
        $rowBlob = (string) preg_replace('/\s+/', ' ', $rowBlob);
        if (preg_match('/\b(inserts?|collateral|marketing insert|gift note|greeting card)\b/i', $rowBlob) === 1) {
            return 'Inserts';
        }
        if (preg_match('/\b(shipping label|postage|mail class|usps|ups|fedex|dhl|ontrac|lasership)\b/i', $rowBlob) === 1) {
            return 'Postage';
        }
        if (preg_match('/\b(return|rma|restock|reverse logistics)\b/i', $rowBlob) === 1) {
            return 'Returns';
        }
        if ($this->isPackagingMaterialText($rowBlob)) {
            return 'Packaging';
        }
        if (preg_match('/\b(amazon prep|amazon_prep)\b/i', $rowBlob) === 1) {
            return 'Fulfillment';
        }
        if (preg_match('/\b(photo|photos)\b/i', $rowBlob) === 1) {
            return 'Ad Hoc';
        }

        $totalVal = $this->parseMoneyToCents($get('total', '0'));
        $qtyNum = $this->parseQty($get('quantity', '0'));
        $urNum = $this->parseMoneyToCents($get('unit_rate', '0'));
        if ($totalVal === 0) {
            $totalVal = $this->parseMoneyToCents($get('charge_subtotal', '0'));
        }
        if ($qtyNum == 0.0) {
            $qtyNum = $this->parseQty($get('charge_qty', '0'));
        }
        if ($urNum === 0) {
            $urNum = $this->parseMoneyToCents($get('avg_rate', '0'));
        }
        if ($totalVal !== 0 || ($qtyNum != 0.0 && $urNum !== 0)) {
            $hasLabel = $get('fee') !== '' || $get('fee_charge') !== '' || $get('billing_category') !== ''
                || $get('charge_type') !== '' || $get('ad_hoc_name') !== '' || $get('label_charge') !== '';
            if ($hasLabel) return 'Ad Hoc';
        }

        return null;
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyFulfillmentRow(array $row, array $index): ?array
    {
        $chargeTypeRaw = $this->cell($row, $index['charge_type'] ?? -1);
        if (preg_match('/first_return_charge|return_remainder_charge/i', (string) $chargeTypeRaw)) {
            return null;
        }
        $chargeTypeNorm = strtolower(trim(str_replace('_', ' ', preg_replace('/\s+/', ' ', (string) $chargeTypeRaw) ?? '')));
        $chargeTypeVal = 'first_pick_charge';
        $chargeTypeName = 'Fulfillment (First Pick)';
        $feeNorm = strtolower(trim((string) $this->cell($row, $index['fee'] ?? -1)));
        $labelNorm = strtolower(trim((string) $this->cell($row, $index['label_charge'] ?? -1)));
        $categoryNorm = strtolower(trim((string) $this->cell($row, $index['billing_category'] ?? -1)));
        $nameNorm = strtolower(trim((string) $this->cell($row, $index['ad_hoc_name'] ?? -1)));
        $amazonPrepBlob = trim((string) preg_replace('/\s+/', ' ', $feeNorm.' '.$labelNorm.' '.$categoryNorm.' '.$nameNorm.' '.$chargeTypeNorm));
        $isAmazonPrepFee = preg_match('/\bamazon[\s_]*prep\b/i', $feeNorm) === 1;
        $isAmazonPrepRow = preg_match('/\bamazon[\s_]*prep\b/i', $amazonPrepBlob) === 1;
        $isAdditionalPick = $chargeTypeNorm !== '' && (
            strpos($chargeTypeNorm, 'remainder') !== false
            || strpos($chargeTypeNorm, 'additional') !== false
            || $chargeTypeNorm === 'pick_remainder charge'
            || $chargeTypeNorm === 'pick remainder charge'
        );
        $isFirstPick = $chargeTypeNorm !== '' && (
            strpos($chargeTypeNorm, 'first') !== false
            || $chargeTypeNorm === 'first_pick charge'
            || $chargeTypeNorm === 'first pick charge'
        );
        $isGenericFulfillmentFee = (
            ($feeNorm === 'fulfillment' || $feeNorm === 'fulfillment fee' || $labelNorm === 'fulfillment' || $labelNorm === 'fulfillment fee')
            && strpos($chargeTypeNorm, 'pick') === false
            && strpos($chargeTypeNorm, 'first') === false
            && strpos($chargeTypeNorm, 'remainder') === false
            && strpos($chargeTypeNorm, 'additional') === false
        );
        if ($isAmazonPrepFee) {
            $chargeTypeVal = '';
            $chargeTypeName = 'Amazon Prep';
        } elseif ($isAmazonPrepRow && ! $isAdditionalPick && ! $isFirstPick) {
            $chargeTypeVal = '';
            $chargeTypeName = 'Amazon Prep';
        } elseif ($isGenericFulfillmentFee) {
            $chargeTypeVal = '';
            $chargeTypeName = 'Fulfillment Fee';
        } elseif ($isAdditionalPick) {
            $chargeTypeVal = 'pick_remainder_charge';
            $chargeTypeName = 'Fulfillment (Additional Pick)';
        } elseif ($isFirstPick) {
            $chargeTypeVal = 'first_pick_charge';
            $chargeTypeName = 'Fulfillment (First Pick)';
        }

        ['qty' => $qty, 'unit_rate' => $unitRate, 'total' => $total] = $this->resolveRowAmounts($row, $index);

        $subtype = null;
        if ($chargeTypeVal === 'pick_remainder_charge') {
            $subtype = 'additional';
        } elseif ($chargeTypeVal === 'first_pick_charge') {
            $subtype = 'first';
        }

        $sourceDescription = $this->firstNonEmpty([
            $this->cell($row, $index['ad_hoc_name'] ?? -1),
            $this->cell($row, $index['label_charge'] ?? -1),
            $chargeTypeName,
        ]) ?? $chargeTypeName;
        $sourceSku = $this->legacyRowProductSku($row, $index);

        return $this->buildItem(
            InvoiceLineCategory::FULFILLMENT,
            $chargeTypeName,
            $sourceDescription,
            $qty,
            $unitRate,
            $total,
            $subtype,
            'fulfillment:'.$this->slug($chargeTypeName),
            $chargeTypeVal !== '' ? $chargeTypeVal : $chargeTypeRaw,
            $sourceSku !== null ? trim($sourceSku) : null
        );
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyReturnsRow(array $row, array $index): ?array
    {
        $chargeTypeRaw = strtolower($this->cell($row, $index['charge_type'] ?? -1));
        ['qty' => $qty, 'unit_rate' => $unitRate, 'total' => $total] = $this->resolveRowAmounts($row, $index);
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;
        $isAdditional = strpos($chargeTypeRaw, 'return_remainder') !== false || strpos($chargeTypeRaw, 'remainder') !== false;

        $sku = $this->legacyRowProductSku($row, $index);

        return $this->buildItem(InvoiceLineCategory::RETURNS, $isAdditional ? 'Returns (Additional Items)' : 'Returns (First Item)', '', $qty, $unitRate, $total, $isAdditional ? 'additional' : 'first', $isAdditional ? 'returns:additional' : 'returns:first', $chargeTypeRaw, $sku);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyPostageRow(array $row, array $index): ?array
    {
        $carrier = $this->cell($row, $index['carrier'] ?? -1);
        if ($carrier === '') {
            $carrier = 'Other';
        }
        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0) {
            $total = $this->parseMoneyToCents($this->cell($row, $index['charge_subtotal'] ?? -1));
        }

        $sku = $this->legacyRowProductSku($row, $index);

        return $this->buildItem(InvoiceLineCategory::POSTAGE, 'Postage ('.trim($carrier).')', trim($carrier), 1.0, 0, $total, null, 'postage', '', $sku);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyPackagingRow(array $row, array $index, string $feeType): ?array
    {
        ['qty' => $qty, 'unit_rate' => $unitRate, 'total' => $total] = $this->resolveRowAmounts($row, $index);
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;

        $sku = $this->legacyRowProductSku($row, $index);

        if ($feeType === 'Inserts') {
            return $this->buildItem(InvoiceLineCategory::PACKAGING, 'Inserts', 'Inserts', $qty, $unitRate, $total, null, 'packaging:inserts', '', $sku);
        }

        $boxRaw = $this->cell($row, $index['box'] ?? -1);
        if (strtolower(trim($boxRaw)) === 'packaging' || trim($boxRaw) === '') {
            $boxRaw = $this->firstNonEmpty([
                $this->cell($row, $index['label_charge'] ?? -1),
                $this->cell($row, $index['ad_hoc_name'] ?? -1),
                $this->cell($row, $index['billing_category'] ?? -1),
                $this->cell($row, $index['fee_charge'] ?? -1),
                $this->cell($row, $index['fee'] ?? -1),
                $this->cell($row, $index['box'] ?? -1),
            ]) ?? 'Other';
        }
        if ($this->isBasicBox6x9x1($boxRaw)) {
            $item = $this->buildItem(
                InvoiceLineCategory::PACKAGING,
                'Box Not Selected',
                $boxRaw,
                $qty,
                $unitRate,
                $total,
                null,
                'packaging:box-not-selected',
                '',
                $sku
            );
            $item['metadata'] = ['box_not_selected' => true];
            return $item;
        }
        $box = $this->packagingDisplayName($boxRaw);

        return $this->buildItem(InvoiceLineCategory::PACKAGING, $box, $boxRaw, $qty, $unitRate, $total, null, 'packaging:'.$this->slug($box), '', $sku);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyOnDemandRow(array $row, array $index): ?array
    {
        $qty = $this->parseQty($this->cell($row, $index['quantity'] ?? -1));
        if ($qty == 0.0) $qty = $this->parseQty($this->cell($row, $index['qty_product'] ?? -1));
        $unitRate = $this->parseMoneyToCents($this->cell($row, $index['unit_rate'] ?? -1));
        if ($unitRate === 0) $unitRate = $this->parseMoneyToCents($this->cell($row, $index['price_product'] ?? -1));
        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0) $total = $this->parseMoneyToCents($this->cell($row, $index['total_product'] ?? -1));
        if ($total === 0 && $qty != 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
        if ($unitRate === 0 && $qty != 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
        if ($qty == 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);
        if ($qty == 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty == 0.0) $qty = 1.0;

        $productName = $this->firstNonEmpty([
            $this->cell($row, $index['name_product'] ?? -1),
            $this->cell($row, $index['ad_hoc_name'] ?? -1),
            $this->cell($row, $index['label_charge'] ?? -1),
        ]) ?? 'On-Demand Product';
        $sku = $this->cell($row, $index['charge_sku'] ?? -1);

        return $this->buildOnDemandItem($productName, '', $sku, $qty, $unitRate, $total);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyAdHocCategoryRow(
        array $row,
        array $index,
        string $categoryLabel,
        ?string $nameOverride = null,
        ?float $qtyOverride = null,
        ?int $unitRateOverride = null,
        ?int $totalOverride = null
    ): ?array
    {
        ['qty' => $qty, 'unit_rate' => $unitRate, 'total' => $total] = $this->resolveRowAmounts($row, $index);
        if ($qtyOverride !== null) {
            $qty = $qtyOverride;
        }
        if ($unitRateOverride !== null) {
            $unitRate = $unitRateOverride;
        }
        if ($totalOverride !== null) {
            $total = $totalOverride;
        }
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;

        $name = $nameOverride ?? $this->firstNonEmpty([
            $this->cell($row, $index['label_charge'] ?? -1),
            $this->cell($row, $index['fee_charge'] ?? -1),
            $this->cell($row, $index['fee'] ?? -1),
            $this->cell($row, $index['ad_hoc_name'] ?? -1),
            $this->cell($row, $index['charge_type'] ?? -1),
        ]) ?? $categoryLabel;

        if ($categoryLabel === 'Ad Hoc' && ($name === '' || preg_match('/^(ad[\s_]?hoc|custom_adhoc)$/i', $name) === 1)) {
            $name = 'Ad Hoc';
        }

        $categoryKey = $this->mapLegacyCategoryLabelToKey($categoryLabel);
        $sku = $this->legacyRowProductSku($row, $index);
        $description = $this->firstNonEmpty([
            $this->cell($row, $index['ad_hoc_name'] ?? -1),
            $this->cell($row, $index['label_charge'] ?? -1),
            $this->cell($row, $index['fee_charge'] ?? -1),
            $this->cell($row, $index['fee'] ?? -1),
            $name,
        ]) ?? $name;
        if ($categoryKey === InvoiceLineCategory::STORAGE) {
            $storageType = $this->extractStorageTypeLabel($description)
                ?? $this->extractStorageTypeLabel($name)
                ?? 'Storage';
            return $this->buildItem(
                InvoiceLineCategory::STORAGE,
                $storageType,
                $description,
                $qty,
                $unitRate,
                $total,
                null,
                'storage:'.$this->slug($storageType),
                $this->cell($row, $index['charge_type'] ?? -1),
                $sku
            );
        }

        return $this->buildItem($categoryKey, $name, $name, $qty, $unitRate, $total, null, $this->defaultGroupKeyFor($categoryKey, $name), $this->cell($row, $index['charge_type'] ?? -1), $sku);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseAdHocFallbackRow(array $row, array $index): ?array
    {
        ['qty' => $qty, 'unit_rate' => $unitRate, 'total' => $total] = $this->resolveRowAmounts($row, $index);
        if ($qty == 0.0 && $unitRate === 0 && $total === 0) {
            return null;
        }
        if ($qty == 0.0) {
            $qty = 1.0;
        }

        $name = $this->firstNonEmpty([
            $this->cell($row, $index['fee_charge'] ?? -1),
            $this->cell($row, $index['fee'] ?? -1),
            $this->cell($row, $index['label_charge'] ?? -1),
            $this->cell($row, $index['billing_category'] ?? -1),
            $this->cell($row, $index['charge_type'] ?? -1),
            $this->cell($row, $index['ad_hoc_name'] ?? -1),
            $this->cell($row, $index['box'] ?? -1),
            $this->cell($row, $index['carrier'] ?? -1),
        ]) ?? 'Imported line';

        $categoryRaw = $this->cell($row, $index['billing_category'] ?? -1);
        $feeRaw = $this->cell($row, $index['fee'] ?? -1);
        $category = $this->normalizeBillingCategoryFromCsv($categoryRaw !== '' ? $categoryRaw : $feeRaw);
        return $this->parseLegacyAdHocCategoryRow($row, $index, $category, $name, $qty, $unitRate, $total);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array{qty: float, unit_rate: int, total: int}
     */
    private function resolveRowAmounts(array $row, array $index): array
    {
        $qty = $this->parseQty($this->cell($row, $index['quantity'] ?? -1));
        if ($qty == 0.0) {
            $qty = $this->parseQty($this->cell($row, $index['charge_qty'] ?? -1));
        }

        $unitRate = $this->parseMoneyToCents($this->cell($row, $index['unit_rate'] ?? -1));
        if ($unitRate === 0) {
            $unitRate = $this->parseMoneyToCents($this->cell($row, $index['avg_rate'] ?? -1));
        }

        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0) {
            $total = $this->parseMoneyToCents($this->cell($row, $index['charge_subtotal'] ?? -1));
        }

        if ($total === 0 && $qty != 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
        if ($unitRate === 0 && $qty != 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
        if ($qty == 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);

        return ['qty' => $qty, 'unit_rate' => $unitRate, 'total' => $total];
    }

    private function isExplicitInsertLikeText(string $text): bool
    {
        $hay = strtolower(trim((string) $text));
        if ($hay === '') {
            return false;
        }
        return preg_match('/\b(inserts?|collateral|marketing insert|gift note|greeting card)\b/i', $hay) === 1;
    }

    private function isPackagingMaterialText(string $text): bool
    {
        $hay = strtolower(trim((string) preg_replace('/\s+/', ' ', $text)));
        if ($hay === '') {
            return false;
        }

        return preg_match(
            '/\b(box_charge|box charge|bubble|bubble wrap|kraft|kraft paper|void fill|voidfill|mailer|poly bag|polybag|clear poly bag|envelope|card envelope|tape|carton|void_fill|package material|packaging material|packaging|mailing tube|stretch wrap)\b/i',
            $hay
        ) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOnDemandItem(string $chargeName, string $chargeTypeRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): array
    {
        $od = $this->onDemandDisplayAndSku($chargeName, $skuFromColumn);
        return $this->buildItem(InvoiceLineCategory::ON_DEMAND, $od['display'], $chargeName !== '' ? $chargeName : $od['display'], $qty, $rateCents, $lineTotalCents, null, 'on_demand:'.$this->slug($od['display']), $chargeTypeRaw, $od['sku']);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildItem(string $category, string $display, string $description, float $qty, int $rateCents, int $lineTotalCents, ?string $subtype, ?string $groupKey, string $serviceCode, ?string $sku = null): array
    {
        // Credits/adjustments can arrive with a negative quantity. Store quantity as
        // a physical count and keep the credit sign on the money columns.
        if ($qty < 0) {
            $qty = abs($qty);
        }

        return [
            'category' => $category,
            'subtype' => $subtype,
            'group_key' => $groupKey,
            'description' => trim($description) !== '' ? trim($description) : $display,
            'display_name' => $display,
            'sku' => $sku,
            'service_code' => Str::limit((string) $serviceCode, 128, ''),
            'quantity' => $qty,
            'unit_price_cents' => $rateCents,
            'line_total_cents' => $lineTotalCents,
        ];
    }

    private function trimmedSkuOrNull(string $sku): ?string
    {
        $trimmed = trim($sku);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function normalizeBillingCategoryFromCsv(string $raw): string
    {
        $t = strtolower(trim(preg_replace('/\s+/', ' ', (string) $raw) ?? ''));
        if ($t === '') return 'Ad Hoc';
        $exact = [
            'fulfillment' => 'Fulfillment',
            'postage' => 'Postage',
            'packaging' => 'Packaging',
            'returns' => 'Returns',
            'inserts' => 'Packaging',
            'photos' => 'Ad Hoc',
            'receiving' => 'Receiving',
            'purchase_receiving' => 'Receiving',
            'purchase receiving' => 'Receiving',
            'amazon prep' => 'Fulfillment',
            'amazon_prep' => 'Fulfillment',
            'skincare' => 'Product (On-Demand)',
            'skin care' => 'Product (On-Demand)',
            'scion cbo' => 'Product (On-Demand)',
            'scion cbd' => 'Product (On-Demand)',
            'scion cbd oil' => 'Product (On-Demand)',
            'on-demand' => 'Product (On-Demand)',
            'on demand' => 'Product (On-Demand)',
            'product (on-demand)' => 'Product (On-Demand)',
            'product (on demand)' => 'Product (On-Demand)',
            'product on demand' => 'Product (On-Demand)',
            'ad hoc' => 'Ad Hoc',
            'ad_hoc' => 'Ad Hoc',
            'storage' => 'Storage',
            'bank fee' => 'Bank Fee',
            'bank_fee' => 'Bank Fee',
            'duties & taxes' => 'Duties & Taxes',
            'duties and taxes' => 'Duties & Taxes',
            'duties_taxes' => 'Duties & Taxes',
        ];
        if (isset($exact[$t])) return $exact[$t];
        if (strpos($t, 'fulfill') !== false) return 'Fulfillment';
        if (strpos($t, 'amazon prep') !== false || strpos($t, 'amazon_prep') !== false) return 'Fulfillment';
        if (strpos($t, 'photo') !== false) return 'Ad Hoc';
        if (strpos($t, 'postage') !== false || preg_match('/\b(ship|shipping|carrier|parcel|mail)\b/', $t)) return 'Postage';
        if (strpos($t, 'packag') !== false || preg_match('/\b(box|mailer|bubble|kraft)\b/', $t)) return 'Packaging';
        if (strpos($t, 'storage') !== false) return 'Storage';
        if (strpos($t, 'receiv') !== false) return 'Receiving';
        if (strpos($t, 'skincare') !== false || preg_match('/\b(on[- ]?demand|product)\b/', $t)) return 'Product (On-Demand)';
        if (strpos($t, 'return') !== false) return 'Returns';
        if (strpos($t, 'duties') !== false && strpos($t, 'tax') !== false) return 'Duties & Taxes';
        if (strpos($t, 'bank') !== false && strpos($t, 'fee') !== false) return 'Bank Fee';
        if (strpos($t, 'ad hoc') !== false || strpos($t, 'ad_hoc') !== false) return 'Ad Hoc';

        return trim((string) $raw);
    }

    private function humanizeChargeTypeSlug(string $slug): string
    {
        $s = strtolower(trim((string) preg_replace('/[\s-]+/', '_', $slug)));
        $s = preg_replace('/_+/', '_', $s) ?? $s;
        $s = str_replace('_', ' ', $s);
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === '') return 'Charge';
        return ucwords($s);
    }

    private function mapLegacyCategoryLabelToKey(string $label): string
    {
        $t = strtolower(trim($label));
        switch ($t) {
            case 'fulfillment':
                return InvoiceLineCategory::FULFILLMENT;
            case 'postage':
                return InvoiceLineCategory::POSTAGE;
            case 'packaging':
                return InvoiceLineCategory::PACKAGING;
            case 'returns':
                return InvoiceLineCategory::RETURNS;
            case 'product (on-demand)':
                return InvoiceLineCategory::ON_DEMAND;
            case 'storage':
                return InvoiceLineCategory::STORAGE;
            case 'receiving':
                return InvoiceLineCategory::RECEIVING;
            default:
                return InvoiceLineCategory::AD_HOC;
        }
    }

    private function defaultGroupKeyFor(string $category, string $name): string
    {
        return $category.':'.$this->slug($name !== '' ? $name : $category);
    }

    private function slug(string $value): string
    {
        $slug = Str::slug($value);
        return $slug !== '' ? $slug : 'item';
    }

    private function isStorageChargeContext(string $billingCategoryRaw, string $feeRaw, string $chargeName, string $chargeTypeRaw, string $descriptionRaw): bool
    {
        $hay = strtolower(trim(implode(' ', [
            $billingCategoryRaw,
            $feeRaw,
            $chargeName,
            $chargeTypeRaw,
            $descriptionRaw,
        ])));
        if ($hay === '') {
            return false;
        }

        return str_contains($hay, 'storage')
            || $this->extractStorageTypeLabel($hay) !== null
            || preg_match('/\boccupied\s+for\s+\d+\s+week/', $hay) === 1;
    }

    private function extractStorageTypeLabel(string $text): ?string
    {
        $source = trim($text);
        if ($source === '') {
            return null;
        }
        if (preg_match('/\b(bin|pallet|shelf)\s*\(\s*(small|medium|large|x-?large)\s*\)/i', $source, $m) === 1) {
            $base = ucfirst(strtolower((string) $m[1]));
            $size = strtolower((string) $m[2]);
            $normalizedSize = $size === 'xlarge' || $size === 'x-large' ? 'X-Large' : ucfirst($size);

            return $base.' ('.$normalizedSize.')';
        }
        if (preg_match('/\bcustom\b/i', $source) === 1) {
            return 'Custom';
        }
        if (preg_match('/\bsleeve\b/i', $source) === 1) {
            return 'Sleeve';
        }

        return null;
    }

    private function buildStorageChargeItem(string $descriptionRaw, string $chargeName, string $chargeTypeRaw, float $qty, int $rateCents, int $lineTotalCents, ?string $sku = null): array
    {
        $description = $this->firstNonEmpty([$descriptionRaw, $chargeName, $chargeTypeRaw]) ?? 'Storage';
        $display = $this->extractStorageTypeLabel($description)
            ?? $this->extractStorageTypeLabel($chargeName)
            ?? 'Storage';

        return $this->buildItem(
            InvoiceLineCategory::STORAGE,
            $display,
            $description,
            $qty,
            $rateCents,
            $lineTotalCents,
            null,
            'storage:'.$this->slug($display),
            $chargeTypeRaw !== '' ? $chargeTypeRaw : 'storage',
            $sku
        );
    }

    /**
     * @param list<string> $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            $trim = trim($value);
            if ($trim !== '') {
                return $trim;
            }
        }

        return null;
    }

    private function onDemandDisplayAndSku(string $chargeName, string $skuFromColumn): array
    {
        $sku = trim($skuFromColumn);
        $name = trim($chargeName);
        if ($name === '') {
            if ($sku === '') {
                return ['display' => 'On-Demand Product', 'sku' => null];
            }

            return ['display' => 'On-Demand Product ('.$sku.')', 'sku' => $sku];
        }
        if ($sku === '') {
            $extracted = $this->extractTrailingSkuFromProductName($name);
            if ($extracted !== null) {
                $name = $extracted['name'];
                $sku = $extracted['sku'];
            }
        }
        $display = $name !== '' ? $name : 'On-Demand Product';
        if ($sku !== '') {
            $display .= ' ('.$sku.')';
        }

        return [
            'display' => $display,
            'sku' => $sku !== '' ? $sku : null,
        ];
    }

    private function extractTrailingSkuFromProductName(string $name): ?array
    {
        if (preg_match('/^(.+)\s+\(([^)]+)\)\s*$/u', $name, $m) !== 1) {
            return null;
        }
        $inner = trim($m[2]);
        if ($inner === '') {
            return null;
        }
        if (preg_match('/\d+\s*[x×]\s*\d+/i', $inner) === 1) {
            return null;
        }
        if (preg_match('/^[A-Z0-9][A-Z0-9._\-]*$/i', $inner) !== 1) {
            return null;
        }
        if (strlen($inner) < 2) {
            return null;
        }

        return ['name' => trim($m[1]), 'sku' => $inner];
    }

    private function isOnDemandChargeTypeOrCategory(string $chargeTypeLower, string $chargeTypeRaw): bool
    {
        return $this->billingCategoryRawImpliesOnDemand($chargeTypeRaw.' '.$chargeTypeLower);
    }

    private function billingCategoryRawImpliesOnDemand(string $raw): bool
    {
        $v = strtolower(trim((string) preg_replace('/\s+/', ' ', $raw)));
        if ($v === '') {
            return false;
        }
        $exact = [
            'skincare', 'skin care', 'scion cbo', 'scion cbd', 'scion cbd oil',
            'product (on-demand)', 'product (on demand)', 'product on demand',
            'on-demand', 'on demand',
        ];
        if (in_array($v, $exact, true)) {
            return true;
        }
        if (
            str_contains($v, 'skincare')
            || str_contains($v, 'skin care')
            || str_contains($v, 'scion cbd')
            || str_contains($v, 'scion cbo')
            || str_contains($v, 'cbd oil')
        ) {
            return true;
        }
        if (preg_match('/\bproduct\s*\(?on[- ]?demand\)?\b/', $v) === 1) {
            return true;
        }
        if (preg_match('/\bproduct\s+on\s+demand\b/', $v) === 1) {
            return true;
        }
        if (preg_match('/\bon[- ]?demand\b/', $v) === 1) {
            return true;
        }

        return false;
    }

    private function looksLikeOnDemandProfile(string $chargeName, string $chargeTypeInput): bool
    {
        $typeRaw = trim($chargeTypeInput);
        $tLower = strtolower($typeRaw);
        if ($this->isOnDemandChargeTypeOrCategory($tLower, $typeRaw)) {
            return true;
        }

        $hay = strtolower(trim($chargeName.' '.$typeRaw));
        if ($hay === '') {
            return false;
        }

        return preg_match('/\b(skincare|skin care|scion cbd|scion cbo|cbd oil)\b/', $hay) === 1
            || preg_match('/\bproduct\s*\(?on[- ]?demand\)?\b/', $hay) === 1
            || preg_match('/\bproduct\s+on\s+demand\b/', $hay) === 1
            || preg_match('/\bon[- ]?demand\b/', $hay) === 1;
    }

    private function postageServiceName(string $chargeName, string $chargeType): string
    {
        $name = trim($chargeName);
        $hay = strtolower(trim($chargeName.' '.$chargeType));

        if (preg_match('/endicia/i', $hay) === 1 && preg_match('/usps/i', $hay) === 1) {
            return 'Endicia (USPS)';
        }
        if (preg_match('/\busps\b/i', $hay) === 1) {
            return 'USPS';
        }
        if (preg_match('/\bups\b/i', $hay) === 1) {
            return 'UPS';
        }
        if (preg_match('/fedex/i', $hay) === 1) {
            return 'FedEx';
        }
        if (preg_match('/\bdhl\b/i', $hay) === 1) {
            return 'DHL';
        }
        if (preg_match('/ontrac/i', $hay) === 1) {
            return 'OnTrac';
        }
        if (preg_match('/lasership/i', $hay) === 1) {
            return 'LaserShip';
        }

        $nameNorm = strtolower(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name !== '' && ! in_array($nameNorm, ['shipping label', 'label', 'postage', 'shipping'], true)) {
            return $name;
        }

        return 'Shipping Label';
    }

    private function packagingDisplayName(string $name): string
    {
        $compact = $this->compactPackagingMaterialLabel($name);
        $n = strtolower(trim($compact));
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        if ($n === '') {
            return 'Other';
        }
        $hasBubble = strpos($n, 'bubble wrap') !== false;
        $hasKraft = strpos($n, 'kraft paper') !== false;
        if ($hasBubble && $hasKraft) {
            return 'Bubble Wrap & Kraft Paper';
        }
        if ($hasBubble) {
            return 'Bubble Wrap';
        }
        if ($hasKraft) {
            return 'Kraft Paper';
        }
        if ($this->isBasicBox6x9x1($n)) {
            return 'Box Not Selected';
        }
        if (strpos($n, 'basic box') !== false || strpos($n, 'ship as is') !== false) {
            return 'Ship As Is';
        }
        if (preg_match('/^\(?\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)(?:\s*[x×]\s*(\d+(?:\.\d+)?))?\s*\)?$/i', $n, $m) === 1) {
            $parts = [$m[1], $m[2]];
            if (isset($m[3]) && trim((string) $m[3]) !== '') {
                $parts[] = $m[3];
            }

            return 'Box ('.implode(' X ', $parts).')';
        }
        if ($n === 'packaging') {
            return 'Other';
        }

        return $compact !== '' ? $compact : $name;
    }

    private function compactPackagingMaterialLabel(string $name): string
    {
        $s = trim($name);
        if ($s === '') {
            return '';
        }
        $s = preg_replace('/\s+used\s+for\s+shipping\s+label\b.*$/iu', '', $s) ?? '';
        $s = trim($s);
        $s = preg_replace('/^box\s+/iu', '', $s) ?? '';
        $s = trim($s);
        $prev = null;
        while ($prev !== $s) {
            $prev = $s;
            $s = preg_replace('/\(\s*\d+(\s*[x×]\s*\d+)+(\s*[^)0-9]*)?\)/iu', '', $s) ?? '';
            $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        }

        return trim($s);
    }

    private function cell(array $row, int $idx): string
    {
        if ($idx < 0 || ! isset($row[$idx])) {
            return '';
        }

        return trim((string) $row[$idx]);
    }

    private function parseQty(string $s): float
    {
        if ($s === '') {
            return 0.0;
        }
        $s = trim($s);
        $s = preg_replace('/\s+/', '', $s) ?? '';
        if (strpos($s, ',') !== false && strpos($s, '.') === false) {
            $s = str_replace(',', '.', $s);
        }
        $s = preg_replace('/[^0-9.\-]/', '', $s) ?? '';

        return (float) $s;
    }

    private function parseMoneyToCents(string $s): int
    {
        if ($s === '') {
            return 0;
        }
        $s = trim($s);
        if (strpos($s, '(') !== false) {
            $s = str_replace(['(', ')'], '', $s);
        }
        $s = preg_replace('/^[\s\$€£]+/u', '', $s) ?? $s;
        $s = trim($s);

        $hasComma = strpos($s, ',') !== false;
        $hasDot = strpos($s, '.') !== false;
        if ($hasComma && $hasDot) {
            if (strrpos($s, ',') > strrpos($s, '.')) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($hasComma && ! $hasDot) {
            if (preg_match('/,\d{2}$/', $s) === 1) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        }

        $s = preg_replace('/[^0-9.\-]/', '', $s) ?? '';
        if ($s === '' || $s === '.' || $s === '-') {
            return 0;
        }

        return (int) round(((float) $s) * 100);
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $c) {
            if (trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    private function shipmentOrderNumber(array $row, array $map): ?string
    {
        $idx = $map['shipment_order_number'] ?? ($map['service_code'] ?? -1);
        $value = trim($this->cell($row, $idx));
        return $value !== '' ? $value : null;
    }

    /**
     * `SKU (product)` from the charge CSV, when mapped to `charge_sku`.
     *
     * @param list<string|null> $row
     * @param array<string, int> $index
     */
    private function legacyRowProductSku(array $row, array $index): ?string
    {
        $s = trim((string) $this->cell($row, $index['charge_sku'] ?? -1));

        return $s !== '' ? $s : null;
    }

    /**
     * @param array<string, mixed>|null $item
     * @return array<string, mixed>|null
     */
    private function attachOrderMetadata(?array $item, ?string $orderNumber): ?array
    {
        if ($item === null || $orderNumber === null || $orderNumber === '') {
            return $item;
        }
        $meta = [];
        if (isset($item['metadata']) && is_array($item['metadata'])) {
            $meta = $item['metadata'];
        }
        $meta['order_number'] = $orderNumber;
        $item['metadata'] = $meta;
        if (! isset($item['service_code']) || trim((string) $item['service_code']) === '') {
            $item['service_code'] = $orderNumber;
        }

        return $item;
    }

    private function isReturnLabelCharge(string $chargeName, string $chargeTypeRaw): bool
    {
        $hay = strtolower(trim($chargeName.' '.$chargeTypeRaw));
        if ($hay === '') {
            return false;
        }

        return preg_match('/\breturn\b.*\blab(?:el)?\b|\blab(?:el)?\b.*\breturn\b/i', $hay) === 1;
    }
}
