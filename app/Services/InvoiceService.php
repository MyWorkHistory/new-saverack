<?php

namespace App\Services;

use App\Mail\InvoiceSentMailable;
use App\Models\Invoice;
use App\Support\Billing\InvoiceHistoryEventType;
use App\Support\Billing\InvoiceLineCategory;
use App\Models\InvoiceHistory;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

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
            $this->insertInvoiceItemRow($invoice, $order, $row);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertInvoiceItemRow(Invoice $invoice, int $sortOrder, array $row): void
    {
        $desc = (string) ($row['description'] ?? '');
        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => $sortOrder,
            'category' => $row['category'] ?? InvoiceLineCategory::OTHER,
            'subtype' => $row['subtype'] ?? null,
            'group_key' => $row['group_key'] ?? null,
            'description' => $desc,
            'display_name' => $row['display_name'] ?? Str::limit($desc, 512, ''),
            'sku' => $row['sku'] ?? null,
            'service_code' => $row['service_code'] ?? null,
            'quantity' => $row['quantity'] ?? 1,
            'unit' => $row['unit'] ?? null,
            'unit_price_cents' => (int) ($row['unit_price_cents'] ?? 0),
            'line_total_cents' => (int) ($row['line_total_cents'] ?? 0),
            'metadata' => $row['metadata'] ?? null,
        ]);
    }

    public function deleteLineGroup(Invoice $invoice, string $groupKey, ?User $actor): Invoice
    {
        if (! $invoice->isEditableDraft()) {
            throw new \RuntimeException('Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($invoice, $groupKey, $actor) {
            $deleted = $invoice->items()->where('group_key', $groupKey)->delete();
            if ($deleted === 0) {
                throw new \InvalidArgumentException('No lines found for that group key.');
            }
            $this->recalculateTotals($invoice->refresh());
            $invoice->save();
            $this->logHistory($invoice, $actor, 'updated', Invoice::STATUS_DRAFT, Invoice::STATUS_DRAFT, [
                'event_type' => InvoiceHistoryEventType::LINE_DELETE,
                'history_message' => 'Deleted line group: '.$groupKey,
            ]);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function replaceLineGroup(Invoice $invoice, string $groupKey, array $items, ?User $actor): Invoice
    {
        if (! $invoice->isEditableDraft()) {
            throw new \RuntimeException('Only draft invoices can be edited.');
        }

        return DB::transaction(function () use ($invoice, $groupKey, $items, $actor) {
            $invoice->items()->where('group_key', $groupKey)->delete();
            $order = (int) $invoice->items()->max('sort_order');
            foreach ($items as $row) {
                $order++;
                $row['group_key'] = $groupKey;
                $this->insertInvoiceItemRow($invoice, $order, $row);
            }
            $this->recalculateTotals($invoice->refresh());
            $invoice->save();
            $this->logHistory($invoice, $actor, 'updated', Invoice::STATUS_DRAFT, Invoice::STATUS_DRAFT, [
                'event_type' => InvoiceHistoryEventType::LINE_EDIT,
                'history_message' => 'Replaced line group: '.$groupKey,
            ]);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    public function ensureShareToken(Invoice $invoice): Invoice
    {
        if ($invoice->share_token !== null && $invoice->share_token !== '') {
            return $invoice->fresh(['clientAccount']) ?? $invoice;
        }

        return DB::transaction(function () use ($invoice) {
            $invoice->refresh();
            if ($invoice->share_token !== null && $invoice->share_token !== '') {
                return $invoice->fresh(['clientAccount']) ?? $invoice;
            }
            for ($i = 0; $i < 8; $i++) {
                $token = Str::random(48);
                $exists = Invoice::query()->where('share_token', $token)->exists();
                if (! $exists) {
                    $invoice->share_token = $token;
                    $invoice->share_token_generated_at = now();
                    $invoice->save();

                    return $invoice->fresh(['clientAccount']) ?? $invoice;
                }
            }

            throw new \RuntimeException('Could not allocate a unique share token.');
        });
    }

    public function resolvePublicInvoice(string $accountSlug, string $shareToken): ?Invoice
    {
        if ($accountSlug === '' || $shareToken === '') {
            return null;
        }

        $invoice = Invoice::query()
            ->where('share_token', $shareToken)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->with(['items', 'clientAccount'])
            ->first();

        if ($invoice === null || $invoice->clientAccount === null) {
            return null;
        }

        $slug = $invoice->clientAccount->invoice_share_slug;
        if ($slug === null || $slug === '' || ! hash_equals((string) $slug, $accountSlug)) {
            return null;
        }

        return $invoice;
    }

    public function publicCustomerViewUrl(Invoice $invoice): ?string
    {
        $invoice->loadMissing('clientAccount');
        if ($invoice->clientAccount === null) {
            return null;
        }
        $slug = $invoice->clientAccount->invoice_share_slug;
        $token = $invoice->share_token;
        if ($slug === null || $slug === '' || $token === null || $token === '') {
            return null;
        }

        return url('/billing-invoice/'.$slug.'/'.$token);
    }

    public function publicCustomerPdfUrl(Invoice $invoice): ?string
    {
        $base = $this->publicCustomerViewUrl($invoice);

        return $base !== null ? $base.'/pdf' : null;
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

        $fresh = $invoice->fresh(['items', 'clientAccount']);
        $this->sendInvoiceSentDevNotification($fresh);

        return $fresh;
    }

    private function sendInvoiceSentDevNotification(Invoice $invoice): void
    {
        $email = config('billing.invoice_send_dev_email');
        if (! is_string($email) || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        try {
            Mail::to($email)->send(new InvoiceSentMailable($invoice));
        } catch (\Throwable $e) {
            Log::warning('invoice.sent_dev_notification_failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
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
        array $meta
    ): void {
        $metaOut = $meta;
        $message = null;
        if (array_key_exists('history_message', $metaOut)) {
            $message = $metaOut['history_message'];
            unset($metaOut['history_message']);
        }
        $eventType = $metaOut['event_type'] ?? $this->inferHistoryEventType($action);
        unset($metaOut['event_type']);

        InvoiceHistory::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $actor !== null ? $actor->id : null,
            'actor_name' => $actor !== null ? $actor->name : null,
            'event_type' => $eventType,
            'message' => $message,
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'meta' => $metaOut ?: null,
        ]);
    }

    private function inferHistoryEventType(string $action): string
    {
        switch ($action) {
            case 'created':
                return InvoiceHistoryEventType::LINE_ADD;
            case 'updated':
                return InvoiceHistoryEventType::HEADER_EDIT;
            case 'sent':
            case 'payment_applied':
            case 'voided':
                return InvoiceHistoryEventType::STATUS;
            default:
                return InvoiceHistoryEventType::HEADER_EDIT;
        }
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
            'customer_view_url' => $this->publicCustomerViewUrl($invoice),
            'customer_pdf_url' => $this->publicCustomerPdfUrl($invoice),
            'paid_at' => $invoice->paid_at !== null ? $invoice->paid_at->toIso8601String() : null,
            'created_by' => $invoice->createdBy ? [
                'id' => $invoice->createdBy->id,
                'name' => $invoice->createdBy->name,
            ] : null,
            'items' => $invoice->items->map(static function (InvoiceItem $item) {
                return [
                    'id' => $item->id,
                    'sort_order' => $item->sort_order,
                    'category' => $item->category,
                    'subtype' => $item->subtype,
                    'group_key' => $item->group_key,
                    'display_name' => $item->display_name,
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
                    'event_type' => $h->event_type,
                    'message' => $h->message,
                    'action' => $h->action,
                    'from_status' => $h->from_status,
                    'to_status' => $h->to_status,
                    'meta' => $h->meta,
                    'actor_name' => $h->actor_name,
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

        return $q->paginate($perPage)->through(function (Invoice $inv) {
            return $this->toListArray($inv);
        });
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
            ->map(function ($n) {
                return (int) $n;
            })
            ->all();

        return [
            'open_balance_due_cents' => $openBalance,
            'overdue_invoice_count' => $overdueCount,
            'draft_invoice_count' => $draftCount,
            'paid_mtd_cents' => $paidMtdCents,
            'counts_by_status' => $countsByStatus,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pdfViewData(Invoice $invoice): array
    {
        $invoice->load(['items', 'clientAccount']);

        $fmtLong = static function ($value) {
            if ($value === null) {
                return null;
            }
            try {
                if ($value instanceof \Carbon\Carbon) {
                    return $value->format('F j, Y');
                }

                return \Carbon\Carbon::parse($value)->format('F j, Y');
            } catch (\Exception $e) {
                return null;
            }
        };

        $currency = $invoice->currency ?: 'USD';
        $sym = $currency === 'USD' ? '$' : $currency.' ';
        $money = static function ($cents) use ($sym) {
            return $sym.number_format(((int) $cents) / 100, 2);
        };

        $items = [];
        foreach ($invoice->items as $item) {
            $detail = $item->sku;
            if ($detail === null || $detail === '') {
                $detail = $item->service_code;
            }
            $items[] = [
                'item' => $item->display_name !== null && $item->display_name !== ''
                    ? $item->display_name
                    : $item->description,
                'description' => $detail !== null && $detail !== '' ? $detail : '—',
                'quantity' => number_format((float) $item->quantity, 1, '.', ''),
                'unit' => $money((int) $item->unit_price_cents),
                'line_total' => $money((int) $item->line_total_cents),
            ];
        }

        return [
            'issuer_name' => config('app.name'),
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'client_company_name' => $invoice->clientAccount !== null ? $invoice->clientAccount->company_name : '',
            'issued_long' => $fmtLong($invoice->issued_at),
            'due_long' => $fmtLong($invoice->due_at),
            'payment_terms' => $invoice->payment_terms,
            'po_number' => $invoice->po_number,
            'items' => $items,
            'subtotal' => $money((int) $invoice->subtotal_cents),
            'tax' => $money((int) $invoice->tax_cents),
            'total' => $money((int) $invoice->total_cents),
            'amount_paid' => $money((int) $invoice->amount_paid_cents),
            'balance_due' => $money((int) $invoice->balance_due_cents),
            'customer_notes' => $invoice->customer_notes,
        ];
    }

    /**
     * Same keys as {@see pdfViewData}, plus `line_sections` for the public HTML invoice (grouped by category).
     *
     * @return array<string, mixed>
     */
    public function publicInvoiceHtmlData(Invoice $invoice): array
    {
        $base = $this->pdfViewData($invoice);
        $invoice->loadMissing('items');
        $currency = $invoice->currency ?: 'USD';
        $sym = $currency === 'USD' ? '$' : $currency.' ';
        $money = static function ($cents) use ($sym) {
            return $sym.number_format(((int) $cents) / 100, 2);
        };

        $order = [
            InvoiceLineCategory::FULFILLMENT,
            InvoiceLineCategory::POSTAGE,
            InvoiceLineCategory::PACKAGING,
            InvoiceLineCategory::RETURNS,
            InvoiceLineCategory::STORAGE,
            InvoiceLineCategory::AD_HOC,
            InvoiceLineCategory::CREDITS,
            InvoiceLineCategory::OTHER,
        ];

        $byCat = [];
        foreach ($invoice->items as $item) {
            $cat = $item->category !== null && $item->category !== ''
                ? (string) $item->category
                : InvoiceLineCategory::OTHER;
            if (! isset($byCat[$cat])) {
                $byCat[$cat] = [];
            }
            $byCat[$cat][] = $item;
        }

        $sections = [];
        $seen = [];
        $push = function (string $cat) use (&$sections, &$seen, $byCat, $money) {
            if (! isset($byCat[$cat]) || $byCat[$cat] === []) {
                return;
            }
            $seen[$cat] = true;
            $list = $byCat[$cat];
            usort($list, static function ($a, $b) {
                return (int) $a->sort_order <=> (int) $b->sort_order;
            });
            $totalCents = 0;
            $qtySum = 0.0;
            foreach ($list as $it) {
                $totalCents += (int) $it->line_total_cents;
                $qtySum += (float) $it->quantity;
            }
            $unitAvgCents = $qtySum > 0.0 ? (int) round($totalCents / $qtySum) : 0;
            $lines = [];
            foreach ($list as $it) {
                $lines[] = $this->publicHtmlLineRow($it, $money);
            }
            $sections[] = [
                'key' => $cat,
                'label' => $this->invoiceCategoryPublicLabel($cat),
                'qty_display' => number_format($qtySum, 1, '.', ''),
                'unit' => $money($unitAvgCents),
                'line_total' => $money($totalCents),
                'lines' => $lines,
            ];
        };

        foreach ($order as $cat) {
            $push($cat);
        }
        foreach (array_keys($byCat) as $cat) {
            if (! isset($seen[$cat])) {
                $push($cat);
            }
        }

        return array_merge($base, ['line_sections' => $sections]);
    }

    /**
     * @param  callable(int): string  $money
     * @return array{item: string, description: string, quantity: string, unit: string, line_total: string}
     */
    private function publicHtmlLineRow(InvoiceItem $item, callable $money): array
    {
        $disp = trim((string) ($item->display_name ?? ''));
        $desc = trim((string) ($item->description ?? ''));
        $primary = $disp !== '' ? $disp : ($desc !== '' ? $desc : '—');
        $bits = [];
        if ($desc !== '' && $desc !== $disp) {
            $bits[] = $desc;
        }
        if ($item->service_code !== null && (string) $item->service_code !== '') {
            $bits[] = (string) $item->service_code;
        }
        if ($item->sku !== null && (string) $item->sku !== '') {
            $bits[] = (string) $item->sku;
        }
        $detail = $bits !== [] ? implode(' · ', $bits) : '—';

        return [
            'item' => $primary,
            'description' => $detail,
            'quantity' => number_format((float) $item->quantity, 1, '.', ''),
            'unit' => $money((int) $item->unit_price_cents),
            'line_total' => $money((int) $item->line_total_cents),
        ];
    }

    private function invoiceCategoryPublicLabel(string $cat): string
    {
        $map = [
            InvoiceLineCategory::FULFILLMENT => 'Fulfillment',
            InvoiceLineCategory::POSTAGE => 'Postage',
            InvoiceLineCategory::PACKAGING => 'Packaging',
            InvoiceLineCategory::RETURNS => 'Returns',
            InvoiceLineCategory::STORAGE => 'Storage',
            InvoiceLineCategory::AD_HOC => 'Ad Hoc',
            InvoiceLineCategory::CREDITS => 'Credits',
            InvoiceLineCategory::OTHER => 'Other',
        ];

        return $map[$cat] ?? Str::title(str_replace('_', ' ', $cat));
    }
}
