<?php

namespace App\Services;

/**
 * UPS duties & taxes export: one combined line per order from Reference No.1 + Billed Charge.
 *
 * @return array{lines: list<array<string, mixed>>, rows_processed: int, skipped: int}
 */
final class InvoiceUpsDutiesTaxesImportParser
{
    public const CATEGORY = 'duties & taxes';

    public const DISPLAY_NAME = 'International Duties & Taxes (UPS)';

    public const GROUP_KEY = 'duties_taxes:international-duties-taxes-ups';

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

            $referenceIdx = $this->findColumn($headerRow, [
                'reference no 1',
                'reference no.1',
                'reference #1',
            ]);
            $chargeIdx = $this->findColumn($headerRow, ['billed charge', 'billed amount']);

            if ($referenceIdx === null) {
                throw new \RuntimeException('CSV must include a Reference No.1 column.');
            }
            if ($chargeIdx === null) {
                throw new \RuntimeException('CSV must include a Billed Charge column.');
            }

            $lines = [];
            $rowsProcessed = 0;
            $skipped = 0;

            while (($row = fgetcsv($fh)) !== false) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $orderNumber = isset($row[$referenceIdx]) ? trim((string) $row[$referenceIdx]) : '';
                if ($orderNumber === '') {
                    $skipped++;

                    continue;
                }

                $chargeCents = $this->parseDollarCents($row[$chargeIdx] ?? null);
                if ($chargeCents === 0) {
                    $skipped++;

                    continue;
                }

                $rowsProcessed++;
                $lines[] = $this->buildLine($chargeCents, $orderNumber);
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
    private function buildLine(int $amountCents, string $orderNumber): array
    {
        return [
            'category' => self::CATEGORY,
            'subtype' => null,
            'group_key' => self::GROUP_KEY,
            'description' => self::DISPLAY_NAME,
            'display_name' => self::DISPLAY_NAME,
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
