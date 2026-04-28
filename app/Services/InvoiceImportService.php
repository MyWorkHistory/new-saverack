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
     * @return array{invoice: \App\Models\Invoice, import: InvoiceImport}
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
        $lines = $this->aggregateOnDemandCatalogPickLines($account, $lines);
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
            ], static function ($value) {
                return $value !== null;
            });
            $import->save();

            return ['invoice' => $invoice->fresh(['items', 'clientAccount']), 'import' => $import];
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
     * @return list<array<string, mixed>>
     */
    private function aggregateOnDemandCatalogPickLines(ClientAccount $account, array $lines): array
    {
        $catalog = $account->onDemandProducts()
            ->get()
            ->keyBy(static function (ClientAccountOnDemandProduct $product): string {
                return strtoupper(trim((string) $product->sku));
            });

        if ($catalog->isEmpty()) {
            return $lines;
        }

        $kept = [];
        $aggregates = [];
        $insertedAggregateKey = [];
        $pendingCatalogPickLines = [];

        foreach ($lines as $line) {
            $sku = $this->extractOnDemandPickSku($line);
            $product = $sku !== null ? $catalog->get($sku) : null;

            if ($product === null) {
                $kept[] = $line;
                continue;
            }

            if (! isset($aggregates[$sku])) {
                $aggregates[$sku] = [
                    'product' => $product,
                    'quantity' => 0.0,
                ];
            }

            $aggregates[$sku]['quantity'] += $this->onDemandPickQuantity($line);
            if (! isset($insertedAggregateKey[$sku])) {
                $insertedAggregateKey[$sku] = count($kept);
            }
            $pendingCatalogPickLines[$sku][] = $line;
        }

        if ($aggregates === []) {
            return $lines;
        }

        $aggregateLines = [];
        foreach ($aggregates as $sku => $aggregate) {
            /** @var ClientAccountOnDemandProduct $product */
            $product = $aggregate['product'];
            $quantity = (float) $aggregate['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            $display = trim((string) $product->name).' ('.$product->sku.')';
            $aggregateLines[$sku] = [
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
                ],
            ];
        }

        if ($aggregateLines === []) {
            $result = $kept;
            foreach ($pendingCatalogPickLines as $skuLines) {
                foreach ($skuLines as $sourceLine) {
                    $result[] = $sourceLine;
                }
            }
            return $result;
        }

        $result = [];
        foreach ($kept as $index => $line) {
            foreach ($insertedAggregateKey as $sku => $insertAt) {
                if ($insertAt === $index && isset($aggregateLines[$sku])) {
                    $result[] = $aggregateLines[$sku];
                }
            }
            $result[] = $line;
        }

        foreach ($insertedAggregateKey as $sku => $insertAt) {
            if ($insertAt >= count($kept) && isset($aggregateLines[$sku])) {
                $result[] = $aggregateLines[$sku];
            }
        }

        foreach ($pendingCatalogPickLines as $sku => $skuLines) {
            if (isset($aggregateLines[$sku])) {
                continue;
            }
            foreach ($skuLines as $sourceLine) {
                $result[] = $sourceLine;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function extractOnDemandPickSku(array $line): ?string
    {
        if (($line['category'] ?? null) !== InvoiceLineCategory::FULFILLMENT) {
            return null;
        }

        $subtype = strtolower(trim((string) ($line['subtype'] ?? '')));
        $serviceCode = strtolower(trim((string) ($line['service_code'] ?? '')));
        $text = strtolower(trim(implode(' ', array_filter([
            (string) ($line['display_name'] ?? ''),
            (string) ($line['description'] ?? ''),
            (string) ($line['service_code'] ?? ''),
        ]))));

        $isPickCharge = in_array($subtype, ['first', 'additional'], true)
            || str_contains($serviceCode, 'first_pick_charge')
            || str_contains($serviceCode, 'pick_remainder_charge')
            || preg_match('/\b(first pick|additional item\(s\) picked|pick remainder)\b/i', $text) === 1;
        if (! $isPickCharge) {
            return null;
        }

        $sku = trim((string) ($line['sku'] ?? ''));
        if ($sku === '' && preg_match('/\bof\s+sku\s+([A-Z0-9._\-]+)\b/i', (string) ($line['description'] ?? ''), $m) === 1) {
            $sku = $m[1];
        }
        if ($sku === '' && preg_match('/\bsku\s+([A-Z0-9._\-]+)\b/i', (string) ($line['display_name'] ?? '').' '.(string) ($line['service_code'] ?? ''), $m) === 1) {
            $sku = $m[1];
        }

        $sku = strtoupper(trim($sku, " \t\n\r\0\x0B."));

        return $sku !== '' ? $sku : null;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function onDemandPickQuantity(array $line): float
    {
        $description = (string) ($line['description'] ?? '');
        if (preg_match('/\b(\d+(?:\.\d+)?)\s+additional\s+item/i', $description, $m) === 1) {
            return max(0.0, (float) $m[1]);
        }

        $quantity = (float) ($line['quantity'] ?? 0);

        return $quantity > 0 ? $quantity : 1.0;
    }
}
