<?php

namespace App\Console\Commands;

use App\Models\InvoiceImport;
use App\Services\InvoiceImportService;
use Illuminate\Console\Command;

class BackfillInvoiceImportBillingPeriodsCommand extends Command
{
    protected $signature = 'billing:backfill-import-periods
                            {--dry-run : Show changes without saving}
                            {--force : Overwrite existing billing periods}';

    protected $description = 'Backfill invoice billing periods from invoice import filenames.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $checked = 0;
        $updated = 0;
        $skipped = 0;

        InvoiceImport::query()
            ->with('invoice:id,invoice_number,billing_period_start,billing_period_end')
            ->whereNotNull('invoice_id')
            ->whereNotNull('original_filename')
            ->orderBy('id')
            ->chunkById(200, function ($imports) use ($dryRun, $force, &$checked, &$updated, &$skipped) {
                foreach ($imports as $import) {
                    $checked++;
                    $invoice = $import->invoice;
                    $period = InvoiceImportService::billingPeriodFromFilename($import->original_filename);
                    if ($invoice === null || $period === null) {
                        $skipped++;
                        continue;
                    }

                    $hasPeriod = $invoice->billing_period_start !== null || $invoice->billing_period_end !== null;
                    if ($hasPeriod && ! $force) {
                        $skipped++;
                        continue;
                    }

                    $this->line(sprintf(
                        '%s invoice %s: %s - %s',
                        $dryRun ? 'Would update' : 'Updating',
                        $invoice->invoice_number,
                        $period['start'],
                        $period['end']
                    ));

                    if (! $dryRun) {
                        $invoice->billing_period_start = $period['start'];
                        $invoice->billing_period_end = $period['end'];
                        $invoice->save();
                    }

                    $updated++;
                }
            });

        $this->info(sprintf(
            '%s. Checked %d import rows, %d %s, %d skipped.',
            $dryRun ? 'Dry run complete' : 'Backfill complete',
            $checked,
            $updated,
            $dryRun ? 'would be updated' : 'updated',
            $skipped
        ));

        return self::SUCCESS;
    }
}
