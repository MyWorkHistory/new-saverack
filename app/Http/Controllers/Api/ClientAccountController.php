<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientAccountBulkDeleteRequest;
use App\Http\Requests\ClientAccountBulkUpdateRequest;
use App\Http\Requests\ClientAccountCommentStoreRequest;
use App\Http\Requests\ClientAccountCommentUpdateRequest;
use App\Http\Requests\ClientAccountFeesSyncRequest;
use App\Http\Requests\ClientAccountStoreRequest;
use App\Http\Requests\ClientAccountUpdateRequest;
use App\Models\ActivityLog;
use App\Models\ClientAccount;
use App\Models\ClientAccountComment;
use App\Models\ClientAccountFee;
use App\Services\ActivityLogService;
use App\Services\ClientAccountService;
use App\Support\CsvExporter;
use App\Support\CrmActivityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientAccountController extends Controller
{
    /** @var ClientAccountService */
    protected $clientAccounts;

    /** @var ActivityLogService */
    protected $activityLog;

    public function __construct(ClientAccountService $clientAccounts, ActivityLogService $activityLog)
    {
        $this->clientAccounts = $clientAccounts;
        $this->activityLog = $activityLog;
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
            'status',
        ]));

        $paginator->getCollection()->transform(function (ClientAccount $row) {
            return $this->clientAccounts->toApiArray($row);
        });

        return response()->json($paginator);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', ClientAccount::class);

        $filters = $request->only(['search', 'account_manager_id', 'status']);
        $query = $this->clientAccounts->filteredAccountsQuery($filters)->orderBy('id');

        $columns = Schema::getColumnListing('client_accounts');
        $headers = array_merge($columns, ['account_manager_name', 'account_manager_email']);
        $filename = 'accounts-export-'.date('Y-m-d').'.csv';

        return CsvExporter::stream($filename, $headers, function ($out) use ($query, $columns) {
            $query->chunk(500, function ($rows) use ($out, $columns) {
                foreach ($rows as $account) {
                    $row = [];
                    foreach ($columns as $col) {
                        $row[] = CsvExporter::cell($account->getAttribute($col));
                    }
                    $manager = $account->accountManager;
                    $row[] = CsvExporter::cell($manager !== null ? $manager->name : null);
                    $row[] = CsvExporter::cell($manager !== null ? $manager->email : null);
                    fputcsv($out, $row);
                }
            });
        });
    }

    public function store(ClientAccountStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $portalPassword = (string) $validated['password'];
        unset($validated['password'], $validated['password_confirmation'], $validated['full_name']);

        try {
            $account = $this->clientAccounts->create($validated, $portalPassword, $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['full_name' => [$e->getMessage()]]);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['email' => [$e->getMessage()]]);
        }

        return response()->json($this->clientAccounts->toApiArray($account->fresh(['accountManager', 'feeItems'])), 201);
    }

    public function show(ClientAccount $client_account): JsonResponse
    {
        $client_account->loadCount(['stores', 'accountUsers']);
        $client_account->load([
            'comments' => fn ($q) => $q
                ->with(['user:id,name,email', 'user.profile:id,user_id,avatar_path'])
                ->orderBy('created_at'),
        ]);
        $payload = $this->clientAccounts->toApiArray($client_account);
        $payload['stores_count'] = (int) $client_account->stores_count;
        $payload['account_users_count'] = (int) $client_account->account_users_count;
        $payload['comments'] = $client_account->relationLoaded('comments')
            ? $client_account->comments
                ->map(fn (ClientAccountComment $c) => $this->transformAccountComment($c))
                ->values()
                ->all()
            : [];

        return response()->json($payload);
    }

    public function history(ClientAccount $client_account): JsonResponse
    {
        $this->authorize('view', $client_account);

        $logs = ActivityLog::query()
            ->where('subject_type', $client_account->getMorphClass())
            ->where('subject_id', $client_account->id)
            ->whereIn('action', ['client_account.created', 'client_account.updated', 'client_account.comment'])
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

    public function storeComment(ClientAccountCommentStoreRequest $request, ClientAccount $client_account): JsonResponse
    {
        $validated = $request->validated();
        $path = null;
        $original = null;
        $mime = null;
        $size = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('client-account-comments/'.$client_account->id, 'local');
            $original = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
            $size = (int) $file->getSize();
        }

        try {
            $comment = ClientAccountComment::query()->create([
                'client_account_id' => $client_account->id,
                'user_id' => $request->user()->id,
                'body' => $validated['body'],
                'attachment_path' => $path,
                'attachment_original_name' => $original,
                'attachment_mime' => $mime,
                'attachment_size' => $size,
            ]);
        } catch (\Throwable $e) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        $this->activityLog->log($request->user(), 'client_account.comment', $client_account, null, [
            'comment_id' => $comment->id,
        ]);

        $comment->load(['user:id,name,email', 'user.profile:id,user_id,avatar_path']);

        return response()->json($this->transformAccountComment($comment), 201);
    }

    public function updateComment(
        ClientAccountCommentUpdateRequest $request,
        ClientAccount $client_account,
        ClientAccountComment $comment
    ): JsonResponse {
        if ((int) $comment->client_account_id !== (int) $client_account->id) {
            abort(404);
        }

        $comment->update(['body' => $request->validated()['body']]);
        $comment->refresh();
        $comment->load(['user:id,name,email', 'user.profile:id,user_id,avatar_path']);

        return response()->json($this->transformAccountComment($comment));
    }

    public function destroyComment(ClientAccount $client_account, ClientAccountComment $comment): JsonResponse
    {
        $this->authorize('modifyComment', [$client_account, $comment]);

        if ((int) $comment->client_account_id !== (int) $client_account->id) {
            abort(404);
        }

        $path = $comment->attachment_path;
        $comment->delete();

        if ($path !== null && $path !== '') {
            Storage::disk('local')->delete((string) $path);
        }

        return response()->json(['message' => 'Note deleted.']);
    }

    public function downloadCommentAttachment(ClientAccount $client_account, ClientAccountComment $comment)
    {
        $this->authorize('view', $client_account);

        if ((int) $comment->client_account_id !== (int) $client_account->id || ! $comment->hasAttachment()) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists((string) $comment->attachment_path)) {
            abort(404);
        }

        return $disk->response(
            (string) $comment->attachment_path,
            $comment->attachment_original_name ?: 'attachment',
            ['Content-Type' => $comment->attachment_mime ?: 'application/octet-stream']
        );
    }

    public function update(ClientAccountUpdateRequest $request, ClientAccount $client_account): JsonResponse
    {
        $account = $this->clientAccounts->update($client_account, $request->validated(), $request->user());

        return response()->json($this->clientAccounts->toApiArray($account));
    }

    public function syncFees(ClientAccountFeesSyncRequest $request, ClientAccount $client_account): JsonResponse
    {
        $account = $this->clientAccounts->syncFees(
            $client_account,
            $request->validated(),
            $request->user()
        );

        return response()->json($this->clientAccounts->toApiArray($account));
    }

    public function destroyFeeItem(ClientAccount $client_account, ClientAccountFee $fee): JsonResponse
    {
        $this->authorize('update', $client_account);

        if ((int) $fee->client_account_id !== (int) $client_account->id) {
            abort(404);
        }

        if ($fee->fee_group !== ClientAccountFee::GROUP_STORAGE) {
            return response()->json([
                'message' => 'Only storage fee lines can be removed.',
            ], 422);
        }

        $fee->delete();

        return response()->json($this->clientAccounts->toApiArray($client_account->fresh()));
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformAccountComment(ClientAccountComment $comment): array
    {
        $u = $comment->relationLoaded('user') ? $comment->user : null;
        $avatarUrl = null;
        if ($u !== null && $u->relationLoaded('profile') && $u->profile !== null) {
            $avatarUrl = $u->profile->avatar_url;
        }

        return [
            'id' => $comment->id,
            'user_id' => $comment->user_id,
            'body' => $comment->body,
            'created_at' => $comment->created_at !== null ? $comment->created_at->toIso8601String() : null,
            'updated_at' => $comment->updated_at !== null ? $comment->updated_at->toIso8601String() : null,
            'user' => $u !== null
                ? [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $avatarUrl,
                ]
                : null,
            'attachment' => $comment->hasAttachment()
                ? [
                    'original_name' => $comment->attachment_original_name,
                    'mime' => $comment->attachment_mime,
                    'size' => $comment->attachment_size,
                ]
                : null,
        ];
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

    public function bulkDestroy(ClientAccountBulkDeleteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = array_map('intval', $validated['client_account_ids']);

        foreach ($ids as $id) {
            $this->authorize('delete', ClientAccount::query()->findOrFail($id));
        }

        $deleted = $this->clientAccounts->bulkDelete($ids);

        return response()->json(['message' => 'Client accounts deleted.', 'deleted' => $deleted]);
    }
}
