<?php

namespace App\Services;

use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\LeadComment;
use App\Models\LeadFee;
use App\Models\LeadStatusEvent;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use App\Support\LeadQuickAddParser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeadService
{
    /** @var ActivityLogService */
    protected $activityLog;

    /** @var PricingFeeIconService */
    protected $icons;

    /** @var LeadLogoService */
    protected $logos;

    /** @var WebsiteScreenshotService */
    protected $screenshots;

    public function __construct(
        ActivityLogService $activityLog,
        PricingFeeIconService $icons,
        LeadLogoService $logos,
        WebsiteScreenshotService $screenshots
    ) {
        $this->activityLog = $activityLog;
        $this->icons = $icons;
        $this->logos = $logos;
        $this->screenshots = $screenshots;
    }

    /**
     * @return array{statuses: list<string>, follow_up_day_options: list<int>, directory_stats: array<string, int>}
     */
    public function meta(): array
    {
        $counts = Lead::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $directoryStats = [];
        foreach (Lead::DIRECTORY_STATUSES as $status) {
            $directoryStats[$status] = (int) ($counts[$status] ?? 0);
        }
        $directoryStats['total'] = (int) Lead::query()->count();

        return [
            'statuses' => Lead::STATUSES,
            'referrals' => Lead::REFERRALS,
            'follow_up_day_options' => Lead::FOLLOW_UP_DAY_OPTIONS,
            'directory_stats' => $directoryStats,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        if ($perPage < 1) {
            $perPage = 25;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }

        $query = $this->filteredQuery($filters);

        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'follow_up_at')));
        $allowedSort = [
            'company_name',
            'email',
            'website',
            'status',
            'follow_up_days',
            'follow_up_at',
            'created_at',
        ];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'follow_up_at';
        }
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'follow_up_at') {
            // Soonest follow-ups first; Off / null last.
            if ($sortDir === 'asc') {
                $query->orderByRaw('follow_up_at IS NULL ASC')
                    ->orderBy('follow_up_at', 'asc')
                    ->orderByDesc('id');
            } else {
                $query->orderByRaw('follow_up_at IS NULL ASC')
                    ->orderBy('follow_up_at', 'desc')
                    ->orderByDesc('id');
            }
        } else {
            $query->orderBy($sortBy, $sortDir)->orderByDesc('id');
        }

        return $query->paginate($perPage)->through(fn (Lead $lead) => $this->toListArray($lead));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function filteredQuery(array $filters): Builder
    {
        $query = Lead::query();

        $status = strtolower(trim((string) ($filters['status'] ?? 'all')));
        if ($status !== '' && $status !== 'all' && in_array($status, Lead::STATUSES, true)) {
            $query->where('status', $status);
        }

        $referral = strtolower(trim((string) ($filters['referral'] ?? 'all')));
        if ($referral !== '' && $referral !== 'all' && in_array($referral, Lead::REFERRALS, true)) {
            $query->where('referral', $referral);
        }

        $search = trim((string) ($filters['search'] ?? $filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('company_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('website', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?User $actor = null): Lead
    {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email !== '' && $this->emailAlreadyExists($email)) {
            throw ValidationException::withMessages([
                'email' => ['Email already exist'],
            ]);
        }

        $followUpDays = Lead::normalizeFollowUpDays(
            array_key_exists('follow_up_days', $data)
                ? $data['follow_up_days']
                : Lead::DEFAULT_FOLLOW_UP_DAYS
        );

        $lead = DB::transaction(function () use ($data, $followUpDays, $actor) {
            $lead = Lead::query()->create([
                'status' => Lead::STATUS_OPEN,
                'referral' => Lead::normalizeReferral($data['referral'] ?? Lead::REFERRAL_BIZY),
                'company_name' => trim((string) $data['company_name']),
                'email' => trim((string) $data['email']),
                'website' => $this->nullableTrim($data['website'] ?? null),
                'name' => $this->nullableTrim($data['name'] ?? null),
                'comment' => $this->nullableTrim($data['comment'] ?? null),
                'follow_up_days' => $followUpDays,
                'follow_up_at' => $followUpDays !== null
                    ? now()->startOfDay()->addDays($followUpDays)->toDateString()
                    : null,
            ]);

            $this->provisionDefaultFees($lead);

            LeadStatusEvent::query()->create([
                'lead_id' => $lead->id,
                'status' => Lead::STATUS_OPEN,
                'follow_up_days' => $followUpDays,
                'email_template_id' => null,
                'template_name' => null,
                'user_id' => $actor !== null ? $actor->id : null,
                'note' => 'Lead created',
            ]);

            $seedComment = $this->nullableTrim($data['comment'] ?? null);
            if ($seedComment !== null && $actor !== null) {
                LeadComment::query()->create([
                    'lead_id' => $lead->id,
                    'user_id' => $actor->id,
                    'body' => $seedComment,
                ]);
            }

            return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
        });

        if ($actor !== null) {
            $this->activityLog->log($actor, 'lead.created', $lead, null, [
                'company_name' => $lead->company_name,
            ]);
        }

        return $lead;
    }

    public function createFromQuickAddText(string $text, ?User $actor = null, string $referral = Lead::REFERRAL_BIZY): Lead
    {
        $referral = Lead::normalizeReferral($referral);
        $parsed = LeadQuickAddParser::parse($text, $referral);
        $company = trim((string) ($parsed['company_name'] ?? ''));
        $email = trim((string) ($parsed['email'] ?? ''));

        $errors = [];
        if ($company === '') {
            $errors['company_name'] = ['Company is required in the pasted text.'];
        }
        if ($email === '') {
            $errors['email'] = ['Email is required in the pasted text.'];
        } elseif (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['Email in the pasted text is invalid.'];
        } elseif ($this->emailAlreadyExists($email)) {
            $errors['email'] = ['Email already exist'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->create([
            'company_name' => $company,
            'email' => $email,
            'website' => $parsed['website'] ?? null,
            'name' => $parsed['name'] ?? null,
            'comment' => $parsed['comment'] ?? null,
            'referral' => $referral,
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Lead $lead, array $data, ?User $actor = null): Lead
    {
        $fields = [];
        $before = [
            'status' => $lead->status,
            'referral' => $lead->referral,
            'follow_up_days' => $lead->follow_up_days,
            'company_name' => $lead->company_name,
            'email' => $lead->email,
            'website' => $lead->website,
            'name' => $lead->name,
            'comment' => $lead->comment,
            'created_at' => $lead->created_at !== null ? $lead->created_at->toIso8601String() : null,
        ];

        $statusChanged = false;
        $recordStatusEvent = ! empty($data['record_status_event'])
            || array_key_exists('status', $data)
            || array_key_exists('email_template_id', $data);

        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (! in_array($status, Lead::STATUSES, true)) {
                throw new InvalidArgumentException('Invalid lead status.');
            }
            if ($lead->status !== $status) {
                $statusChanged = true;
            }
            $lead->status = $status;
            $fields[] = 'status';
        }

        if (array_key_exists('referral', $data)) {
            $lead->referral = Lead::normalizeReferral($data['referral']);
            $fields[] = 'referral';
        }

        if (array_key_exists('follow_up_days', $data)) {
            $days = Lead::normalizeFollowUpDays($data['follow_up_days']);
            $lead->follow_up_days = $days;
            $lead->follow_up_at = $days !== null
                ? now()->startOfDay()->addDays($days)->toDateString()
                : null;
            $fields[] = 'follow_up_days';
        }

        foreach (['company_name', 'email', 'website', 'name', 'comment'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'company_name' || $key === 'email') {
                $value = trim((string) $data[$key]);
                if ($key === 'email' && $this->emailAlreadyExists($value, (int) $lead->id)) {
                    throw ValidationException::withMessages([
                        'email' => ['Email already exist'],
                    ]);
                }
                $lead->{$key} = $value;
            } else {
                $lead->{$key} = $this->nullableTrim($data[$key]);
            }
            $fields[] = $key;
        }

        if (array_key_exists('created_at', $data) && $data['created_at'] !== null && $data['created_at'] !== '') {
            try {
                $lead->created_at = \Illuminate\Support\Carbon::parse($data['created_at']);
                $fields[] = 'created_at';
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'created_at' => ['Created date is invalid.'],
                ]);
            }
        }

        $templateId = null;
        $templateName = null;
        if (array_key_exists('email_template_id', $data) && $data['email_template_id'] !== null && $data['email_template_id'] !== '' && $data['email_template_id'] !== 'custom') {
            $template = EmailTemplate::query()->find((int) $data['email_template_id']);
            if ($template === null) {
                throw ValidationException::withMessages([
                    'email_template_id' => ['Email template not found.'],
                ]);
            }
            $eventStatus = $lead->status;
            if ($this->hasTemplateUsageForTemplate($lead, (int) $template->id)) {
                throw ValidationException::withMessages([
                    'email_template_id' => ['This template was already used for this lead.'],
                ]);
            }
            if ((string) $template->category !== (string) $eventStatus) {
                throw ValidationException::withMessages([
                    'email_template_id' => ['Template category must match the lead status.'],
                ]);
            }
            $templateId = $template->id;
            $templateName = $template->name;
            $fields[] = 'email_template';
        }

        $lead->save();

        if ($recordStatusEvent && (array_key_exists('status', $data) || $templateId !== null || ! empty($data['record_status_event']))) {
            LeadStatusEvent::query()->create([
                'lead_id' => $lead->id,
                'status' => $lead->status,
                'follow_up_days' => $lead->follow_up_days,
                'email_template_id' => $templateId,
                'template_name' => $templateName,
                'user_id' => $actor !== null ? $actor->id : null,
                'note' => $statusChanged || array_key_exists('status', $data)
                    ? null
                    : ($templateId !== null ? 'Email template used' : null),
            ]);
        }

        if ($actor !== null && $fields !== []) {
            $this->activityLog->log($actor, 'lead.updated', $lead, null, [
                'fields' => array_values(array_unique($fields)),
                'before' => $before,
            ]);
        }

        return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
    }

    public function uploadLogo(Lead $lead, UploadedFile $file, ?User $actor = null, array $options = []): Lead
    {
        $this->logos->replaceForLead($lead, $file, $options);

        if ($actor !== null) {
            $fields = ['logo'];
            if (! empty($options['from_website_thumbnail'])) {
                $fields[] = 'website_thumbnail';
            }
            $this->activityLog->log($actor, 'lead.updated', $lead, null, [
                'fields' => $fields,
            ]);
        }

        return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
    }

    /**
     * Build a thum.io screenshot URL for the lead website (no server-side download).
     * The browser fetches this URL and uploads the image via uploadLogo — avoids Cloudflare 502
     * when the origin waits on thum.io.
     *
     * @return array{website: string, screenshot_url: string}
     */
    public function websiteThumbnailCapturePlan(Lead $lead): array
    {
        $website = trim((string) ($lead->website ?? ''));
        if ($website === '') {
            throw ValidationException::withMessages([
                'website' => ['Add a website on this lead before generating a thumbnail.'],
            ]);
        }

        $url = $this->screenshots->normalizeWebsiteUrl($website);
        if ($url === null) {
            throw ValidationException::withMessages([
                'website' => ['A valid website URL is required to generate a thumbnail.'],
            ]);
        }

        return [
            'website' => $url,
            // Single image URL only — no prefetch (extra hits cause "local rate limited").
            'screenshot_url' => $this->screenshots->buildThumIoUrl($url),
        ];
    }

    public function captureWebsiteThumbnail(Lead $lead, ?User $actor = null): Lead
    {
        $website = trim((string) ($lead->website ?? ''));
        if ($website === '') {
            throw ValidationException::withMessages([
                'website' => ['Add a website on this lead before generating a thumbnail.'],
            ]);
        }

        $bytes = $this->screenshots->captureImageBytes($website);
        $this->logos->replaceFromBytes($lead, $bytes, 'png', [
            'fit' => 'cover',
            'background' => 'white',
            'prefer_top' => true,
        ]);

        if ($actor !== null) {
            $this->activityLog->log($actor, 'lead.updated', $lead, null, [
                'fields' => ['logo', 'website_thumbnail'],
            ]);
        }

        return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
    }

    /**
     * @param  array{body: string, attachment?: UploadedFile|null}  $data
     */
    public function addComment(Lead $lead, User $actor, array $data): LeadComment
    {
        $path = null;
        $original = null;
        $mime = null;
        $size = null;

        if (! empty($data['attachment']) && $data['attachment'] instanceof UploadedFile) {
            $file = $data['attachment'];
            $path = $file->store('lead-comments/'.$lead->id, 'local');
            $original = $file->getClientOriginalName();
            $mime = $file->getClientMimeType();
            $size = (int) $file->getSize();
        }

        try {
            $comment = LeadComment::query()->create([
                'lead_id' => $lead->id,
                'user_id' => $actor->id,
                'body' => trim((string) $data['body']),
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

        $this->activityLog->log($actor, 'lead.updated', $lead, null, [
            'fields' => ['notes'],
            'comment_id' => $comment->id,
        ]);

        $comment->load(['user:id,name,email', 'user.profile:id,user_id,avatar_path']);

        return $comment;
    }

    public function deleteComment(Lead $lead, LeadComment $comment, ?User $actor = null): void
    {
        if ((int) $comment->lead_id !== (int) $lead->id) {
            throw new InvalidArgumentException('Comment does not belong to this lead.');
        }

        $path = $comment->attachment_path;
        $comment->delete();

        if ($path !== null && $path !== '') {
            Storage::disk('local')->delete((string) $path);
        }

        if ($actor !== null) {
            $this->activityLog->log($actor, 'lead.updated', $lead, null, [
                'fields' => ['notes'],
                'deleted_comment_id' => $comment->id,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function commentToApiArray(LeadComment $comment): array
    {
        $u = $comment->relationLoaded('user') ? $comment->user : null;
        $avatarUrl = null;
        if ($u !== null && $u->relationLoaded('profile') && $u->profile !== null) {
            $avatarUrl = $u->profile->avatar_url;
        }

        return [
            'id' => $comment->id,
            'user_id' => $comment->user_id,
            'body' => $comment->body,
            'created_at' => $comment->created_at !== null ? $comment->created_at->toIso8601String() : null,
            'updated_at' => $comment->updated_at !== null ? $comment->updated_at->toIso8601String() : null,
            'user' => $u !== null
                ? [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'avatar_url' => $avatarUrl,
                ]
                : null,
            'attachment' => $comment->hasAttachment()
                ? [
                    'original_name' => $comment->attachment_original_name,
                    'mime' => $comment->attachment_mime,
                    'size' => $comment->attachment_size,
                ]
                : null,
        ];
    }

    public function hasTemplateUsageForStatus(Lead $lead, string $status): bool
    {
        return LeadStatusEvent::query()
            ->where('lead_id', $lead->id)
            ->where('status', $status)
            ->whereNotNull('email_template_id')
            ->exists();
    }

    public function hasTemplateUsageForTemplate(Lead $lead, int $emailTemplateId): bool
    {
        if ($emailTemplateId <= 0) {
            return false;
        }

        return LeadStatusEvent::query()
            ->where('lead_id', $lead->id)
            ->where('email_template_id', $emailTemplateId)
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function statusesWithTemplateUsage(Lead $lead): array
    {
        return LeadStatusEvent::query()
            ->where('lead_id', $lead->id)
            ->whereNotNull('email_template_id')
            ->distinct()
            ->pluck('status')
            ->map(fn ($s) => (string) $s)
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{last_sent_at: string|null, status: string, template_name: string|null}>
     */
    public function templateUsageMap(Lead $lead): array
    {
        $events = LeadStatusEvent::query()
            ->where('lead_id', $lead->id)
            ->whereNotNull('email_template_id')
            ->orderByDesc('id')
            ->get(['email_template_id', 'template_name', 'status', 'created_at']);

        $map = [];
        foreach ($events as $event) {
            $id = (int) $event->email_template_id;
            if (isset($map[$id])) {
                continue;
            }
            $map[$id] = [
                'last_sent_at' => $event->created_at !== null ? $event->created_at->toIso8601String() : null,
                'status' => (string) $event->status,
                'template_name' => $event->template_name,
            ];
        }

        return $map;
    }

    public function delete(Lead $lead, ?User $actor = null): void
    {
        if ($actor !== null) {
            $this->activityLog->log($actor, 'lead.deleted', $lead, null, [
                'company_name' => $lead->company_name,
            ]);
        }

        $lead->delete();
    }

    public function provisionDefaultFees(Lead $lead): void
    {
        $templates = PricingFeeTemplate::query()->orderBy('sort_order')->orderBy('id')->get();
        foreach ($templates as $template) {
            if (! PricingFeeTemplate::isAccountScheduleCategory((string) $template->category)) {
                continue;
            }
            $this->provisionTemplateForLead($lead, $template);
        }
    }

    public function provisionTemplateForLead(Lead $lead, PricingFeeTemplate $template): ?LeadFee
    {
        if (! PricingFeeTemplate::isAccountScheduleCategory((string) $template->category)) {
            return null;
        }

        $feeGroup = PricingFeeTemplate::categoryToFeeGroup($template->category);
        $lineCode = 'template_'.$template->id;

        $existing = LeadFee::query()
            ->where('lead_id', $lead->id)
            ->where('pricing_template_id', $template->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LeadFee::query()->create([
            'lead_id' => $lead->id,
            'pricing_template_id' => $template->id,
            'fee_group' => $feeGroup,
            'line_code' => $lineCode,
            'label' => $template->name,
            'description' => $template->description,
            'icon_path' => $template->icon_path,
            'amount' => $template->amount,
            'currency' => 'USD',
            'sort_order' => (int) $template->sort_order,
        ]);
    }

    /**
     * @param  array{amount?: float|null, cost?: float|null}|null  $fields
     */
    public function updateFeeAmount(
        Lead $lead,
        LeadFee $fee,
        ?float $amount,
        ?User $actor = null,
        ?array $fields = null
    ): Lead {
        if ((int) $fee->lead_id !== (int) $lead->id) {
            throw new InvalidArgumentException('Fee does not belong to this lead.');
        }

        $payload = ['amount' => $this->normalizeFeeAmount($amount)];
        if (is_array($fields) && array_key_exists('cost', $fields)) {
            $payload['cost'] = $this->normalizeFeeAmount($fields['cost']);
        }

        $fee->update($payload);

        if ($actor !== null) {
            $this->activityLog->log($actor, 'lead.updated', $lead, null, [
                'fields' => ['fees'],
            ]);
        }

        return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function feesPayloadForApi(Lead $lead, bool $withCost = true): array
    {
        $lead->loadMissing(['feeItems.pricingTemplate']);
        $items = $lead->feeItems
            ->filter(fn ($fee) => $fee instanceof LeadFee)
            ->filter(function (LeadFee $fee) {
                return PricingFeeTemplate::isAccountScheduleCategory((string) $fee->fee_group);
            })
            ->sortBy(function (LeadFee $fee) {
                $group = (string) $fee->fee_group;
                $categoryIndex = array_search($group, PricingFeeTemplate::CATEGORIES, true);
                if ($categoryIndex === false) {
                    $categoryIndex = 999;
                }

                return [$categoryIndex, (int) $fee->sort_order, (int) $fee->id];
            })
            ->values()
            ->map(fn (LeadFee $fee) => $this->feeItemPayload($fee, $withCost))
            ->all();

        return ['items' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function toListArray(Lead $lead): array
    {
        $followUpDays = $lead->follow_up_days;
        if ($followUpDays !== null) {
            $followUpDays = (int) $followUpDays;
        }

        return [
            'id' => $lead->id,
            'status' => $lead->status,
            'status_label' => Lead::statusLabel((string) $lead->status),
            'referral' => Lead::normalizeReferral($lead->referral ?? Lead::REFERRAL_BIZY),
            'referral_label' => Lead::referralLabel((string) ($lead->referral ?? Lead::REFERRAL_BIZY)),
            'company_name' => $lead->company_name,
            'email' => $lead->email,
            'website' => $lead->website,
            'name' => $lead->name,
            'comment' => $lead->comment,
            'logo_url' => $this->logos->publicUrl($lead->logo_path),
            'follow_up_days' => $followUpDays,
            'follow_up_at' => $lead->follow_up_at !== null
                ? $lead->follow_up_at->toDateString()
                : null,
            'follow_up_label' => Lead::followUpRemainingLabel($lead->follow_up_at, $followUpDays),
            'created_at' => $lead->created_at !== null ? $lead->created_at->toIso8601String() : null,
            'updated_at' => $lead->updated_at !== null ? $lead->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetailArray(Lead $lead): array
    {
        $payload = $this->toListArray($lead);
        $payload['fees'] = $this->feesPayloadForApi($lead, true);

        $lead->loadMissing([
            'comments.user.profile',
            'statusEvents.user.profile',
        ]);

        $payload['comments'] = $lead->comments
            ->map(fn (LeadComment $c) => $this->commentToApiArray($c))
            ->values()
            ->all();

        $events = $lead->statusEvents->sortBy('id')->values();
        $currentEventId = optional($events->last())->id;
        $payload['status_events'] = $events
            ->map(fn (LeadStatusEvent $event) => $this->statusEventToApiArray($event, $currentEventId))
            ->all();

        $payload['templates_used_statuses'] = $this->statusesWithTemplateUsage($lead);
        $payload['template_usages'] = $this->templateUsageMap($lead);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  int|string|null  $currentEventId
     * @return array<string, mixed>
     */
    public function statusEventToApiArray(LeadStatusEvent $event, $currentEventId = null): array
    {
        $u = $event->relationLoaded('user') ? $event->user : null;

        return [
            'id' => $event->id,
            'status' => $event->status,
            'status_label' => Lead::statusLabel((string) $event->status),
            'follow_up_days' => $event->follow_up_days !== null ? (int) $event->follow_up_days : null,
            'email_template_id' => $event->email_template_id,
            'template_name' => $event->template_name,
            'note' => $event->note,
            'is_current' => $currentEventId !== null && (int) $event->id === (int) $currentEventId,
            'user_id' => $event->user_id,
            'user_name' => $u !== null ? $u->name : null,
            'created_at' => $event->created_at !== null ? $event->created_at->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function feeItemPayload(LeadFee $fee, bool $withCost): array
    {
        $category = (string) $fee->fee_group;
        $amount = $fee->amount !== null && $fee->amount !== '' ? (float) $fee->amount : null;

        $payload = [
            'id' => $fee->id,
            'name' => $fee->label !== null && trim((string) $fee->label) !== ''
                ? trim((string) $fee->label)
                : 'Fee',
            'description' => $fee->description,
            'category' => $category,
            'category_label' => PricingFeeTemplate::categoryLabel($category),
            'amount' => $amount,
            'icon_url' => $this->icons->publicUrl($fee->icon_path),
            'pricing_template_id' => $fee->pricing_template_id,
            'sort_order' => (int) $fee->sort_order,
            'line_code' => $fee->line_code,
        ];

        if ($withCost) {
            $defaultCost = null;
            if ($fee->relationLoaded('pricingTemplate') && $fee->pricingTemplate !== null) {
                $defaultCost = $fee->pricingTemplate->cost !== null
                    ? (float) $fee->pricingTemplate->cost
                    : null;
            }
            $isOverride = $fee->cost !== null && $fee->cost !== '';
            $payload['cost'] = $isOverride ? (float) $fee->cost : $defaultCost;
            $payload['default_cost'] = $defaultCost;
            $payload['cost_is_override'] = $isOverride;
        }

        return $payload;
    }

    /**
     * @param  mixed  $amount
     */
    private function normalizeFeeAmount($amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if (! is_numeric($amount)) {
            return null;
        }

        return number_format((float) $amount, 4, '.', '');
    }

    private function emailAlreadyExists(string $email, ?int $ignoreLeadId = null): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $query = Lead::query()->whereRaw('LOWER(email) = ?', [$normalized]);
        if ($ignoreLeadId !== null && $ignoreLeadId > 0) {
            $query->where('id', '!=', $ignoreLeadId);
        }

        return $query->exists();
    }

    /**
     * @param  mixed  $value
     */
    private function nullableTrim($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim((string) $value);

        return $t === '' ? null : $t;
    }
}
