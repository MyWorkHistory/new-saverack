<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Models\CustomBillItem;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /** @var CustomBillService */
    private $customBills;

    public function __construct(CustomBillService $customBills)
    {
        $this->customBills = $customBills;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function paginate(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $sortBy = in_array($filters['sort_by'] ?? '', ['pid', 'name', 'status', 'created_at', 'completed_at', 'id'], true)
            ? (string) $filters['sort_by']
            : 'created_at';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = Project::query()
            ->with(['clientAccount:id,company_name']);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['client_account_id'])) {
            $query->where('client_account_id', (int) $filters['client_account_id']);
        }

        $search = trim((string) ($filters['q'] ?? $filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                $q->where('pid', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhereHas('clientAccount', function (Builder $aq) use ($search) {
                        $aq->where('company_name', 'like', '%'.$search.'%');
                    });
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query->orderBy($sortBy, $sortDir)->paginate($perPage);

        return [
            'data' => collect($paginator->items())->map(function (Project $project) {
                return $this->toListArray($project);
            })->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * @return array{pending: int, in_progress: int, completed: int}
     */
    public function summary(): array
    {
        $counts = Project::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'pending' => (int) ($counts[Project::STATUS_PENDING] ?? 0),
            'in_progress' => (int) ($counts[Project::STATUS_IN_PROGRESS] ?? 0),
            'completed' => (int) ($counts[Project::STATUS_COMPLETED] ?? 0),
        ];
    }

    /**
     * @param  array{client_account_id: int, name: string, description?: string|null}  $data
     */
    public function create(array $data, ?User $actor): Project
    {
        $accountId = (int) $data['client_account_id'];
        if (! ClientAccount::query()->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages([
                'client_account_id' => ['Account not found.'],
            ]);
        }

        return DB::transaction(function () use ($data, $actor, $accountId) {
            $pid = $this->nextPid();

            $bill = $this->customBills->create(
                [
                    'client_account_id' => $accountId,
                    'bill_date' => now()->toDateString(),
                    'name' => $pid,
                ],
                [],
                $actor
            );

            $project = Project::query()->create([
                'pid' => $pid,
                'client_account_id' => $accountId,
                'name' => trim((string) $data['name']),
                'description' => isset($data['description'])
                    ? (trim((string) $data['description']) ?: null)
                    : null,
                'status' => Project::STATUS_PENDING,
                'custom_bill_id' => $bill->id,
                'created_by_user_id' => $actor ? $actor->id : null,
                'completed_at' => null,
            ]);

            return $project->fresh(['clientAccount', 'customBill', 'notes.user', 'createdBy']);
        });
    }

    public function findOrFail(int $id): Project
    {
        return Project::query()
            ->with([
                'clientAccount:id,company_name,email',
                'customBill.items',
                'notes.user',
                'createdBy:id,name',
            ])
            ->findOrFail($id);
    }

    public function updateStatus(Project $project, string $status): Project
    {
        if (! in_array($status, Project::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid status.'],
            ]);
        }

        $project->status = $status;
        if ($status === Project::STATUS_COMPLETED) {
            $project->completed_at = $project->completed_at ?? now();
        } else {
            $project->completed_at = null;
        }
        $project->save();

        return $project->fresh(['clientAccount', 'customBill.items', 'notes.user', 'createdBy']);
    }

    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project->notes()->delete();
            $project->custom_bill_id = null;
            $project->save();
            $project->delete();
        });
    }

    public function addNote(Project $project, string $body, ?User $actor): ProjectNote
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['Note cannot be empty.'],
            ]);
        }

        return ProjectNote::query()->create([
            'project_id' => $project->id,
            'user_id' => $actor ? $actor->id : null,
            'body' => $body,
        ])->fresh('user');
    }

    public function updateNote(ProjectNote $note, string $body): ProjectNote
    {
        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages([
                'body' => ['Note cannot be empty.'],
            ]);
        }

        $note->body = $body;
        $note->save();

        return $note->fresh('user');
    }

    public function deleteNote(ProjectNote $note): void
    {
        $note->delete();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addQuoteItem(Project $project, array $row, ?User $actor): Project
    {
        $bill = $this->requireLinkedOpenBill($project);
        $this->customBills->addItem($bill, $row, $actor);

        return $this->findOrFail((int) $project->id);
    }

    public function removeQuoteItem(Project $project, int $itemId, ?User $actor): Project
    {
        $bill = $this->requireLinkedOpenBill($project);
        $item = CustomBillItem::query()
            ->where('custom_bill_id', $bill->id)
            ->whereKey($itemId)
            ->firstOrFail();
        $this->customBills->deleteItem($bill, $item, $actor);

        return $this->findOrFail((int) $project->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toListArray(Project $project): array
    {
        $project->loadMissing('clientAccount');

        return [
            'id' => $project->id,
            'pid' => $project->pid,
            'name' => $project->name,
            'status' => $project->status,
            'status_label' => $this->statusLabel((string) $project->status),
            'client_account_id' => $project->client_account_id,
            'client_account_name' => $project->clientAccount
                ? (string) $project->clientAccount->company_name
                : '',
            'created_at' => $project->created_at ? $project->created_at->toIso8601String() : null,
            'completed_at' => $project->completed_at ? $project->completed_at->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetailArray(Project $project): array
    {
        $project->loadMissing([
            'clientAccount',
            'customBill.items',
            'notes.user',
            'createdBy',
        ]);

        $bill = $project->customBill;
        $items = [];
        if ($bill instanceof CustomBill) {
            $bill->loadMissing('items');
            $items = $bill->items->map(function (CustomBillItem $item) {
                return [
                    'id' => $item->id,
                    'line_type' => $item->line_type,
                    'name' => $item->name,
                    'quantity' => (float) $item->quantity,
                    'unit_price_cents' => (int) $item->unit_price_cents,
                    'line_total_cents' => (int) $item->line_total_cents,
                    'sku' => $item->sku,
                    'sort_order' => (int) $item->sort_order,
                ];
            })->values()->all();
        }

        return [
            'id' => $project->id,
            'pid' => $project->pid,
            'name' => $project->name,
            'description' => $project->description,
            'status' => $project->status,
            'status_label' => $this->statusLabel((string) $project->status),
            'client_account_id' => $project->client_account_id,
            'client_account_name' => $project->clientAccount
                ? (string) $project->clientAccount->company_name
                : '',
            'custom_bill_id' => $project->custom_bill_id,
            'custom_bill_number' => $bill ? $bill->bill_number : null,
            'custom_bill_name' => $bill ? $bill->name : null,
            'custom_bill_status' => $bill ? $bill->status : null,
            'quote_total_cents' => $bill ? (int) $bill->total_cents : 0,
            'quote_items' => $items,
            'quote_open' => $bill instanceof CustomBill && $bill->isOpen(),
            'category_options' => collect(InvoiceLineCategory::staffUiValues())->map(function (string $value) {
                return [
                    'value' => $value,
                    'label' => $this->categoryLabel($value),
                ];
            })->values()->all(),
            'notes' => $project->notes->map(function (ProjectNote $note) {
                return $this->noteToArray($note);
            })->values()->all(),
            'created_by_name' => $project->createdBy ? $project->createdBy->name : null,
            'created_at' => $project->created_at ? $project->created_at->toIso8601String() : null,
            'completed_at' => $project->completed_at ? $project->completed_at->toIso8601String() : null,
            'updated_at' => $project->updated_at ? $project->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function noteToArray(ProjectNote $note): array
    {
        $note->loadMissing('user');

        return [
            'id' => $note->id,
            'body' => $note->body,
            'user_id' => $note->user_id,
            'user_name' => $note->user ? $note->user->name : 'Staff',
            'created_at' => $note->created_at ? $note->created_at->toIso8601String() : null,
            'updated_at' => $note->updated_at ? $note->updated_at->toIso8601String() : null,
        ];
    }

    private function requireLinkedOpenBill(Project $project): CustomBill
    {
        $billId = (int) ($project->custom_bill_id ?? 0);
        if ($billId <= 0) {
            throw ValidationException::withMessages([
                'custom_bill_id' => ['This project has no linked custom bill.'],
            ]);
        }

        $bill = CustomBill::query()->find($billId);
        if (! $bill instanceof CustomBill) {
            throw ValidationException::withMessages([
                'custom_bill_id' => ['Linked custom bill was not found.'],
            ]);
        }
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages([
                'custom_bill_id' => ['Quote cannot be changed after the custom bill is invoiced.'],
            ]);
        }

        return $bill;
    }

    private function nextPid(): string
    {
        $max = Project::query()->lockForUpdate()->pluck('pid');
        $highest = Project::FIRST_PID_NUMBER - 1;
        foreach ($max as $pid) {
            if (preg_match('/^P-(\d+)$/i', (string) $pid, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return 'P-'.($highest + 1);
    }

    private function statusLabel(string $status): string
    {
        switch ($status) {
            case Project::STATUS_IN_PROGRESS:
                return 'In-Progress';
            case Project::STATUS_COMPLETED:
                return 'Completed';
            default:
                return 'Pending';
        }
    }

    private function categoryLabel(string $value): string
    {
        $labels = [
            InvoiceLineCategory::FULFILLMENT => 'Fulfillment',
            InvoiceLineCategory::WHOLESALE => 'Wholesale',
            InvoiceLineCategory::POSTAGE => 'Postage',
            InvoiceLineCategory::PACKAGING => 'Packaging',
            InvoiceLineCategory::RETURNS => 'Returns',
            InvoiceLineCategory::AD_HOC => 'Ad Hoc',
            'bank fee' => 'Bank Fee',
            'duties & taxes' => 'Duties & Taxes',
            InvoiceLineCategory::STORAGE => 'Storage',
            InvoiceLineCategory::ON_DEMAND => 'On Demand',
            InvoiceLineCategory::RECEIVING => 'Receiving',
            InvoiceLineCategory::CREDITS => 'Credits',
            InvoiceLineCategory::OTHER => 'Other',
        ];

        return $labels[$value] ?? ucwords(str_replace('_', ' ', $value));
    }
}
