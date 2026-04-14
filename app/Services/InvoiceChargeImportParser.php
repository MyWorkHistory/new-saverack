<?php

namespace App\Services;

use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Support\Str;

/**
 * Parses “charge summary” style CSV rows into invoice line payloads for {@see InvoiceService::replaceItems}.
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
            if (! isset($map['charge_type'], $map['charge_name'])) {
                $seen = array_values(array_filter(array_map(function ($raw) {
                    return $this->normalizeHeaderKey((string) $raw);
                }, $headerRow)));
                $hint = $seen !== [] ? ' Found headers: '.implode(', ', $seen).'.' : '';

                throw new \RuntimeException(
                    'Could not detect which columns are charge type and charge name.'.$hint
                    .' Use headers containing “type” / “category” and “name” / “description” (underscores and spacing are OK).'
                );
            }
            $lines = [];
            while (($row = fgetcsv($fh)) !== false) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                $line = $this->parseRow($row, $map);
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
     * @param  list<string|null>  $headerRow
     * @return array<string, int>
     */
    private function mapHeaders(array $headerRow): array
    {
        $map = [];
        $normByIndex = [];

        foreach ($headerRow as $i => $raw) {
            $key = $this->normalizeHeaderKey((string) $raw);
            if ($key === '') {
                continue;
            }
            $idx = (int) $i;
            $normByIndex[$idx] = $key;
            foreach ($this->headerAliases() as $canonical => $aliases) {
                if (in_array($key, $aliases, true) && ! isset($map[$canonical])) {
                    $map[$canonical] = $idx;
                    break;
                }
            }
        }

        $typeScores = [];
        $nameScores = [];
        foreach ($normByIndex as $idx => $key) {
            $typeScores[$idx] = $this->scoreHeaderAsChargeType($key);
            $nameScores[$idx] = $this->scoreHeaderAsChargeName($key);
        }

        if (! isset($map['charge_type'])) {
            arsort($typeScores);
            foreach ($typeScores as $idx => $score) {
                if ($score > 0) {
                    $map['charge_type'] = (int) $idx;
                    break;
                }
            }
        }

        if (! isset($map['charge_name'])) {
            arsort($nameScores);
            foreach ($nameScores as $idx => $score) {
                if ($score <= 0) {
                    continue;
                }
                if (isset($map['charge_type']) && (int) $idx === $map['charge_type']) {
                    continue;
                }
                $map['charge_name'] = (int) $idx;
                break;
            }
        }

        if (isset($map['charge_type'], $map['charge_name']) && $map['charge_type'] === $map['charge_name']) {
            $secondNameIdx = null;
            $secondNameScore = 0;
            foreach ($nameScores as $idx => $score) {
                if ((int) $idx === $map['charge_type']) {
                    continue;
                }
                if ($score > $secondNameScore) {
                    $secondNameScore = $score;
                    $secondNameIdx = (int) $idx;
                }
            }
            if ($secondNameIdx !== null) {
                $map['charge_name'] = $secondNameIdx;
            }
        }

        $usedIdx = [];
        foreach ($map as $mappedCol) {
            $usedIdx[(int) $mappedCol] = true;
        }
        if (isset($map['charge_type']) && ! isset($map['charge_name'])) {
            foreach (array_keys($normByIndex) as $idx) {
                if (isset($usedIdx[(int) $idx])) {
                    continue;
                }
                $map['charge_name'] = (int) $idx;
                break;
            }
        }
        if (isset($map['charge_type']) && ! isset($map['charge_name'])) {
            foreach (array_keys($normByIndex) as $idx) {
                if ((int) $idx === $map['charge_type']) {
                    continue;
                }
                $map['charge_name'] = (int) $idx;
                break;
            }
        }

        $usedIdx = [];
        foreach ($map as $mappedCol) {
            $usedIdx[(int) $mappedCol] = true;
        }

        if (isset($map['charge_name']) && ! isset($map['charge_type'])) {
            foreach (array_keys($normByIndex) as $idx) {
                if (isset($usedIdx[(int) $idx])) {
                    continue;
                }
                if ((int) $idx === $map['charge_name']) {
                    continue;
                }
                $map['charge_type'] = (int) $idx;
                break;
            }
        }
        if (isset($map['charge_name']) && ! isset($map['charge_type'])) {
            foreach (array_keys($normByIndex) as $idx) {
                if ((int) $idx === $map['charge_name']) {
                    continue;
                }
                $map['charge_type'] = (int) $idx;
                break;
            }
        }

        return $map;
    }

    private function scoreHeaderAsChargeType(string $key): int
    {
        if ($key === '' || $this->headerLooksLikeNameNotType($key)) {
            return 0;
        }

        if (preg_match('/^(charge|fee|item)\s*type$/', $key) === 1) {
            return 100;
        }
        if ($key === 'type' || $key === 'category' || $key === 'fee type' || $key === 'charge type') {
            return 95;
        }
        if (str_contains($key, 'charge') && str_contains($key, 'type')) {
            return 90;
        }
        if (preg_match('/\b(service|fee|item)\s+type\b/', $key) === 1) {
            return 85;
        }
        if (str_contains($key, 'category') && ! str_contains($key, 'name')) {
            return 70;
        }
        if (preg_match('/\btype\b/', $key) === 1 && ! str_contains($key, 'name')) {
            return 55;
        }

        return 0;
    }

    private function scoreHeaderAsChargeName(string $key): int
    {
        if ($key === '' || $this->headerLooksLikeTypeNotName($key)) {
            return 0;
        }

        if (preg_match('/^(charge|fee|item)\s*name$/', $key) === 1) {
            return 100;
        }
        if ($key === 'name' || $key === 'title' || $key === 'label' || $key === 'memo' || $key === 'item') {
            return 90;
        }
        if (str_contains($key, 'charge') && str_contains($key, 'name')) {
            return 95;
        }
        if (preg_match('/\b(desc|description|detail|summary)\b/', $key) === 1) {
            return 88;
        }
        if (preg_match('/\b(product|sku|service)\s*name\b/', $key) === 1) {
            return 75;
        }
        if (preg_match('/\bname\b/', $key) === 1 && ! preg_match('/\b(file|user|company|customer|account|first|last|middle)\s*name\b/', $key)) {
            return 50;
        }

        return 0;
    }

    private function headerLooksLikeNameNotType(string $key): bool
    {
        return preg_match('/\b(name|description|title|label|memo|sku|product)\b/', $key) === 1
            && preg_match('/\btype\b/', $key) !== 1;
    }

    private function headerLooksLikeTypeNotName(string $key): bool
    {
        if (preg_match('/\b(name|description|title)\b/', $key) === 1) {
            return false;
        }

        return preg_match('/\b(type|category)\b/', $key) === 1;
    }

    /**
     * @return array<string, list<string>>
     */
    private function headerAliases(): array
    {
        return [
            'charge_name' => [
                'charge name', 'chargename', 'name', 'description', 'charge description',
                'item description', 'line description', 'service name', 'product name',
                'charge label', 'detail', 'summary',
            ],
            'charge_type' => [
                'charge type', 'chargetype', 'type', 'fee type', 'category',
                'item type', 'service type', 'fee category', 'charge category',
            ],
            'charge_qty' => [
                'charge qty', 'quantity', 'qty', 'charge count', 'count', 'units',
                'line qty', 'item qty',
            ],
            'avg_rate' => [
                'avg rate', 'average rate', 'rate', 'unit rate', 'avg', 'unit price',
                'price', 'cost', 'rate each', 'each price', 'price each', 'per unit',
                'unit cost', 'cost each', 'avg cost',
            ],
            'charge_subtotal' => [
                'charge subtotal', 'subtotal', 'amount', 'total', 'charge total',
                'line total', 'extended', 'ext price', 'amount usd',
            ],
        ];
    }

    private function normalizeHeaderKey(string $h): string
    {
        $h = trim($h);
        if (str_starts_with($h, "\xEF\xBB\xBF")) {
            $h = substr($h, 3);
        }
        $h = trim($h, " \t\n\r\0\x0B\"'");
        $h = strtolower($h);
        $h = str_replace(['_', '-', '.'], ' ', $h);
        $h = preg_replace('/\s+/', ' ', $h) ?? '';

        return trim($h);
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row, array $map): ?array
    {
        $typeRaw = $this->cell($row, $map['charge_type'] ?? -1);
        $nameRaw = $this->cell($row, $map['charge_name'] ?? -1);
        if ($typeRaw === '' && $nameRaw === '') {
            return null;
        }
        $type = strtolower($typeRaw);
        $qty = $this->parseQty($this->cell($row, $map['charge_qty'] ?? -1));
        $rateCents = $this->parseMoneyToCents($this->cell($row, $map['avg_rate'] ?? -1));
        $subtotalCents = $this->parseMoneyToCents($this->cell($row, $map['charge_subtotal'] ?? -1));

        if ($qty <= 0 && $subtotalCents > 0 && $rateCents > 0) {
            $qty = $subtotalCents / $rateCents;
        }
        if ($qty <= 0) {
            $qty = 1.0;
        }
        if (abs($qty - round($qty)) < 0.02) {
            $qty = (float) (int) round($qty);
        }
        if ($rateCents <= 0 && $subtotalCents > 0 && $qty > 0) {
            $rateCents = (int) round($subtotalCents / $qty);
        }
        $lineTotal = $subtotalCents > 0 ? $subtotalCents : (int) round($qty * $rateCents);

        $routed = $this->routeLine($type, $typeRaw, $nameRaw, $qty, $rateCents, $lineTotal);
        if ($routed === null) {
            return null;
        }

        return $routed;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function routeLine(string $chargeTypeLower, string $chargeTypeRaw, string $chargeName, float $qty, int $rateCents, int $lineTotalCents): ?array
    {
        $t = $chargeTypeLower;
        $name = trim($chargeName);
        $typeRawTrim = trim($chargeTypeRaw);
        $groupKey = null;
        $category = InvoiceLineCategory::OTHER;
        $display = $name !== '' ? $name : 'Charge';
        $subtype = null;

        if (strpos($t, 'shipping_label') !== false) {
            $category = InvoiceLineCategory::POSTAGE;
            $display = 'Postage ('.($name !== '' ? $name : 'Shipping').')';
            $groupKey = 'postage:'.Str::slug($display);
        } elseif (strpos($t, 'box_charge') !== false) {
            $category = InvoiceLineCategory::PACKAGING;
            $display = 'Packaging ('.($name !== '' ? $name : 'Box').')';
            $groupKey = 'packaging:'.Str::slug($display);
        } elseif (strpos($t, 'order_value_charge') !== false || strpos($t, 'inserts') !== false) {
            $category = InvoiceLineCategory::PACKAGING;
            $display = 'Inserts';
            $groupKey = 'packaging:inserts';
        } elseif (strpos($t, 'first_return_charge') !== false) {
            $category = InvoiceLineCategory::RETURNS;
            $display = 'Returns (First item)';
            $subtype = 'first';
            $groupKey = 'returns:first';
        } elseif (strpos($t, 'return_remainder_charge') !== false) {
            $category = InvoiceLineCategory::RETURNS;
            $display = 'Returns (Additional)';
            $subtype = 'additional';
            $groupKey = 'returns:additional';
        } elseif (strpos($t, 'first_pick_charge') !== false) {
            $category = InvoiceLineCategory::FULFILLMENT;
            if ($name === '' || stripos($name, 'fulfillment') !== false) {
                $display = 'Fulfillment (First Pick)';
                $groupKey = 'fulfillment:first-pick';
            } else {
                $display = 'Fulfillment ('.$name.')';
                $groupKey = 'fulfillment:'.Str::slug($name);
            }
        } elseif (strpos($t, 'pick_remainder_charge') !== false) {
            $category = InvoiceLineCategory::FULFILLMENT;
            if ($name === '' || stripos($name, 'fulfillment') !== false) {
                $display = 'Fulfillment (Additional Pick)';
                $groupKey = 'fulfillment:additional-pick';
            } else {
                $display = 'Fulfillment ('.$name.')';
                $groupKey = 'fulfillment:'.Str::slug($name);
            }
        } else {
            $category = InvoiceLineCategory::AD_HOC;
            $display = $name !== '' ? $name : 'Ad hoc ('.($typeRawTrim !== '' ? $typeRawTrim : $t).')';
            $groupKey = 'ad_hoc:'.Str::slug($display);
        }

        $desc = $name !== '' ? $name : $display;
        $code = $typeRawTrim !== '' ? $typeRawTrim : $t;

        return [
            'category' => $category,
            'subtype' => $subtype,
            'group_key' => $groupKey,
            'description' => $desc,
            'display_name' => $display,
            'service_code' => Str::limit($code, 128, ''),
            'quantity' => $qty,
            'unit_price_cents' => max(0, $rateCents),
            'line_total_cents' => max(0, $lineTotalCents),
        ];
    }

    /**
     * @param  list<string|null>  $row
     */
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

    /**
     * @param  list<string|null>  $row
     */
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
