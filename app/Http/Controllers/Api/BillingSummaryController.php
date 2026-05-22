<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingSummaryController extends Controller
{
    private InvoiceService $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);

        $clientAccountId = $this->resolvePortalClientAccountId($request);

        return response()->json($this->invoices->summary($clientAccountId));
    }

    private function resolvePortalClientAccountId(Request $request): ?int
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }

        $portalId = (int) ($user->client_account_id ?? 0);
        if ($portalId <= 0) {
            return null;
        }

        if ($request->has('client_account_id')) {
            $requested = (int) $request->input('client_account_id');
            if ($requested > 0 && $requested !== $portalId) {
                abort(403);
            }
        }

        return $portalId;
    }
}
