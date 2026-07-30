<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\LeadFee;
use App\Services\LeadService;
use App\Support\CrmActivityPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
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

        if ($request->has('follow_up_days')) {
            $rawFollowUp = $request->input('follow_up_days');
            if ($rawFollowUp === '' || $rawFollowUp === 'off' || $rawFollowUp === 'Off') {
                $request->merge(['follow_up_days' => null]);
            }
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Lead::STATUSES)],
            'follow_up_days' => ['sometimes', 'nullable', 'integer', Rule::in(Lead::FOLLOW_UP_DAY_OPTIONS)],
            'company_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:20000'],
            'created_at' => ['sometimes', 'date'],
            'email_template_id' => ['sometimes', 'nullable'],
            'record_status_event' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('email_template_id', $validated)) {
            $raw = $validated['email_template_id'];
            if ($raw === 'custom' || $raw === '' || $raw === null) {
                $validated['email_template_id'] = null;
            } else {
                $validated['email_template_id'] = (int) $raw;
            }
        }

        $lead = $this->leads->update($lead, $validated, $request->user());

        return response()->json($this->leads->toDetailArray($lead));
    }

    public function uploadLogo(Request $request, Lead $lead): JsonResponse
    {
        Gate::authorize('update', $lead);

        $request->validate([
            'logo' => ['required', 'file', 'image', 'max:5120'],
        ]);

        $lead = $this->leads->uploadLogo($lead, $request->file('logo'), $request->user());

        return response()->json($this->leads->toDetailArray($lead));
    }

    public function captureWebsiteThumbnail(Request $request, Lead $lead): JsonResponse
    {
        Gate::authorize('update', $lead);

        try {
            $lead = $this->leads->captureWebsiteThumbnail($lead, $request->user());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Could not generate website thumbnail.',
            ], 502);
        }

        return response()->json($this->leads->toDetailArray($lead));
    }

    public function storeComment(Request $request, Lead $lead): JsonResponse
    {
        Gate::authorize('update', $lead);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'attachment' => [
                'nullable',
                'file',
                'max:5120',
                'mimes:jpeg,jpg,png,gif,webp,pdf,txt,doc,docx',
            ],
        ]);

        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $comment = $this->leads->addComment($lead, $user, [
            'body' => $validated['body'],
            'attachment' => $request->file('attachment'),
        ]);

        return response()->json($this->leads->commentToApiArray($comment), 201);
    }

    public function destroyComment(Lead $lead, LeadComment $comment): JsonResponse
    {
        Gate::authorize('update', $lead);

        if ((int) $comment->lead_id !== (int) $lead->id) {
            abort(404);
        }

        $this->leads->deleteComment($lead, $comment, request()->user());

        return response()->json(['message' => 'Note deleted.']);
    }

    public function downloadCommentAttachment(Lead $lead, LeadComment $comment)
    {
        Gate::authorize('view', $lead);

        if ((int) $comment->lead_id !== (int) $lead->id || ! $comment->hasAttachment()) {
            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists((string) $comment->attachment_path)) {
            abort(404);
        }

        return $disk->response(
            (string) $comment->attachment_path,
            $comment->attachment_original_name ?: 'attachment',
            ['Content-Type' => $comment->attachment_mime ?: 'application/octet-stream']
        );
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
