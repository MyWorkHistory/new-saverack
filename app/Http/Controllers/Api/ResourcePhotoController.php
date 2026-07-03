<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResourcePhotoStoreRequest;
use App\Models\ResourcePhoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ResourcePhotoController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(ResourcePhoto::class, 'photo');
    }

    public function index(Request $request): JsonResponse
    {
        $photos = ResourcePhoto::query()
            ->with('creator:id,name,email')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ResourcePhoto $photo) => $this->transformPhoto($photo))
            ->values()
            ->all();

        return response()->json(['data' => $photos]);
    }

    public function store(ResourcePhotoStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $file = $request->file('photo');
        $maxSort = (int) ResourcePhoto::query()->max('sort_order');

        $photo = ResourcePhoto::query()->create([
            'name' => $validated['name'],
            'file_path' => '',
            'file_original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'created_by' => $request->user()->id,
            'sort_order' => $maxSort + 1,
        ]);

        $path = $file->store('resource-photos/'.$photo->id, 'local');
        $photo->file_path = $path;
        $photo->save();

        return response()->json(
            $this->transformPhoto($photo->fresh('creator')),
            201
        );
    }

    public function destroy(Request $request, ResourcePhoto $photo): JsonResponse
    {
        if ($photo->file_path) {
            Storage::disk('local')->delete($photo->file_path);
        }
        $photo->delete();

        return response()->json(['message' => 'Photo deleted.']);
    }

    public function file(ResourcePhoto $photo)
    {
        $this->authorize('view', $photo);

        $disk = Storage::disk('local');
        if (! $photo->file_path || ! $disk->exists($photo->file_path)) {
            abort(404);
        }

        return $disk->response(
            $photo->file_path,
            $photo->file_original_name ?: 'photo',
            ['Content-Type' => $photo->mime ?: 'application/octet-stream']
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformPhoto(ResourcePhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'name' => $photo->name,
            'mime' => $photo->mime,
            'size' => $photo->size,
            'created_by' => $photo->created_by,
            'created_at' => optional($photo->created_at)->toIso8601String(),
            'creator' => $photo->relationLoaded('creator') ? $photo->creator : null,
        ];
    }
}
