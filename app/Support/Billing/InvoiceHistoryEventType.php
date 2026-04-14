<?php

namespace App\Support\Billing;

/**
 * Invoice audit trail event kinds (header, lines, status, imports).
 */
final class InvoiceHistoryEventType
{
    public const STATUS = 'status';

    public const LINE_ADD = 'line_add';

    public const LINE_EDIT = 'line_edit';

    public const LINE_DELETE = 'line_delete';

    public const HEADER_EDIT = 'header_edit';

    public const IMPORT = 'import';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::STATUS,
            self::LINE_ADD,
            self::LINE_EDIT,
            self::LINE_DELETE,
            self::HEADER_EDIT,
            self::IMPORT,
        ];
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::all(), true);
    }
}
