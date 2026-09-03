<?php

namespace App\Services;

use App\Models\ClientAccountShopifyConnection;
use App\Models\ShopifyProductVariant;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * CSV upload handling for the Shopify Products page (Import Products / Bulk Edit).
 *
 * Only columns that carry a value are written, so a blank cell leaves the existing
 * value untouched instead of clearing it.
 */
class ShopifyProductCsvService
{
    /** Column order used for the downloadable template and the modal chips. */
    public const COLUMNS = ['name', 'sku', 'barcode', 'weight', 'height', 'width', 'length'];

    /** Columns parsed as numbers. */
    private const NUMERIC_COLUMNS = ['weight', 'height', 'width', 'length'];

    /** @var array<string, list<string>> */
    private const HEADER_ALIASES = [
        'name' => ['name', 'product name', 'product title', 'title', 'product'],
        'sku' => ['sku', 'skus', 'sku code', 'item sku', 'variant sku'],
        'barcode' => ['barcode', 'bar code', 'upc', 'ean', 'gtin'],
        'weight' => ['weight', 'weight lb', 'weight lbs', 'weight (lb)', 'weight (lbs)'],
        'height' => ['height', 'height in', 'height (in)'],
        'width' => ['width', 'width in', 'width (in)'],
        'length' => ['length', 'length in', 'length (in)'],
    ];

    private const MAX_ROWS = 5000;

    /** @var ShopifyProductSyncService */
    private $products;

    public function __construct(ShopifyProductSyncService $products)
    {
        $this->products = $products;
    }

    /**
     * Read an uploaded CSV into normalized rows.
     *
     * @param  list<string>  $required  Columns that must be present and filled on every row.
     * @return array{rows: list<array{row:int, values:array<string, mixed>}>, errors: list<array{row:int, sku:string, message:string}>}
     */
    public function parse(string $path, array $required): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'file' => ['Could not read the uploaded CSV file.'],
            ]);
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false || $header === [null] || count($header) === 0) {
                throw ValidationException::withMessages([
                    'file' => ['The CSV file is empty.'],
                ]);
            }

            $map = $this->mapHeaders($header);
            foreach ($required as $column) {
                if (! isset($map[$column])) {
                    throw ValidationException::withMessages([
                        'file' => ['The CSV must include a '.strtoupper($column).' column.'],
                    ]);
                }
            }

            $rows = [];
            $errors = [];
            $rowNum = 1;

            while (($raw = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($this->rowIsEmpty($raw)) {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages([
                        'file' => ['The CSV has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files.'],
                    ]);
                }

                $values = [];
                $rowError = null;

                foreach (self::COLUMNS as $column) {
                    if (! isset($map[$column])) {
                        continue;
                    }
                    $cell = trim((string) ($raw[$map[$column]] ?? ''));
                    if ($cell === '') {
                        continue;
                    }
                    if (in_array($column, self::NUMERIC_COLUMNS, true)) {
                        $numeric = str_replace([',', ' '], '', $cell);
                        if (! is_numeric($numeric) || (float) $numeric < 0) {
                            $rowError = ucfirst($column).' must be a positive number.';
                            break;
                        }
                        $values[$column] = (float) $numeric;

                        continue;
                    }
                    $values[$column] = $cell;
                }

                $sku = (string) ($values['sku'] ?? '');

                if ($rowError !== null) {
                    $errors[] = ['row' => $rowNum, 'sku' => $sku, 'message' => $rowError];

                    continue;
                }

                foreach ($required as $column) {
                    if (! isset($values[$column])) {
                        $rowError = strtoupper($column).' is required.';
                        break;
                    }
                }
                if ($rowError !== null) {
                    $errors[] = ['row' => $rowNum, 'sku' => $sku, 'message' => $rowError];

                    continue;
                }

                $rows[] = ['row' => $rowNum, 'values' => $values];
            }

            if ($rows === [] && $errors === []) {
                throw ValidationException::withMessages([
                    'file' => ['The CSV has no data rows.'],
                ]);
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Create products in Shopify (and mirror them locally) for each parsed row.
     * Rows whose SKU already exists on the connection are updated instead.
     *
     * @param  list<array{row:int, values:array<string, mixed>}>  $rows
     * @return array{created:int, updated:int, failed:int, errors:list<array{row:int, sku:string, message:string}>}
     */
    public function import(array $rows, ClientAccountShopifyConnection $connection): array
    {
        $created = 0;
        $updated = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $sku = (string) ($values['sku'] ?? '');

            try {
                $existing = ShopifyProductVariant::query()
                    ->with(['product', 'connection'])
                    ->where('connection_id', $connection->id)
                    ->where('sku', $sku)
                    ->first();

                if ($existing !== null) {
                    $this->applyRowToVariant($existing, $values);
                    $updated++;

                    continue;
                }

                $this->products->createProductWithVariant($connection, $this->withUnitDefaults($values));
                $created++;
            } catch (Throwable $e) {
                $failed++;
                $errors[] = [
                    'row' => (int) $row['row'],
                    'sku' => $sku,
                    'message' => $e->getMessage(),
                ];
                Log::warning('shopify.products.import_row_failed', [
                    'connection_id' => (int) $connection->id,
                    'row' => (int) $row['row'],
                    'sku' => $sku,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return compact('created', 'updated', 'failed', 'errors');
    }

    /**
     * Update existing variants matched by SKU. Blank cells are left untouched.
     *
     * @param  list<array{row:int, values:array<string, mixed>}>  $rows
     * @return array{updated:int, missing:int, failed:int, errors:list<array{row:int, sku:string, message:string}>}
     */
    public function bulkEdit(array $rows, ?int $clientAccountId = null): array
    {
        $updated = 0;
        $missing = 0;
        $failed = 0;
        $errors = [];

        foreach ($rows as $row) {
            $values = $row['values'];
            $sku = (string) ($values['sku'] ?? '');

            $query = ShopifyProductVariant::query()
                ->with(['product', 'connection'])
                ->where('sku', $sku);
            if ($clientAccountId !== null && $clientAccountId > 0) {
                $query->whereIn(
                    'connection_id',
                    ClientAccountShopifyConnection::query()
                        ->where('client_account_id', $clientAccountId)
                        ->pluck('id')
                );
            }
            $variants = $query->get();

            if ($variants->isEmpty()) {
                $missing++;
                $errors[] = ['row' => (int) $row['row'], 'sku' => $sku, 'message' => 'No product found with this SKU.'];

                continue;
            }

            foreach ($variants as $variant) {
                try {
                    $this->applyRowToVariant($variant, $values);
                    $updated++;
                } catch (Throwable $e) {
                    $failed++;
                    $errors[] = [
                        'row' => (int) $row['row'],
                        'sku' => $sku,
                        'message' => $e->getMessage(),
                    ];
                    Log::warning('shopify.products.bulk_edit_row_failed', [
                        'variant_id' => (int) $variant->id,
                        'row' => (int) $row['row'],
                        'sku' => $sku,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return compact('updated', 'missing', 'failed', 'errors');
    }

    /**
     * Save the filled columns onto the CRM record, then mirror them to Shopify.
     *
     * @param  array<string, mixed>  $values
     */
    private function applyRowToVariant(ShopifyProductVariant $variant, array $values): void
    {
        $fields = $this->variantFieldsFromRow($variant, $values);
        if ($fields === []) {
            return;
        }

        // Persist locally first so a Shopify outage does not lose the CRM edit.
        if (isset($fields['product_title'])) {
            $variant->loadMissing('product');
            if ($variant->product !== null) {
                $variant->product->title = (string) $fields['product_title'];
                $variant->product->save();
            }
        }
        if (isset($fields['barcode'])) {
            $variant->barcode = (string) $fields['barcode'];
        }
        if (isset($fields['weight'])) {
            $variant->weight = (float) $fields['weight'];
            $variant->weight_unit = (string) $fields['weight_unit'];
        }
        foreach (['length', 'width', 'height'] as $dimension) {
            if (isset($fields[$dimension])) {
                $variant->{$dimension} = (float) $fields[$dimension];
            }
        }
        if (isset($fields['dimension_unit'])) {
            $variant->dimension_unit = (string) $fields['dimension_unit'];
        }
        $variant->save();

        $this->products->pushVariantToShopify($variant, $fields);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function variantFieldsFromRow(ShopifyProductVariant $variant, array $values): array
    {
        $fields = [];
        if (isset($values['name'])) {
            $fields['product_title'] = (string) $values['name'];
        }
        if (isset($values['barcode'])) {
            $fields['barcode'] = (string) $values['barcode'];
        }
        if (isset($values['weight'])) {
            $fields['weight'] = (float) $values['weight'];
            $fields['weight_unit'] = $this->weightUnit($variant->weight_unit);
        }
        $hasDimension = false;
        foreach (['length', 'width', 'height'] as $dimension) {
            if (isset($values[$dimension])) {
                $fields[$dimension] = (float) $values[$dimension];
                $hasDimension = true;
            }
        }
        if ($hasDimension) {
            $fields['dimension_unit'] = $this->dimensionUnit($variant->dimension_unit);
        }

        return $fields;
    }

    /**
     * Stamp the units the CRM stores alongside measurements so new products
     * render the same way as ones synced from Shopify.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withUnitDefaults(array $values): array
    {
        if (isset($values['weight'])) {
            $values['weight_unit'] = $this->weightUnit(null);
        }
        foreach (['length', 'width', 'height'] as $dimension) {
            if (isset($values[$dimension])) {
                $values['dimension_unit'] = $this->dimensionUnit(null);
                break;
            }
        }

        return $values;
    }

    /**
     * @param  mixed  $current
     */
    private function weightUnit($current): string
    {
        $unit = strtoupper(trim((string) ($current ?? '')));

        return in_array($unit, ['GRAMS', 'KILOGRAMS', 'OUNCES', 'POUNDS'], true) ? $unit : 'POUNDS';
    }

    /**
     * @param  mixed  $current
     */
    private function dimensionUnit($current): string
    {
        $unit = strtoupper(trim((string) ($current ?? '')));

        return in_array($unit, ['INCHES', 'CENTIMETERS'], true) ? $unit : 'INCHES';
    }

    /**
     * @param  list<mixed>  $header
     * @return array<string, int>
     */
    private function mapHeaders(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $normalized = $this->normalizeHeader((string) $label);
            if ($normalized === '') {
                continue;
            }
            foreach (self::HEADER_ALIASES as $column => $aliases) {
                if (isset($map[$column])) {
                    continue;
                }
                if (in_array($normalized, $aliases, true)) {
                    $map[$column] = (int) $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function normalizeHeader(string $label): string
    {
        // Strip a UTF-8 BOM on the first header cell before normalizing.
        $clean = str_replace("\xEF\xBB\xBF", '', $label);
        $clean = strtolower(trim($clean));
        $clean = preg_replace('/[_\-]+/', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', (string) $clean);

        return trim((string) $clean);
    }

    /**
     * @param  list<mixed>  $row
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
