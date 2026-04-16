<?php

namespace App\Services;

use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Support\Str;

/**
 * Old-beta-compatible CSV importer for billing invoices.
 */
final class InvoiceChargeImportParser
{
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

            if (! $isChargeSummary && ! isset($map['billing_category'], $map['fee'], $map['charge_type'])) {
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
                    $lines[] = $line;
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

            foreach ($this->headerAliases() as $field => $aliases) {
                if (in_array($key, $aliases, true) && ! isset($index[$field])) {
                    $index[$field] = (int) $i;
                    break;
                }
            }
        }

        if (! isset($index['charge_type_new']) && isset($index['charge_type'])) {
            $index['charge_type_new'] = $index['charge_type'];
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
            'billing_category' => ['category (charge)', 'category', 'fee type'],
            'fee' => ['fee (charge)', 'fee', 'type', 'fee type'],
            'charge_name' => ['charge name'],
            'charge_type_new' => ['charge type'],
            'avg_rate' => ['avg rate', 'average rate'],
            'charge_qty' => ['charge qty', 'charge quantity', 'charge count', 'qty', 'quantity'],
            'charge_subtotal' => ['charge subtotal', 'subtotal'],
            'charge_type' => [
                'charge type', 'charge type (charge)', 'charge_type', 'chargetype',
                'type of charge', 'charge type(charge)', 'type (charge)',
            ],
            'quantity' => ['quantity', 'quantity (charge)', 'qty', 'qty (charge)'],
            'unit_rate' => ['unit rate (charge)', 'unit rate', 'unit_rate', 'unit price'],
            'total' => ['total (charge)', 'total', 'amount', 'line total'],
            'carrier' => ['carrier (shipment)', 'carrier'],
            'box' => ['box (shipment)', 'box'],
            'ad_hoc_name' => ['name', 'description', 'item', 'item name', 'description (charge)'],
            'label_charge' => ['label (charge)', 'label'],
            'fee_charge' => ['fee (charge)'],
            'charge_sku' => ['sku', 'sku (product)', 'product sku'],
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
        $h = strtolower(trim($h));
        $h = str_replace(['_', '-', '.'], ' ', $h);
        $h = preg_replace('/\s+/', ' ', $h) ?? '';
        $h = preg_replace('/\s*\(\s*/', ' (', $h) ?? $h;
        $h = preg_replace('/\s*\)\s*/', ') ', $h) ?? $h;

        return trim((string) preg_replace('/\s+/', ' ', $h));
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

        if ($chargeName === '' && $chargeTypeRaw === '' && $categoryRaw === '') {
            return null;
        }

        $qty = $this->parseQty($this->cell($row, $map['charge_qty'] ?? -1));
        $rateCents = $this->parseMoneyToCents($this->cell($row, $map['avg_rate'] ?? -1));
        $subtotalCents = $this->parseMoneyToCents($this->cell($row, $map['charge_subtotal'] ?? -1));
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

        $item = $this->mapChargeSummaryPrimary($chargeName, $chargeTypeRaw, $categoryRaw, $sku, $qty, $rateCents, $lineTotalCents);
        if ($item !== null) {
            return $item;
        }

        $item = $this->mapChargeSummaryRowByHeuristics($chargeName, $chargeTypeRaw, $sku, $qty, $rateCents, $lineTotalCents);
        if ($item !== null) {
            return $item;
        }

        return $this->buildChargeSummaryFallbackItem($chargeName, $chargeTypeRaw, $categoryRaw, $sku, $qty, $rateCents, $lineTotalCents);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $map
     * @return array<string, mixed>|null
     */
    private function parseLegacyRow(array $row, array $map): ?array
    {
        $feeType = $this->getFeeType($row, $map);
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
    private function mapChargeSummaryPrimary(string $chargeName, string $chargeTypeRaw, string $billingCategoryRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        $t = strtolower(trim($chargeTypeRaw));

        if ($this->billingCategoryRawImpliesOnDemand($billingCategoryRaw)) {
            return $this->buildOnDemandItem($chargeName, $chargeTypeRaw, $skuFromColumn, $qty, $rateCents, $lineTotalCents);
        }
        if (strpos($t, 'shipping_label') !== false) {
            $carrier = $this->postageServiceName($chargeName !== '' ? $chargeName : 'Other', $chargeTypeRaw);
            return $this->buildItem(InvoiceLineCategory::POSTAGE, $carrier, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'postage', $chargeTypeRaw);
        }
        if (strpos($t, 'box_charge') !== false) {
            $pkg = $this->packagingDisplayName($chargeName !== '' ? $chargeName : 'Other');
            return $this->buildItem(InvoiceLineCategory::PACKAGING, $pkg, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'packaging:'.$this->slug($pkg), $chargeTypeRaw);
        }
        if (strpos($t, 'order_value_charge') !== false || $t === 'inserts') {
            return $this->buildItem(InvoiceLineCategory::PACKAGING, 'Inserts', 'Inserts', $qty, $rateCents, $lineTotalCents, null, 'packaging:inserts', $chargeTypeRaw);
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
                $chargeTypeRaw
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
                $chargeTypeRaw
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
    private function mapChargeSummaryRowByHeuristics(string $chargeName, string $chargeTypeRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        $hay = strtolower(trim($chargeName.' '.$chargeTypeRaw));
        if ($hay === '') {
            return null;
        }

        if (preg_match('/\b(shipping_label|shipping label|postage|mail class|priority mail|parcel select|ground advantage|media mail|first[- ]class parcel|endicia|stamps?\.com|shipstation|shippo|easy_post|easy post|usps|ups|fedex|dhl|ontrac|lasership|pitney|flat rate|intl|international|zone|delivery confirmation)\b/i', $hay)) {
            $carrier = $this->postageServiceName($chargeName !== '' ? $chargeName : 'Other', $chargeTypeRaw);
            return $this->buildItem(InvoiceLineCategory::POSTAGE, $carrier, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'postage', $chargeTypeRaw);
        }
        if (preg_match('/\b(box_charge|box charge|bubble|kraft|void fill|voidfill|mailer|poly bag|polybag|tape|carton|void_fill|package material|packaging material|mailing tube|stretch wrap)\b/i', $hay)) {
            $pkg = $this->packagingDisplayName($chargeName !== '' ? $chargeName : 'Other');
            return $this->buildItem(InvoiceLineCategory::PACKAGING, $pkg, $chargeName, $qty, $rateCents, $lineTotalCents, null, 'packaging:'.$this->slug($pkg), $chargeTypeRaw);
        }
        if (preg_match('/\b(order_value_charge|order value|inserts?|collateral|marketing insert|gift note|greeting card)\b/i', $hay)) {
            return $this->buildItem(InvoiceLineCategory::PACKAGING, 'Inserts', 'Inserts', $qty, $rateCents, $lineTotalCents, null, 'packaging:inserts', $chargeTypeRaw);
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
                $chargeTypeRaw
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
                $chargeTypeRaw
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
    private function buildChargeSummaryFallbackItem(string $chargeName, string $chargeTypeRaw, string $billingCategoryRaw, string $skuFromColumn, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        if ($lineTotalCents === 0 && $rateCents === 0 && abs($qty) < 0.0001) {
            return null;
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
            $chargeTypeRaw
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
            if (in_array($val, ['skincare', 'skin care', 'product (on-demand)', 'product (on demand)', 'on-demand', 'on demand'], true)) return 'Product (On-Demand)';
            if ($val === 'returns') return 'Returns';
            if ($val === 'order_value_charge' || strpos($val, 'order_value_charge') !== false) return 'Inserts';
            if (strpos($val, 'bubble wrap') !== false || strpos($val, 'kraft paper') !== false) return 'Packaging';
            if ($val === 'ad hoc' || $val === 'ad_hoc' || strpos($val, 'ad_hoc') !== false || strpos($val, 'ad hoc') !== false) return 'Ad Hoc';
            if ($val === 'bank fee' || $val === 'bank_fee' || str_replace([' ', '_'], '', $val) === 'bankfee') return 'Bank Fee';
            if (in_array($val, ['duties & taxes', 'duties and taxes', 'duties_taxes'], true) || str_replace([' ', '_'], '', $val) === 'duties&taxes') return 'Duties & Taxes';
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
            if (in_array($norm, ['Fulfillment', 'Postage', 'Packaging', 'Returns', 'Bank Fee', 'Duties & Taxes', 'Ad Hoc', 'Product (On-Demand)'], true)) {
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

        if ($get('carrier') !== '') return 'Postage';
        if ($get('box') !== '') return 'Packaging';
        $ct = strtolower($get('charge_type'));
        if (strpos($ct, 'order_value_charge') !== false || $ct === 'inserts') return 'Inserts';
        if (strpos($ct, 'first_return_charge') !== false || strpos($ct, 'return_remainder_charge') !== false) return 'Returns';
        if (strpos($ct, 'first') !== false || strpos($ct, 'remainder') !== false || strpos($ct, 'additional') !== false || $ct === 'first_pick_charge' || $ct === 'pick_remainder_charge') return 'Fulfillment';
        if (strpos($ct, 'ad_hoc') !== false || strpos($ct, 'ad hoc') !== false) return 'Ad Hoc';
        if ($ct === 'bank fee' || $ct === 'bank_fee' || strpos($ct, 'bank fee') !== false) return 'Bank Fee';
        if (strpos($ct, 'duties') !== false && (strpos($ct, 'tax') !== false || strpos($ct, 'taxes') !== false)) return 'Duties & Taxes';
        if ($get('charge_sku') !== '' || $get('name_product') !== '' || strpos(strtolower($get('billing_category')), 'skincare') !== false || strpos(strtolower($get('fee')), 'skincare') !== false) {
            return 'Product (On-Demand)';
        }

        $totalVal = $this->parseMoneyToCents($get('total', '0'));
        $qtyNum = $this->parseQty($get('quantity', '0'));
        $urNum = $this->parseMoneyToCents($get('unit_rate', '0'));
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
        if ($chargeTypeNorm !== '' && (
            strpos($chargeTypeNorm, 'remainder') !== false
            || strpos($chargeTypeNorm, 'additional') !== false
            || $chargeTypeNorm === 'pick_remainder charge'
            || $chargeTypeNorm === 'pick remainder charge'
        )) {
            $chargeTypeVal = 'pick_remainder_charge';
            $chargeTypeName = 'Fulfillment (Additional Pick)';
        } elseif ($chargeTypeNorm !== '' && (
            strpos($chargeTypeNorm, 'first') !== false
            || $chargeTypeNorm === 'first_pick charge'
            || $chargeTypeNorm === 'first pick charge'
        )) {
            $chargeTypeVal = 'first_pick_charge';
            $chargeTypeName = 'Fulfillment (First Pick)';
        }

        $qty = $this->parseQty($this->cell($row, $index['quantity'] ?? -1));
        $unitRate = $this->parseMoneyToCents($this->cell($row, $index['unit_rate'] ?? -1));
        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0 && $qty !== 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
        if ($unitRate === 0 && $qty !== 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
        if ($qty === 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;

        return $this->buildItem(InvoiceLineCategory::FULFILLMENT, $chargeTypeName, $chargeTypeName, $qty, $unitRate, $total, $chargeTypeVal === 'pick_remainder_charge' ? 'additional' : 'first', 'fulfillment:'.$this->slug($chargeTypeName), $chargeTypeVal);
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyReturnsRow(array $row, array $index): ?array
    {
        $chargeTypeRaw = strtolower($this->cell($row, $index['charge_type'] ?? -1));
        $qty = $this->parseQty($this->cell($row, $index['quantity'] ?? -1));
        $unitRate = $this->parseMoneyToCents($this->cell($row, $index['unit_rate'] ?? -1));
        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0 && $qty !== 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
        if ($unitRate === 0 && $qty !== 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
        if ($qty === 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;
        $isAdditional = strpos($chargeTypeRaw, 'return_remainder') !== false || strpos($chargeTypeRaw, 'remainder') !== false;

        return $this->buildItem(InvoiceLineCategory::RETURNS, $isAdditional ? 'Returns (Additional Items)' : 'Returns (First Item)', '', $qty, $unitRate, $total, $isAdditional ? 'additional' : 'first', $isAdditional ? 'returns:additional' : 'returns:first', $chargeTypeRaw);
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

        return $this->buildItem(InvoiceLineCategory::POSTAGE, trim($carrier), trim($carrier), 1.0, 0, $total, null, 'postage', '');
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseLegacyPackagingRow(array $row, array $index, string $feeType): ?array
    {
        $qty = $this->parseQty($this->cell($row, $index['quantity'] ?? -1));
        $unitRate = $this->parseMoneyToCents($this->cell($row, $index['unit_rate'] ?? -1));
        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0 && $qty !== 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
        if ($unitRate === 0 && $qty !== 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
        if ($qty === 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;

        if ($feeType === 'Inserts') {
            return $this->buildItem(InvoiceLineCategory::PACKAGING, 'Inserts', 'Inserts', $qty, $unitRate, $total, null, 'packaging:inserts', '');
        }

        $boxRaw = $this->firstNonEmpty([
            $this->cell($row, $index['box'] ?? -1),
            $this->cell($row, $index['label_charge'] ?? -1),
            $this->cell($row, $index['ad_hoc_name'] ?? -1),
            $this->cell($row, $index['billing_category'] ?? -1),
            $this->cell($row, $index['fee_charge'] ?? -1),
            $this->cell($row, $index['fee'] ?? -1),
        ]) ?? 'Other';
        $box = $this->packagingDisplayName($boxRaw);

        return $this->buildItem(InvoiceLineCategory::PACKAGING, $box, $boxRaw, $qty, $unitRate, $total, null, 'packaging:'.$this->slug($box), '');
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
    private function parseLegacyAdHocCategoryRow(array $row, array $index, string $categoryLabel): ?array
    {
        $qty = $this->parseQty($this->cell($row, $index['quantity'] ?? -1));
        $unitRate = $this->parseMoneyToCents($this->cell($row, $index['unit_rate'] ?? -1));
        $total = $this->parseMoneyToCents($this->cell($row, $index['total'] ?? -1));
        if ($total === 0 && $qty !== 0.0 && $unitRate !== 0) $total = (int) round($qty * $unitRate);
        if ($unitRate === 0 && $qty !== 0.0 && $total !== 0) $unitRate = (int) round($total / $qty);
        if ($qty === 0.0 && $total !== 0 && $unitRate !== 0) $qty = round($total / $unitRate, 4);
        if ($qty === 0.0 && $unitRate === 0 && $total === 0) return null;
        if ($qty === 0.0) $qty = 1.0;

        $name = $this->firstNonEmpty([
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
        return $this->buildItem($categoryKey, $name, $name, $qty, $unitRate, $total, null, $this->defaultGroupKeyFor($categoryKey, $name), $this->cell($row, $index['charge_type'] ?? -1));
    }

    /**
     * @param list<string|null> $row
     * @param array<string, int> $index
     * @return array<string, mixed>|null
     */
    private function parseAdHocFallbackRow(array $row, array $index): ?array
    {
        return $this->parseLegacyAdHocCategoryRow($row, $index, 'Ad Hoc');
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
        return [
            'category' => $category,
            'subtype' => $subtype,
            'group_key' => $groupKey,
            'description' => trim($description) !== '' ? trim($description) : $display,
            'display_name' => $display,
            'sku' => $sku,
            'service_code' => Str::limit((string) $serviceCode, 128, ''),
            'quantity' => $qty,
            'unit_price_cents' => $category === InvoiceLineCategory::CREDITS ? $rateCents : max(0, $rateCents),
            'line_total_cents' => $category === InvoiceLineCategory::CREDITS ? $lineTotalCents : max(0, $lineTotalCents),
        ];
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
            'skincare' => 'Product (On-Demand)',
            'skin care' => 'Product (On-Demand)',
            'on-demand' => 'Product (On-Demand)',
            'on demand' => 'Product (On-Demand)',
            'product (on-demand)' => 'Product (On-Demand)',
            'product (on demand)' => 'Product (On-Demand)',
            'product on demand' => 'Product (On-Demand)',
            'ad hoc' => 'Ad Hoc',
            'ad_hoc' => 'Ad Hoc',
            'bank fee' => 'Bank Fee',
            'bank_fee' => 'Bank Fee',
            'duties & taxes' => 'Duties & Taxes',
            'duties and taxes' => 'Duties & Taxes',
            'duties_taxes' => 'Duties & Taxes',
        ];
        if (isset($exact[$t])) return $exact[$t];
        if (strpos($t, 'fulfill') !== false) return 'Fulfillment';
        if (strpos($t, 'postage') !== false || preg_match('/\b(ship|shipping|carrier|parcel|mail)\b/', $t)) return 'Postage';
        if (strpos($t, 'packag') !== false || preg_match('/\b(box|mailer|bubble|kraft)\b/', $t)) return 'Packaging';
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
        return match ($t) {
            'fulfillment' => InvoiceLineCategory::FULFILLMENT,
            'postage' => InvoiceLineCategory::POSTAGE,
            'packaging' => InvoiceLineCategory::PACKAGING,
            'returns' => InvoiceLineCategory::RETURNS,
            'product (on-demand)' => InvoiceLineCategory::ON_DEMAND,
            'storage' => InvoiceLineCategory::STORAGE,
            default => InvoiceLineCategory::AD_HOC,
        };
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
            'skincare', 'skin care', 'product (on-demand)', 'product (on demand)', 'product on demand',
            'on-demand', 'on demand',
        ];
        if (in_array($v, $exact, true)) {
            return true;
        }
        if (str_contains($v, 'skincare') || str_contains($v, 'skin care')) {
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

        return preg_match('/\b(skincare|skin care)\b/', $hay) === 1
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
        if (strpos($n, 'basic box') !== false || strpos($n, 'ship as is') !== false) {
            return 'Ship As Is';
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
}
