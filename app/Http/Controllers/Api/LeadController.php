<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadFee;
use App\Services\LeadService;
use App\Support\CrmActivityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /** @var LeadService */
    private $leads;

    public function __construct(LeadService $leads)
    {
        $this->leads = $leads;
    }

    public function meta(): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return response()->json($this->leads->meta());
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Lead::class);

        return response()->json($this->leads->paginate($request->only([
            'status',
            'search',
            'q',
            'per_page',
            'page',
            'sort_by',
            'sort_dir',
        ])));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:20000'],
            'follow_up_days' => ['nullable', 'integer', Rule::in(Lead::FOLLOW_UP_DAY_OPTIONS)],
        ]);

        $lead = $this->leads->create($validated, $request->user());

        return response()->json($this->leads->toDetailArray($lead), 201);
    }

    public function quickAdd(Request $request): JsonResponse
    {
        Gate::authorize('create', Lead::class);

        $validated = $request->validate([
            'text' => ['required', 'string', 'max:50000'],
        ]);

        $lead = $this->leads->createFromQuickAddText((string) $validated['text'], $request->user());

        return response()->json($this->leads->toDetailArray($lead), 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        Gate::authorize('view', $lead);

        return response()->json($this->leads->toDetailArray($lead));
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Lead::STATUSES)],
            'follow_up_days' => ['sometimes', 'integer', Rule::in(Lead::FOLLOW_UP_DAY_OPTIONS)],
            'company_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:20000'],
        ]);

        $lead = $this->leads->update($lead, $validated, $request->user());

        return response()->json($this->leads->toDetailArray($lead));
    }

    public function destroy(Request $request, Lead $lead): JsonResponse
    {
        Gate::authorize('delete', $lead);

        $this->leads->delete($lead, $request->user());

        return response()->json(['ok' => true]);
    }

    public function updateFee(Request $request, Lead $lead, LeadFee $fee): JsonResponse
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'amount' => ['sometimes', 'nullable', 'numeric'],
            'cost' => ['sometimes', 'nullable', 'numeric'],
        ]);

        if (! array_key_exists('amount', $validated) && ! array_key_exists('cost', $validated)) {
            return response()->json($this->leads->toDetailArray($lead));
        }

        $currentAmount = $fee->amount !== null ? (float) $fee->amount : null;
        $amount = array_key_exists('amount', $validated)
            ? ($validated['amount'] !== null ? (float) $validated['amount'] : null)
            : $currentAmount;

        $fields = null;
        if (array_key_exists('cost', $validated)) {
            $fields = ['cost' => $validated['cost']];
        }

        $lead = $this->leads->updateFeeAmount(
            $lead,
            $fee,
            $amount,
            $request->user(),
            $fields
        );

        return response()->json($this->leads->toDetailArray($lead));
    }

    public function history(Lead $lead): JsonResponse
    {
        Gate::authorize('view', $lead);

        $logs = ActivityLog::query()
            ->where('subject_type', $lead->getMorphClass())
            ->where('subject_id', $lead->id)
            ->whereIn('action', ['lead.created', 'lead.updated', 'lead.deleted'])
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
}
