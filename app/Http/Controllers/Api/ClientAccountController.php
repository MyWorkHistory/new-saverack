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

        return response()->json([
            'statuses' => ClientAccount::STATUSES,
            'account_managers' => $this->clientAccounts->accountManagersForMeta(),
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
        $account = $this->clientAccounts->create($request->validated());

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
