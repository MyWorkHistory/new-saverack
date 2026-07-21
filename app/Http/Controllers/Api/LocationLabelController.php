<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocationLabel;
use App\Services\LocationLabelPrintService;
use App\Services\LocationLabelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LocationLabelController extends Controller
{
    /** @var LocationLabelService */
    protected $labels;

    /** @var LocationLabelPrintService */
    protected $printer;

    public function __construct(LocationLabelService $labels, LocationLabelPrintService $printer)
    {
        $this->labels = $labels;
        $this->printer = $printer;
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('inventory_location_labels.view');

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:500'],
            'sort_by' => ['nullable', 'string', Rule::in(['barcode', 'display_name', 'location', 'type', 'created_at', 'updated_at', 'id'])],
            'sort_dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $paginator = $this->labels->paginate($validated);

        return response()->json([
            'locations' => collect($paginator->items())->map(function (LocationLabel $row) {
                return $row->toApiArray();
            })->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('inventory_location_labels.create');

        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $row = $this->labels->create($validated);
        } catch (ValidationException $e) {
            throw $e;
        }

        return response()->json(['location' => $row->toApiArray()], 201);
    }

    public function update(Request $request, LocationLabel $location_label): JsonResponse
    {
        Gate::authorize('inventory_location_labels.update');

        $validated = $request->validate([
            'barcode' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
        ]);

        $row = $this->labels->update($location_label, $validated);

        return response()->json(['location' => $row->toApiArray()]);
    }

    public function destroy(LocationLabel $location_label): JsonResponse
    {
        Gate::authorize('inventory_location_labels.delete');

        $this->labels->softDelete($location_label);

        return response()->json(['deleted' => true]);
    }

    public function bulkDestroy(Request $request): JsonResponse
    {
        Gate::authorize('inventory_location_labels.delete');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $count = $this->labels->softDeleteMany($validated['ids']);

        return response()->json(['deleted' => true, 'count' => $count]);
    }

    public function import(Request $request): JsonResponse
    {
        Gate::authorize('inventory_location_labels.create');

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        try {
            $summary = $this->labels->importCsv($validated['file']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not import location CSV.',
            ], 500);
        }

        return response()->json($summary, 201);
    }

    /**
     * @return Response|JsonResponse
     */
    public function print(Request $request)
    {
        Gate::authorize('inventory_location_labels.view');

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'label_type' => ['nullable', 'string', Rule::in(['large', 'small', 'normal'])],
        ]);

        $labelType = (string) ($validated['label_type'] ?? 'large');
        if ($labelType === 'normal') {
            $labelType = 'large';
        }

        $rows = $this->labels->findActiveByIds($validated['ids']);
        if ($rows === []) {
            return response()->json(['message' => 'No location labels found for printing.'], 404);
        }

        try {
            return $this->printer->stream($rows, $labelType);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'Could not generate location labels.',
            ], 502);
        }
    }
}
