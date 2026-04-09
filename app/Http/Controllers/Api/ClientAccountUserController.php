<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientAccountUserStoreRequest;
use App\Http\Requests\ClientAccountUserUpdateRequest;
use App\Models\ActivityLog;
use App\Models\ClientAccount;
use App\Models\User;
use App\Policies\ClientAccountUserPolicy;
use App\Services\ClientAccountUserService;
use App\Support\CrmActivityPresenter;
use App\Support\CsvExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function exportCsv(Request $request): StreamedResponse
    {
        $user = $request->user();
        if ($user === null || ! app(ClientAccountUserPolicy::class)->viewAny($user)) {
            abort(403);
        }

        $filters = $request->only(['search', 'status', 'client_account_id']);
        $query = $this->accountUsers->filteredAccountUsersQuery($filters)
            ->orderBy('users.id');

        $userColumns = array_values(array_diff(
            Schema::getColumnListing('users'),
            ['password', 'remember_token'],
        ));
        $headers = array_merge(
            $userColumns,
            ['client_account_company_name', 'client_account_email'],
        );
        $filename = 'account-users-export-'.date('Y-m-d').'.csv';

        return CsvExporter::stream($filename, $headers, function ($out) use ($query, $userColumns) {
            $query->chunk(500, function ($rows) use ($out, $userColumns) {
                foreach ($rows as $portalUser) {
                    $row = [];
                    foreach ($userColumns as $col) {
                        $row[] = CsvExporter::cell($portalUser->getAttribute($col));
                    }
                    $account = $portalUser->clientAccount;
                    $row[] = CsvExporter::cell($account !== null ? $account->company_name : null);
                    $row[] = CsvExporter::cell($account !== null ? $account->email : null);
                    fputcsv($out, $row);
                }
            });
        });
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

    public function history(Request $request, ClientAccount $client_account, User $user): JsonResponse
    {
        $this->guardUserBelongsToAccount($client_account, $user);

        $auth = $request->user();
        if ($auth === null || ! app(ClientAccountUserPolicy::class)->view($auth, $user, $client_account)) {
            abort(403);
        }

        $logs = ActivityLog::query()
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->id)
            ->whereIn('action', ['portal_user.created', 'portal_user.updated', 'portal_user.deleted'])
            ->with(['user:id,name', 'user.profile:id,user_id,avatar_path'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $items = $logs
            ->map(static fn (ActivityLog $log) => CrmActivityPresenter::toHistoryItem($log))
            ->values()
            ->all();

        return response()->json(['items' => $items]);
    }

    public function store(ClientAccountUserStoreRequest $request, ClientAccount $client_account): JsonResponse
    {
        $user = $this->accountUsers->createSecondary(
            $client_account,
            $request->validated(),
            $request->user(),
        );

        return response()->json($this->accountUsers->toApiArray($user), 201);
    }

    public function update(
        ClientAccountUserUpdateRequest $request,
        ClientAccount $client_account,
        User $user
    ): JsonResponse {
        $this->guardUserBelongsToAccount($client_account, $user);

        $updated = $this->accountUsers->updateAccountUser(
            $user,
            $request->validated(),
            $request->user(),
        );

        return response()->json($this->accountUsers->toApiArray($updated));
    }

    public function destroy(Request $request, ClientAccount $client_account, User $user): JsonResponse
    {
        $this->guardUserBelongsToAccount($client_account, $user);

        $auth = $request->user();
        if ($auth === null || ! app(ClientAccountUserPolicy::class)->delete($auth, $user, $client_account)) {
            abort(403);
        }

        $this->accountUsers->deleteAccountUser($user, $auth);

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
