<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\ClientAccountOnDemandProduct;
use App\Models\InvoiceImport;
use App\Models\User;
use App\Support\Billing\InvoiceHistoryEventType;
use App\Support\Billing\InvoiceLineCategory;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceImportService
{
    /** @var InvoiceService */
    private $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    /**
     * @return array{
     *   invoice: \App\Models\Invoice,
     *   import: InvoiceImport,
     *   on_demand_debug: array{
     *     catalog_rows: int,
     *     sku_candidate_rows: int,
     *     matched_sku_rows: int,
     *     unmatched_sku_rows: int,
     *     unmatched_sku_samples: list<string>
     *   }
     * }
     */
    public function importChargeCsv(
        ClientAccount $account,
        UploadedFile $file,
        string $dueDateYmd,
        ?string $invoiceNumber,
        ?User $actor
    ): array {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new \RuntimeException('Invalid upload.');
        }

        $parser = new InvoiceChargeImportParser();
        $lines = $parser->parseFile($path);
        $aggregate = $this->aggregateOnDemandCatalogPickLines($account, $lines);
        $lines = $aggregate['lines'];
        $onDemandDebug = $aggregate['debug'];
        if (count($lines) === 0) {
            throw new \RuntimeException('No billable rows found in CSV.');
        }

        $originalFilename = $file->getClientOriginalName();
        $billingPeriod = self::billingPeriodFromFilename($originalFilename);

        $import = new InvoiceImport([
            'client_account_id' => $account->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'import_type' => InvoiceImport::TYPE_FULL_CHARGE_CSV,
            'original_filename' => $originalFilename,
            'status' => InvoiceImport::STATUS_PENDING,
        ]);
        $import->save();

        try {
            $invoice = DB::transaction(function () use ($account, $lines, $dueDateYmd, $invoiceNumber, $actor, $import, $billingPeriod) {
                $header = [
                    'client_account_id' => $account->id,
                    'currency' => 'USD',
                    'due_at' => $dueDateYmd,
                ];
                if ($billingPeriod !== null) {
                    $header['billing_period_start'] = $billingPeriod['start'];
                    $header['billing_period_end'] = $billingPeriod['end'];
                }
                $inv = $this->invoices->createDraft($header, $lines, $actor, $invoiceNumber);
                $this->invoices->logHistory($inv, $actor, 'updated', $inv->status, $inv->status, [
                    'event_type' => InvoiceHistoryEventType::IMPORT,
                    'history_message' => 'Imported charge CSV ('.count($lines).' lines).',
                    'import_id' => $import->id,
                    'billing_period_start' => $billingPeriod['start'] ?? null,
                    'billing_period_end' => $billingPeriod['end'] ?? null,
                ]);

                return $inv;
            });

            $import->invoice_id = $invoice->id;
            $import->rows_processed = count($lines);
            $import->status = InvoiceImport::STATUS_COMPLETED;
            $import->result_summary = array_filter([
                'line_count' => count($lines),
                'billing_period_start' => $billingPeriod['start'] ?? null,
                'billing_period_end' => $billingPeriod['end'] ?? null,
                'on_demand_debug' => $onDemandDebug,
            ], static function ($value) {
                return $value !== null;
            });
            $import->save();

            return [
                'invoice' => $invoice->fresh(['items', 'clientAccount']),
                'import' => $import,
                'on_demand_debug' => $onDemandDebug,
            ];
        } catch (\Throwable $e) {
            $import->status = InvoiceImport::STATUS_FAILED;
            $import->error_message = $e->getMessage();
            $import->save();
            throw $e;
        }
    }

    /**
     * @return array{invoice: \App\Models\Invoice, import: InvoiceImport, skipped: list<array{label: string, qty: float}>}
     */
    public function importStorageCsv(
        ClientAccount $account,
        UploadedFile $file,
        string $dueDateYmd,
        ?string $invoiceNumber,
        ?User $actor
    ): array {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new \RuntimeException('Invalid upload.');
        }

        $storageFees = $account->feeItems()
            ->get()
            ->filter(static function ($f) {
                return strcasecmp((string) $f->fee_group, \App\Models\ClientAccountFee::GROUP_STORAGE) === 0;
            });

        $parser = new InvoiceStorageImportParser();
        $parsed = $parser->parseFile($path, $storageFees);
        $lines = $parsed['lines'];
        $skipped = $parsed['skipped'];

        if (count($lines) === 0) {
            throw new \RuntimeException(
                count($skipped) > 0
                    ? 'No storage fees matched CSV types. Check account fee catalog.'
                    : 'No storage rows found in CSV.'
            );
        }

        $originalFilename = $file->getClientOriginalName();
        $billingPeriod = self::billingPeriodFromFilename($originalFilename);

        $import = new InvoiceImport([
            'client_account_id' => $account->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'import_type' => InvoiceImport::TYPE_STORAGE_CSV,
            'original_filename' => $originalFilename,
            'status' => InvoiceImport::STATUS_PENDING,
        ]);
        $import->save();

        try {
            $invoice = DB::transaction(function () use ($account, $lines, $dueDateYmd, $invoiceNumber, $actor, $import, $skipped, $billingPeriod) {
                $header = [
                    'client_account_id' => $account->id,
                    'currency' => 'USD',
                    'due_at' => $dueDateYmd,
                ];
                if ($billingPeriod !== null) {
                    $header['billing_period_start'] = $billingPeriod['start'];
                    $header['billing_period_end'] = $billingPeriod['end'];
                }
                $inv = $this->invoices->createDraft($header, $lines, $actor, $invoiceNumber);
                $this->invoices->logHistory($inv, $actor, 'updated', $inv->status, $inv->status, [
                    'event_type' => InvoiceHistoryEventType::IMPORT,
                    'history_message' => 'Imported storage CSV ('.count($lines).' lines).',
                    'import_id' => $import->id,
                    'skipped_types' => $skipped,
                    'billing_period_start' => $billingPeriod['start'] ?? null,
                    'billing_period_end' => $billingPeriod['end'] ?? null,
                ]);

                return $inv;
            });

            $import->invoice_id = $invoice->id;
            $import->rows_processed = count($lines);
            $import->status = InvoiceImport::STATUS_COMPLETED;
            $import->result_summary = [
                'line_count' => count($lines),
                'skipped' => $skipped,
                'billing_period_start' => $billingPeriod['start'] ?? null,
                'billing_period_end' => $billingPeriod['end'] ?? null,
            ];
            $import->save();

            return [
                'invoice' => $invoice->fresh(['items', 'clientAccount']),
                'import' => $import,
                'skipped' => $skipped,
            ];
        } catch (\Throwable $e) {
            $import->status = InvoiceImport::STATUS_FAILED;
            $import->error_message = $e->getMessage();
            $import->save();
            throw $e;
        }
    }

    /**
     * @return array{start: string, end: string}|null
     */
    public static function billingPeriodFromFilename(?string $filename): ?array
    {
        $raw = trim((string) $filename);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/(?<!\d)(\d{4}-\d{2}-\d{2})--(\d{4}-\d{2}-\d{2})(?!\d)/', $raw, $m) !== 1) {
            return null;
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $m[1])->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $m[2])->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }

        if ($end->lt($start)) {
            return null;
        }

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   debug: array{
     *     catalog_rows: int,
     *     sku_candidate_rows: int,
     *     matched_sku_rows: int,
     *     unmatched_sku_rows: int,
     *     unmatched_sku_samples: list<string>
     *   }
     * }
     */
    private function aggregateOnDemandCatalogPickLines(ClientAccount $account, array $lines): array
    {
        $catalogRows = $account->onDemandProducts()->get();
        $debug = [
            'catalog_rows' => (int) $catalogRows->count(),
            'sku_candidate_rows' => 0,
            'matched_sku_rows' => 0,
            'unmatched_sku_rows' => 0,
            'unmatched_sku_samples' => [],
        ];
        $catalog = [];
        $catalogCompact = [];
        foreach ($catalogRows as $product) {
            $exactKey = $this->normalizeSkuKey((string) $product->sku);
            if ($exactKey !== null) {
                $catalog[$exactKey] = $product;
            }
            $compactKey = $this->normalizeSkuCompactKey((string) $product->sku);
            if ($compactKey !== null) {
                $catalogCompact[$compactKey] = $product;
            }
        }

        if ($catalog === [] && $catalogCompact === []) {
            foreach ($lines as $line) {
                if ($this->isReturnLine((array) $line)) {
                    continue;
                }
                $lineSkuRaw = $this->extractLineSkuForCatalog((array) $line);
                $skuKey = $this->normalizeSkuKey($lineSkuRaw);
                $compactKey = $this->normalizeSkuCompactKey($lineSkuRaw);
                if ($skuKey === null && $compactKey === null) {
                    continue;
                }
                $debug['sku_candidate_rows']++;
                $debug['unmatched_sku_rows']++;
                if (count($debug['unmatched_sku_samples']) < 10) {
                    $debug['unmatched_sku_samples'][] = $lineSkuRaw;
                }
            }
            return ['lines' => $lines, 'debug' => $debug];
        }

        $kept = $lines;
        $aggregates = [];

        foreach ($lines as $line) {
            if ($this->isReturnLine((array) $line)) {
                continue;
            }
            $lineSkuRaw = $this->extractLineSkuForCatalog((array) $line);
            $skuKey = $this->normalizeSkuKey($lineSkuRaw);
            $compactKey = $this->normalizeSkuCompactKey($lineSkuRaw);
            if ($skuKey !== null || $compactKey !== null) {
                $debug['sku_candidate_rows']++;
            }
            $product = $skuKey !== null ? ($catalog[$skuKey] ?? null) : null;
            if ($product === null && $compactKey !== null) {
                $product = $catalogCompact[$compactKey] ?? null;
            }
            $aggregateKey = $skuKey ?? $compactKey;

            if ($product === null || $aggregateKey === null) {
                if ($aggregateKey !== null) {
                    $debug['unmatched_sku_rows']++;
                    if (count($debug['unmatched_sku_samples']) < 10) {
                        $debug['unmatched_sku_samples'][] = $lineSkuRaw;
                    }
                }
                continue;
            }

            if (! isset($aggregates[$aggregateKey])) {
                $aggregates[$aggregateKey] = [
                    'product' => $product,
                    'quantity' => 0.0,
                    'order_numbers' => [],
                ];
            }

            $aggregates[$aggregateKey]['quantity'] += 1.0;
            $orderNumber = $this->extractLineOrderNumber((array) $line);
            if ($orderNumber !== null) {
                $aggregates[$aggregateKey]['order_numbers'][$orderNumber] = true;
            }
            $debug['matched_sku_rows']++;
        }

        if ($aggregates === []) {
            return ['lines' => $lines, 'debug' => $debug];
        }

        $aggregateLines = [];
        foreach ($aggregates as $skuKey => $aggregate) {
            /** @var ClientAccountOnDemandProduct $product */
            $product = $aggregate['product'];
            $quantity = (float) $aggregate['quantity'];
            if ($quantity <= 0) {
                continue;
            }
            $orderNumbers = array_keys((array) ($aggregate['order_numbers'] ?? []));
            sort($orderNumbers, SORT_STRING);
            $orderNumberValue = 'Multiple';
            if (count($orderNumbers) === 1) {
                $orderNumberValue = $orderNumbers[0];
            }

            $display = trim((string) $product->name).' ('.$product->sku.')';
            $aggregateLines[$skuKey] = [
                'category' => InvoiceLineCategory::ON_DEMAND,
                'subtype' => null,
                'group_key' => 'on_demand:'.Str::slug($product->sku),
                'description' => $display,
                'display_name' => $display,
                'sku' => $product->sku,
                'service_code' => 'on_demand_catalog',
                'quantity' => $quantity,
                'unit_price_cents' => (int) $product->price_cents,
                'line_total_cents' => (int) round($quantity * (int) $product->price_cents),
                'metadata' => [
                    'on_demand_product_id' => $product->id,
                    'on_demand_category' => $product->category,
                    'source' => 'billing_import_sku_catalog',
                    'order_number' => $orderNumberValue,
                    'order_numbers' => $orderNumbers,
                ],
            ];
        }

        if ($aggregateLines === []) {
            return ['lines' => $kept, 'debug' => $debug];
        }

        $result = array_values(array_merge($kept, array_values($aggregateLines)));
        return ['lines' => $result, 'debug' => $debug];
    }

    /**
     * @param array<string, mixed> $line
     */
    private function extractLineOrderNumber(array $line): ?string
    {
        $metadata = isset($line['metadata']) && is_array($line['metadata']) ? $line['metadata'] : [];
        $raw = $metadata['order_number'] ?? $line['order_number'] ?? null;
        $value = trim((string) $raw);

        return $value !== '' ? $value : null;
    }

    /**
     * Normalized key for catalog lookup (matches `ClientAccountOnDemandProduct` keying).
     */
    private function normalizeSkuKey(string $raw): ?string
    {
        $sku = trim((string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $raw), " \t\n\r\0\x0B.\"'");
        $sku = str_replace(["\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2212}"], '-', $sku);
        $sku = mb_strtolower($sku);

        return $sku !== '' ? $sku : null;
    }

    private function normalizeSkuCompactKey(string $raw): ?string
    {
        $sku = $this->normalizeSkuKey($raw);
        if ($sku === null) {
            return null;
        }
        $compact = (string) preg_replace('/[^a-z0-9]/', '', $sku);

        return $compact !== '' ? $compact : null;
    }

    /**
     * Pull SKU from parsed line, falling back to description patterns.
     *
     * @param array<string, mixed> $line
     */
    private function extractLineSkuForCatalog(array $line): string
    {
        $sku = trim((string) ($line['sku'] ?? ''));
        if ($sku !== '') {
            return $this->extractCatalogSkuCandidate($sku);
        }

        $text = trim((string) ($line['description'] ?? ''));
        if ($text !== '' && preg_match('/\bof\s+sku\s+([A-Z0-9._\-]+)/i', $text, $m) === 1) {
            return (string) $m[1];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $line
     */
    private function isReturnLine(array $line): bool
    {
        $category = trim((string) ($line['category'] ?? ''));
        if ($category !== '' && strcasecmp($category, InvoiceLineCategory::RETURNS) === 0) {
            return true;
        }

        $type = trim((string) ($line['type'] ?? ''));
        if ($type !== '' && strcasecmp($type, InvoiceLineCategory::RETURNS) === 0) {
            return true;
        }

        return false;
    }

    private function extractCatalogSkuCandidate(string $rawSku): string
    {
        $value = trim($rawSku);
        if ($value === '') {
            return '';
        }

        // Some exports prefix product SKU with a unit count, e.g. "1 aura-essence...".
        if (preg_match('/^\d+(?:\.\d+)?\s+(.+)$/', $value, $m) === 1) {
            $value = trim((string) $m[1]);
        }

        if (preg_match('/\b([A-Z0-9][A-Z0-9._\-]*-[A-Z0-9._\-]+)\b/i', $value, $m) === 1) {
            return trim((string) $m[1], " \t\n\r\0\x0B.\"'");
        }

        return trim($value, " \t\n\r\0\x0B.\"'");
    }
}
