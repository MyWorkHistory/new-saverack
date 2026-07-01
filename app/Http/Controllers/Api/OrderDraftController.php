<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderDraftStoreRequest;
use App\Models\ClientAccount;
use App\Models\OrderDraft;
use App\Models\User;
use App\Services\OrderDraftService;
use App\Services\ShipHeroOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class OrderDraftController extends Controller
{
    /** @var OrderDraftService */
    protected $drafts;

    /** @var ShipHeroOrderService */
    protected $orders;

    public function __construct(OrderDraftService $drafts, ShipHeroOrderService $orders)
    {
        $this->drafts = $drafts;
        $this->orders = $orders;
    }

    public function store(OrderDraftStoreRequest $request): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');

        $validated = $request->validated();
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $clientAccountId = (int) $validated['client_account_id'];
        $this->assertClientAccountAccess($clientAccountId, $user);

        $draft = $this->drafts->createDraft(
            $clientAccountId,
            (string) $validated['order_number'],
            $validated['shipping_address'],
            $user
        );

        return response()->json([
            'draft_id' => $draft->id,
            'draft_route_id' => $this->drafts->encodeRouteId((int) $draft->id),
            'order_number' => $draft->order_number,
            'client_account_id' => $draft->client_account_id,
        ], 201);
    }

    public function readyToShip(Request $request, int $orderDraft): JsonResponse
    {
        Gate::authorize('shiphero.orders.write');

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $draft = OrderDraft::query()->findOrFail($orderDraft);
        if (! $draft->isDraft()) {
            return response()->json(['message' => 'This order draft has already been submitted.'], 422);
        }

        $this->drafts->assertDraftAccountAccess($draft, $user);
        $account = ClientAccount::query()->find((int) $draft->client_account_id);
        if ($account === null) {
            abort(404);
        }
        Gate::forUser($user)->authorize('view', $account);

        try {
            $result = $this->drafts->submitToShipHero($draft, $this->orders, $user);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Could not submit order to ShipHero.',
            ], 502);
        }

        return response()->json($result);
    }

    private function assertClientAccountAccess(int $clientAccountId, User $user): void
    {
        $portalAccountId = (int) ($user->client_account_id ?? 0);
        if ($portalAccountId > 0 && $portalAccountId !== $clientAccountId) {
            abort(403);
        }

        $account = ClientAccount::query()->findOrFail($clientAccountId);
        Gate::forUser($user)->authorize('view', $account);
    }
}
