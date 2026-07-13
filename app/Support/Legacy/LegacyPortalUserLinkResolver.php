<?php

namespace App\Support\Legacy;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder;

/**
 * Reads legacy user ↔ customer links (accounts / user_customers / customer_users).
 */
final class LegacyPortalUserLinkResolver
{
    /** @var list<string> */
    private const LINK_TABLE_CANDIDATES = [
        'accounts',
        'user_customers',
        'customer_users',
    ];

    /** @var list<string> */
    private const USER_FK_CANDIDATES = [
        'user_id',
        'users_id',
        'user',
    ];

    /** @var list<string> */
    private const CUSTOMER_FK_CANDIDATES = [
        'customer_id',
        'customer',
        'customers_id',
        'account_id',
        'client_id',
    ];

    /**
     * @return list<array{table: string, user_column: string, customer_column: string}>
     */
    public static function discoverLinkTables(Builder $schema, string $explicitTable = 'auto'): array
    {
        $explicitTable = trim($explicitTable);
        if ($explicitTable !== '' && strcasecmp($explicitTable, 'auto') !== 0) {
            $resolved = self::resolveLinkTable($schema, $explicitTable);

            return $resolved !== null ? [$resolved] : [];
        }

        $out = [];
        foreach (self::LINK_TABLE_CANDIDATES as $table) {
            $resolved = self::resolveLinkTable($schema, $table);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{table: string, user_column: string, customer_column: string}>  $linkTables
     * @return array<int, list<int>>
     */
    public static function buildUserCustomerMap(Connection $legacy, array $linkTables): array
    {
        /** @var array<int, list<int>> $map */
        $map = [];

        foreach ($linkTables as $link) {
            $rows = $legacy->table($link['table'])
                ->select([$link['user_column'], $link['customer_column']])
                ->get();

            foreach ($rows as $row) {
                $userId = self::intOrNull($row->{$link['user_column']} ?? null);
                $customerId = self::intOrNull($row->{$link['customer_column']} ?? null);
                if ($userId === null || $customerId === null) {
                    continue;
                }

                if (! isset($map[$userId])) {
                    $map[$userId] = [];
                }
                $map[$userId][] = $customerId;
            }
        }

        foreach ($map as $userId => $customerIds) {
            $map[$userId] = array_values(array_unique($customerIds));
        }

        return $map;
    }

    /**
     * @param  array<int, list<int>>  $userCustomerMap
     * @return list<int>
     */
    public static function linkedCustomerIdsForUser(int $legacyUserId, array $userCustomerMap, object $userRow): array
    {
        $ids = $userCustomerMap[$legacyUserId] ?? [];
        $ids = array_merge($ids, LegacyPortalUserImportMapper::parseLegacyCustomerIds($userRow->customers ?? null));

        $ids = array_values(array_unique(array_filter($ids, static function ($id) {
            return is_int($id) && $id > 0;
        })));

        return $ids;
    }

    /**
     * @param  list<int>  $customerIds
     * @param  array<int, object|null>  $cache
     * @return list<object>
     */
    public static function fetchLegacyCustomerRows(
        Connection $legacy,
        string $customersTable,
        array $customerIds,
        array &$cache = []
    ): array {
        $rows = [];
        foreach ($customerIds as $customerId) {
            if ($customerId <= 0) {
                continue;
            }

            if (! array_key_exists($customerId, $cache)) {
                $cache[$customerId] = $legacy->table($customersTable)
                    ->where('id', $customerId)
                    ->first();
            }

            if ($cache[$customerId] !== null) {
                $rows[] = $cache[$customerId];
            }
        }

        return $rows;
    }

    /**
     * @return array{table: string, user_column: string, customer_column: string}|null
     */
    private static function resolveLinkTable(Builder $schema, string $table): ?array
    {
        if (! $schema->hasTable($table)) {
            return null;
        }

        $userColumn = self::firstExistingColumn($schema, $table, self::USER_FK_CANDIDATES);
        $customerColumn = self::firstExistingColumn($schema, $table, self::CUSTOMER_FK_CANDIDATES);
        if ($userColumn === null || $customerColumn === null) {
            return null;
        }

        return [
            'table' => $table,
            'user_column' => $userColumn,
            'customer_column' => $customerColumn,
        ];
    }

    /**
     * @param  list<string>  $candidates
     */
    private static function firstExistingColumn(Builder $schema, string $table, array $candidates): ?string
    {
        foreach ($candidates as $column) {
            if ($schema->hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @param  mixed  $raw
     */
    private static function intOrNull($raw): ?int
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }
}
