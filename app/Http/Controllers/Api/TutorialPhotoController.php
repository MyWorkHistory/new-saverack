<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResourcePhotoStoreRequest;
use App\Models\ResourcePhoto;
use App\Models\Tutorial;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class TutorialPhotoController extends Controller
{
    public function index(Tutorial $tutorial): JsonResponse
    {
        $this->authorize('view', $tutorial);

        $photos = $tutorial->photos()
            ->with('creator:id,name,email')
            ->orderByDesc('sort_order')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ResourcePhoto $photo) => $this->transformPhoto($photo))
            ->values()
            ->all();

        return response()->json(['data' => $photos]);
    }

    public function store(ResourcePhotoStoreRequest $request, Tutorial $tutorial): JsonResponse
    {
        $this->authorize('view', $tutorial);

        $validated = $request->validated();
        $file = $request->file('photo');
        $maxSort = (int) ResourcePhoto::query()
            ->where('tutorial_id', $tutorial->id)
            ->max('sort_order');

        $photo = ResourcePhoto::query()->create([
            'tutorial_id' => $tutorial->id,
            'name' => $validated['name'],
            'file_path' => '',
            'file_original_name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => (int) $file->getSize(),
            'created_by' => $request->user()->id,
            'sort_order' => $maxSort + 1,
        ]);

        $path = $file->store('tutorial-photos/'.$tutorial->id.'/'.$photo->id, 'local');
        $photo->file_path = $path;
        $photo->save();

        return response()->json(
            $this->transformPhoto($photo->fresh('creator')),
            201
        );
    }

    public function destroy(Tutorial $tutorial, ResourcePhoto $photo): JsonResponse
    {
        $this->authorize('delete', $photo);

        if ((int) $photo->tutorial_id !== (int) $tutorial->id) {
            abort(404);
        }

        if ($photo->file_path) {
            Storage::disk('local')->delete($photo->file_path);
        }
        $photo->delete();

        return response()->json(['message' => 'Photo deleted.']);
    }

    public function file(Tutorial $tutorial, ResourcePhoto $photo)
    {
        $this->authorize('view', $tutorial);

        if ((int) $photo->tutorial_id !== (int) $tutorial->id) {
            abort(404);
        }

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
    public function transformPhoto(ResourcePhoto $photo): array
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
