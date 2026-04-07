<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientAccountBulkUpdateRequest;
use App\Http\Requests\ClientAccountStoreRequest;
use App\Http\Requests\ClientAccountUpdateRequest;
use App\Models\ClientAccount;
use App\Services\ClientAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientAccountController extends Controller
{
    /** @var ClientAccountService */
    protected $clientAccounts;

    public function __construct(ClientAccountService $clientAccounts)
    {
        $this->clientAccounts = $clientAccounts;
        $this->authorizeResource(ClientAccount::class, 'client_account');
    }

    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClientAccount::class);

        $countsByStatus = ClientAccount::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $total = array_sum($countsByStatus);
        $directoryStats = [
            'total' => $total,
            'active' => (int) ($countsByStatus[ClientAccount::STATUS_ACTIVE] ?? 0),
            'pending' => (int) ($countsByStatus[ClientAccount::STATUS_PENDING] ?? 0),
            'paused' => (int) ($countsByStatus[ClientAccount::STATUS_PAUSED] ?? 0),
            'inactive' => (int) ($countsByStatus[ClientAccount::STATUS_INACTIVE] ?? 0),
        ];

        return response()->json([
            'statuses' => ClientAccount::STATUSES,
            'account_managers' => $this->clientAccounts->accountManagersForMeta(),
            'directory_stats' => $directoryStats,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->clientAccounts->paginate($request->only([
            'search',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
            'account_manager_id',
        ]));

        $paginator->getCollection()->transform(function (ClientAccount $row) {
            return $this->clientAccounts->toApiArray($row);
        });

        return response()->json($paginator);
    }

    public function store(ClientAccountStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $portalPassword = (string) $validated['password'];
        unset($validated['password'], $validated['password_confirmation'], $validated['full_name']);

        try {
            $account = $this->clientAccounts->create($validated, $portalPassword);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['full_name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }

        return response()->json($this->clientAccounts->toApiArray($account->fresh(['accountManager'])), 201);
    }

    public function show(ClientAccount $client_account): JsonResponse
    {
        return response()->json($this->clientAccounts->toApiArray($client_account));
    }

    public function update(ClientAccountUpdateRequest $request, ClientAccount $client_account): JsonResponse
    {
        $account = $this->clientAccounts->update($client_account, $request->validated());

        return response()->json($this->clientAccounts->toApiArray($account));
    }

    public function destroy(ClientAccount $client_account): JsonResponse
    {
        $this->clientAccounts->delete($client_account);

        return response()->json(['message' => 'Client account deleted.']);
    }

    public function bulkUpdate(ClientAccountBulkUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = $validated['client_account_ids'];
        $status = $validated['status'];

        foreach ($ids as $id) {
            $this->authorize('update', ClientAccount::query()->findOrFail($id));
        }

        $updated = $this->clientAccounts->bulkUpdateStatus($ids, $status);

        return response()->json(['message' => 'Client accounts updated.', 'updated' => $updated]);
    }
}
