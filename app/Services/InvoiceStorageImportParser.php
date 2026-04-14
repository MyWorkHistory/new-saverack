<?php

namespace App\Services;

use App\Models\ClientAccountFee;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * ItemLocations-style export: aggregate rows by Type column, price from account storage fees.
 *
 * @return array{lines: list<array<string, mixed>>, skipped: list<array{label: string, qty: float}>}
 */
final class InvoiceStorageImportParser
{
    /**
     * @return array{lines: list<array<string, mixed>>, skipped: list<array{label: string, qty: float}>}
     */
    public function parseFile(string $path, Collection $storageFees): array
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
            $typeIdx = $this->findTypeColumn($headerRow);
            if ($typeIdx === null) {
                throw new \RuntimeException('CSV must include a Type column.');
            }
            $counts = [];
            while (($row = fgetcsv($fh)) !== false) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                $type = isset($row[$typeIdx]) ? trim((string) $row[$typeIdx]) : '';
                if ($type === '') {
                    continue;
                }
                $key = $this->normalizeTypeKey($type);
                if (! isset($counts[$key])) {
                    $counts[$key] = ['label' => $type, 'qty' => 0];
                }
                $counts[$key]['qty'] += 1;
            }

            $lines = [];
            $skipped = [];
            foreach ($counts as $bucket) {
                $label = $bucket['label'];
                $qty = (float) $bucket['qty'];
                $rateCents = $this->resolveStorageRateCents($storageFees, $label);
                if ($rateCents === null) {
                    $skipped[] = ['label' => $label, 'qty' => $qty];

                    continue;
                }
                $lineTotal = (int) round($qty * $rateCents);
                $gk = 'storage:'.Str::slug($label);
                $lines[] = [
                    'category' => InvoiceLineCategory::STORAGE,
                    'subtype' => null,
                    'group_key' => $gk,
                    'description' => $label,
                    'display_name' => $label,
                    'quantity' => $qty,
                    'unit_price_cents' => $rateCents,
                    'line_total_cents' => $lineTotal,
                ];
            }

            return ['lines' => $lines, 'skipped' => $skipped];
        } finally {
            fclose($fh);
        }
    }

    /**
     * @param  list<string|null>  $headerRow
     */
    private function findTypeColumn(array $headerRow): ?int
    {
        foreach ($headerRow as $i => $raw) {
            $k = $this->normalizeHeader((string) $raw);
            if ($k === 'type') {
                return (int) $i;
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

    private function normalizeTypeKey(string $type): string
    {
        return preg_replace('/\s+/', ' ', trim($type)) ?? trim($type);
    }

    /**
     * @param  Collection<int, ClientAccountFee>  $storageFees
     */
    private function resolveStorageRateCents(Collection $storageFees, string $label): ?int
    {
        $labelTrim = trim($label);
        $candidates = array_unique(array_filter([
            $labelTrim,
            $this->aliasLabel($labelTrim),
        ]));
        foreach ($candidates as $cand) {
            $fee = $storageFees->first(function (ClientAccountFee $f) use ($cand) {
                if (strcasecmp((string) $f->fee_group, ClientAccountFee::GROUP_STORAGE) !== 0) {
                    return false;
                }

                return strcasecmp(trim((string) $f->label), $cand) === 0;
            });
            if ($fee !== null) {
                return (int) round(((float) $fee->amount) * 100);
            }
        }
        foreach ($candidates as $cand) {
            $fee = $storageFees->first(function (ClientAccountFee $f) use ($cand) {
                return strcasecmp(trim((string) $f->label), $cand) === 0;
            });
            if ($fee !== null) {
                return (int) round(((float) $fee->amount) * 100);
            }
        }

        return null;
    }

    private function aliasLabel(string $label): string
    {
        if (preg_match('/^bin\s*\(/i', $label)) {
            return 'Bin Storage '.preg_replace('/^bin\s*/i', '', $label);
        }
        if (preg_match('/^shelf\s*\(/i', $label)) {
            return 'Shelf Storage '.preg_replace('/^shelf\s*/i', '', $label);
        }
        if (preg_match('/^pallet\s*\(/i', $label)) {
            return 'Pallet Storage '.preg_replace('/^pallet\s*/i', '', $label);
        }

        return $label;
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
