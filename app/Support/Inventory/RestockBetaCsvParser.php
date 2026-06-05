<?php

namespace App\Support\Inventory;

use RuntimeException;

final class RestockBetaCsvParser
{
    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'sku' => ['sku'],
        'name' => ['name'],
        'on_hand' => ['on hand', 'onhand'],
        'allocated' => ['allocated'],
        'replenishment_level' => ['replenishment level', 'replenishment'],
        'pickable_qty' => [
            'available in pickable bins',
            'available in pickable bin',
        ],
        'backstock_qty' => [
            'qty from non-pickable bins',
            'qty from non pickable bins',
        ],
        'restock_needed' => ['items to replenish', 'items to restock'],
        'backstock_locations' => [
            'top 3 non-pickable bins',
            'top 3 non pickable bins',
        ],
    ];

    /** @var list<string> */
    private const REQUIRED_FIELDS = ['sku', 'name'];

    /**
     * @return list<array<string, mixed>>
     */
    public function parseFile(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Could not read CSV file.');
        }

        try {
            $headerRow = fgetcsv($fh);
            if ($headerRow === false || $headerRow === [null] || count($headerRow) === 0) {
                throw new RuntimeException('CSV is empty.');
            }

            $map = $this->mapHeaders($headerRow);
            $missing = array_values(array_filter(
                self::REQUIRED_FIELDS,
                fn (string $field): bool => ! isset($map[$field])
            ));
            if ($missing !== []) {
                throw new RuntimeException('Missing required CSV columns: SKU and Name.');
            }

            $rows = [];
            while (($row = fgetcsv($fh)) !== false) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $parsed = $this->parseRow($row, $map);
                if ($parsed !== null) {
                    $rows[] = $parsed;
                }
            }

            if ($rows === []) {
                throw new RuntimeException('CSV has no data rows.');
            }

            return $rows;
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
        foreach ($headerRow as $index => $raw) {
            $key = $this->normalizeHeaderKey((string) $raw);
            if ($key === '') {
                continue;
            }
            foreach (self::HEADER_ALIASES as $field => $aliases) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($aliases as $alias) {
                    if ($key === $alias) {
                        $map[$field] = (int) $index;
                        break;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     * @return array<string, mixed>|null
     */
    private function parseRow(array $row, array $map): ?array
    {
        $sku = $this->cellString($row, $map, 'sku');
        if ($sku === '') {
            return null;
        }

        return [
            'sku' => $sku,
            'name' => $this->cellString($row, $map, 'name'),
            'on_hand' => $this->cellInt($row, $map, 'on_hand'),
            'allocated' => $this->cellInt($row, $map, 'allocated'),
            'pickable_qty' => $this->cellInt($row, $map, 'pickable_qty'),
            'backstock_qty' => $this->cellInt($row, $map, 'backstock_qty'),
            'restock_needed' => $this->cellInt($row, $map, 'restock_needed'),
            'backstock_locations' => $this->cellString($row, $map, 'backstock_locations'),
        ];
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function cellString(array $row, array $map, string $field): string
    {
        if (! isset($map[$field])) {
            return '';
        }
        $value = $row[$map[$field]] ?? '';

        return trim((string) $value);
    }

    /**
     * @param  list<string|null>  $row
     * @param  array<string, int>  $map
     */
    private function cellInt(array $row, array $map, string $field): ?int
    {
        if (! isset($map[$field])) {
            return null;
        }
        $raw = trim((string) ($row[$map[$field]] ?? ''));
        if ($raw === '') {
            return null;
        }
        $normalized = str_replace([',', ' '], '', $raw);
        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return (int) round((float) $normalized);
    }

    /**
     * @param  list<string|null>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) ($cell ?? '')) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeHeaderKey(string $raw): string
    {
        $key = strtolower(trim($raw));
        $key = preg_replace('/\s+/', ' ', $key) ?? $key;

        return trim($key);
    }
}
