<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PutAwayReceivingSnapshot;
use App\Services\PutAwayInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class PutAwayController extends Controller
{
    public function index(Request $request, PutAwayInventoryService $putAway): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
            'query' => ['nullable', 'string', 'max:255'],
            'first' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'after' => ['nullable', 'string', 'max:500'],
            'receiving_only' => ['sometimes', 'boolean'],
            'refresh' => ['sometimes', 'boolean'],
        ]);

        $clientAccountId = isset($validated['client_account_id'])
            ? (int) $validated['client_account_id']
            : null;
        $first = (int) ($validated['first'] ?? PutAwayInventoryService::LIST_PAGE_SIZE);
        $after = isset($validated['after']) ? (string) $validated['after'] : null;
        $query = isset($validated['query']) ? (string) $validated['query'] : null;
        $receivingOnly = filter_var($validated['receiving_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $refresh = filter_var($validated['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            return response()->json($putAway->listReceiving(
                $clientAccountId,
                $query,
                $first,
                $after,
                $refresh,
                $receivingOnly
            ));
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load put away list.',
            ], 500);
        }
    }

    public function show(Request $request, string $sku, PutAwayInventoryService $putAway): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'refresh' => ['sometimes', 'boolean'],
        ]);

        $refresh = filter_var($validated['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $row = $putAway->rowForSku((int) $validated['client_account_id'], $sku, $refresh);
            if ($row === null) {
                return response()->json(['message' => 'Product not found.'], 404);
            }

            return response()->json(['row' => $row]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not load put away product.',
            ], 500);
        }
    }

    public function refresh(Request $request, PutAwayInventoryService $putAway): JsonResponse
    {
        $request->validate([
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        try {
            $meta = $putAway->refreshReceiving();
            $statusCode = ($meta['status'] ?? null) === PutAwayReceivingSnapshot::STATUS_OK ? 200 : 202;

            return response()->json($meta, $statusCode);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not refresh put away list.',
            ], 500);
        }
    }
}
