<?php

namespace App\Support\Billing;

/**
 * Dedupes legacy customers_fees rows and resolves map keys for account pricing import.
 */
final class LegacyCustomerFeeImportMapper
{
    /**
     * @param  iterable<object|array<string, mixed>>  $rows
     * @return array<int, array<string, array{service: string, fee: float, category: ?string, legacy_category: ?string}>>
     */
    public static function dedupeByCustomerAndService(iterable $rows): array
    {
        /** @var array<int, array<string, object|array<string, mixed>>> $grouped */
        $grouped = [];

        foreach ($rows as $row) {
            $customerId = self::intVal(self::field($row, 'customer'));
            if ($customerId === null || $customerId <= 0) {
                continue;
            }

            $type = trim((string) (self::field($row, 'type') ?? ''));
            if (strcasecmp($type, 'Postage') === 0) {
                continue;
            }

            if ((int) (self::field($row, 'status') ?? 0) !== 1) {
                continue;
            }

            if ((int) (self::field($row, 'is_deleted') ?? 0) !== 1) {
                continue;
            }

            $service = trim((string) (self::field($row, 'service') ?? ''));
            if ($service === '') {
                continue;
            }

            $mapKey = self::mapKeyForRow($row, $service);
            if ($mapKey === null) {
                continue;
            }

            $grouped[$customerId][$mapKey][] = $row;
        }

        $result = [];
        foreach ($grouped as $customerId => $byKey) {
            foreach ($byKey as $mapKey => $candidates) {
                $winner = self::pickBestRow($candidates);
                if ($winner === null) {
                    continue;
                }

                $service = trim((string) (self::field($winner, 'service') ?? ''));
                $fee = (float) (self::field($winner, 'fee') ?? 0);
                $legacyCategory = trim((string) (self::field($winner, 'category') ?? ''));

                $result[$customerId][$mapKey] = [
                    'service' => $service,
                    'fee' => $fee,
                    'category' => self::resolveMappedCategory($service, $legacyCategory),
                    'legacy_category' => $legacyCategory !== '' ? $legacyCategory : null,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  object|array<string, mixed>  $row
     */
    public static function mapKeyForRow($row, ?string $service = null): ?string
    {
        $service = trim($service ?? (string) (self::field($row, 'service') ?? ''));
        if ($service === '') {
            return null;
        }

        $legacyCategory = trim((string) (self::field($row, 'category') ?? ''));
        if (isset(config('legacy_customer_fee_map', [])[$service])) {
            return 'template:'.$service;
        }

        if (strcasecmp($legacyCategory, 'Storage') === 0) {
            return 'storage:'.self::slug($service);
        }

        if (strcasecmp($legacyCategory, 'Packaging') === 0) {
            return 'packaging:'.self::slug($service);
        }

        return 'custom:'.self::slug($legacyCategory).':'.self::slug($service);
    }

    public static function resolveMappedCategory(string $service, string $legacyCategory = ''): ?string
    {
        $map = config('legacy_customer_fee_map', []);
        if (isset($map[$service])) {
            return (string) $map[$service]['category'];
        }

        if (strcasecmp($legacyCategory, 'Storage') === 0) {
            return 'storage';
        }

        if (strcasecmp($legacyCategory, 'Packaging') === 0) {
            return 'packaging';
        }

        return null;
    }

    /**
     * @param  list<object|array<string, mixed>>  $candidates
     * @return object|array<string, mixed>|null
     */
    private static function pickBestRow(array $candidates)
    {
        usort($candidates, function ($a, $b) {
            $aStoreNull = self::field($a, 'store') === null ? 0 : 1;
            $bStoreNull = self::field($b, 'store') === null ? 0 : 1;
            if ($aStoreNull !== $bStoreNull) {
                return $aStoreNull <=> $bStoreNull;
            }

            $aOrder = (int) (self::field($a, 'fee_order') ?? 100);
            $bOrder = (int) (self::field($b, 'fee_order') ?? 100);
            if ($aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            $aUpdated = strtotime((string) (self::field($a, 'updated_at') ?? '')) ?: 0;
            $bUpdated = strtotime((string) (self::field($b, 'updated_at') ?? '')) ?: 0;

            return $bUpdated <=> $aUpdated;
        });

        return $candidates[0] ?? null;
    }

    /**
     * @param  object|array<string, mixed>  $row
     * @return mixed
     */
    private static function field($row, string $key)
    {
        if (is_array($row)) {
            return $row[$key] ?? null;
        }

        return $row->{$key} ?? null;
    }

    private static function intVal($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;

        return trim($value, '_');
    }
}
