<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadFee;
use App\Models\PricingFeeTemplate;
use App\Models\User;
use App\Support\LeadQuickAddParser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeadService
{
    /** @var ActivityLogService */
    protected $activityLog;

    /** @var PricingFeeIconService */
    protected $icons;

    public function __construct(ActivityLogService $activityLog, PricingFeeIconService $icons)
    {
        $this->activityLog = $activityLog;
        $this->icons = $icons;
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

        $sortBy = strtolower(trim((string) ($filters['sort_by'] ?? 'created_at')));
        $allowedSort = [
            'company_name',
            'email',
            'website',
            'status',
            'follow_up_days',
            'created_at',
        ];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }
        $sortDir = strtolower(trim((string) ($filters['sort_dir'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortBy, $sortDir)->orderByDesc('id');

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
        $followUpDays = Lead::normalizeFollowUpDays(
            $data['follow_up_days'] ?? Lead::DEFAULT_FOLLOW_UP_DAYS
        );

        $lead = DB::transaction(function () use ($data, $followUpDays) {
            $lead = Lead::query()->create([
                'status' => Lead::STATUS_OPEN,
                'company_name' => trim((string) $data['company_name']),
                'email' => trim((string) $data['email']),
                'website' => $this->nullableTrim($data['website'] ?? null),
                'name' => $this->nullableTrim($data['name'] ?? null),
                'comment' => $this->nullableTrim($data['comment'] ?? null),
                'follow_up_days' => $followUpDays,
                'follow_up_at' => now()->startOfDay()->addDays($followUpDays)->toDateString(),
            ]);

            $this->provisionDefaultFees($lead);

            return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
        });

        if ($actor !== null) {
            $this->activityLog->log($actor, 'lead.created', $lead, null, [
                'company_name' => $lead->company_name,
            ]);
        }

        return $lead;
    }

    public function createFromQuickAddText(string $text, ?User $actor = null): Lead
    {
        $parsed = LeadQuickAddParser::parse($text);
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
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->create([
            'company_name' => $company,
            'email' => $email,
            'website' => $parsed['website'] ?? null,
            'comment' => $parsed['comment'] ?? null,
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
            'follow_up_days' => $lead->follow_up_days,
            'company_name' => $lead->company_name,
            'email' => $lead->email,
            'website' => $lead->website,
            'name' => $lead->name,
            'comment' => $lead->comment,
        ];

        if (array_key_exists('status', $data)) {
            $status = strtolower(trim((string) $data['status']));
            if (! in_array($status, Lead::STATUSES, true)) {
                throw new InvalidArgumentException('Invalid lead status.');
            }
            $lead->status = $status;
            $fields[] = 'status';
        }

        if (array_key_exists('follow_up_days', $data)) {
            $days = Lead::normalizeFollowUpDays($data['follow_up_days']);
            $lead->follow_up_days = $days;
            $lead->follow_up_at = now()->startOfDay()->addDays($days)->toDateString();
            $fields[] = 'follow_up_days';
        }

        foreach (['company_name', 'email', 'website', 'name', 'comment'] as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            if ($key === 'company_name' || $key === 'email') {
                $lead->{$key} = trim((string) $data[$key]);
            } else {
                $lead->{$key} = $this->nullableTrim($data[$key]);
            }
            $fields[] = $key;
        }

        $lead->save();

        if ($actor !== null && $fields !== []) {
            $this->activityLog->log($actor, 'lead.updated', $lead, null, [
                'fields' => array_values(array_unique($fields)),
                'before' => $before,
            ]);
        }

        return $lead->fresh(['feeItems.pricingTemplate']) ?? $lead;
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
        return [
            'id' => $lead->id,
            'status' => $lead->status,
            'status_label' => Lead::statusLabel((string) $lead->status),
            'company_name' => $lead->company_name,
            'email' => $lead->email,
            'website' => $lead->website,
            'name' => $lead->name,
            'comment' => $lead->comment,
            'follow_up_days' => (int) $lead->follow_up_days,
            'follow_up_at' => $lead->follow_up_at !== null
                ? $lead->follow_up_at->toDateString()
                : null,
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

        return $payload;
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

    private function normalizeFeeAmount(mixed $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        if (! is_numeric($amount)) {
            return null;
        }

        return number_format((float) $amount, 4, '.', '');
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $t = trim((string) $value);

        return $t === '' ? null : $t;
    }
}
