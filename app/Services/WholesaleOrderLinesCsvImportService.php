<?php

namespace App\Services;

use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Parse + apply wholesale order line CSV imports (SKU + QTY).
 * Product enrichment uses local inventory index/cache only — no ShipHero round-trips.
 */
class WholesaleOrderLinesCsvImportService
{
    /** @var ShipHeroInventoryService */
    private $inventory;

    /** @var InventoryProductDetailCacheService */
    private $detailCache;

    public function __construct(
        ShipHeroInventoryService $inventory,
        InventoryProductDetailCacheService $detailCache
    ) {
        $this->inventory = $inventory;
        $this->detailCache = $detailCache;
    }

    /**
     * @return array{rows: list<array{row: int, name: string, sku: string, quantity: int}>, errors: list<array{row: int, sku: string, message: string}>}
     */
    public function parse(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw ValidationException::withMessages([
                'file' => ['Could not read the uploaded CSV file.'],
            ]);
        }

        try {
            $firstLine = fgets($fh);
            if ($firstLine === false || trim($firstLine) === '') {
                throw ValidationException::withMessages([
                    'file' => ['CSV is empty.'],
                ]);
            }
            $delimiter = $this->detectDelimiter($firstLine);
            rewind($fh);

            $headerRow = fgetcsv($fh, 0, $delimiter);
            if ($headerRow === false || $headerRow === [null] || count($headerRow) === 0) {
                throw ValidationException::withMessages([
                    'file' => ['CSV is empty.'],
                ]);
            }

            $map = $this->mapHeaders($headerRow);
            if (! isset($map['sku']) || ! isset($map['quantity'])) {
                throw ValidationException::withMessages([
                    'file' => ['CSV must include SKU and QTY columns.'],
                ]);
            }

            $rows = [];
            $errors = [];
            $rowNum = 1;
            while (($raw = fgetcsv($fh, 0, $delimiter)) !== false) {
                $rowNum++;
                if ($this->rowIsEmpty($raw)) {
                    continue;
                }

                $sku = isset($map['sku'], $raw[$map['sku']])
                    ? $this->inventory->cleanAsnCsvSkuValue((string) $raw[$map['sku']])
                    : '';
                $name = isset($map['name'], $raw[$map['name']])
                    ? trim((string) $raw[$map['name']])
                    : '';
                $qtyRaw = isset($map['quantity'], $raw[$map['quantity']])
                    ? trim((string) $raw[$map['quantity']])
                    : '';
                $qtyRaw = str_replace([',', ' '], '', $qtyRaw);

                if ($sku === '') {
                    $errors[] = [
                        'row' => $rowNum,
                        'sku' => '',
                        'message' => 'SKU is required.',
                    ];

                    continue;
                }

                if ($qtyRaw === '' || ! is_numeric($qtyRaw) || (int) $qtyRaw < 1) {
                    $errors[] = [
                        'row' => $rowNum,
                        'sku' => $sku,
                        'message' => 'Enter a quantity of at least 1.',
                    ];

                    continue;
                }

                $qty = (int) $qtyRaw;
                if ($qty > 99999999) {
                    $errors[] = [
                        'row' => $rowNum,
                        'sku' => $sku,
                        'message' => 'Quantity is too large.',
                    ];

                    continue;
                }

                if (mb_strlen($sku) > 255) {
                    $errors[] = [
                        'row' => $rowNum,
                        'sku' => mb_substr($sku, 0, 255),
                        'message' => 'SKU is too long.',
                    ];

                    continue;
                }

                $rows[] = [
                    'row' => $rowNum,
                    'name' => $name,
                    'sku' => $sku,
                    'quantity' => $qty,
                ];
            }

            if ($rows === [] && $errors === []) {
                throw ValidationException::withMessages([
                    'file' => ['CSV has no data rows.'],
                ]);
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param  list<array{row: int, name: string, sku: string, quantity: int}>  $rows
     * @return array{imported: int, updated: int, errors: list<array{row: int, sku: string, message: string}>}
     */
    public function apply(WholesaleOrder $order, array $rows): array
    {
        if ($rows === []) {
            return ['imported' => 0, 'updated' => 0, 'errors' => []];
        }

        $order->loadMissing(['clientAccount', 'lines']);
        $clientAccountId = (int) $order->client_account_id;
        $customerId = $order->clientAccount
            ? trim((string) ($order->clientAccount->shiphero_customer_account_id ?? ''))
            : '';
        $shipheroCustomerId = $customerId !== '' ? $customerId : null;

        $skuList = [];
        foreach ($rows as $row) {
            $skuList[] = (string) ($row['sku'] ?? '');
        }

        // Local index/cache only — never call ShipHero per SKU (Cloudflare 524).
        $productCache = $this->inventory->resolveProductsForAsnCsvImport(
            $clientAccountId,
            $shipheroCustomerId,
            $skuList,
            false
        );

        $weightBySkuKey = [];
        $pairs = [];
        foreach ($skuList as $sku) {
            $sku = trim((string) $sku);
            if ($sku === '') {
                continue;
            }
            $pairs[] = [
                'client_account_id' => $clientAccountId,
                'sku' => $sku,
            ];
        }
        if ($pairs !== [] && $clientAccountId > 0) {
            $cachedProducts = $this->detailCache->getCachedProductsForPairs($pairs);
            foreach ($cachedProducts as $mapKey => $product) {
                if (! is_array($product)) {
                    continue;
                }
                $raw = $product['dimensions']['weight'] ?? null;
                if ($raw === null || $raw === '' || ! is_numeric($raw)) {
                    continue;
                }
                $parts = explode('|', (string) $mapKey, 2);
                $norm = isset($parts[1]) ? (string) $parts[1] : '';
                if ($norm !== '') {
                    $weightBySkuKey[$norm] = (float) $raw;
                }
            }
        }

        /** @var array<string, WholesaleOrderLine> $linesBySku */
        $linesBySku = [];
        foreach ($order->lines as $existingLine) {
            $key = mb_strtolower(trim((string) $existingLine->sku));
            if ($key !== '') {
                $linesBySku[$key] = $existingLine;
            }
        }
        $maxSort = (int) $order->lines->max('sort_order');

        $imported = 0;
        $updated = 0;
        $errors = [];

        DB::transaction(function () use (
            $order,
            $rows,
            $productCache,
            $weightBySkuKey,
            &$linesBySku,
            &$maxSort,
            &$imported,
            &$updated,
            &$errors
        ) {
            foreach ($rows as $row) {
                $rowNum = (int) $row['row'];
                $sku = (string) $row['sku'];
                $skuKey = mb_strtolower(trim($sku));
                $qty = (int) $row['quantity'];
                $csvName = (string) ($row['name'] ?? '');

                $product = $productCache[$skuKey] ?? null;

                if (isset($linesBySku[$skuKey])) {
                    $line = $linesBySku[$skuKey];
                    $line->quantity = (int) $line->quantity + $qty;
                    $line->save();
                    $updated++;

                    continue;
                }

                $name = $csvName !== ''
                    ? $csvName
                    : (string) ($product['name'] ?? '');
                if ($name === '') {
                    $name = $sku;
                }
                if (mb_strlen($name) > 512) {
                    $name = mb_substr($name, 0, 512);
                }

                $canonicalSku = $sku;
                if (is_array($product) && isset($product['sku']) && trim((string) $product['sku']) !== '') {
                    $canonicalSku = trim((string) $product['sku']);
                }
                if (mb_strlen($canonicalSku) > 255) {
                    $errors[] = [
                        'row' => $rowNum,
                        'sku' => mb_substr($sku, 0, 255),
                        'message' => 'SKU is too long.',
                    ];

                    continue;
                }

                $imageUrl = null;
                if (is_array($product) && isset($product['image_url'])) {
                    $imageUrl = trim((string) $product['image_url']);
                    if ($imageUrl !== '' && strlen($imageUrl) > 2048) {
                        $imageUrl = substr($imageUrl, 0, 2048);
                    }
                    if ($imageUrl === '') {
                        $imageUrl = null;
                    }
                }

                $line = new WholesaleOrderLine;
                $line->wholesale_order_id = $order->id;
                $line->sku = $canonicalSku;
                $line->name = $name;
                $line->image_url = $imageUrl;
                $line->quantity = $qty;
                $line->barcode_mode = WholesaleOrderLine::BARCODE_SHIP_AS_IS;
                $line->syncStatusFromBarcodeMode();
                $line->sort_order = ++$maxSort;
                $normKey = $this->detailCache->normalizeSku($canonicalSku);
                $line->weight = $weightBySkuKey[$normKey] ?? $weightBySkuKey[$skuKey] ?? null;
                $line->save();

                $linesBySku[$skuKey] = $line;
                $imported++;
            }
        });

        $sum = (int) WholesaleOrderLine::query()
            ->where('wholesale_order_id', $order->id)
            ->sum('quantity');
        $order->items_count = $sum;
        $order->saveQuietly();

        return [
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
        ];
    }

    private function detectDelimiter(string $headerLine): string
    {
        $tabs = substr_count($headerLine, "\t");
        $commas = substr_count($headerLine, ',');
        $semicolons = substr_count($headerLine, ';');
        if ($tabs > $commas && $tabs >= $semicolons) {
            return "\t";
        }
        if ($semicolons > $commas) {
            return ';';
        }

        return ',';
    }

    /**
     * @param  list<string|null>  $headerRow
     * @return array{name?: int, sku?: int, quantity?: int}
     */
    private function mapHeaders(array $headerRow): array
    {
        $nameAliases = [
            'name', 'product', 'product name', 'productname', 'title', 'item', 'item name', 'itemname',
        ];
        $skuAliases = [
            'sku', 'skus', 'product sku', 'productsku', 'item sku', 'itemsku',
        ];
        $qtyAliases = [
            'qty' => 100,
            'quantity' => 95,
            'qnty' => 90,
            'quantity available' => 80,
            'qty available' => 80,
            'available qty' => 75,
            'available quantity' => 75,
            'on hand' => 70,
            'onhand' => 70,
            'expected qty' => 60,
            'expected quantity' => 60,
            'expected_qty' => 60,
            'units' => 40,
            'count' => 35,
        ];

        $map = [];
        $bestQtyScore = -1;
        foreach ($headerRow as $index => $raw) {
            $normalized = mb_strtolower(trim((string) $raw));
            $normalized = preg_replace('/^\xEF\xBB\xBF/', '', $normalized) ?? $normalized;
            $normalized = str_replace(['_', '-'], ' ', $normalized);
            $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

            if (! isset($map['name']) && in_array($normalized, $nameAliases, true)) {
                $map['name'] = (int) $index;
                continue;
            }
            if (! isset($map['sku']) && in_array($normalized, $skuAliases, true)) {
                $map['sku'] = (int) $index;
                continue;
            }
            if (isset($qtyAliases[$normalized]) && (int) $qtyAliases[$normalized] > $bestQtyScore) {
                $bestQtyScore = (int) $qtyAliases[$normalized];
                $map['quantity'] = (int) $index;
            }
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
