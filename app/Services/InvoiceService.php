<?php

namespace App\Services;

use App\Mail\InvoiceSentMailable;
use App\Models\Invoice;
use App\Support\Billing\InvoiceHistoryEventType;
use App\Support\Billing\InvoiceLineCategory;
use App\Models\InvoiceHistory;
use App\Models\InvoiceItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
        $prefix = 'INV-';
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

    /**
     * @param  array<string, mixed>  $item
     */
    public function addInvoiceItem(Invoice $invoice, array $item, ?User $actor): Invoice
    {
        if ($invoice->isVoid()) {
            throw new \RuntimeException('Cannot add items to a void invoice.');
        }

        return DB::transaction(function () use ($invoice, $item, $actor) {
            $invoice->refresh();
            $order = (int) $invoice->items()->max('sort_order') + 1;
            if (! isset($item['group_key']) || trim((string) $item['group_key']) === '') {
                $seed = (string) ($item['display_name'] ?? $item['description'] ?? 'item');
                $item['group_key'] = 'manual:'.Str::slug($seed !== '' ? $seed : 'item');
            }
            $this->insertInvoiceItemRow($invoice, $order, $item);
            $from = $invoice->status;
            $this->recalculateTotals($invoice->refresh());
            $invoice->save();
            $this->logHistory($invoice, $actor, 'item_added', $from, $invoice->status, [
                'event_type' => InvoiceHistoryEventType::LINE_ADD,
                'history_message' => 'Added invoice line item.',
            ]);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    public function addCcFee(Invoice $invoice, int $amountCents, string $label, ?User $actor): Invoice
    {
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('CC fee amount must be positive.');
        }

        return $this->addInvoiceItem($invoice, [
            'description' => $label,
            'display_name' => $label,
            'category' => InvoiceLineCategory::AD_HOC,
            'quantity' => 1,
            'unit_price_cents' => $amountCents,
            'line_total_cents' => $amountCents,
            'group_key' => 'cc_fee:'.Str::slug($label),
        ], $actor);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function updateInvoiceItem(Invoice $invoice, int $itemId, array $item, ?User $actor): Invoice
    {
        if (! $invoice->isEditableDraft()) {
            throw new \RuntimeException('Only draft invoice items can be edited.');
        }

        return DB::transaction(function () use ($invoice, $itemId, $item, $actor) {
            $target = $invoice->items()->whereKey($itemId)->first();
            if ($target === null) {
                throw new \InvalidArgumentException('Invoice item was not found.');
            }
            $target->fill([
                'category' => $item['category'] ?? $target->category,
                'subtype' => $item['subtype'] ?? $target->subtype,
                'group_key' => $item['group_key'] ?? $target->group_key,
                'description' => $item['description'] ?? $target->description,
                'display_name' => $item['display_name'] ?? $target->display_name,
                'sku' => $item['sku'] ?? $target->sku,
                'service_code' => $item['service_code'] ?? $target->service_code,
                'quantity' => $item['quantity'] ?? $target->quantity,
                'unit' => $item['unit'] ?? $target->unit,
                'unit_price_cents' => $item['unit_price_cents'] ?? $target->unit_price_cents,
                'line_total_cents' => $item['line_total_cents'] ?? $target->line_total_cents,
                'metadata' => $item['metadata'] ?? $target->metadata,
            ]);
            $target->save();

            $this->recalculateTotals($invoice->refresh());
            $invoice->save();
            $this->logHistory($invoice, $actor, 'item_updated', Invoice::STATUS_DRAFT, Invoice::STATUS_DRAFT, [
                'event_type' => InvoiceHistoryEventType::LINE_EDIT,
                'history_message' => 'Updated invoice line item.',
                'item_id' => $itemId,
            ]);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    public function deleteInvoiceItem(Invoice $invoice, int $itemId, ?User $actor): Invoice
    {
        if (! $invoice->isEditableDraft()) {
            throw new \RuntimeException('Only draft invoice items can be deleted.');
        }

        return DB::transaction(function () use ($invoice, $itemId, $actor) {
            $deleted = $invoice->items()->whereKey($itemId)->delete();
            if ($deleted < 1) {
                throw new \InvalidArgumentException('Invoice item was not found.');
            }

            $this->recalculateTotals($invoice->refresh());
            $invoice->save();
            $this->logHistory($invoice, $actor, 'item_deleted', Invoice::STATUS_DRAFT, Invoice::STATUS_DRAFT, [
                'event_type' => InvoiceHistoryEventType::LINE_DELETE,
                'history_message' => 'Deleted invoice line item.',
                'item_id' => $itemId,
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

        return $invoice->fresh(['items', 'clientAccount']);
    }

    /**
     * @return array{recipients: list<string>}
     */
    public function sendInvoiceEmail(Invoice $invoice, ?User $actor, ?string $customMessage = null): array
    {
        if ($invoice->isVoid()) {
            throw new \RuntimeException('Void invoices cannot be emailed.');
        }
        $invoice = $this->ensureShareToken($invoice);
        $invoice->loadMissing('clientAccount');
        $recipients = $this->invoiceRecipientEmails($invoice);
        if ($recipients === []) {
            throw new \RuntimeException('Client account does not have a valid billing email.');
        }
        $url = $this->publicCustomerViewUrl($invoice);

        try {
            Mail::to($recipients)->send(new InvoiceSentMailable($invoice, $url, $customMessage));
            $this->logHistory($invoice, $actor, 'emailed', $invoice->status, $invoice->status, [
                'event_type' => InvoiceHistoryEventType::STATUS,
                'history_message' => 'Invoice email sent.',
                'recipients' => $recipients,
            ]);
        } catch (\Throwable $e) {
            Log::warning('invoice.send_email_failed', [
                'invoice_id' => $invoice->id,
                'recipients' => $recipients,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return ['recipients' => $recipients];
    }

    /**
     * @return array{provider_status: int, to: string, type: string}
     */
    public function sendInvoiceWhatsapp(Invoice $invoice, ?User $actor, string $type = 'send_invoice', ?string $customMessage = null): array
    {
        if ($invoice->isVoid()) {
            throw new \RuntimeException('Void invoices cannot be sent via WhatsApp.');
        }
        $invoice = $this->ensureShareToken($invoice);
        $invoice->loadMissing('clientAccount');
        $target = trim((string) ($invoice->clientAccount->whatsapp_e164 ?? ''));
        if ($target === '') {
            throw new \RuntimeException('Client account does not have a WhatsApp number.');
        }
        $endpoint = (string) config('billing.whatsapp.endpoint', '');
        if ($endpoint === '') {
            throw new \RuntimeException('WhatsApp provider endpoint is not configured.');
        }
        $invoiceUrl = $this->publicCustomerViewUrl($invoice);
        if ($invoiceUrl === null) {
            throw new \RuntimeException('Could not generate invoice public link.');
        }
        $message = $customMessage ?: $this->defaultWhatsappMessage($invoice, $invoiceUrl, $type);
        $timeout = max(3, (int) config('billing.whatsapp.timeout_seconds', 20));

        $req = Http::timeout($timeout)->acceptJson();
        $token = trim((string) config('billing.whatsapp.api_token', ''));
        if ($token !== '') {
            $req = $req->withToken($token);
        }

        $response = $req->post($endpoint, [
            'chat_id' => $target,
            'message' => $message,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'type' => $type,
            'url' => $invoiceUrl,
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('WhatsApp provider rejected the request.');
        }

        $this->logHistory($invoice, $actor, 'whatsapp_sent', $invoice->status, $invoice->status, [
            'event_type' => InvoiceHistoryEventType::STATUS,
            'history_message' => 'Invoice WhatsApp message sent.',
            'to' => $target,
            'type' => $type,
        ]);

        return [
            'provider_status' => $response->status(),
            'to' => $target,
            'type' => $type,
        ];
    }

    /**
     * @param  array<string, mixed>  $paymentMeta
     */
    public function recordPayment(Invoice $invoice, int $amountCents, ?User $actor, array $paymentMeta = []): Invoice
    {
        if ($invoice->isVoid() || $invoice->status === Invoice::STATUS_DRAFT) {
            throw new \RuntimeException('Cannot record payment on this invoice.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }

        return DB::transaction(function () use ($invoice, $amountCents, $actor, $paymentMeta) {
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
            ] + $paymentMeta);

            return $invoice->fresh(['items', 'clientAccount']);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentAllocationContext(Invoice $invoice): array
    {
        $invoice->loadMissing('clientAccount');
        if ($invoice->clientAccount === null) {
            throw new \RuntimeException('Invoice account is unavailable.');
        }

        $payable = Invoice::query()
            ->where('client_account_id', $invoice->client_account_id)
            ->where('status', '!=', Invoice::STATUS_DRAFT)
            ->where('status', '!=', Invoice::STATUS_VOID)
            ->where('balance_due_cents', '>', 0)
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('id')
            ->get();

        $pending = Invoice::query()
            ->where('client_account_id', $invoice->client_account_id)
            ->where('status', Invoice::STATUS_DRAFT)
            ->sum('balance_due_cents');

        $open = 0;
        $pastDue = 0;
        $rows = [];
        foreach ($payable as $row) {
            $isPastDue = $this->isOverdue($row);
            $balance = (int) $row->balance_due_cents;
            if ($isPastDue) {
                $pastDue += $balance;
            } else {
                $open += $balance;
            }

            $rows[] = [
                'id' => (int) $row->id,
                'row_key' => 'b-'.(int) $row->id,
                'type' => 'Beta',
                'status' => (string) $row->status,
                'is_overdue' => $isPastDue,
                'invoice_number' => (string) $row->invoice_number,
                'due_in' => $row->due_at !== null ? now()->startOfDay()->diffInDays($row->due_at->copy()->startOfDay(), false) : null,
                'due_date' => $row->due_at !== null ? $row->due_at->toDateString() : null,
                'balance_cents' => $balance,
            ];
        }

        return [
            'account' => [
                'id' => (int) $invoice->clientAccount->id,
                'name' => (string) $invoice->clientAccount->company_name,
            ],
            'current_invoice_id' => (int) $invoice->id,
            'available_funds_cents' => 0,
            'open_balance_cents' => $open,
            'past_due_balance_cents' => $pastDue,
            'pending_balance_cents' => (int) $pending,
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<int>  $invoiceIds
     * @param  array<string, mixed>  $paymentMeta
     * @return array{invoice: Invoice, allocations: list<array<string, mixed>>, remaining_amount_cents: int}
     */
    public function allocatePaymentAcrossInvoices(
        Invoice $rootInvoice,
        array $invoiceIds,
        int $amountCents,
        ?User $actor,
        array $paymentMeta = []
    ): array {
        if ($rootInvoice->isVoid() || $rootInvoice->status === Invoice::STATUS_DRAFT) {
            throw new \RuntimeException('Cannot record payment on this invoice.');
        }
        if ($amountCents <= 0) {
            throw new \InvalidArgumentException('Amount must be positive.');
        }
        if ($invoiceIds === []) {
            throw new \InvalidArgumentException('Select at least one invoice.');
        }

        $rootInvoice->loadMissing('clientAccount');
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));

        return DB::transaction(function () use ($rootInvoice, $invoiceIds, $amountCents, $actor, $paymentMeta) {
            $selected = Invoice::query()
                ->where('client_account_id', $rootInvoice->client_account_id)
                ->whereIn('id', $invoiceIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if (count($selected) !== count($invoiceIds)) {
                throw new \RuntimeException('Selected invoice set is invalid.');
            }

            $remaining = $amountCents;
            $allocations = [];
            foreach ($invoiceIds as $invoiceId) {
                /** @var Invoice $target */
                $target = $selected[$invoiceId];
                if ($target->isVoid() || $target->status === Invoice::STATUS_DRAFT) {
                    throw new \RuntimeException('Draft or void invoices cannot be paid from this flow.');
                }

                $target->refresh();
                $balance = (int) $target->balance_due_cents;
                if ($balance <= 0 || $remaining <= 0) {
                    continue;
                }

                $apply = min($balance, $remaining);
                $updated = $this->recordPayment($target, $apply, $actor, $paymentMeta + [
                    'allocated_via_invoice_id' => (int) $rootInvoice->id,
                ]);
                $remaining -= $apply;

                $allocations[] = [
                    'invoice_id' => (int) $updated->id,
                    'invoice_number' => (string) $updated->invoice_number,
                    'applied_cents' => $apply,
                    'remaining_balance_cents' => (int) $updated->balance_due_cents,
                    'status' => (string) $updated->status,
                ];
            }

            return [
                'invoice' => $rootInvoice->fresh(['items', 'histories.user', 'clientAccount', 'createdBy']) ?? $rootInvoice,
                'allocations' => $allocations,
                'remaining_amount_cents' => $remaining,
            ];
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
            case 'emailed':
            case 'whatsapp_sent':
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
        $date = $this->invoiceDatePayload($invoice);

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
            'due_in' => $invoice->due_at !== null ? now()->startOfDay()->diffInDays($invoice->due_at->copy()->startOfDay(), false) : null,
            'issued_at' => $invoice->issued_at !== null ? $invoice->issued_at->toIso8601String() : null,
            'due_at' => $invoice->due_at !== null ? $invoice->due_at->toIso8601String() : null,
            'invoice_date' => $date['invoice_date'],
            'invoice_date_from' => $date['invoice_date_from'],
            'invoice_date_to' => $date['invoice_date_to'],
            'invoice_date_label' => $date['invoice_date_label'],
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
        $presentation = $this->staffDetailPresentation($invoice);
        $date = $this->invoiceDatePayload($invoice);

        return array_merge($base, [
            'amount_paid_cents' => $invoice->amount_paid_cents,
            'subtotal_cents' => $invoice->subtotal_cents,
            'tax_cents' => $invoice->tax_cents,
            'tax_rate_basis_points' => $invoice->tax_rate_basis_points,
            'billing_period_start' => $invoice->billing_period_start !== null ? $invoice->billing_period_start->toDateString() : null,
            'billing_period_end' => $invoice->billing_period_end !== null ? $invoice->billing_period_end->toDateString() : null,
            'invoice_date' => $date['invoice_date'],
            'invoice_date_from' => $date['invoice_date_from'],
            'invoice_date_to' => $date['invoice_date_to'],
            'invoice_date_label' => $date['invoice_date_label'],
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
            'presentation' => $presentation,
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

    /**
     * @return array<string, mixed>
     */
    private function staffDetailPresentation(Invoice $invoice): array
    {
        $invoice->loadMissing('items');

        $fulfillment = [];
        $postage = [];
        $packaging = [];
        $adHoc = [];
        $returns = [];
        $onDemand = [];
        $otherItems = [];

        foreach ($invoice->items as $item) {
            $category = strtolower(trim((string) $item->category));
            if ($category === InvoiceLineCategory::FULFILLMENT) {
                $name = $this->oldBetaDisplayName($item);
                $key = $name;
                if (! isset($fulfillment[$key])) {
                    $fulfillment[$key] = ['name' => $name, 'items' => [], 'qty_sum' => 0.0, 'total_sum' => 0];
                }
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $fulfillment[$key]['items'][] = $this->detailLeafRow($item, 'Fulfillment', $name, $unitRate, $total);
                $fulfillment[$key]['qty_sum'] += $qty;
                $fulfillment[$key]['total_sum'] += $total;
            } elseif ($category === InvoiceLineCategory::POSTAGE) {
                $rawName = $this->oldBetaDisplayName($item, 'Other');
                $key = strtolower($rawName);
                if (! isset($postage[$key])) {
                    $postage[$key] = ['name' => $rawName, 'items' => [], 'qty' => 0.0, 'total' => 0];
                }
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $postage[$key]['items'][] = $this->detailLeafRow($item, 'Postage', $rawName, $unitRate, $total);
                $postage[$key]['qty'] += $qty;
                $postage[$key]['total'] += $total;
            } elseif ($category === InvoiceLineCategory::PACKAGING) {
                $rawName = $this->packagingDisplayName($this->oldBetaDisplayName($item, 'Other'));
                $key = strtolower($rawName);
                if (! isset($packaging[$key])) {
                    $packaging[$key] = ['name' => $rawName, 'items' => [], 'qty' => 0.0, 'total' => 0];
                }
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $packaging[$key]['items'][] = $this->detailLeafRow($item, $this->oldBetaPackagingType($rawName), $rawName, $unitRate, $total);
                $packaging[$key]['qty'] += $qty;
                $packaging[$key]['total'] += $total;
            } elseif ($category === InvoiceLineCategory::AD_HOC || $category === 'ad hoc') {
                $rawName = $this->oldBetaDisplayName($item, 'Ad Hoc');
                $key = strtolower($rawName);
                if (! isset($adHoc[$key])) {
                    $adHoc[$key] = ['name' => $rawName, 'items' => [], 'qty' => 0.0, 'total' => 0];
                }
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $adHoc[$key]['items'][] = $this->detailLeafRow($item, 'Ad Hoc', $rawName, $unitRate, $total);
                $adHoc[$key]['qty'] += $qty;
                $adHoc[$key]['total'] += $total;
            } elseif ($category === InvoiceLineCategory::RETURNS) {
                $name = $this->oldBetaDisplayName($item, 'Returns');
                $key = $name;
                if (! isset($returns[$key])) {
                    $returns[$key] = ['name' => $name, 'items' => [], 'qty_sum' => 0.0, 'total_sum' => 0];
                }
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $returns[$key]['items'][] = $this->detailLeafRow($item, 'Returns', $name, $unitRate, $total);
                $returns[$key]['qty_sum'] += $qty;
                $returns[$key]['total_sum'] += $total;
            } elseif ($this->isOnDemandProductCategory($category)) {
                $rawName = $this->oldBetaDisplayName($item, 'On-Demand Product');
                $key = strtolower($rawName);
                if (! isset($onDemand[$key])) {
                    $onDemand[$key] = ['name' => $rawName, 'items' => [], 'qty' => 0.0, 'total' => 0];
                }
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $onDemand[$key]['items'][] = $this->detailLeafRow($item, 'Product (On-Demand)', $rawName, $unitRate, $total);
                $onDemand[$key]['qty'] += $qty;
                $onDemand[$key]['total'] += $total;
            } else {
                $qty = (float) $item->quantity;
                $total = (int) $item->line_total_cents;
                $unitRate = (int) $item->unit_price_cents;
                if ($unitRate === 0 && $qty != 0.0 && $total !== 0) {
                    $unitRate = (int) round($total / $qty);
                }
                $name = $this->oldBetaDisplayName($item, $item->category ?: 'Other');
                $type = $this->oldBetaCategoryLabel((string) $item->category, $name);
                $otherItems[] = [
                    'id' => 'other-'.$item->id,
                    'name' => $name,
                    'type' => $type,
                    'qty' => $qty,
                    'price_cents' => $unitRate,
                    'total_cents' => $total,
                    'groupKey' => strtolower((string) ($item->category ?: InvoiceLineCategory::OTHER)),
                    'groupName' => $name,
                    'line_group_key' => $item->group_key,
                    'details' => [],
                ];
            }
        }

        $rows = [];
        foreach ($fulfillment as $fulfillKey => $agg) {
            $qty = (float) $agg['qty_sum'];
            $total = (int) $agg['total_sum'];
            if ($qty != 0.0 || $total !== 0) {
                $rows[] = [
                    'id' => 'fulfillment-'.md5($fulfillKey),
                    'name' => $agg['name'],
                    'type' => 'Fulfillment',
                    'qty' => $qty,
                    'price_cents' => $qty != 0.0 ? (int) round($total / $qty) : 0,
                    'total_cents' => $total,
                    'groupKey' => 'fulfillment',
                    'groupName' => $fulfillKey,
                    'line_group_key' => $this->singleGroupKey($agg['items']),
                    'details' => $agg['items'],
                ];
            }
        }
        foreach ($postage as $carrierKey => $agg) {
            $qty = (float) $agg['qty'];
            $total = (int) $agg['total'];
            $rows[] = [
                'id' => 'post-'.$carrierKey,
                'name' => $agg['name'],
                'type' => 'Postage',
                'qty' => $qty,
                'price_cents' => $qty != 0.0 ? (int) round($total / $qty) : 0,
                'total_cents' => $total,
                'groupKey' => 'postage',
                'groupName' => $agg['name'],
                'line_group_key' => $this->singleGroupKey($agg['items']),
                'details' => $agg['items'],
            ];
        }
        foreach ($packaging as $boxKey => $agg) {
            $qty = (float) $agg['qty'];
            $total = (int) $agg['total'];
            $rows[] = [
                'id' => 'pkg-'.$boxKey,
                'name' => $agg['name'],
                'type' => $this->oldBetaPackagingType($agg['name']),
                'qty' => $qty,
                'price_cents' => $qty != 0.0 ? (int) round($total / $qty) : 0,
                'total_cents' => $total,
                'groupKey' => 'packaging',
                'groupName' => $agg['name'],
                'line_group_key' => $this->singleGroupKey($agg['items']),
                'details' => $agg['items'],
            ];
        }
        foreach ($adHoc as $key => $agg) {
            $qty = (float) $agg['qty'];
            $total = (int) $agg['total'];
            $rows[] = [
                'id' => 'adhoc-'.$key,
                'name' => $agg['name'],
                'type' => 'Ad Hoc',
                'qty' => $qty,
                'price_cents' => $qty != 0.0 ? (int) round($total / $qty) : 0,
                'total_cents' => $total,
                'groupKey' => 'ad_hoc',
                'groupName' => $agg['name'],
                'line_group_key' => $this->singleGroupKey($agg['items']),
                'details' => $agg['items'],
            ];
        }
        foreach ($returns as $returnKey => $agg) {
            $qty = (float) $agg['qty_sum'];
            $total = (int) $agg['total_sum'];
            if ($qty != 0.0 || $total !== 0) {
                $rows[] = [
                    'id' => 'returns-'.md5($returnKey),
                    'name' => $agg['name'],
                    'type' => 'Returns',
                    'qty' => $qty,
                    'price_cents' => $qty != 0.0 ? (int) round($total / $qty) : 0,
                    'total_cents' => $total,
                    'groupKey' => 'returns',
                    'groupName' => $returnKey,
                    'line_group_key' => $this->singleGroupKey($agg['items']),
                    'details' => $agg['items'],
                ];
            }
        }
        foreach ($onDemand as $productKey => $agg) {
            $qty = (float) $agg['qty'];
            $total = (int) $agg['total'];
            $rows[] = [
                'id' => 'ondemand-'.$productKey,
                'name' => $agg['name'],
                'type' => 'Product (On-Demand)',
                'qty' => $qty,
                'price_cents' => $qty != 0.0 ? (int) round($total / $qty) : 0,
                'total_cents' => $total,
                'groupKey' => 'on_demand',
                'groupName' => $agg['name'],
                'line_group_key' => $this->singleGroupKey($agg['items']),
                'details' => $agg['items'],
            ];
        }
        foreach ($otherItems as $item) {
            $rows[] = $item;
        }

        usort($rows, function (array $a, array $b) {
            $ai = $this->oldBetaTypeOrder($a['type']);
            $bi = $this->oldBetaTypeOrder($b['type']);
            if ($ai !== $bi) {
                return $ai < $bi ? -1 : 1;
            }
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });

        return [
            'rows' => $rows,
            'has_grouped_rows' => collect($rows)->contains(function (array $row) {
                return $row['details'] !== [];
            }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailLeafRow(InvoiceItem $item, string $type, string $name, int $unitRate, int $total): array
    {
        return [
            'id' => $item->id,
            'name' => $name,
            'type' => $type,
            'qty' => (float) $item->quantity,
            'price_cents' => $unitRate,
            'total_cents' => $total,
            'display_name' => $item->display_name,
            'description' => $item->description,
            'sku' => $item->sku,
            'service_code' => $item->service_code,
            'group_key' => $item->group_key,
            'category' => $item->category,
            'subtype' => $item->subtype,
            'unit' => $item->unit,
            'metadata' => $item->metadata,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function singleGroupKey(array $items): ?string
    {
        $keys = [];
        foreach ($items as $item) {
            $key = trim((string) ($item['group_key'] ?? ''));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        $all = array_keys($keys);
        if (count($all) !== 1) {
            return null;
        }

        return $all[0];
    }

    private function oldBetaDisplayName(InvoiceItem $item, string $fallback = '—'): string
    {
        $name = trim((string) ($item->display_name ?: $item->description ?: ''));
        if ($name === '') {
            $name = $fallback;
        }
        $n = strtolower($name);
        if ($n === 'first_pick_charge') return 'Fulfillment (First Pick)';
        if ($n === 'pick_remainder_charge') return 'Fulfillment (Additional Pick)';
        if ($n === 'first_return_charge') return 'Returns (First Item)';
        if ($n === 'return_remainder_charge') return 'Returns (Additional Items)';
        if (strtolower((string) $item->category) === InvoiceLineCategory::PACKAGING && strpos($n, 'basic box') !== false) {
            return 'Ship As Is';
        }
        return $name;
    }

    private function oldBetaPackagingType(string $name): string
    {
        $norm = strtolower(trim($name));
        if (in_array($norm, ['bubble wrap', 'kraft paper', 'bubble wrap & kraft paper'], true)) {
            return $name;
        }
        return 'Packaging';
    }

    private function packagingDisplayName(string $name): string
    {
        $n = strtolower(trim($name));
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;
        if ($n === '') {
            return 'Other';
        }
        $hasBubble = strpos($n, 'bubble wrap') !== false;
        $hasKraft = strpos($n, 'kraft paper') !== false;
        if ($hasBubble && $hasKraft) {
            return 'Bubble Wrap & Kraft Paper';
        }
        if ($hasBubble) {
            return 'Bubble Wrap';
        }
        if ($hasKraft) {
            return 'Kraft Paper';
        }
        if (strpos($n, 'basic box') !== false || strpos($n, 'ship as is') !== false) {
            return 'Ship As Is';
        }
        if (preg_match('/^\(?\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)(?:\s*[x×]\s*(\d+(?:\.\d+)?))?\s*\)?$/i', $n, $m) === 1) {
            $parts = [$m[1], $m[2]];
            if (isset($m[3]) && trim((string) $m[3]) !== '') {
                $parts[] = $m[3];
            }

            return 'Box ('.implode(' X ', $parts).')';
        }
        if ($n === 'packaging') {
            return 'Other';
        }
        return $name;
    }

    private function oldBetaCategoryLabel(string $category, string $name = ''): string
    {
        $cat = strtolower(trim(str_replace('-', '_', $category)));
        switch ($cat) {
            case InvoiceLineCategory::FULFILLMENT:
                return 'Fulfillment';
            case InvoiceLineCategory::POSTAGE:
                return 'Postage';
            case InvoiceLineCategory::PACKAGING:
                return $this->oldBetaPackagingType($name);
            case InvoiceLineCategory::RETURNS:
                return 'Returns';
            case InvoiceLineCategory::ON_DEMAND:
                return 'Product (On-Demand)';
            case InvoiceLineCategory::AD_HOC:
                return 'Ad Hoc';
            case InvoiceLineCategory::STORAGE:
                return 'Storage';
            case InvoiceLineCategory::CREDITS:
                return 'Credits';
            default:
                return Str::title(str_replace('_', ' ', $cat !== '' ? $cat : InvoiceLineCategory::OTHER));
        }
    }

    private function oldBetaTypeOrder(string $type): int
    {
        $normalized = strtolower(trim($type));
        $map = [
            'fulfillment' => 10,
            'postage' => 20,
            'packaging' => 30,
            'bubble wrap' => 31,
            'kraft paper' => 32,
            'bubble wrap & kraft paper' => 33,
            'ad hoc' => 40,
            'returns' => 50,
            'product (on-demand)' => 60,
            'storage' => 70,
            'credits' => 80,
        ];
        return $map[$normalized] ?? 90;
    }

    private function isOnDemandProductCategory(string $category): bool
    {
        $c = strtolower(trim((string) preg_replace('/\s+/', ' ', $category)));
        return $c === 'product (on-demand)'
            || $c === 'product (on demand)'
            || $c === 'product on demand'
            || $c === 'on-demand'
            || $c === 'on demand'
            || $c === 'on_demand'
            || $c === 'skincare'
            || $c === 'skin care';
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
        $date = $this->invoiceDatePayload($invoice);
        $presentation = $this->staffDetailPresentation($invoice);
        $publicPresentation = $this->legacyPublicPresentation($invoice, $presentation);

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

        $groupedItems = [];
        foreach (($presentation['rows'] ?? []) as $row) {
            $groupedItems[] = [
                'name' => (string) ($row['name'] ?? '—'),
                'qty' => (float) ($row['qty'] ?? 0),
                'price' => ((int) ($row['price_cents'] ?? 0)) / 100,
                'total' => ((int) ($row['total_cents'] ?? 0)) / 100,
            ];
        }

        return [
            'issuer_name' => 'Save Rack',
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status,
            'client_company_name' => $invoice->clientAccount !== null ? $invoice->clientAccount->company_name : '',
            'issued_long' => $date['invoice_date_label'],
            'due_long' => $this->dateToMdY($invoice->due_at !== null ? $invoice->due_at->toDateString() : null),
            'invoice_date' => $date['invoice_date'],
            'invoice_date_from' => $date['invoice_date_from'],
            'invoice_date_to' => $date['invoice_date_to'],
            'invoice_date_label' => $date['invoice_date_label'],
            'payment_terms' => $invoice->payment_terms,
            'po_number' => $invoice->po_number,
            'items' => $items,
            'grouped_items' => $groupedItems,
            'public_sections' => $publicPresentation['sections'],
            'account_address' => $this->clientAccountAddress($invoice),
            'subtotal' => $money((int) $invoice->subtotal_cents),
            'tax' => $money((int) $invoice->tax_cents),
            'total' => $money((int) $invoice->total_cents),
            'amount_paid' => $money((int) $invoice->amount_paid_cents),
            'balance_due' => $money((int) $invoice->balance_due_cents),
            'total_amount' => ((int) $invoice->total_cents) / 100,
            'paid_amount' => ((int) $invoice->amount_paid_cents) / 100,
            'balance_amount' => ((int) $invoice->balance_due_cents) / 100,
            'invoice' => [
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $date['invoice_date'],
                'invoice_date_from' => $date['invoice_date_from'],
                'invoice_date_to' => $date['invoice_date_to'],
                'due_date' => $invoice->due_at !== null ? $invoice->due_at->toDateString() : null,
                'account' => $invoice->clientAccount !== null ? $invoice->clientAccount->company_name : '',
                'amount' => ((int) $invoice->balance_due_cents) / 100,
            ],
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
        return array_merge($base, ['line_sections' => $base['public_sections'] ?? []]);
    }

    /**
     * @param  array<string, mixed>|null  $presentation
     * @return array{sections: list<array<string, mixed>>}
     */
    private function legacyPublicPresentation(Invoice $invoice, ?array $presentation = null): array
    {
        $presentation = $presentation ?? $this->staffDetailPresentation($invoice);
        $currency = $invoice->currency ?: 'USD';
        $sym = $currency === 'USD' ? '$' : $currency.' ';
        $money = static function ($cents) use ($sym) {
            return $sym.number_format(((int) $cents) / 100, 2);
        };

        $rows = $presentation['rows'] ?? [];
        $sections = [];
        foreach ($rows as $row) {
            $details = is_array($row['details'] ?? null) ? $row['details'] : [];
            $lines = [];
            foreach ($details as $detail) {
                $lines[] = [
                    'name' => (string) ($detail['name'] ?? '—'),
                    'qty' => number_format((float) ($detail['qty'] ?? 0), 3, '.', ''),
                    'qty_display' => $this->formatLegacyQty((float) ($detail['qty'] ?? 0)),
                    'unit' => $money((int) ($detail['price_cents'] ?? 0)),
                    'line_total' => $money((int) ($detail['total_cents'] ?? 0)),
                ];
            }

            $sections[] = [
                'id' => (string) ($row['id'] ?? Str::uuid()),
                'label' => (string) ($row['name'] ?? '—'),
                'type' => (string) ($row['type'] ?? ''),
                'qty' => number_format((float) ($row['qty'] ?? 0), 3, '.', ''),
                'qty_display' => $this->formatLegacyQty((float) ($row['qty'] ?? 0)),
                'unit' => $money((int) ($row['price_cents'] ?? 0)),
                'line_total' => $money((int) ($row['total_cents'] ?? 0)),
                'has_breakdown' => $lines !== [],
                'lines' => $lines,
            ];
        }

        return ['sections' => $sections];
    }

    /**
     * @return array<string, string>|null
     */
    private function clientAccountAddress(Invoice $invoice): ?array
    {
        $invoice->loadMissing('clientAccount');
        $account = $invoice->clientAccount;
        if ($account === null) {
            return null;
        }

        $values = [
            'line1' => trim((string) ($account->street ?? '')),
            'line2' => '',
            'city' => trim((string) ($account->city ?? '')),
            'state' => trim((string) ($account->state ?? '')),
            'zip' => trim((string) ($account->zip ?? '')),
            'country' => trim((string) ($account->country ?? '')),
        ];

        $hasAny = false;
        foreach ($values as $value) {
            if ($value !== '') {
                $hasAny = true;
                break;
            }
        }

        return $hasAny ? $values : null;
    }

    private function formatLegacyQty(float $qty): string
    {
        if (floor($qty) === $qty) {
            return number_format($qty, 0, '.', '');
        }
        if (abs($qty) < 1) {
            return number_format($qty, 3, '.', '');
        }

        return number_format($qty, 2, '.', '');
    }

    /**
     * @return array{invoice_date: string|null, invoice_date_from: string|null, invoice_date_to: string|null, invoice_date_label: string}
     */
    private function invoiceDatePayload(Invoice $invoice): array
    {
        $date = $invoice->issued_at !== null
            ? $invoice->issued_at->toDateString()
            : ($invoice->created_at !== null ? $invoice->created_at->toDateString() : null);
        $from = $invoice->billing_period_start !== null ? $invoice->billing_period_start->toDateString() : null;
        $to = $invoice->billing_period_end !== null ? $invoice->billing_period_end->toDateString() : null;
        if ($date !== null && ($from === null || $to === null)) {
            try {
                $anchor = Carbon::parse($date);
                $from = $anchor->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
                $to = $anchor->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
            } catch (\Throwable $e) {
                // Keep existing fallback behavior below.
            }
        }
        $label = '—';
        if ($from !== null && $to !== null) {
            $label = $this->dateToMdY($from).' - '.$this->dateToMdY($to);
        } elseif ($from !== null) {
            $label = $this->dateToMdY($from);
        } elseif ($to !== null) {
            $label = $this->dateToMdY($to);
        } elseif ($date !== null) {
            $label = $this->dateToMdY($date);
        }

        return [
            'invoice_date' => $date,
            'invoice_date_from' => $from,
            'invoice_date_to' => $to,
            'invoice_date_label' => $label,
        ];
    }

    private function dateToMdY(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return '—';
        }
        try {
            return Carbon::parse($date)->format('m/d/Y');
        } catch (\Throwable $e) {
            return '—';
        }
    }

    /**
     * @return list<string>
     */
    private function invoiceRecipientEmails(Invoice $invoice): array
    {
        $emails = [];
        $account = $invoice->clientAccount;
        if ($account !== null && is_string($account->email) && filter_var($account->email, FILTER_VALIDATE_EMAIL)) {
            $emails[] = strtolower(trim($account->email));
        }

        return array_values(array_unique(array_filter($emails)));
    }

    private function defaultWhatsappMessage(Invoice $invoice, string $invoiceUrl, string $type): string
    {
        $label = $this->invoiceDatePayload($invoice)['invoice_date_label'];
        $balance = number_format(((int) $invoice->balance_due_cents) / 100, 2);
        if ($type === 'invoice_reminder') {
            return 'Invoice reminder: '.$invoice->invoice_number.' ('.$label.'). Balance due $'.$balance.'. '.$invoiceUrl;
        }

        return 'Invoice '.$invoice->invoice_number.' is ready ('.$label.'). View invoice: '.$invoiceUrl;
    }
}
