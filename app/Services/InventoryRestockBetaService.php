<?php

namespace App\Services;

use App\Models\InventoryRestockBetaSnapshot;
use App\Models\User;
use App\Support\Inventory\RestockBetaCsvParser;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class InventoryRestockBetaService
{
    /** @var RestockBetaCsvParser */
    private $parser;

    public function __construct(RestockBetaCsvParser $parser)
    {
        $this->parser = $parser;
    }

    /**
     * @return array<string, mixed>
     */
    public function importCsv(UploadedFile $file, ?User $actor): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new RuntimeException('Could not read uploaded file.');
        }

        $rows = $this->parser->parseFile($path);
        $uploadedAt = now();

        InventoryRestockBetaSnapshot::query()->delete();

        $snapshot = InventoryRestockBetaSnapshot::query()->create([
            'uploaded_by_user_id' => $actor !== null ? $actor->id : null,
            'original_filename' => $file->getClientOriginalName(),
            'row_count' => count($rows),
            'rows' => $rows,
            'uploaded_at' => $uploadedAt,
        ]);

        return $this->toArray($snapshot);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestSnapshot(): ?array
    {
        $snapshot = InventoryRestockBetaSnapshot::query()
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id')
            ->first();

        if ($snapshot === null) {
            return null;
        }

        return $this->toArray($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(InventoryRestockBetaSnapshot $snapshot): array
    {
        $uploadedAt = $snapshot->uploaded_at;

        return [
            'original_filename' => $snapshot->original_filename,
            'row_count' => (int) $snapshot->row_count,
            'uploaded_at' => $uploadedAt !== null ? $uploadedAt->toIso8601String() : null,
            'rows' => is_array($snapshot->rows) ? $snapshot->rows : [],
        ];
    }
}
