<?php

namespace App\Services;

use App\Models\LocationLabel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocationLabelService
{
    /**
     * @param  array{q?:string, page?:int, per_page?:int, sort_by?:string, sort_dir?:string}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $sortBy = (string) ($filters['sort_by'] ?? 'location');
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 500);
        $page = max((int) ($filters['page'] ?? 1), 1);

        $sortColumn = $sortBy === 'display_name' ? 'type' : ($sortBy === 'barcode' ? 'location' : $sortBy);
        if (! in_array($sortColumn, ['location', 'type', 'created_at', 'updated_at', 'id'], true)) {
            $sortColumn = 'location';
        }

        $query = LocationLabel::query()->active();

        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('location', 'like', '%'.$q.'%')
                    ->orWhere('type', 'like', '%'.$q.'%');
            });
        }

        $query->orderBy($sortColumn, $sortDir);
        if ($sortColumn !== 'id') {
            $query->orderBy('id');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array{barcode:string, display_name:string}  $data
     */
    public function create(array $data): LocationLabel
    {
        $barcode = $this->normalizeRequired($data['barcode'] ?? '', 'barcode');
        $displayName = $this->normalizeRequired($data['display_name'] ?? '', 'display_name');

        $this->assertBarcodeAvailable($barcode);

        $row = new LocationLabel([
            'location' => $barcode,
            'type' => $displayName,
            'label' => null,
            'is_deleted' => false,
        ]);
        $row->save();

        return $row->fresh();
    }

    /**
     * @param  array{barcode:string, display_name:string}  $data
     */
    public function update(LocationLabel $label, array $data): LocationLabel
    {
        if ($label->is_deleted) {
            throw ValidationException::withMessages([
                'id' => ['This location label was deleted.'],
            ]);
        }

        $barcode = $this->normalizeRequired($data['barcode'] ?? '', 'barcode');
        $displayName = $this->normalizeRequired($data['display_name'] ?? '', 'display_name');

        $this->assertBarcodeAvailable($barcode, (int) $label->id);

        $label->location = $barcode;
        $label->type = $displayName;
        $label->save();

        return $label->fresh();
    }

    public function softDelete(LocationLabel $label): void
    {
        $label->is_deleted = true;
        $label->save();
    }

    /**
     * @param  list<int>  $ids
     */
    public function softDeleteMany(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }

        return LocationLabel::query()
            ->whereIn('id', $ids)
            ->active()
            ->update(['is_deleted' => true]);
    }

    /**
     * @return array{imported:int, skipped:int, errors:list<string>}
     */
    public function importCsv(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false || ! is_readable($path)) {
            throw new RuntimeException('Could not read uploaded CSV file.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open uploaded CSV file.');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 0;
        $isFirst = true;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNum++;
                if ($row === [null] || $row === false) {
                    continue;
                }

                $barcode = isset($row[0]) ? trim((string) $row[0]) : '';
                $displayName = isset($row[1]) ? trim((string) $row[1]) : '';

                if ($isFirst) {
                    $isFirst = false;
                    if ($this->looksLikeHeader($barcode, $displayName)) {
                        continue;
                    }
                }

                if ($barcode === '' && $displayName === '') {
                    continue;
                }

                if ($barcode === '' || $displayName === '') {
                    $errors[] = 'Row '.$rowNum.': Barcode and Display Name are required.';
                    $skipped++;
                    continue;
                }

                if (mb_strlen($barcode) > 255 || mb_strlen($displayName) > 255) {
                    $errors[] = 'Row '.$rowNum.': values must be 255 characters or fewer.';
                    $skipped++;
                    continue;
                }

                $exists = LocationLabel::query()
                    ->active()
                    ->where('location', $barcode)
                    ->exists();
                if ($exists) {
                    $skipped++;
                    continue;
                }

                LocationLabel::query()->create([
                    'location' => $barcode,
                    'type' => $displayName,
                    'label' => null,
                    'is_deleted' => false,
                ]);
                $imported++;
            }
        } finally {
            fclose($handle);
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 25),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<LocationLabel>
     */
    public function findActiveByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }

        return LocationLabel::query()
            ->active()
            ->whereIn('id', $ids)
            ->orderBy('location')
            ->get()
            ->all();
    }

    private function assertBarcodeAvailable(string $barcode, ?int $ignoreId = null): void
    {
        $query = LocationLabel::query()->active()->where('location', $barcode);
        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                'barcode' => ['A location with this barcode already exists.'],
            ]);
        }
    }

    private function normalizeRequired(string $value, string $field): string
    {
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages([
                $field => [ucfirst(str_replace('_', ' ', $field)).' is required.'],
            ]);
        }
        if (mb_strlen($value) > 255) {
            throw ValidationException::withMessages([
                $field => [ucfirst(str_replace('_', ' ', $field)).' may not be greater than 255 characters.'],
            ]);
        }

        return $value;
    }

    private function looksLikeHeader(string $barcode, string $displayName): bool
    {
        $a = strtolower($barcode);
        $b = strtolower($displayName);

        return in_array($a, ['barcode', 'location', 'locations'], true)
            && in_array($b, ['display name', 'display_name', 'type', 'name'], true);
    }
}
