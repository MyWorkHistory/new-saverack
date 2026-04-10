<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceHistory;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    /**
     * Must be called inside an active DB transaction when creating invoices.
     */
    public function allocateInvoiceNumber(): string
    {
        $year = now()->year;
        $prefix = 'INV-'.$year.'-';
        $max = Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');
        $next = 1;
        if (is_string($max) && preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $max, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function createDraft(array $header, array $items, ?User $actor, ?string $invoiceNumberOverride = null): Invoice
    {
        return DB::transaction(function () use ($header, $items, $actor, $invoiceNumberOverride) {
            $invoice = new Invoice($header);
            if ($invoiceNumberOverride !== null && $invoiceNumberOverride !== '') {
                $invoice->invoice_number = $invoiceNumberOverride;
            } else {
                $invoice->invoice_number = $this->allocateInvoiceNumber();
            }
            $invoice->status = Invoice::STATUS_DRAFT;
            $invoice->created_by_user_id = $actor !== null ? $actor->id : null;
            $invoice->amount_paid_cents = 0;
            $invoice->save();

            $this->replaceItems($invoice, $items);
            $this->recalculateTotals($invoice);
            $invoice->save();

            $this->logHistory($invoice, $actor, 'created', null, Invoice::STATUS_DRAFT, [
                'invoice_number' => $invoice->invoice_number,
            ]);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function updateDraft(Invoice $invoice, array $header, array $items, ?User $actor): Invoice
    {
        if (! $invoice->isEditableDraft()) {
            throw new \RuntimeException('Only draft invoices can be updated.');
        }

        return DB::transaction(function () use ($invoice, $header, $items, $actor) {
            $invoice->fill($header);
            $invoice->save();

            $this->replaceItems($invoice, $items);
            $this->recalculateTotals($invoice);
            $invoice->save();

            $this->logHistory($invoice, $actor, 'updated', Invoice::STATUS_DRAFT, Invoice::STATUS_DRAFT, []);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function replaceItems(Invoice $invoice, array $items): void
    {
        $invoice->items()->delete();
        $order = 0;
        foreach ($items as $row) {
            $order++;
            InvoiceItem::query()->create([
                'invoice_id' => $invoice->id,
                'sort_order' => $order,
                'description' => (string) ($row['description'] ?? ''),
                'sku' => $row['sku'] ?? null,
                'service_code' => $row['service_code'] ?? null,
                'quantity' => $row['quantity'] ?? 1,
                'unit' => $row['unit'] ?? null,
                'unit_price_cents' => (int) ($row['unit_price_cents'] ?? 0),
                'line_total_cents' => (int) ($row['line_total_cents'] ?? 0),
                'metadata' => $row['metadata'] ?? null,
            ]);
        }
    }

    public function recalculateTotals(Invoice $invoice): void
    {
        $invoice->loadMissing('items');
        $subtotal = (int) $invoice->items->sum('line_total_cents');
        $invoice->subtotal_cents = $subtotal;

        if ($invoice->tax_rate_basis_points !== null && $invoice->tax_rate_basis_points > 0) {
            $invoice->tax_cents = (int) round($subtotal * $invoice->tax_rate_basis_points / 10000);
        }

        $invoice->total_cents = (int) $invoice->subtotal_cents + (int) $invoice->tax_cents;
        $paid = min($invoice->amount_paid_cents, $invoice->total_cents);
        $invoice->amount_paid_cents = $paid;
        $invoice->balance_due_cents = max(0, $invoice->total_cents - $paid);
        $this->syncPaymentDerivedFields($invoice);
    }

    private function syncPaymentDerivedFields(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::STATUS_VOID) {
            return;
        }

        if ($invoice->total_cents <= 0) {
            if ($invoice->amount_paid_cents > 0) {
                $invoice->status = Invoice::STATUS_PAID;
                $invoice->paid_at = $invoice->paid_at ?? now();
            }

            return;
        }

        if ($invoice->balance_due_cents <= 0) {
            $invoice->status = Invoice::STATUS_PAID;
            $invoice->paid_at = $invoice->paid_at ?? now();
        } elseif ($invoice->amount_paid_cents > 0) {
            $invoice->status = Invoice::STATUS_PARTIAL;
            $invoice->paid_at = null;
        }
    }

    public function markSent(Invoice $invoice, ?User $actor): Invoice
    {
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw new \RuntimeException('Only draft invoices can be sent.');
        }

        $from = $invoice->status;
        $invoice->status = Invoice::STATUS_SENT;
        $invoice->issued_at = $invoice->issued_at ?? now();
        $this->recalculateTotals($invoice);
        $invoice->save();
        $this->logHistory($invoice, $actor, 'sent', $from, $invoice->status, []);

        return $invoice->fresh(['items', 'clientAccount']);
    }

    public function recordPayment(Invoice $invoice, int $amountCents, ?User $actor): Invoice
    {
        if ($invoice->isVoid() || $invoice->status === Invoice::STATUS_DRAFT) {
            throw new \RuntimeException('Cannot record payment on this invoice.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        return DB::transaction(function () use ($invoice, $amountCents, $actor) {
            $invoice->refresh();
            $from = $invoice->status;
            $invoice->amount_paid_cents = min(
                $invoice->total_cents,
                $invoice->amount_paid_cents + $amountCents,
            );
            $this->recalculateTotals($invoice);
            $invoice->save();
            $this->logHistory($invoice, $actor, 'payment_applied', $from, $invoice->status, [
                'amount_cents' => $amountCents,
            ]);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    public function voidInvoice(Invoice $invoice, ?User $actor): Invoice
    {
        if ($invoice->isVoid()) {
            return $invoice;
        }
        if ($invoice->amount_paid_cents > 0) {
            throw new \RuntimeException('Cannot void an invoice with payments recorded.');
        }

        $from = $invoice->status;
        $invoice->status = Invoice::STATUS_VOID;
        $invoice->balance_due_cents = 0;
        $invoice->save();
        $this->logHistory($invoice, $actor, 'voided', $from, Invoice::STATUS_VOID, []);

        return $invoice->fresh(['items', 'clientAccount']);
    }

    public function deleteDraft(Invoice $invoice): void
    {
        if (! $invoice->isEditableDraft()) {
            throw new \RuntimeException('Only draft invoices can be deleted.');
        }
        DB::transaction(function () use ($invoice) {
            $invoice->items()->delete();
            $invoice->histories()->delete();
            $invoice->delete();
        });
    }

    public function logHistory(
        Invoice $invoice,
        ?User $actor,
        string $action,
        ?string $fromStatus,
        ?string $toStatus,
        array $meta,
    ): void {
        InvoiceHistory::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toListArray(Invoice $invoice): array
    {
        $invoice->loadMissing('clientAccount');

        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'is_overdue' => $this->isOverdue($invoice),
            'currency' => $invoice->currency,
            'client_account_id' => $invoice->client_account_id,
            'client_company_name' => $invoice->clientAccount !== null ? $invoice->clientAccount->company_name : null,
            'total_cents' => $invoice->total_cents,
            'balance_due_cents' => $invoice->balance_due_cents,
            'issued_at' => $invoice->issued_at !== null ? $invoice->issued_at->toIso8601String() : null,
            'due_at' => $invoice->due_at !== null ? $invoice->due_at->toIso8601String() : null,
            'created_at' => $invoice->created_at->toIso8601String(),
            'updated_at' => $invoice->updated_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDetailArray(Invoice $invoice): array
    {
        $invoice->load(['items', 'histories.user', 'clientAccount', 'createdBy']);

        $base = $this->toListArray($invoice);

        return array_merge($base, [
            'amount_paid_cents' => $invoice->amount_paid_cents,
            'subtotal_cents' => $invoice->subtotal_cents,
            'tax_cents' => $invoice->tax_cents,
            'tax_rate_basis_points' => $invoice->tax_rate_basis_points,
            'billing_period_start' => $invoice->billing_period_start !== null ? $invoice->billing_period_start->toDateString() : null,
            'billing_period_end' => $invoice->billing_period_end !== null ? $invoice->billing_period_end->toDateString() : null,
            'payment_terms' => $invoice->payment_terms,
            'po_number' => $invoice->po_number,
            'customer_notes' => $invoice->customer_notes,
            'internal_notes' => $invoice->internal_notes,
            'paid_at' => $invoice->paid_at !== null ? $invoice->paid_at->toIso8601String() : null,
            'created_by' => $invoice->createdBy ? [
                'id' => $invoice->createdBy->id,
                'name' => $invoice->createdBy->name,
            ] : null,
            'items' => $invoice->items->map(static function (InvoiceItem $item) {
                return [
                    'id' => $item->id,
                    'sort_order' => $item->sort_order,
                    'description' => $item->description,
                    'sku' => $item->sku,
                    'service_code' => $item->service_code,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                    'unit_price_cents' => $item->unit_price_cents,
                    'line_total_cents' => $item->line_total_cents,
                    'metadata' => $item->metadata,
                ];
            })->values()->all(),
            'histories' => $invoice->histories->take(50)->map(static function (InvoiceHistory $h) {
                return [
                    'id' => $h->id,
                    'action' => $h->action,
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'meta' => $h->meta,
                    'user' => $h->user ? ['id' => $h->user->id, 'name' => $h->user->name] : null,
                    'created_at' => $h->created_at->toIso8601String(),
                ];
            })->values()->all(),
        ]);
    }

    public function isOverdue(Invoice $invoice): bool
    {
        if ($invoice->isPaidLike() || $invoice->status === Invoice::STATUS_DRAFT) {
            return false;
        }
        if ($invoice->due_at === null) {
            return false;
        }

        return $invoice->due_at->isPast() && $invoice->balance_due_cents > 0;
    }

    public function paginate(array $filters): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $q = Invoice::query()->with('clientAccount');

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            if ($filters['status'] === 'overdue') {
                $q->where('status', '!=', Invoice::STATUS_VOID)
                    ->where('status', '!=', Invoice::STATUS_PAID)
                    ->where('status', '!=', Invoice::STATUS_DRAFT)
                    ->where('balance_due_cents', '>', 0)
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now()->startOfDay());
            } else {
                $q->where('status', $filters['status']);
            }
        }

        if (! empty($filters['client_account_id'])) {
            $q->where('client_account_id', (int) $filters['client_account_id']);
        }

        if (! empty($filters['issued_from'])) {
            $q->whereDate('issued_at', '>=', $filters['issued_from']);
        }
        if (! empty($filters['issued_to'])) {
            $q->whereDate('issued_at', '<=', $filters['issued_to']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $q->where(function ($w) use ($term) {
                $w->where('invoice_number', 'like', $term)
                    ->orWhereHas('clientAccount', function ($c) use ($term) {
                        $c->where('company_name', 'like', $term);
                    });
            });
        }

        $sortBy = $filters['sort_by'] ?? 'issued_at';
        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['invoice_number', 'status', 'issued_at', 'due_at', 'total_cents', 'balance_due_cents', 'created_at'];
        if (! in_array($sortBy, $allowedSort, true)) {
            $sortBy = 'created_at';
        }
        $q->orderBy($sortBy, $sortDir);

        $perPage = (int) ($filters['per_page'] ?? 15);
        $perPage = max(1, min(100, $perPage));

        return $q->paginate($perPage)->through(fn (Invoice $inv) => $this->toListArray($inv));
    }

    /**
     * @return array<string, int|string>
     */
    public function summary(): array
    {
        $openStatuses = [Invoice::STATUS_SENT, Invoice::STATUS_PARTIAL];
        $openBalance = (int) Invoice::query()
            ->whereIn('status', $openStatuses)
            ->sum('balance_due_cents');

        $overdueCount = (int) Invoice::query()
            ->whereIn('status', $openStatuses)
            ->where('balance_due_cents', '>', 0)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now()->startOfDay())
            ->count();

        $draftCount = (int) Invoice::query()->where('status', Invoice::STATUS_DRAFT)->count();

        $startMtd = now()->startOfMonth();
        $paidMtdCents = (int) Invoice::query()
            ->where('status', Invoice::STATUS_PAID)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $startMtd)
            ->sum('amount_paid_cents');

        $countsByStatus = Invoice::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->map(fn ($n) => (int) $n)
            ->all();

        return [
            'open_balance_due_cents' => $openBalance,
            'overdue_invoice_count' => $overdueCount,
            'draft_invoice_count' => $draftCount,
            'paid_mtd_cents' => $paidMtdCents,
            'counts_by_status' => $countsByStatus,
        ];
    }
}
