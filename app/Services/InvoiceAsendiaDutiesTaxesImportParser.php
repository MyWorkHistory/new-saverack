<?php

namespace App\Services;

/**
 * Asendia duties & taxes export: one line per order per non-zero Duty/Tax amount.
 *
 * @return array{lines: list<array<string, mixed>>, rows_processed: int, skipped: int}
 */
final class InvoiceAsendiaDutiesTaxesImportParser
{
    public const CATEGORY = 'duties & taxes';

    public const DISPLAY_DUTIES = 'International Duties (Asendia)';

    public const DISPLAY_TAXES = 'International Taxes (Asendia)';

    public const GROUP_KEY_DUTIES = 'duties_taxes:international-duties-asendia';

    public const GROUP_KEY_TAXES = 'duties_taxes:international-taxes-asendia';

    /**
     * @return array{lines: list<array<string, mixed>>, rows_processed: int, skipped: int}
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

            $orderIdx = $this->findColumn($headerRow, ['order number', 'order #', 'order#']);
            $dutyIdx = $this->findColumn($headerRow, ['duty']);
            $taxIdx = $this->findColumn($headerRow, ['tax']);
            $productIdx = $this->findColumn($headerRow, ['product']);

            if ($orderIdx === null) {
                throw new \RuntimeException('CSV must include an Order Number column.');
            }
            if ($dutyIdx === null) {
                throw new \RuntimeException('CSV must include a Duty column.');
            }
            if ($taxIdx === null) {
                throw new \RuntimeException('CSV must include a Tax column.');
            }

            $lines = [];
            $rowsProcessed = 0;
            $skipped = 0;

            while (($row = fgetcsv($fh)) !== false) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                if ($productIdx !== null) {
                    $product = isset($row[$productIdx]) ? trim((string) $row[$productIdx]) : '';
                    if ($product !== '' && ! $this->isDutiesTaxesProduct($product)) {
                        $skipped++;

                        continue;
                    }
                }

                $orderNumber = isset($row[$orderIdx]) ? trim((string) $row[$orderIdx]) : '';
                if ($orderNumber === '') {
                    $skipped++;

                    continue;
                }

                $dutyCents = $this->parseDollarCents($row[$dutyIdx] ?? null);
                $taxCents = $this->parseDollarCents($row[$taxIdx] ?? null);

                if ($dutyCents === 0 && $taxCents === 0) {
                    $skipped++;

                    continue;
                }

                $rowsProcessed++;

                if ($dutyCents !== 0) {
                    $lines[] = $this->buildLine(
                        self::DISPLAY_DUTIES,
                        self::GROUP_KEY_DUTIES,
                        $dutyCents,
                        $orderNumber
                    );
                }
                if ($taxCents !== 0) {
                    $lines[] = $this->buildLine(
                        self::DISPLAY_TAXES,
                        self::GROUP_KEY_TAXES,
                        $taxCents,
                        $orderNumber
                    );
                }
            }

            if (count($lines) === 0) {
                throw new \RuntimeException('No billable duties or taxes rows found in CSV.');
            }

            return [
                'lines' => $lines,
                'rows_processed' => $rowsProcessed,
                'skipped' => $skipped,
            ];
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLine(string $displayName, string $groupKey, int $amountCents, string $orderNumber): array
    {
        return [
            'category' => self::CATEGORY,
            'subtype' => null,
            'group_key' => $groupKey,
            'description' => $displayName,
            'display_name' => $displayName,
            'quantity' => 1.0,
            'unit_price_cents' => $amountCents,
            'line_total_cents' => $amountCents,
            'metadata' => ['order_number' => $orderNumber],
        ];
    }

    /**
     * @param  list<string|null>  $headerRow
     * @param  list<string>  $candidates
     */
    private function findColumn(array $headerRow, array $candidates): ?int
    {
        foreach ($headerRow as $i => $raw) {
            $normalized = $this->normalizeHeader((string) $raw);
            foreach ($candidates as $candidate) {
                if ($normalized === $candidate) {
                    return (int) $i;
                }
            }
        }

        return null;
    }

    private function normalizeHeader(string $h): string
    {
        $h = strtolower(trim($h));
        if ($h !== '' && strpos($h, "\xEF\xBB\xBF") === 0) {
            $h = substr($h, 3);
        }
        $h = preg_replace('/\s+/', ' ', $h) ?? $h;

        return trim($h);
    }

    private function isDutiesTaxesProduct(string $product): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $product) ?? $product));
        $normalized = str_replace('&', ' and ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return in_array($normalized, [
            'duty and taxes',
            'duties and taxes',
            'duty and tax',
            'duties and tax',
        ], true);
    }

    /**
     * @param  mixed  $value
     */
    private function parseDollarCents($value): int
    {
        if ($value === null) {
            return 0;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return 0;
        }
        $raw = str_replace(['$', ','], '', $raw);
        if (! is_numeric($raw)) {
            return 0;
        }

        return (int) round(((float) $raw) * 100);
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
