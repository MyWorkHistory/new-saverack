<?php

namespace App\Services;

use App\Models\ClientAccountReturn;
use App\Models\Invoice;
use App\Models\ReturnBill;
use App\Models\ReturnBillHistory;
use App\Models\ReturnBillItem;
use App\Models\User;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnBillService
{
    public const FIRST_ITEM_NAME = 'Return Fee (1st Item)';

    public const ADDITIONAL_ITEMS_NAME = 'Return Fee (Additional Items)';

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

        $query = ReturnBill::query()
            ->with(['clientAccount', 'clientAccountReturn']);

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
                $q->orWhereHas('clientAccountReturn', function (Builder $rq) use ($search) {
                    $rq->where('rma_number', 'like', '%'.$search.'%')
                        ->orWhere('order_number', 'like', '%'.$search.'%');
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
            'data' => collect($page->items())->map(function (ReturnBill $bill) {
                return $this->toListArray($bill);
            })->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    public function createFromProcessedReturn(ClientAccountReturn $return, ?User $actor): ReturnBill
    {
        if ($return->return_bill_id !== null) {
            $existing = ReturnBill::query()->find($return->return_bill_id);
            if ($existing instanceof ReturnBill) {
                return $existing;
            }
        }

        $return->loadMissing('lines');
        $totalUnits = (int) $return->lines->sum('return_qty');
        $firstQty = $totalUnits >= 1 ? 1.0 : 0.0;
        $additionalQty = max(0, $totalUnits - 1);

        $firstCents = (int) round($this->feeService()->firstItemFeeAmount($return) * 100);
        $additionalCents = (int) round($this->feeService()->additionalItemFeeAmount($return) * 100);

        return DB::transaction(function () use ($return, $actor, $firstQty, $additionalQty, $firstCents, $additionalCents) {
            $bill = ReturnBill::query()->create([
                'bill_number' => $this->nextBillNumber(),
                'status' => ReturnBill::STATUS_OPEN,
                'client_account_id' => $return->client_account_id,
                'client_account_return_id' => $return->id,
                'bill_date' => now()->toDateString(),
                'total_cents' => 0,
                'created_by_user_id' => $actor ? $actor->id : null,
            ]);

            $order = 0;
            if ($firstQty > 0 && $firstCents !== 0) {
                $order++;
                $this->insertItem($bill, $order, ReturnBill::LINE_FIRST_ITEM, self::FIRST_ITEM_NAME, $firstQty, $firstCents, [
                    'return_id' => $return->id,
                    'rma_number' => $return->rma_number,
                ]);
            }
            if ($additionalQty > 0 && $additionalCents !== 0) {
                $order++;
                $this->insertItem($bill, $order, ReturnBill::LINE_ADDITIONAL_ITEMS, self::ADDITIONAL_ITEMS_NAME, (float) $additionalQty, $additionalCents, [
                    'return_id' => $return->id,
                    'rma_number' => $return->rma_number,
                ]);
            }

            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'created', 'Return bill created from processed return.');

            $return->return_bill_id = $bill->id;
            $return->saveQuietly();

            return $bill->fresh(['items', 'clientAccount', 'clientAccountReturn', 'histories.user']);
        });
    }

    public function addToInvoice(ReturnBill $bill, int $invoiceId, ?User $actor): ReturnBill
    {
        $this->assertOpen($bill);
        $bill->loadMissing('items', 'clientAccountReturn');

        if ($bill->items->isEmpty()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'This return bill has no billable lines.',
            ]);
        }

        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice not found.']);
        }
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['invoice_id' => 'Only draft invoices can receive return bill lines.']);
        }
        if ((int) $invoice->client_account_id !== (int) $bill->client_account_id) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice must belong to the same account as this bill.']);
        }

        return DB::transaction(function () use ($bill, $invoice, $actor) {
            foreach ($bill->items as $item) {
                $subtype = $item->line_type === ReturnBill::LINE_ADDITIONAL_ITEMS ? 'additional' : 'first';
                $groupKey = $subtype === 'additional' ? 'returns:additional' : 'returns:first';
                $qty = (float) $item->quantity;
                $unitCents = (int) $item->unit_price_cents;
                $lineTotal = (int) $item->line_total_cents;
                $name = trim((string) $item->name);

                $this->invoices->addInvoiceItem($invoice, [
                    'description' => $name,
                    'display_name' => $name,
                    'category' => InvoiceLineCategory::RETURNS,
                    'subtype' => $subtype,
                    'quantity' => $qty,
                    'unit_price_cents' => $unitCents,
                    'line_total_cents' => $lineTotal,
                    'group_key' => $groupKey,
                    'metadata' => [
                        'source' => 'return_bill',
                        'return_bill_id' => (int) $bill->id,
                        'return_bill_item_id' => (int) $item->id,
                        'return_bill_number' => (int) $bill->bill_number,
                        'return_id' => $bill->client_account_return_id,
                        'rma_number' => $bill->clientAccountReturn?->rma_number,
                    ],
                ], $actor);
                $invoice = $invoice->fresh();
            }

            $bill->status = ReturnBill::STATUS_INVOICED;
            $bill->invoice_id = $invoice->id;
            $bill->save();

            $this->logHistory($bill, $actor, 'invoiced', 'Added return bill lines to invoice #'.$invoice->invoice_number.'.', [
                'invoice_id' => $invoice->id,
            ]);

            return $bill->fresh(['items', 'clientAccount', 'clientAccountReturn', 'histories.user', 'invoice']);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function draftInvoicesForBill(ReturnBill $bill): array
    {
        return Invoice::query()
            ->where('client_account_id', $bill->client_account_id)
            ->where('status', Invoice::STATUS_DRAFT)
            ->orderByDesc('id')
            ->limit(50)
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

    public function toDetailArray(ReturnBill $bill): array
    {
        $bill->loadMissing(['items', 'clientAccount', 'clientAccountReturn', 'histories.user', 'invoice']);

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'status' => $bill->status,
            'status_label' => $bill->isOpen() ? 'Open' : 'Invoiced',
            'client_account_id' => $bill->client_account_id,
            'client_account_name' => $bill->clientAccount ? $bill->clientAccount->company_name : '',
            'client_account_return_id' => $bill->client_account_return_id,
            'rma_number' => $bill->clientAccountReturn?->rma_number,
            'order_number' => $bill->clientAccountReturn?->order_number,
            'bill_date' => $bill->bill_date ? $bill->bill_date->format('Y-m-d') : null,
            'total_cents' => (int) $bill->total_cents,
            'invoice_id' => $bill->invoice_id,
            'invoice_number' => $bill->invoice ? $bill->invoice->invoice_number : null,
            'items' => $bill->items->map(fn (ReturnBillItem $item) => $this->itemToArray($item))->values()->all(),
            'histories' => $bill->histories->map(function (ReturnBillHistory $h) {
                return [
                    'id' => $h->id,
                    'event_type' => $h->event_type,
                    'message' => $h->message,
                    'actor_name' => $h->actor_name ?: ($h->user ? $h->user->name : 'System'),
                    'created_at' => $h->created_at ? $h->created_at->toIso8601String() : null,
                ];
            })->values()->all(),
            'created_at' => $bill->created_at ? $bill->created_at->toIso8601String() : null,
            'updated_at' => $bill->updated_at ? $bill->updated_at->toIso8601String() : null,
        ];
    }

    private function toListArray(ReturnBill $bill): array
    {
        $bill->loadMissing(['clientAccount', 'clientAccountReturn']);

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'status' => $bill->status,
            'status_label' => $bill->isOpen() ? 'Open' : 'Invoiced',
            'client_account_id' => $bill->client_account_id,
            'client_account_name' => $bill->clientAccount ? $bill->clientAccount->company_name : '',
            'rma_number' => $bill->clientAccountReturn?->rma_number,
            'order_number' => $bill->clientAccountReturn?->order_number,
            'bill_date' => $bill->bill_date ? $bill->bill_date->format('Y-m-d') : null,
            'total_cents' => (int) $bill->total_cents,
            'invoice_id' => $bill->invoice_id,
            'created_at' => $bill->created_at ? $bill->created_at->toIso8601String() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function insertItem(
        ReturnBill $bill,
        int $sortOrder,
        string $lineType,
        string $name,
        float $quantity,
        int $unitPriceCents,
        array $metadata = []
    ): ReturnBillItem {
        $lineTotal = (int) round($quantity * $unitPriceCents);

        return ReturnBillItem::query()->create([
            'return_bill_id' => $bill->id,
            'line_type' => $lineType,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
            'line_total_cents' => $lineTotal,
            'metadata' => $metadata,
            'sort_order' => $sortOrder,
        ]);
    }

    private function recalculateTotal(ReturnBill $bill): void
    {
        $total = (int) $bill->items()->sum('line_total_cents');
        $bill->total_cents = $total;
        $bill->saveQuietly();
    }

    private function nextBillNumber(): int
    {
        return DB::transaction(function () {
            $max = (int) ReturnBill::query()->lockForUpdate()->max('bill_number');
            if ($max < ReturnBill::FIRST_BILL_NUMBER) {
                return ReturnBill::FIRST_BILL_NUMBER;
            }

            return $max + 1;
        });
    }

    private function assertOpen(ReturnBill $bill): void
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages([
                'status' => ['Only open return bills can be modified.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logHistory(ReturnBill $bill, ?User $actor, string $eventType, string $message, array $meta = []): void
    {
        ReturnBillHistory::query()->create([
            'return_bill_id' => $bill->id,
            'user_id' => $actor?->id,
            'actor_name' => $actor ? trim((string) $actor->name) : null,
            'event_type' => $eventType,
            'message' => $message,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemToArray(ReturnBillItem $item): array
    {
        return [
            'id' => $item->id,
            'line_type' => $item->line_type,
            'name' => $item->name,
            'quantity' => (float) $item->quantity,
            'unit_price_cents' => (int) $item->unit_price_cents,
            'line_total_cents' => (int) $item->line_total_cents,
            'metadata' => $item->metadata,
            'sort_order' => (int) $item->sort_order,
        ];
    }

    private function feeService(): ReturnFeeService
    {
        return app(ReturnFeeService::class);
    }
}
