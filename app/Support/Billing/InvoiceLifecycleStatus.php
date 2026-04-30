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

    public const COLLECTION = 'collection';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** @return list<string> */
    public static function canonical(): array
    {
        return [
            self::DRAFT,
            self::PENDING,
            self::OPEN,
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
            'past_due' => self::OPEN,
            self::COLLECTION => self::COLLECTION,
            self::PAID => self::PAID,
            self::VOID => self::VOID,
            self::DRAFT => self::DRAFT,
        ];

        return $map[$stored] ?? $stored;
    }

    /**
     * Past due is derived: open (or legacy sent/partial) with due date before start of today.
     */
    public static function isPastDue(Invoice $invoice): bool
    {
        if ($invoice->due_at === null) {
            return false;
        }
        $openLike = in_array($invoice->status, [
            self::OPEN,
            self::PENDING,
            Invoice::STATUS_SENT,
            Invoice::STATUS_PARTIAL,
        ], true);

        return $openLike
            && $invoice->due_at->copy()->startOfDay()->lt(now()->startOfDay());
    }
}
