<?php

namespace App\Services;

use App\Models\ClientAccount;
use App\Models\CustomBill;
use App\Models\CustomBillItem;
use App\Models\Project;
use App\Models\ProjectNote;
use App\Models\ProjectQuoteItem;
use App\Models\User;
use App\Support\Billing\CustomBillLineType;
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

            $project = Project::query()->create([
                'pid' => $pid,
                'client_account_id' => $accountId,
                'name' => trim((string) $data['name']),
                'description' => isset($data['description'])
                    ? (trim((string) $data['description']) ?: null)
                    : null,
                'status' => Project::STATUS_PENDING,
                'custom_bill_id' => null,
                'created_by_user_id' => $actor ? $actor->id : null,
                'completed_at' => null,
            ]);

            return $project->fresh(['clientAccount', 'customBill', 'notes.user', 'createdBy', 'quoteItems']);
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
                'quoteItems',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array{name: string, description?: string|null}  $data
     */
    public function update(Project $project, array $data): Project
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => ['Project name is required.'],
            ]);
        }

        $project->name = $name;
        $project->description = array_key_exists('description', $data)
            ? (trim((string) $data['description']) ?: null)
            : $project->description;
        $project->save();

        return $this->findOrFail((int) $project->id);
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

        return $this->findOrFail((int) $project->id);
    }

    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project->notes()->delete();
            $project->quoteItems()->delete();
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
        if ($project->custom_bill_id) {
            $bill = $this->requireLinkedOpenBill($project);
            $this->customBills->addItem($bill, $row, $actor);

            return $this->findOrFail((int) $project->id);
        }

        $normalized = $this->normalizeQuoteRow($row);
        $order = (int) $project->quoteItems()->max('sort_order') + 1;
        ProjectQuoteItem::query()->create([
            'project_id' => $project->id,
            'line_type' => $normalized['line_type'],
            'name' => $normalized['name'],
            'quantity' => $normalized['quantity'],
            'unit_price_cents' => $normalized['unit_price_cents'],
            'line_total_cents' => $normalized['line_total_cents'],
            'sku' => $normalized['sku'],
            'sort_order' => $order,
        ]);

        return $this->findOrFail((int) $project->id);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function updateQuoteItem(Project $project, int $itemId, array $row, ?User $actor): Project
    {
        if ($project->custom_bill_id) {
            $bill = $this->requireLinkedOpenBill($project);
            $item = CustomBillItem::query()
                ->where('custom_bill_id', $bill->id)
                ->whereKey($itemId)
                ->firstOrFail();
            $this->customBills->updateItem($bill, $item, $row, $actor);

            return $this->findOrFail((int) $project->id);
        }

        $item = ProjectQuoteItem::query()
            ->where('project_id', $project->id)
            ->whereKey($itemId)
            ->firstOrFail();

        $normalized = $this->normalizeQuoteRow($row);
        $item->line_type = $normalized['line_type'];
        $item->name = $normalized['name'];
        $item->quantity = $normalized['quantity'];
        $item->unit_price_cents = $normalized['unit_price_cents'];
        $item->line_total_cents = $normalized['line_total_cents'];
        $item->sku = $normalized['sku'];
        $item->save();

        return $this->findOrFail((int) $project->id);
    }

    public function removeQuoteItem(Project $project, int $itemId, ?User $actor): Project
    {
        if ($project->custom_bill_id) {
            $bill = $this->requireLinkedOpenBill($project);
            $item = CustomBillItem::query()
                ->where('custom_bill_id', $bill->id)
                ->whereKey($itemId)
                ->firstOrFail();
            $this->customBills->deleteItem($bill, $item, $actor);

            return $this->findOrFail((int) $project->id);
        }

        $item = ProjectQuoteItem::query()
            ->where('project_id', $project->id)
            ->whereKey($itemId)
            ->firstOrFail();
        $item->delete();

        return $this->findOrFail((int) $project->id);
    }

    public function createBill(Project $project, ?User $actor): Project
    {
        if ($project->custom_bill_id) {
            throw ValidationException::withMessages([
                'custom_bill_id' => ['This project already has a custom bill.'],
            ]);
        }

        return DB::transaction(function () use ($project, $actor) {
            $project->loadMissing('quoteItems');
            $items = $project->quoteItems->map(function (ProjectQuoteItem $item) {
                return [
                    'line_type' => $item->line_type,
                    'name' => $item->name,
                    'quantity' => (float) $item->quantity,
                    'unit_price_cents' => (int) $item->unit_price_cents,
                    'sku' => $item->sku,
                ];
            })->values()->all();

            $bill = $this->customBills->create(
                [
                    'client_account_id' => (int) $project->client_account_id,
                    'bill_date' => now()->toDateString(),
                    'name' => trim((string) $project->name) ?: null,
                ],
                $items,
                $actor
            );

            $project->custom_bill_id = $bill->id;
            $project->save();
            $project->quoteItems()->delete();

            return $this->findOrFail((int) $project->id);
        });
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
            'quoteItems',
        ]);

        $bill = $project->customBill;
        $items = [];
        $quoteTotal = 0;

        if ($bill instanceof CustomBill) {
            $bill->loadMissing('items');
            $items = $bill->items->map(function (CustomBillItem $item) {
                return $this->quoteItemArray(
                    (int) $item->id,
                    (string) $item->line_type,
                    (string) $item->name,
                    (float) $item->quantity,
                    (int) $item->unit_price_cents,
                    (int) $item->line_total_cents,
                    $item->sku,
                    (int) $item->sort_order
                );
            })->values()->all();
            $quoteTotal = (int) $bill->total_cents;
        } else {
            $items = $project->quoteItems->map(function (ProjectQuoteItem $item) {
                return $this->quoteItemArray(
                    (int) $item->id,
                    (string) $item->line_type,
                    (string) $item->name,
                    (float) $item->quantity,
                    (int) $item->unit_price_cents,
                    (int) $item->line_total_cents,
                    $item->sku,
                    (int) $item->sort_order
                );
            })->values()->all();
            $quoteTotal = (int) $project->quoteItems->sum('line_total_cents');
        }

        $quoteOpen = ! ($bill instanceof CustomBill) || $bill->isOpen();

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
            'quote_total_cents' => $quoteTotal,
            'quote_items' => $items,
            'quote_open' => $quoteOpen,
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

    /**
     * @return array{id: int, line_type: string, name: string, quantity: float, unit_price_cents: int, line_total_cents: int, sku: string|null, sort_order: int}
     */
    private function quoteItemArray(
        int $id,
        string $lineType,
        string $name,
        float $quantity,
        int $unitPriceCents,
        int $lineTotalCents,
        $sku,
        int $sortOrder
    ): array {
        return [
            'id' => $id,
            'line_type' => $lineType,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
            'line_total_cents' => $lineTotalCents,
            'sku' => $sku,
            'sort_order' => $sortOrder,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{line_type: string, name: string, quantity: float, unit_price_cents: int, line_total_cents: int, sku: string|null}
     */
    private function normalizeQuoteRow(array $row): array
    {
        $lineType = trim((string) ($row['line_type'] ?? ''));
        if (! CustomBillLineType::isAcceptedLineType($lineType)) {
            throw ValidationException::withMessages(['line_type' => ['Invalid category.']]);
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => ['Name is required.']]);
        }
        $qty = (float) ($row['quantity'] ?? 1);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
        }
        if (isset($row['unit_price_cents'])) {
            $unitCents = (int) $row['unit_price_cents'];
        } else {
            $unitCents = (int) round((float) ($row['unit_price'] ?? 0) * 100);
        }
        $lineTotal = (int) round($qty * $unitCents);
        if ($lineType === CustomBillLineType::CREDIT || $lineType === InvoiceLineCategory::CREDITS) {
            $unitCents = -abs($unitCents);
            $lineTotal = -abs($lineTotal);
        }

        return [
            'line_type' => $lineType,
            'name' => $name,
            'quantity' => $qty,
            'unit_price_cents' => $unitCents,
            'line_total_cents' => $lineTotal,
            'sku' => isset($row['sku']) && trim((string) $row['sku']) !== '' ? trim((string) $row['sku']) : null,
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
