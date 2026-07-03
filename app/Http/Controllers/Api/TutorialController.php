<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TutorialCommentStoreRequest;
use App\Http\Requests\TutorialStoreRequest;
use App\Http\Requests\TutorialUpdateRequest;
use App\Models\Tutorial;
use App\Models\TutorialComment;
use App\Services\TutorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TutorialController extends Controller
{
    /** @var TutorialService */
    protected $tutorialService;

    public function __construct(TutorialService $tutorialService)
    {
        $this->tutorialService = $tutorialService;
        $this->authorizeResource(Tutorial::class, 'tutorial');
    }

    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Tutorial::class);

        return response()->json([
            'categories' => Tutorial::categoryOptions(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tutorials = $this->tutorialService->paginate($request->only([
            'search',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
            'category',
        ]));

        $tutorials->getCollection()->transform(fn (Tutorial $tutorial) => $this->transformTutorial($tutorial));

        return response()->json($tutorials);
    }

    public function store(TutorialStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $tutorial = Tutorial::query()->create($data);

        return response()->json(
            $this->transformTutorial($tutorial->fresh('creator')),
            201
        );
    }

    public function show(Tutorial $tutorial): JsonResponse
    {
        $tutorial->load([
            'creator:id,name,email',
            'comments' => fn ($q) => $q->with('user:id,name,email')->orderBy('created_at'),
        ]);

        return response()->json($this->transformTutorial($tutorial));
    }

    public function update(TutorialUpdateRequest $request, Tutorial $tutorial): JsonResponse
    {
        $tutorial->update($request->validated());

        return response()->json($this->transformTutorial($tutorial->fresh('creator')));
    }

    public function destroy(Request $request, Tutorial $tutorial): JsonResponse
    {
        $tutorial->delete();

        return response()->json(['message' => 'Tutorial deleted.']);
    }

    public function storeComment(TutorialCommentStoreRequest $request, Tutorial $tutorial): JsonResponse
    {
        $validated = $request->validated();
        $path = null;
        $original = null;
        $mime = null;
        $size = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('tutorial-comments/'.$tutorial->id, 'local');
            $original = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
            $size = (int) $file->getSize();
        }

        try {
            $comment = TutorialComment::query()->create([
                'tutorial_id' => $tutorial->id,
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

        $comment->load('user:id,name,email');

        return response()->json($this->transformComment($comment), 201);
    }

    public function downloadCommentAttachment(Tutorial $tutorial, TutorialComment $comment)
    {
        $this->authorize('view', $tutorial);

        if ($comment->tutorial_id !== $tutorial->id || ! $comment->hasAttachment()) {
            abort(404);
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($comment->attachment_path)) {
            abort(404);
        }

        return $disk->response(
            $comment->attachment_path,
            $comment->attachment_original_name ?: 'attachment',
            ['Content-Type' => $comment->attachment_mime ?: 'application/octet-stream']
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformTutorial(Tutorial $tutorial): array
    {
        return [
            'id' => $tutorial->id,
            'title' => $tutorial->title,
            'description' => $tutorial->description,
            'category' => $tutorial->category,
            'category_label' => Tutorial::categoryLabel($tutorial->category),
            'created_by' => $tutorial->created_by,
            'created_at' => optional($tutorial->created_at)->toIso8601String(),
            'updated_at' => optional($tutorial->updated_at)->toIso8601String(),
            'creator' => $tutorial->relationLoaded('creator') ? $tutorial->creator : null,
            'comments' => $tutorial->relationLoaded('comments')
                ? $tutorial->comments->map(fn (TutorialComment $c) => $this->transformComment($c))->values()->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformComment(TutorialComment $comment): array
    {
        $attachment = null;
        if ($comment->hasAttachment()) {
            $attachment = [
                'original_name' => $comment->attachment_original_name,
                'mime' => $comment->attachment_mime,
                'size' => $comment->attachment_size,
            ];
        }

        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'user' => $comment->relationLoaded('user') ? $comment->user : null,
            'attachment' => $attachment,
        ];
    }
}
