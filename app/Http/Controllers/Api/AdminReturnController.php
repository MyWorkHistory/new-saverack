<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\ClientAccountReturn;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AdminReturnController extends Controller
{
    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403);
        }
        if ((int) ($user->client_account_id ?? 0) > 0) {
            abort(403, 'Admin return endpoints are for staff only.');
        }
    }

    private function normalizeOrderNumber(?string $raw): string
    {
        $s = strtolower(trim((string) $raw));
        $s = ltrim($s, '#');

        return trim($s);
    }

    private function normalizeRmaNumber(?string $raw): string
    {
        $s = strtolower(trim((string) $raw));
        $s = ltrim($s, '#');
        if (str_starts_with($s, 'rma')) {
            $s = ltrim(substr($s, 3), " \t#");
        }

        return trim($s);
    }

    private function orderNumberMatches(ClientAccountReturn $return, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return $this->normalizeOrderNumber($return->order_number) === $needle;
    }

    private function rmaNumberMatches(ClientAccountReturn $return, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return $this->normalizeRmaNumber($return->rma_number) === $needle;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeProcessLookupRow(ClientAccountReturn $return): array
    {
        $return->loadMissing('clientAccount');
        $companyName = $return->clientAccount !== null
            ? trim((string) $return->clientAccount->company_name)
            : '';

        return [
            'id' => $return->id,
            'client_account_id' => $return->client_account_id,
            'client_account_company_name' => $companyName,
            'rma_number' => $return->rma_number,
            'status' => $return->status,
            'return_type' => $return->return_type,
            'order_number' => $return->order_number,
            'customer_name' => $return->customer_name,
            'items_count' => $return->items_count,
            'created_at' => optional($return->created_at)->toIso8601String(),
            'processed_at' => optional($return->processed_at)->toIso8601String(),
        ];
    }

    public function processLookup(Request $request): JsonResponse
    {
        $this->assertStaff($request);
        Gate::authorize('viewAny', ClientAccountReturn::class);

        $validated = $request->validate([
            'order_number' => ['nullable', 'string', 'max:255', 'required_without:rma_number'],
            'rma_number' => ['nullable', 'string', 'max:255', 'required_without:order_number'],
            'client_account_id' => ['nullable', 'integer', 'exists:client_accounts,id'],
        ]);

        $orderNumber = isset($validated['order_number'])
            ? $this->normalizeOrderNumber($validated['order_number'])
            : '';
        $rmaNumber = isset($validated['rma_number'])
            ? $this->normalizeRmaNumber($validated['rma_number'])
            : '';

        if ($orderNumber === '' && $rmaNumber === '') {
            throw ValidationException::withMessages([
                'order_number' => ['Enter an order number or RMA number.'],
            ]);
        }

        $query = ClientAccountReturn::query()
            ->where('status', ClientAccountReturn::STATUS_PENDING)
            ->with('clientAccount');

        if (! empty($validated['client_account_id'])) {
            $account = ClientAccount::query()->findOrFail((int) $validated['client_account_id']);
            Gate::authorize('view', $account);
            $query->where('client_account_id', $account->id);
        }

        if ($orderNumber !== '') {
            $query->where('order_number', 'like', '%'.$orderNumber.'%');
        }
        if ($rmaNumber !== '') {
            $query->where('rma_number', 'like', '%'.$rmaNumber.'%');
        }

        $user = $request->user();
        $returns = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->filter(function (ClientAccountReturn $return) use ($user, $orderNumber, $rmaNumber) {
                if (! Gate::forUser($user)->allows('view', $return)) {
                    return false;
                }
                if (! $this->orderNumberMatches($return, $orderNumber)) {
                    return false;
                }

                return $this->rmaNumberMatches($return, $rmaNumber);
            })
            ->take(25)
            ->values()
            ->map(fn (ClientAccountReturn $return) => $this->serializeProcessLookupRow($return));

        return response()->json([
            'data' => $returns->all(),
        ]);
    }
}
