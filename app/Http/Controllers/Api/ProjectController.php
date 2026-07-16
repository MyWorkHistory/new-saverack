<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomBillItemStoreRequest;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /** @var ProjectService */
    private $projects;

    public function __construct(ProjectService $projects)
    {
        $this->projects = $projects;
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Project::class);

        return response()->json($this->projects->paginate($request->only([
            'status',
            'q',
            'search',
            'client_account_id',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
        ])));
    }

    public function summary(): JsonResponse
    {
        Gate::authorize('viewAny', Project::class);

        return response()->json($this->projects->summary());
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Project::class);

        $validated = $request->validate([
            'client_account_id' => ['required', 'integer', 'exists:client_accounts,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);

        $project = $this->projects->create($validated, $request->user());

        return response()->json($this->projects->toDetailArray($project), 201);
    }

    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $project = $this->projects->findOrFail((int) $project->id);

        return response()->json($this->projects->toDetailArray($project));
    }

    public function updateStatus(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(Project::STATUSES)],
        ]);

        $project = $this->projects->updateStatus($project, (string) $validated['status']);

        return response()->json($this->projects->toDetailArray($project));
    }

    public function destroy(Project $project): JsonResponse
    {
        Gate::authorize('delete', $project);

        $this->projects->delete($project);

        return response()->json(['ok' => true]);
    }

    public function storeNote(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $note = $this->projects->addNote($project, (string) $validated['body'], $request->user());

        return response()->json($this->projects->noteToArray($note), 201);
    }

    public function updateNote(Request $request, Project $project, ProjectNote $note): JsonResponse
    {
        Gate::authorize('update', $project);
        $this->assertNoteBelongs($project, $note);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $note = $this->projects->updateNote($note, (string) $validated['body']);

        return response()->json($this->projects->noteToArray($note));
    }

    public function destroyNote(Project $project, ProjectNote $note): JsonResponse
    {
        Gate::authorize('update', $project);
        $this->assertNoteBelongs($project, $note);

        $this->projects->deleteNote($note);

        return response()->json(['ok' => true]);
    }

    public function storeQuoteItem(CustomBillItemStoreRequest $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $project = $this->projects->addQuoteItem($project, $request->validated(), $request->user());

        return response()->json($this->projects->toDetailArray($project), 201);
    }

    public function destroyQuoteItem(Project $project, int $item): JsonResponse
    {
        Gate::authorize('update', $project);

        $project = $this->projects->removeQuoteItem($project, $item, request()->user());

        return response()->json($this->projects->toDetailArray($project));
    }

    public function createBill(Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $project = $this->projects->createBill($project, request()->user());

        return response()->json($this->projects->toDetailArray($project), 201);
    }

    private function assertNoteBelongs(Project $project, ProjectNote $note): void
    {
        if ((int) $note->project_id !== (int) $project->id) {
            abort(404);
        }
    }
}
