<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientAccountUserStoreRequest;
use App\Http\Requests\ClientAccountUserUpdateRequest;
use App\Models\ClientAccount;
use App\Models\User;
use App\Policies\ClientAccountUserPolicy;
use App\Services\ClientAccountUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientAccountUserController extends Controller
{
    /** @var ClientAccountUserService */
    protected $accountUsers;

    public function __construct(ClientAccountUserService $accountUsers)
    {
        $this->accountUsers = $accountUsers;
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! app(ClientAccountUserPolicy::class)->viewAny($user)) {
            abort(403);
        }

        $paginator = $this->accountUsers->paginate($request->only([
            'search',
            'status',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
            'client_account_id',
        ]));

        $paginator->getCollection()->transform(function (User $row) {
            return $this->accountUsers->toApiArray($row);
        });

        return response()->json($paginator);
    }

    public function show(Request $request, ClientAccount $client_account, User $user): JsonResponse
    {
        $this->guardUserBelongsToAccount($client_account, $user);

        $auth = $request->user();
        if ($auth === null || ! app(ClientAccountUserPolicy::class)->view($auth, $user, $client_account)) {
            abort(403);
        }

        $user->loadMissing('clientAccount:id,company_name,email');

        return response()->json($this->accountUsers->toApiArray($user));
    }

    public function store(ClientAccountUserStoreRequest $request, ClientAccount $client_account): JsonResponse
    {
        $user = $this->accountUsers->createSecondary($client_account, $request->validated());

        return response()->json($this->accountUsers->toApiArray($user), 201);
    }

    public function update(
        ClientAccountUserUpdateRequest $request,
        ClientAccount $client_account,
        User $user
    ): JsonResponse {
        $this->guardUserBelongsToAccount($client_account, $user);

        $updated = $this->accountUsers->updateAccountUser($user, $request->validated());

        return response()->json($this->accountUsers->toApiArray($updated));
    }

    public function destroy(Request $request, ClientAccount $client_account, User $user): JsonResponse
    {
        $this->guardUserBelongsToAccount($client_account, $user);

        $auth = $request->user();
        if ($auth === null || ! app(ClientAccountUserPolicy::class)->delete($auth, $user, $client_account)) {
            abort(403);
        }

        $this->accountUsers->deleteAccountUser($user);

        return response()->json(['message' => 'User deleted.']);
    }

    private function guardUserBelongsToAccount(ClientAccount $account, User $user): void
    {
        if ($user->client_account_id === null
            || (int) $user->client_account_id !== (int) $account->id) {
            abort(404);
        }
    }
}
