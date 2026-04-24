<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\InvoiceImport;
use App\Models\User;
use App\Support\Billing\InvoiceHistoryEventType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

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
        if (count($lines) === 0) {
            throw new \RuntimeException('No billable rows found in CSV.');
        }

        $import = new InvoiceImport([
            'client_account_id' => $account->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'import_type' => InvoiceImport::TYPE_FULL_CHARGE_CSV,
            'original_filename' => $file->getClientOriginalName(),
            'status' => InvoiceImport::STATUS_PENDING,
        ]);
        $import->save();

        try {
            $invoice = DB::transaction(function () use ($account, $lines, $dueDateYmd, $invoiceNumber, $actor, $import) {
                $header = [
                    'client_account_id' => $account->id,
                    'currency' => 'USD',
                    'due_at' => $dueDateYmd,
                ];
                $inv = $this->invoices->createDraft($header, $lines, $actor, $invoiceNumber);
                $this->invoices->logHistory($inv, $actor, 'updated', $inv->status, $inv->status, [
                    'event_type' => InvoiceHistoryEventType::IMPORT,
                    'history_message' => 'Imported charge CSV ('.count($lines).' lines).',
                    'import_id' => $import->id,
                ]);

                return $inv;
            });

            $import->invoice_id = $invoice->id;
            $import->rows_processed = count($lines);
            $import->status = InvoiceImport::STATUS_COMPLETED;
            $import->result_summary = ['line_count' => count($lines)];
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

        $import = new InvoiceImport([
            'client_account_id' => $account->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'import_type' => InvoiceImport::TYPE_STORAGE_CSV,
            'original_filename' => $file->getClientOriginalName(),
            'status' => InvoiceImport::STATUS_PENDING,
        ]);
        $import->save();

        try {
            $invoice = DB::transaction(function () use ($account, $lines, $dueDateYmd, $invoiceNumber, $actor, $import, $skipped) {
                $header = [
                    'client_account_id' => $account->id,
                    'currency' => 'USD',
                    'due_at' => $dueDateYmd,
                ];
                $inv = $this->invoices->createDraft($header, $lines, $actor, $invoiceNumber);
                $this->invoices->logHistory($inv, $actor, 'updated', $inv->status, $inv->status, [
                    'event_type' => InvoiceHistoryEventType::IMPORT,
                    'history_message' => 'Imported storage CSV ('.count($lines).' lines).',
                    'import_id' => $import->id,
                    'skipped_types' => $skipped,
                ]);

                return $inv;
            });

            $import->invoice_id = $invoice->id;
            $import->rows_processed = count($lines);
            $import->status = InvoiceImport::STATUS_COMPLETED;
            $import->result_summary = [
                'line_count' => count($lines),
                'skipped' => $skipped,
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
}
