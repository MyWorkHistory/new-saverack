<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserPersonalTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserPersonalTaskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $tasks = UserPersonalTask::query()
            ->where('user_id', $user->id)
            ->orderBy('is_completed')
            ->orderByRaw('CASE WHEN is_completed = 1 THEN completed_at ELSE created_at END DESC')
            ->get();

        $incompleteCount = $tasks->where('is_completed', false)->count();

        return response()->json([
            'tasks' => $tasks->map(fn (UserPersonalTask $task) => $this->transformTask($task))->values()->all(),
            'incomplete_count' => $incompleteCount,
            'total_count' => $tasks->count(),
            'max_tasks' => UserPersonalTask::MAX_PER_USER,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $this->requireUser($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $existingCount = UserPersonalTask::query()
            ->where('user_id', $user->id)
            ->count();

        if ($existingCount >= UserPersonalTask::MAX_PER_USER) {
            throw ValidationException::withMessages([
                'title' => ['You can only have 10 tasks.'],
            ]);
        }

        $task = UserPersonalTask::query()->create([
            'user_id' => $user->id,
            'title' => trim((string) $validated['title']),
            'is_completed' => false,
            'completed_at' => null,
        ]);

        return response()->json($this->transformTask($task), 201);
    }

    public function update(Request $request, UserPersonalTask $userPersonalTask): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->assertOwnsTask($user, $userPersonalTask);

        $validated = $request->validate([
            'is_completed' => ['required', 'boolean'],
        ]);

        $isCompleted = (bool) $validated['is_completed'];
        $userPersonalTask->is_completed = $isCompleted;
        $userPersonalTask->completed_at = $isCompleted ? now() : null;
        $userPersonalTask->save();

        return response()->json($this->transformTask($userPersonalTask->fresh()));
    }

    public function destroy(Request $request, UserPersonalTask $userPersonalTask): JsonResponse
    {
        $user = $this->requireUser($request);
        $this->assertOwnsTask($user, $userPersonalTask);

        $userPersonalTask->delete();

        return response()->json(['deleted' => true]);
    }

    private function requireUser(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    private function assertOwnsTask(User $user, UserPersonalTask $task): void
    {
        if ((int) $task->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function transformTask(UserPersonalTask $task): array
    {
        return [
            'id' => (int) $task->id,
            'title' => (string) $task->title,
            'is_completed' => (bool) $task->is_completed,
            'completed_at' => optional($task->completed_at)->toIso8601String(),
            'created_at' => optional($task->created_at)->toIso8601String(),
        ];
    }
}
