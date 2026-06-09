<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'query' => ['nullable', 'string', 'max:255'],
            'first' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'after' => ['nullable', 'string', 'max:64'],
            'refresh' => ['sometimes', 'boolean'],
        ]);

        $clientAccountId = (int) $validated['client_account_id'];
        $first = (int) ($validated['first'] ?? 50);
        $after = isset($validated['after']) ? (string) $validated['after'] : null;
        $query = isset($validated['query']) ? (string) $validated['query'] : null;
        $refresh = filter_var($validated['refresh'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            return response()->json($putAway->list($clientAccountId, $query, $first, $after, $refresh));
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

    public function refresh(Request $request, PutAwayInventoryService $putAway): JsonResponse
    {
        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
        ]);

        try {
            return response()->json($putAway->refresh((int) $validated['client_account_id']));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
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
