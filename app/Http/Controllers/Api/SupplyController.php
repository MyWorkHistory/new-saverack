<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Supply::class);

        $q = trim((string) $request->query('q', ''));
        $type = trim((string) $request->query('type', ''));

        // Team catalog — never filter by user; all rows are shared across staff.
        $query = Supply::query()->orderBy('sort_order')->orderBy('name');
        if ($type !== '' && in_array($type, Supply::TYPES, true)) {
            $query->where('type', $type);
        }
        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like, $q) {
                $builder->where('name', 'like', $like)
                    ->orWhere('type', 'like', $like);
                foreach (Supply::TYPE_LABELS as $key => $label) {
                    if (stripos($label, $q) !== false) {
                        $builder->orWhere('type', $key);
                    }
                }
            });
        }

        $rows = $query->get()->map(fn (Supply $s) => $this->serialize($s))->values()->all();

        return response()->json([
            'data' => $rows,
            'types' => Supply::TYPES,
            'type_labels' => Supply::TYPE_LABELS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Supply::class);

        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(Supply::TYPES)],
            'name' => ['required', 'string', 'max:255'],
            'link' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        $supply = Supply::query()->create([
            'type' => $validated['type'],
            'name' => trim($validated['name']),
            'link' => isset($validated['link']) ? trim((string) $validated['link']) ?: null : null,
            'sort_order' => (int) ($validated['sort_order'] ?? 0),
        ]);

        return response()->json($this->serialize($supply), 201);
    }

    public function show(Supply $supply): JsonResponse
    {
        $this->authorize('view', $supply);

        return response()->json($this->serialize($supply));
    }

    public function update(Request $request, Supply $supply): JsonResponse
    {
        $this->authorize('update', $supply);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', Rule::in(Supply::TYPES)],
            'name' => ['sometimes', 'string', 'max:255'],
            'link' => ['nullable', 'string', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
        ]);

        if (array_key_exists('type', $validated)) {
            $supply->type = $validated['type'];
        }
        if (array_key_exists('name', $validated)) {
            $supply->name = trim($validated['name']);
        }
        if (array_key_exists('link', $validated)) {
            $supply->link = trim((string) $validated['link']) !== '' ? trim((string) $validated['link']) : null;
        }
        if (array_key_exists('sort_order', $validated)) {
            $supply->sort_order = (int) ($validated['sort_order'] ?? 0);
        }
        $supply->save();

        return response()->json($this->serialize($supply));
    }

    public function destroy(Supply $supply): JsonResponse
    {
        $this->authorize('delete', $supply);
        $supply->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Supply $supply): array
    {
        return [
            'id' => $supply->id,
            'type' => $supply->type,
            'type_label' => Supply::typeLabel($supply->type),
            'name' => $supply->name,
            'link' => $supply->link,
            'sort_order' => (int) $supply->sort_order,
            'created_at' => optional($supply->created_at)->toIso8601String(),
            'updated_at' => optional($supply->updated_at)->toIso8601String(),
        ];
    }
}
