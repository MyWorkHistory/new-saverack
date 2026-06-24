<?php

namespace App\Support\Billing;

use App\Models\Invoice;

/**
 * Target lifecycle statuses (spec §2). Legacy CRM rows may still use sent/partial;
 * use mapFromLegacy() / normalizeForDisplay() at API boundaries until data is migrated.
 */
final class InvoiceLifecycleStatus
{
    public const DRAFT = 'draft';

    public const PENDING = 'pending';

    public const OPEN = 'open';

    public const PAST_DUE = 'past_due';

    public const COLLECTION = 'collection';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** Days after due date before an open invoice is considered past due. */
    public const PAST_DUE_GRACE_DAYS = 3;

    /** @return list<string> */
    public static function canonical(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::OPEN,
            self::PAST_DUE,
            self::COLLECTION,
            self::PAID,
            self::VOID,
        ];
    }

    /**
     * Map stored status (legacy or canonical) to a canonical label for new UI.
     */
    public static function normalizeForDisplay(string $stored): string
    {
        $map = [
            Invoice::STATUS_DRAFT => self::DRAFT,
            Invoice::STATUS_SENT => self::OPEN,
            Invoice::STATUS_PARTIAL => self::OPEN,
            Invoice::STATUS_PAID => self::PAID,
            Invoice::STATUS_VOID => self::VOID,
            self::PENDING => self::PENDING,
            self::OPEN => self::OPEN,
            self::PAST_DUE => self::PAST_DUE,
            'past_due' => self::PAST_DUE,
            self::COLLECTION => self::COLLECTION,
            self::PAID => self::PAID,
            self::VOID => self::VOID,
            self::DRAFT => self::DRAFT,
        ];

        return $map[$stored] ?? $stored;
    }

    /**
     * Past due is derived: open-like invoice with balance due, due date + grace days on or before today.
     */
    public static function isPastDue(Invoice $invoice): bool
    {
        if ($invoice->due_at === null) {
            return false;
        }
        if ($invoice->isPaidLike() || $invoice->status === Invoice::STATUS_DRAFT) {
            return false;
        }
        if ((int) $invoice->balance_due_cents <= 0) {
            return false;
        }
        if (! self::isOpenLikeStoredStatus((string) $invoice->status)) {
            return false;
        }

        $pastDueStartsOn = $invoice->due_at->copy()->startOfDay()->addDays(self::PAST_DUE_GRACE_DAYS);

        return now()->startOfDay()->greaterThanOrEqualTo($pastDueStartsOn);
    }

    /**
     * Latest due_at date (inclusive) that is still not past due as of today.
     */
    public static function latestDueDateNotPastDue(): \Carbon\Carbon
    {
        return now()->startOfDay()->subDays(self::PAST_DUE_GRACE_DAYS);
    }

    public static function isOpenLikeStoredStatus(string $stored): bool
    {
        $status = strtolower(trim($stored));

        return in_array($status, [
            self::OPEN,
            self::PENDING,
            self::PAST_DUE,
            Invoice::STATUS_SENT,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_PROCESSING,
            Invoice::STATUS_PAYMENT_FAILED,
            self::COLLECTION,
        ], true);
    }
}
