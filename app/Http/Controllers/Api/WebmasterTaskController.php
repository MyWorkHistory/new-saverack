<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'account_name',
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
        $task->load(['creator:id,name,email', 'assignee:id,name,email']);

        return response()->json($this->transformTask($task));
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
            'account_name' => $task->account_name,
            'due_date' => $task->due_date ? $task->due_date->format('Y-m-d') : null,
            'created_by' => $task->created_by,
            'assigned_to' => $task->assigned_to,
            'created_at' => optional($task->created_at)->toIso8601String(),
            'updated_at' => optional($task->updated_at)->toIso8601String(),
            'creator' => $task->relationLoaded('creator') ? $task->creator : null,
            'assignee' => $task->relationLoaded('assignee') ? $task->assignee : null,
        ];
    }
}
