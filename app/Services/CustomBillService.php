<?php

namespace App\Services;

use App\Models\CustomBill;
use App\Models\CustomBillHistory;
use App\Models\CustomBillItem;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing\CustomBillLineType;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomBillService
{
    /** @var InvoiceService */
    private $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function paginate(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $sortBy = in_array($filters['sort_by'] ?? '', ['bill_number', 'bill_date', 'total_cents', 'status', 'id'], true)
            ? (string) $filters['sort_by']
            : 'id';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = CustomBill::query()
            ->with(['clientAccount:id,company_name']);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['client_account_id'])) {
            $query->where('client_account_id', (int) $filters['client_account_id']);
        }
        if (! empty($filters['bill_number'])) {
            $query->where('bill_number', (int) $filters['bill_number']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('bill_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('bill_date', '<=', (string) $filters['date_to']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                if (ctype_digit($search)) {
                    $q->orWhere('bill_number', (int) $search);
                }
                $q->orWhereHas('clientAccount', function (Builder $cq) use ($search) {
                    $cq->where('company_name', 'like', '%'.$search.'%');
                });
            });
        }

        if ($sortBy === 'bill_date') {
            $query->orderBy('bill_date', $sortDir)->orderByDesc('id');
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map(function (CustomBill $bill) {
                return $this->toListArray($bill);
            })->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function create(array $header, array $items, ?User $actor): CustomBill
    {
        return DB::transaction(function () use ($header, $items, $actor) {
            $bill = CustomBill::query()->create([
                'bill_number' => $this->nextBillNumber(),
                'status' => CustomBill::STATUS_OPEN,
                'client_account_id' => (int) $header['client_account_id'],
                'bill_date' => $header['bill_date'],
                'total_cents' => 0,
                'created_by_user_id' => $actor ? $actor->id : null,
            ]);

            $order = 0;
            foreach ($items as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $order++;
                $this->insertItem($bill, $order, $row);
            }
            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'created', 'Custom bill created.');

            return $bill->fresh(['items', 'clientAccount', 'histories.user']);
        });
    }

    public function updateHeader(CustomBill $bill, array $data, ?User $actor): CustomBill
    {
        $this->assertOpen($bill);

        return DB::transaction(function () use ($bill, $data, $actor) {
            if (isset($data['bill_date'])) {
                $bill->bill_date = $data['bill_date'];
            }
            $bill->save();
            $this->logHistory($bill, $actor, 'updated', 'Bill details updated.');

            return $bill->fresh(['items', 'clientAccount', 'histories.user']);
        });
    }

    public function delete(CustomBill $bill, ?User $actor): void
    {
        $this->assertOpen($bill);

        DB::transaction(function () use ($bill) {
            $bill->items()->delete();
            $bill->histories()->delete();
            $bill->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addItem(CustomBill $bill, array $row, ?User $actor): CustomBill
    {
        $this->assertOpen($bill);

        return DB::transaction(function () use ($bill, $row, $actor) {
            $order = (int) $bill->items()->max('sort_order') + 1;
            $this->insertItem($bill, $order, $row);
            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'line_add', 'Added bill line item.');

            return $bill->fresh(['items', 'clientAccount', 'histories.user']);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function updateItem(CustomBill $bill, CustomBillItem $item, array $row, ?User $actor): CustomBill
    {
        $this->assertOpen($bill);
        if ((int) $item->custom_bill_id !== (int) $bill->id) {
            throw new \InvalidArgumentException('Line item does not belong to this bill.');
        }

        return DB::transaction(function () use ($bill, $item, $row, $actor) {
            $normalized = $this->normalizeItemRow($row);
            $item->fill($normalized);
            $item->save();
            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'line_edit', 'Updated bill line item.');

            return $bill->fresh(['items', 'clientAccount', 'histories.user']);
        });
    }

    public function deleteItem(CustomBill $bill, CustomBillItem $item, ?User $actor): CustomBill
    {
        $this->assertOpen($bill);
        if ((int) $item->custom_bill_id !== (int) $bill->id) {
            throw new \InvalidArgumentException('Line item does not belong to this bill.');
        }

        return DB::transaction(function () use ($bill, $item, $actor) {
            $item->delete();
            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'line_delete', 'Removed bill line item.');

            return $bill->fresh(['items', 'clientAccount', 'histories.user']);
        });
    }

    public function updateStatus(CustomBill $bill, string $status, ?User $actor): CustomBill
    {
        if (! in_array($status, [CustomBill::STATUS_OPEN, CustomBill::STATUS_INVOICED], true)) {
            throw ValidationException::withMessages(['status' => 'Invalid status.']);
        }

        return DB::transaction(function () use ($bill, $status, $actor) {
            if ($status === CustomBill::STATUS_OPEN && $bill->isInvoiced()) {
                $bill->status = CustomBill::STATUS_OPEN;
                $bill->invoice_id = null;
                $bill->save();
                $this->logHistory($bill, $actor, 'status', 'Bill marked as Open.');

                return $bill->fresh(['items', 'clientAccount', 'histories.user', 'invoice']);
            }

            throw ValidationException::withMessages([
                'status' => 'Use Add To Invoice to mark a bill as Invoiced.',
            ]);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDraftInvoicesForAccount(CustomBill $bill): array
    {
        return Invoice::query()
            ->where('client_account_id', $bill->client_account_id)
            ->where('status', Invoice::STATUS_DRAFT)
            ->orderByDesc('id')
            ->get(['id', 'invoice_number', 'total_cents', 'balance_due_cents'])
            ->map(static function (Invoice $inv) {
                return [
                    'id' => $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'total_cents' => (int) $inv->total_cents,
                    'balance_due_cents' => (int) $inv->balance_due_cents,
                ];
            })
            ->values()
            ->all();
    }

    public function addToInvoice(CustomBill $bill, int $invoiceId, ?User $actor): CustomBill
    {
        $this->assertOpen($bill);
        $bill->loadMissing('items');

        if ($bill->items->isEmpty()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'Add at least one line item before adding to an invoice.',
            ]);
        }

        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice not found.']);
        }
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['invoice_id' => 'Only draft invoices can receive custom bill lines.']);
        }
        if ((int) $invoice->client_account_id !== (int) $bill->client_account_id) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice must belong to the same account as this bill.']);
        }

        return DB::transaction(function () use ($bill, $invoice, $actor) {
            foreach ($bill->items as $item) {
                $category = CustomBillLineType::toInvoiceCategory((string) $item->line_type);
                $qty = (float) $item->quantity;
                $unitCents = (int) $item->unit_price_cents;
                $lineTotal = (int) $item->line_total_cents;
                if ($category === InvoiceLineCategory::CREDITS && $lineTotal > 0) {
                    $unitCents = -abs($unitCents);
                    $lineTotal = -abs($lineTotal);
                }

                $name = trim((string) $item->name);
                $this->invoices->addInvoiceItem($invoice, [
                    'description' => $name,
                    'display_name' => $name,
                    'category' => $category,
                    'subtype' => (string) $item->line_type,
                    'sku' => $item->sku,
                    'quantity' => $qty,
                    'unit_price_cents' => $unitCents,
                    'line_total_cents' => $lineTotal,
                    'group_key' => 'custom_bill:'.(int) $bill->id,
                    'metadata' => [
                        'source' => 'custom_bill',
                        'custom_bill_id' => (int) $bill->id,
                        'custom_bill_item_id' => (int) $item->id,
                        'custom_bill_number' => (int) $bill->bill_number,
                    ],
                ], $actor);
                $invoice = $invoice->fresh();
            }

            $bill->status = CustomBill::STATUS_INVOICED;
            $bill->invoice_id = $invoice->id;
            $bill->save();

            $this->logHistory($bill, $actor, 'invoiced', 'Added bill lines to invoice #'.$invoice->invoice_number.'.', [
                'invoice_id' => $invoice->id,
            ]);

            return $bill->fresh(['items', 'clientAccount', 'histories.user', 'invoice']);
        });
    }

    public function toDetailArray(CustomBill $bill): array
    {
        $bill->loadMissing(['items', 'clientAccount', 'histories.user', 'invoice']);

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'status' => $bill->status,
            'status_label' => $bill->isOpen() ? 'Open' : 'Invoiced',
            'client_account_id' => $bill->client_account_id,
            'client_account_name' => $bill->clientAccount ? $bill->clientAccount->company_name : '',
            'bill_date' => $bill->bill_date ? $bill->bill_date->format('Y-m-d') : null,
            'total_cents' => (int) $bill->total_cents,
            'invoice_id' => $bill->invoice_id,
            'invoice_number' => $bill->invoice ? $bill->invoice->invoice_number : null,
            'items' => $bill->items->map(function (CustomBillItem $item) {
                return $this->itemToArray($item);
            })->values()->all(),
            'histories' => $bill->histories->map(function (CustomBillHistory $h) {
                return [
                    'id' => $h->id,
                    'event_type' => $h->event_type,
                    'message' => $h->message,
                    'actor_name' => $h->actor_name ?: ($h->user ? $h->user->name : 'System'),
                    'created_at' => $h->created_at ? $h->created_at->toIso8601String() : null,
                ];
            })->values()->all(),
            'line_types' => CustomBillLineType::all(),
            'created_at' => $bill->created_at ? $bill->created_at->toIso8601String() : null,
            'updated_at' => $bill->updated_at ? $bill->updated_at->toIso8601String() : null,
        ];
    }

    private function toListArray(CustomBill $bill): array
    {
        $bill->loadMissing('clientAccount');

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'status' => $bill->status,
            'status_label' => $bill->isOpen() ? 'Open' : 'Invoiced',
            'client_account_id' => $bill->client_account_id,
            'client_account_name' => $bill->clientAccount ? $bill->clientAccount->company_name : '',
            'bill_date' => $bill->bill_date ? $bill->bill_date->format('Y-m-d') : null,
            'total_cents' => (int) $bill->total_cents,
            'invoice_id' => $bill->invoice_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function itemToArray(CustomBillItem $item): array
    {
        return [
            'id' => $item->id,
            'line_type' => $item->line_type,
            'name' => $item->name,
            'quantity' => (float) $item->quantity,
            'unit_price_cents' => (int) $item->unit_price_cents,
            'line_total_cents' => (int) $item->line_total_cents,
            'sku' => $item->sku,
            'metadata' => $item->metadata,
            'sort_order' => (int) $item->sort_order,
        ];
    }

    private function nextBillNumber(): int
    {
        return DB::transaction(function () {
            $max = CustomBill::query()->lockForUpdate()->max('bill_number');
            if ($max === null) {
                return CustomBill::FIRST_BILL_NUMBER;
            }

            return max(CustomBill::FIRST_BILL_NUMBER, (int) $max + 1);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertItem(CustomBill $bill, int $sortOrder, array $row): CustomBillItem
    {
        $normalized = $this->normalizeItemRow($row);
        $normalized['custom_bill_id'] = $bill->id;
        $normalized['sort_order'] = $sortOrder;

        return CustomBillItem::query()->create($normalized);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeItemRow(array $row): array
    {
        $lineType = trim((string) ($row['line_type'] ?? ''));
        if (! CustomBillLineType::isValid($lineType)) {
            throw ValidationException::withMessages(['line_type' => 'Invalid line type.']);
        }
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Name is required.']);
        }
        $qty = (float) ($row['quantity'] ?? 1);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }
        if (isset($row['unit_price_cents'])) {
            $unitCents = (int) $row['unit_price_cents'];
        } else {
            $unitCents = (int) round((float) ($row['unit_price'] ?? 0) * 100);
        }
        $lineTotal = (int) round($qty * $unitCents);
        if ($lineType === CustomBillLineType::CREDIT) {
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
            'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : null,
        ];
    }

    private function recalculateTotal(CustomBill $bill): void
    {
        $total = (int) $bill->items()->sum('line_total_cents');
        $bill->total_cents = $total;
        $bill->save();
    }

    private function assertOpen(CustomBill $bill): void
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages([
                'status' => 'This bill is invoiced. Mark it Open before editing.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private function logHistory(CustomBill $bill, ?User $actor, string $eventType, string $message, ?array $meta = null): void
    {
        CustomBillHistory::query()->create([
            'custom_bill_id' => $bill->id,
            'user_id' => $actor ? $actor->id : null,
            'actor_name' => $actor ? $actor->name : null,
            'event_type' => $eventType,
            'message' => $message,
            'meta' => $meta,
        ]);
    }
}
