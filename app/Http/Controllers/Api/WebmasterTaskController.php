<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskCommentStoreRequest;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WebmasterTaskController extends Controller
{
    /** @var TaskService */
    protected $taskService;

    public function __construct(TaskService $taskService)
    {
        $this->taskService = $taskService;
        $this->authorizeResource(Task::class, 'task');
    }

    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Task::class);

        return response()->json([
            'statuses' => [
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'in_progress', 'label' => 'In progress'],
                ['value' => 'review', 'label' => 'Review'],
                ['value' => 'completed', 'label' => 'Completed'],
            ],
            'priorities' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'medium', 'label' => 'Medium'],
                ['value' => 'high', 'label' => 'High'],
                ['value' => 'urgent', 'label' => 'Urgent'],
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->taskService->paginate($request->only([
            'search',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
            'status',
            'priority',
            'assigned_to',
            'min_price',
            'max_price',
        ]));

        $tasks->getCollection()->transform(function (Task $task) {
            return $this->transformTask($task);
        });

        return response()->json($tasks);
    }

    public function store(TaskStoreRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $request->user()->id;

        $task = Task::query()->create($data);

        return response()->json(
            $this->transformTask($task->fresh(['creator', 'assignee'])),
            201
        );
    }

    public function show(Task $task): JsonResponse
    {
        $task->load([
            'creator:id,name,email',
            'assignee:id,name,email',
            'comments' => fn ($q) => $q->with('user:id,name,email')->orderBy('created_at'),
        ]);

        return response()->json($this->transformTask($task));
    }

    public function storeComment(TaskCommentStoreRequest $request, Task $task): JsonResponse
    {
        $validated = $request->validated();
        $path = null;
        $original = null;
        $mime = null;
        $size = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $path = $file->store('task-comments/'.$task->id, 'local');
            $original = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
            $size = (int) $file->getSize();
        }

        try {
            $comment = TaskComment::query()->create([
                'task_id' => $task->id,
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

    public function downloadCommentAttachment(Task $task, TaskComment $comment)
    {
        $this->authorize('view', $task);

        if ($comment->task_id !== $task->id || ! $comment->hasAttachment()) {
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

    public function update(TaskUpdateRequest $request, Task $task): JsonResponse
    {
        $task->update($request->validated());

        return response()->json($this->transformTask($task->fresh(['creator', 'assignee'])));
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(['message' => 'Task deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'price' => $task->price !== null ? (string) $task->price : null,
            'due_date' => $task->due_date ? $task->due_date->format('Y-m-d') : null,
            'created_by' => $task->created_by,
            'assigned_to' => $task->assigned_to,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
            'creator' => $task->relationLoaded('creator') ? $task->creator : null,
            'assignee' => $task->relationLoaded('assignee') ? $task->assignee : null,
            'comments' => $task->relationLoaded('comments')
                ? $task->comments->map(fn (TaskComment $c) => $this->transformComment($c))->values()->all()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transformComment(TaskComment $comment): array
    {
        return [
            'id' => $comment->id,
            'body' => $comment->body,
            'created_at' => optional($comment->created_at)->toIso8601String(),
            'user' => $comment->relationLoaded('user') ? $comment->user : null,
            'attachment' => $comment->hasAttachment()
                ? [
                    'original_name' => $comment->attachment_original_name,
                    'mime' => $comment->attachment_mime,
                    'size' => $comment->attachment_size,
                ]
                : null,
        ];
    }
}
