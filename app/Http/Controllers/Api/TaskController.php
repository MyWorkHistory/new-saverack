<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TaskStoreRequest;
use App\Http\Requests\TaskUpdateRequest;
use App\Models\Task;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(private readonly TaskService $taskService)
    {
        $this->authorizeResource(Task::class, 'task');
    }

    public function index(Request $request): JsonResponse
    {
        $tasks = $this->taskService->paginate($request->only(['search', 'per_page', 'sort_by', 'sort_dir']));

        return response()->json($tasks);
    }

    public function store(TaskStoreRequest $request): JsonResponse
    {
        $task = $this->taskService->create($request->validated(), $request->user());

        return response()->json($task, 201);
    }

    public function show(Task $task): JsonResponse
    {
        return response()->json($task->load(['assignee', 'creator']));
    }

    public function update(TaskUpdateRequest $request, Task $task): JsonResponse
    {
        $updated = $this->taskService->update($task, $request->validated(), $request->user());

        return response()->json($updated);
    }

    public function destroy(Request $request, Task $task): JsonResponse
    {
        $this->taskService->delete($task, $request->user());

        return response()->json(['message' => 'Task deleted.']);
    }
}
