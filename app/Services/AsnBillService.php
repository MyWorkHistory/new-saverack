<?php

namespace App\Services;

use App\Models\AsnBill;
use App\Models\AsnBillHistory;
use App\Models\AsnBillItem;
use App\Models\ClientAccount;
use App\Models\ClientAccountAsn;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Billing\AsnBillChargeCatalog;
use App\Support\Billing\InvoiceLineCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AsnBillService
{
    /** @var InvoiceService */
    private $invoices;

    public function __construct(InvoiceService $invoices)
    {
        $this->invoices = $invoices;
    }

    /**
     * Flat paginated list of ASN bill line items (only bills with at least one line).
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function paginateLines(array $filters): array
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $sortBy = in_array($filters['sort_by'] ?? '', ['service_name', 'asn_number', 'quantity', 'unit_price_cents', 'line_total_cents', 'id'], true)
            ? (string) $filters['sort_by']
            : 'id';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = AsnBillItem::query()
            ->join('asn_bills', 'asn_bill_items.asn_bill_id', '=', 'asn_bills.id')
            ->join('client_account_asns', 'asn_bills.client_account_asn_id', '=', 'client_account_asns.id')
            ->join('client_accounts', 'asn_bills.client_account_id', '=', 'client_accounts.id')
            ->select([
                'asn_bill_items.*',
                'asn_bills.bill_number',
                'asn_bills.status as bill_status',
                'asn_bills.client_account_id',
                'asn_bills.client_account_asn_id',
                'client_account_asns.asn_number',
                'client_accounts.company_name as client_account_name',
            ]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('asn_bills.status', (string) $filters['status']);
        }
        if (! empty($filters['client_account_id'])) {
            $query->where('asn_bills.client_account_id', (int) $filters['client_account_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('asn_bills.bill_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('asn_bills.bill_date', '<=', (string) $filters['date_to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $q) use ($search) {
                if (ctype_digit($search)) {
                    $q->orWhere('asn_bills.bill_number', (int) $search);
                }
                $q->orWhere('client_account_asns.asn_number', 'like', '%'.$search.'%')
                    ->orWhere('asn_bill_items.name', 'like', '%'.$search.'%')
                    ->orWhere('client_accounts.company_name', 'like', '%'.$search.'%');
            });
        }

        switch ($sortBy) {
            case 'service_name':
                $query->orderBy('asn_bill_items.name', $sortDir);
                break;
            case 'asn_number':
                $query->orderBy('client_account_asns.asn_number', $sortDir);
                break;
            case 'quantity':
                $query->orderBy('asn_bill_items.quantity', $sortDir);
                break;
            case 'unit_price_cents':
                $query->orderBy('asn_bill_items.unit_price_cents', $sortDir);
                break;
            case 'line_total_cents':
                $query->orderBy('asn_bill_items.line_total_cents', $sortDir);
                break;
            default:
                $query->orderBy('asn_bill_items.id', $sortDir);
        }

        /** @var LengthAwarePaginator $page */
        $page = $query->paginate($perPage);

        return [
            'data' => collect($page->items())->map(function ($row) {
                return $this->lineToListArray($row);
            })->values()->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    public function findOrCreateOpenBillForAsn(ClientAccountAsn $asn, ?User $actor): AsnBill
    {
        $asn->loadMissing('asnBill');
        if ($asn->asn_bill_id !== null) {
            $existing = AsnBill::query()->find($asn->asn_bill_id);
            if ($existing instanceof AsnBill) {
                if (! $existing->isOpen()) {
                    throw ValidationException::withMessages([
                        'asn_bill' => ['This ASN bill has already been invoiced.'],
                    ]);
                }

                return $existing;
            }
        }

        return DB::transaction(function () use ($asn, $actor) {
            $bill = AsnBill::query()->create([
                'bill_number' => $this->nextBillNumber(),
                'status' => AsnBill::STATUS_OPEN,
                'client_account_id' => $asn->client_account_id,
                'client_account_asn_id' => $asn->id,
                'bill_date' => now()->toDateString(),
                'total_cents' => 0,
                'created_by_user_id' => $actor ? $actor->id : null,
            ]);

            $asn->asn_bill_id = $bill->id;
            $asn->saveQuietly();

            $this->logHistory($bill, $actor, 'created', 'ASN bill created.');

            return $bill->fresh(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'createdBy']);
        });
    }

    public function updateHeader(AsnBill $bill, array $data, ?User $actor): AsnBill
    {
        $this->assertOpen($bill);

        return DB::transaction(function () use ($bill, $data, $actor) {
            if (isset($data['bill_date'])) {
                $bill->bill_date = $data['bill_date'];
            }
            $bill->save();
            $this->logHistory($bill, $actor, 'updated', 'Bill details updated.');

            return $bill->fresh(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'createdBy', 'invoice']);
        });
    }

    public function delete(AsnBill $bill, ?User $actor): void
    {
        $this->assertOpen($bill);

        DB::transaction(function () use ($bill) {
            ClientAccountAsn::query()
                ->where('asn_bill_id', $bill->id)
                ->update(['asn_bill_id' => null]);
            $bill->items()->delete();
            $bill->histories()->delete();
            $bill->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addItem(AsnBill $bill, array $row, ?User $actor): AsnBill
    {
        $this->assertOpen($bill);
        $normalized = $this->normalizeItemRow($bill, $row);

        return DB::transaction(function () use ($bill, $normalized, $actor) {
            $order = (int) $bill->items()->max('sort_order') + 1;
            $this->insertItem(
                $bill,
                $order,
                $normalized['line_type'],
                $normalized['name'],
                $normalized['quantity'],
                $normalized['unit_price_cents'],
                $normalized['metadata']
            );
            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'line_add', 'Added bill line item.');

            return $bill->fresh(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'createdBy', 'invoice']);
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function addItemForAsn(ClientAccountAsn $asn, array $row, ?User $actor): AsnBill
    {
        $bill = $this->findOrCreateOpenBillForAsn($asn, $actor);

        return $this->addItem($bill, $row, $actor);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function updateItem(AsnBill $bill, AsnBillItem $item, array $row, ?User $actor): AsnBill
    {
        $this->assertOpen($bill);
        if ((int) $item->asn_bill_id !== (int) $bill->id) {
            throw new \InvalidArgumentException('Line item does not belong to this bill.');
        }
        $normalized = $this->normalizeItemRow($bill, $row, $item);

        return DB::transaction(function () use ($bill, $item, $normalized, $actor) {
            $qty = $normalized['quantity'];
            $unitCents = $normalized['unit_price_cents'];
            $item->fill([
                'line_type' => $normalized['line_type'],
                'name' => $normalized['name'],
                'quantity' => $qty,
                'unit_price_cents' => $unitCents,
                'line_total_cents' => (int) round($qty * $unitCents),
                'metadata' => $normalized['metadata'],
            ]);
            $item->save();
            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'line_edit', 'Updated bill line item.');

            return $bill->fresh(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'createdBy', 'invoice']);
        });
    }

    public function deleteItem(AsnBill $bill, AsnBillItem $item, ?User $actor): ?AsnBill
    {
        $this->assertOpen($bill);
        if ((int) $item->asn_bill_id !== (int) $bill->id) {
            throw new \InvalidArgumentException('Line item does not belong to this bill.');
        }

        return DB::transaction(function () use ($bill, $item, $actor) {
            $item->delete();
            $remaining = (int) $bill->items()->count();
            if ($remaining === 0) {
                ClientAccountAsn::query()
                    ->where('asn_bill_id', $bill->id)
                    ->update(['asn_bill_id' => null]);
                $bill->histories()->delete();
                $bill->delete();

                return null;
            }

            $this->recalculateTotal($bill);
            $this->logHistory($bill, $actor, 'line_delete', 'Removed bill line item.');

            return $bill->fresh(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'createdBy', 'invoice']);
        });
    }

    /**
     * @param  list<string>|null  $lineTypes
     */
    public function addToInvoice(AsnBill $bill, int $invoiceId, ?User $actor, ?array $lineTypes = null): AsnBill
    {
        $this->assertOpen($bill);
        $bill->loadMissing('items', 'clientAccountAsn', 'clientAccount');

        $items = $bill->items;
        if ($lineTypes !== null && $lineTypes !== []) {
            $allowed = array_flip($lineTypes);
            $items = $items->filter(function (AsnBillItem $item) use ($allowed) {
                return isset($allowed[$item->line_type]);
            })->values();
        }

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'invoice_id' => 'This ASN bill has no billable lines for the selected charge types.',
            ]);
        }

        $invoice = Invoice::query()->find($invoiceId);
        if ($invoice === null) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice not found.']);
        }
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw ValidationException::withMessages(['invoice_id' => 'Only draft invoices can receive ASN bill lines.']);
        }
        if ((int) $invoice->client_account_id !== (int) $bill->client_account_id) {
            throw ValidationException::withMessages(['invoice_id' => 'Invoice must belong to the same account as this bill.']);
        }

        $asnNumber = $bill->clientAccountAsn !== null ? (string) $bill->clientAccountAsn->asn_number : '';

        return DB::transaction(function () use ($bill, $invoice, $actor, $items, $asnNumber) {
            foreach ($items as $item) {
                $lineType = (string) $item->line_type;
                $subtype = AsnBillChargeCatalog::subtype($lineType);
                $groupKey = AsnBillChargeCatalog::groupKey($lineType);
                $qty = (float) $item->quantity;
                $unitCents = (int) $item->unit_price_cents;
                $lineTotal = (int) $item->line_total_cents;
                $name = trim((string) $item->name);
                if ($name === '') {
                    $name = AsnBillChargeCatalog::displayName($lineType);
                }

                $this->invoices->addOrMergeReceivingLine($invoice, [
                    'description' => $name,
                    'display_name' => $name,
                    'category' => InvoiceLineCategory::RECEIVING,
                    'subtype' => $subtype,
                    'quantity' => $qty,
                    'unit_price_cents' => $unitCents,
                    'line_total_cents' => $lineTotal,
                    'group_key' => $groupKey,
                    'metadata' => [
                        'source' => 'asn_bill',
                        'asn_bill_id' => (int) $bill->id,
                        'asn_bill_item_id' => (int) $item->id,
                        'asn_bill_number' => (int) $bill->bill_number,
                        'client_account_asn_id' => (int) $bill->client_account_asn_id,
                        'asn_number' => $asnNumber,
                    ],
                ], $actor);
                $invoice = $invoice->fresh();
            }

            $bill->status = AsnBill::STATUS_INVOICED;
            $bill->invoice_id = $invoice->id;
            $bill->save();

            $this->logHistory($bill, $actor, 'invoiced', 'Added ASN bill lines to invoice #'.$invoice->invoice_number.'.', [
                'invoice_id' => $invoice->id,
            ]);

            return $bill->fresh(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'createdBy', 'invoice']);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function draftInvoicesForBill(AsnBill $bill): array
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

    /**
     * @return list<array<string, mixed>>
     */
    public function linesForAsn(ClientAccountAsn $asn): array
    {
        if ($asn->asn_bill_id === null) {
            return [];
        }

        $bill = AsnBill::query()->with('items')->find($asn->asn_bill_id);
        if ($bill === null) {
            return [];
        }

        return $bill->items->map(fn (AsnBillItem $item) => $this->itemToArray($item))->values()->all();
    }

    public function toDetailArray(AsnBill $bill): array
    {
        $bill->loadMissing(['items', 'clientAccount', 'clientAccountAsn', 'histories.user', 'invoice', 'createdBy']);
        $chargeOptions = $bill->clientAccount
            ? AsnBillChargeCatalog::optionsForAccount($bill->clientAccount)
            : [];

        return [
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'status' => $bill->status,
            'status_label' => $bill->isOpen() ? 'Open' : 'Invoiced',
            'client_account_id' => $bill->client_account_id,
            'client_account_name' => $bill->clientAccount ? $bill->clientAccount->company_name : '',
            'client_account_asn_id' => $bill->client_account_asn_id,
            'asn_number' => $bill->clientAccountAsn !== null ? $bill->clientAccountAsn->asn_number : null,
            'bill_date' => $bill->bill_date ? $bill->bill_date->format('Y-m-d') : null,
            'total_cents' => (int) $bill->total_cents,
            'invoice_id' => $bill->invoice_id,
            'invoice_number' => $bill->invoice ? $bill->invoice->invoice_number : null,
            'created_by_name' => $bill->createdBy ? $bill->createdBy->name : null,
            'items' => $bill->items->map(fn (AsnBillItem $item) => $this->itemToArray($item))->values()->all(),
            'histories' => $bill->histories->map(function (AsnBillHistory $h) {
                $meta = is_array($h->meta) ? $h->meta : [];

                return [
                    'id' => $h->id,
                    'event_type' => $h->event_type,
                    'event_label' => $this->historyEventLabel((string) $h->event_type),
                    'message' => $h->message,
                    'actor_name' => $h->actor_name ?: ($h->user ? $h->user->name : 'System'),
                    'invoice_id' => isset($meta['invoice_id']) ? (int) $meta['invoice_id'] : null,
                    'created_at' => $h->created_at ? $h->created_at->toIso8601String() : null,
                ];
            })->values()->all(),
            'charge_options' => $chargeOptions,
            'created_at' => $bill->created_at ? $bill->created_at->toIso8601String() : null,
            'updated_at' => $bill->updated_at ? $bill->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{line_type: string, name: string, quantity: float, unit_price_cents: int, metadata: array<string, mixed>}
     */
    private function normalizeItemRow(AsnBill $bill, array $row, ?AsnBillItem $existing = null): array
    {
        $lineType = isset($row['line_type']) ? (string) $row['line_type'] : ($existing ? (string) $existing->line_type : '');
        AsnBillChargeCatalog::assertValidLineType($lineType);

        $name = isset($row['name']) ? trim((string) $row['name']) : '';
        if ($name === '') {
            $name = AsnBillChargeCatalog::displayName($lineType);
        }

        $qty = isset($row['quantity']) ? (float) $row['quantity'] : ($existing ? (float) $existing->quantity : 1.0);
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => ['Quantity must be greater than zero.']]);
        }

        if (isset($row['unit_price_cents'])) {
            $unitCents = (int) $row['unit_price_cents'];
        } elseif (isset($row['unit_price'])) {
            $unitCents = (int) round(((float) $row['unit_price']) * 100);
        } elseif ($existing !== null) {
            $unitCents = (int) $existing->unit_price_cents;
        } else {
            $bill->loadMissing('clientAccount');
            $unitCents = $bill->clientAccount
                ? AsnBillChargeCatalog::defaultUnitPriceCents($bill->clientAccount, $lineType)
                : 0;
        }

        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        if ($existing !== null && is_array($existing->metadata)) {
            $metadata = array_merge($existing->metadata, $metadata);
        }

        return [
            'line_type' => $lineType,
            'name' => $name,
            'quantity' => $qty,
            'unit_price_cents' => $unitCents,
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function insertItem(
        AsnBill $bill,
        int $sortOrder,
        string $lineType,
        string $name,
        float $quantity,
        int $unitPriceCents,
        array $metadata = []
    ): AsnBillItem {
        $lineTotal = (int) round($quantity * $unitPriceCents);

        return AsnBillItem::query()->create([
            'asn_bill_id' => $bill->id,
            'line_type' => $lineType,
            'name' => $name,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPriceCents,
            'line_total_cents' => $lineTotal,
            'metadata' => $metadata,
            'sort_order' => $sortOrder,
        ]);
    }

    private function recalculateTotal(AsnBill $bill): void
    {
        $total = (int) $bill->items()->sum('line_total_cents');
        $bill->total_cents = $total;
        $bill->saveQuietly();
    }

    private function nextBillNumber(): int
    {
        return DB::transaction(function () {
            $max = (int) AsnBill::query()->lockForUpdate()->max('bill_number');
            if ($max < AsnBill::FIRST_BILL_NUMBER) {
                return AsnBill::FIRST_BILL_NUMBER;
            }

            return $max + 1;
        });
    }

    private function assertOpen(AsnBill $bill): void
    {
        if (! $bill->isOpen()) {
            throw ValidationException::withMessages([
                'status' => ['Only open ASN bills can be modified.'],
            ]);
        }
    }

    private function historyEventLabel(string $eventType): string
    {
        switch ($eventType) {
            case 'created':
                return 'Created';
            case 'invoiced':
                return 'Added to Invoice';
            case 'updated':
            case 'line_add':
            case 'line_edit':
            case 'line_delete':
                return 'Edited';
            default:
                return 'Activity';
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function logHistory(AsnBill $bill, ?User $actor, string $eventType, string $message, array $meta = []): void
    {
        AsnBillHistory::query()->create([
            'asn_bill_id' => $bill->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'actor_name' => $actor ? trim((string) $actor->name) : null,
            'event_type' => $eventType,
            'message' => $message,
            'meta' => $meta !== [] ? $meta : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemToArray(AsnBillItem $item): array
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

    /**
     * @param  object  $row
     * @return array<string, mixed>
     */
    private function lineToListArray($row): array
    {
        return [
            'id' => (int) $row->id,
            'asn_bill_id' => (int) $row->asn_bill_id,
            'bill_number' => (int) $row->bill_number,
            'bill_status' => (string) $row->bill_status,
            'bill_status_label' => $row->bill_status === AsnBill::STATUS_OPEN ? 'Open' : 'Invoiced',
            'client_account_id' => (int) $row->client_account_id,
            'client_account_name' => (string) $row->client_account_name,
            'client_account_asn_id' => (int) $row->client_account_asn_id,
            'asn_number' => (string) $row->asn_number,
            'service_name' => (string) $row->name,
            'line_type' => (string) $row->line_type,
            'quantity' => (float) $row->quantity,
            'unit_price_cents' => (int) $row->unit_price_cents,
            'line_total_cents' => (int) $row->line_total_cents,
        ];
    }
}
