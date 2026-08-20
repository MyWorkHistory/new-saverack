<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Models\WholesaleBill;
use App\Models\WholesaleBillHistory;
use App\Models\WholesaleBillItem;
use App\Models\WholesaleOrder;
use App\Models\WholesaleOrderFeeLine;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WholesaleBillService
{
    /** @var InvoiceService */
    private $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    public function createFromOrder(WholesaleOrder $order, ?User $actor): WholesaleBill
    {
        $order->loadMissing(['feeLines', 'clientAccount', 'wholesaleBill']);
        if ($order->wholesale_bill_id !== null && $order->wholesaleBill instanceof WholesaleBill) {
            return $order->wholesaleBill;
        }
        if ($order->feeLines->isEmpty()) {
            throw ValidationException::withMessages([
                'fee_lines' => ['Add at least one fee before creating a bill.'],
            ]);
        }

        return DB::transaction(function () use ($order, $actor) {
            $bill = WholesaleBill::query()->create([
                'bill_number' => $this->nextBillNumber(),
                'status' => WholesaleBill::STATUS_OPEN,
                'client_account_id' => $order->client_account_id,
                'wholesale_order_id' => $order->id,
                'display_name' => 'Wholesale Order #'.$order->order_number,
                'bill_date' => now()->toDateString(),
                'total_cents' => 0,
                'created_by_user_id' => $actor ? $actor->id : null,
            ]);

            $total = 0;
            $sort = 0;
            foreach ($order->feeLines as $line) {
                $lineTotal = $line->lineTotalCents();
                $total += $lineTotal;
                WholesaleBillItem::query()->create([
                    'wholesale_bill_id' => $bill->id,
                    'line_type' => $line->line_type,
                    'source' => $line->source ?: WholesaleOrderFeeLine::SOURCE_WHOLESALE,
                    'client_account_fee_id' => $line->client_account_fee_id,
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price_cents' => $line->unit_price_cents,
                    'line_total_cents' => $lineTotal,
                    'metadata' => ['wholesale_order_fee_line_id' => (int) $line->id],
                    'sort_order' => $sort++,
                ]);
            }

            $bill->total_cents = $total;
            $bill->save();
            $order->wholesale_bill_id = $bill->id;
            $order->saveQuietly();
            $this->logHistory($bill, $actor, 'created', 'Wholesale bill created.');

            return $bill->fresh($this->detailRelations());
        });
    }

    /**
     * @param  array{bill_date?: string}  $data
     */
    public function updateHeader(WholesaleBill $bill, array $data, ?User $actor): WholesaleBill
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages(['status' => ['Only open wholesale bills can be updated.']]);
        }

        return DB::transaction(function () use ($bill, $data, $actor) {
            if (isset($data['bill_date'])) {
                $bill->bill_date = $data['bill_date'];
            }
            $bill->save();
            $this->logHistory($bill, $actor, 'updated', 'Bill details updated.');

            return $bill->fresh($this->detailRelations());
        });
    }

    public function delete(WholesaleBill $bill, ?User $actor): void
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages(['status' => ['Only open wholesale bills can be deleted.']]);
        }

        DB::transaction(function () use ($bill) {
            WholesaleOrder::query()
                ->where('wholesale_bill_id', $bill->id)
                ->update(['wholesale_bill_id' => null]);
            $bill->items()->delete();
            $bill->histories()->delete();
            $bill->delete();
        });
    }

    public function addToInvoice(WholesaleBill $bill, int $invoiceId, ?User $actor): WholesaleBill
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages(['status' => ['Only open wholesale bills can be invoiced.']]);
        }
        $bill->loadMissing(['items', 'wholesaleOrder']);
        if ($bill->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['This wholesale bill has no billable items.']]);
        }

        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null || $invoice->status !== Invoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['invoice_id' => ['Select a draft invoice.']]);
        }
        if ((int) $invoice->client_account_id !== (int) $bill->client_account_id) {
            throw ValidationException::withMessages(['invoice_id' => ['Invoice must belong to the same account as this bill.']]);
        }

        $orderNumber = $bill->wholesaleOrder ? (string) $bill->wholesaleOrder->order_number : '';
        $displayName = 'Wholesale Order #'.$orderNumber;
        $breakdown = $bill->items->map(function (WholesaleBillItem $item) use ($orderNumber) {
            return [
                'wholesale_bill_item_id' => (int) $item->id,
                'line_type' => (string) $item->line_type,
                'source' => (string) $item->source,
                'name' => (string) $item->name,
                'quantity' => (float) $item->quantity,
                'unit_price_cents' => (int) $item->unit_price_cents,
                'line_total_cents' => (int) $item->line_total_cents,
                'order_number' => $orderNumber,
            ];
        })->values()->all();

        return DB::transaction(function () use ($bill, $invoice, $actor, $displayName, $orderNumber, $breakdown) {
            $this->invoices->addInvoiceItem($invoice, [
                'description' => $displayName,
                'display_name' => $displayName,
                'category' => InvoiceLineCategory::WHOLESALE,
                'subtype' => 'wholesale_order',
                'quantity' => 1,
                'unit_price_cents' => (int) $bill->total_cents,
                'line_total_cents' => (int) $bill->total_cents,
                'group_key' => 'wholesale_bill:'.(int) $bill->id,
                'metadata' => [
                    'source' => 'wholesale_bill',
                    'wholesale_bill_id' => (int) $bill->id,
                    'wholesale_order_id' => (int) $bill->wholesale_order_id,
                    'order_number' => $orderNumber,
                    'breakdown' => $breakdown,
                ],
            ], $actor);

            $bill->status = WholesaleBill::STATUS_INVOICED;
            $bill->invoice_id = $invoice->id;
            $bill->save();
            $this->logHistory($bill, $actor, 'invoiced', 'Added wholesale bill to invoice #'.$invoice->invoice_number.'.', [
                'invoice_id' => $invoice->id,
            ]);

            return $bill->fresh($this->detailRelations());
        });
    }

    /** @return list<array<string, mixed>> */
    public function draftInvoices(WholesaleBill $bill, bool $ensure = false, ?User $actor = null): array
    {
        return $this->invoices->draftInvoicesPayloadForAccount(
            (int) $bill->client_account_id,
            $ensure,
            $actor
        );
    }

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $query = WholesaleBill::query()
            ->with(['clientAccount:id,company_name', 'wholesaleOrder:id,order_number'])
            ->withCount('items');
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['client_account_id'])) {
            $query->where('client_account_id', (int) $filters['client_account_id']);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                if (ctype_digit($search)) {
                    $q->orWhere('bill_number', (int) $search);
                }
                $q->orWhereHas('clientAccount', function (Builder $cq) use ($search) {
                    $cq->where('company_name', 'like', '%'.$search.'%');
                })->orWhereHas('wholesaleOrder', function (Builder $oq) use ($search) {
                    $oq->where('order_number', 'like', '%'.$search.'%');
                });
            });
        }
        /** @var LengthAwarePaginator $page */
        $page = $query->orderByDesc('bill_date')->orderByDesc('id')->paginate($perPage);

        return [
            'data' => collect($page->items())->map(function (WholesaleBill $bill) {
                return $this->toListArray($bill);
            })->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    public function toDetailArray(WholesaleBill $bill): array
    {
        $bill->loadMissing($this->detailRelations());

        return array_merge($this->toListArray($bill), [
            'invoice_number' => $bill->invoice ? $bill->invoice->invoice_number : null,
            'created_by_name' => $bill->createdBy ? $bill->createdBy->name : null,
            'items' => $bill->items->map(function (WholesaleBillItem $item) {
                return [
                    'id' => (int) $item->id,
                    'line_type' => $item->line_type,
                    'source' => $item->source,
                    'client_account_fee_id' => $item->client_account_fee_id,
                    'name' => $item->name,
                    'quantity' => (float) $item->quantity,
                    'unit_price_cents' => (int) $item->unit_price_cents,
                    'line_total_cents' => (int) $item->line_total_cents,
                ];
            })->values()->all(),
            'histories' => $bill->histories->map(function (WholesaleBillHistory $history) {
                return [
                    'id' => (int) $history->id,
                    'event_type' => $history->event_type,
                    'message' => $history->message,
                    'actor_name' => $history->actor_name ?: ($history->user ? $history->user->name : 'System'),
                    'created_at' => $history->created_at ? $history->created_at->toIso8601String() : null,
                ];
            })->values()->all(),
            'created_at' => $bill->created_at ? $bill->created_at->toIso8601String() : null,
            'updated_at' => $bill->updated_at ? $bill->updated_at->toIso8601String() : null,
        ]);
    }

    private function toListArray(WholesaleBill $bill): array
    {
        $bill->loadMissing(['clientAccount', 'wholesaleOrder']);

        return [
            'id' => (int) $bill->id,
            'bill_number' => (int) $bill->bill_number,
            'display_name' => $bill->display_name,
            'status' => $bill->status,
            'status_label' => $bill->isOpen() ? 'Open' : 'Invoiced',
            'client_account_id' => (int) $bill->client_account_id,
            'client_account_name' => $bill->clientAccount ? $bill->clientAccount->company_name : '',
            'wholesale_order_id' => (int) $bill->wholesale_order_id,
            'order_number' => $bill->wholesaleOrder ? $bill->wholesaleOrder->order_number : null,
            'bill_date' => $bill->bill_date ? $bill->bill_date->format('Y-m-d') : null,
            'total_cents' => (int) $bill->total_cents,
            'invoice_id' => $bill->invoice_id,
            'items_count' => (int) ($bill->items_count ?? $bill->items->count()),
        ];
    }

    /** @return list<string> */
    private function detailRelations(): array
    {
        return ['items', 'clientAccount', 'wholesaleOrder', 'histories.user', 'createdBy', 'invoice'];
    }

    private function nextBillNumber(): int
    {
        $max = (int) WholesaleBill::query()->lockForUpdate()->max('bill_number');

        return $max < WholesaleBill::FIRST_BILL_NUMBER ? WholesaleBill::FIRST_BILL_NUMBER : $max + 1;
    }

    /** @param array<string, mixed> $meta */
    private function logHistory(WholesaleBill $bill, ?User $actor, string $eventType, string $message, array $meta = []): void
    {
        WholesaleBillHistory::query()->create([
            'wholesale_bill_id' => $bill->id,
            'user_id' => $actor ? $actor->id : null,
            'actor_name' => $actor ? trim((string) $actor->name) : null,
            'event_type' => $eventType,
            'message' => $message,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }
}
