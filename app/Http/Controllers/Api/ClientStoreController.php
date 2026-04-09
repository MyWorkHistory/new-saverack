<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClientStoreBulkDeleteRequest;
use App\Http\Requests\ClientStoreBulkUpdateRequest;
use App\Http\Requests\ClientStoreDestroyRequest;
use App\Http\Requests\ClientStoreListRequest;
use App\Http\Requests\ClientStoreStoreRequest;
use App\Http\Requests\ClientStoreUpdateRequest;
use App\Models\ClientAccount;
use App\Models\ClientStore;
use Illuminate\Http\JsonResponse;

class ClientStoreController extends Controller
{
    /**
     * @return array<string, mixed>
     */
    public static function storeToApiArray(ClientStore $store): array
    {
        return [
            'id' => $store->id,
            'client_account_id' => $store->client_account_id,
            'status' => $store->status,
            'name' => $store->name,
            'website' => $store->website,
            'marketplace' => $store->marketplace,
            'created_at' => $store->created_at !== null
                ? $store->created_at->toIso8601String()
                : null,
            'updated_at' => $store->updated_at !== null
                ? $store->updated_at->toIso8601String()
                : null,
        ];
    }

    public function index(ClientStoreListRequest $request, ClientAccount $client_account): JsonResponse
    {
        $rows = $client_account->stores()
            ->orderBy('name')
            ->get();

        return response()->json(
            $rows->map(function (ClientStore $s) {
                return self::storeToApiArray($s);
            })->values()
        );
    }

    public function store(ClientStoreStoreRequest $request, ClientAccount $client_account): JsonResponse
    {
        $data = $request->validated();
        $data['status'] = ClientStore::STATUS_PENDING;

        $store = $client_account->stores()->create($data);

        return response()->json(self::storeToApiArray($store->fresh()), 201);
    }

    public function update(ClientStoreUpdateRequest $request, ClientStore $client_store): JsonResponse
    {
        $client_store->update($request->validated());

        return response()->json(self::storeToApiArray($client_store->fresh()));
    }

    public function bulkUpdate(ClientStoreBulkUpdateRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = $validated['client_store_ids'];

        foreach ($ids as $id) {
            $this->authorize('update', ClientStore::query()->findOrFail($id));
        }

        $attrs = [];
        if (! empty($validated['apply_status'])) {
            $attrs['status'] = $validated['status'];
        }
        if (! empty($validated['apply_marketplace'])) {
            $attrs['marketplace'] = $validated['marketplace'] ?? null;
        }

        $updated = ClientStore::query()->whereIn('id', $ids)->update($attrs);

        return response()->json([
            'message' => 'Stores updated.',
            'updated' => $updated,
        ]);
    }

    public function bulkDestroy(ClientStoreBulkDeleteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ids = array_map('intval', $validated['client_store_ids']);

        foreach ($ids as $id) {
            $this->authorize('delete', ClientStore::query()->findOrFail($id));
        }

        $deleted = ClientStore::query()->whereIn('id', $ids)->delete();

        return response()->json([
            'message' => 'Stores deleted.',
            'deleted' => $deleted,
        ]);
    }

    public function destroy(ClientStoreDestroyRequest $request, ClientStore $client_store): JsonResponse
    {
        $client_store->delete();

        return response()->json(['message' => 'Store deleted.']);
    }
}
