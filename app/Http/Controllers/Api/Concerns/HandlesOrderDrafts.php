<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\OrderDraft;
use App\Models\User;
use App\Services\OrderDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

trait HandlesOrderDrafts
{
    protected function orderDrafts(): OrderDraftService
    {
        return app(OrderDraftService::class);
    }

    protected function findEditableDraft(string $orderId): ?OrderDraft
    {
        return $this->orderDrafts()->findEditableDraftForRoute($orderId);
    }

    protected function draftUnavailableResponse(string $action = 'This action'): JsonResponse
    {
        return response()->json([
            'message' => $action.' is not available until the order is sent to ShipHero. Mark the draft as Ready to Ship first.',
        ], 422);
    }

    protected function authorizeDraft(OrderDraft $draft, Request $request): void
    {
        $user = $request->user();
        if ($user instanceof User) {
            $this->orderDrafts()->assertDraftAccountAccess($draft, $user);
        }
    }
}
