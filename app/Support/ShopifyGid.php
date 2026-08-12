<?php

namespace App\Support;

class ShopifyGid
{
    public static function toId(?string $gidOrId): string
    {
        $value = trim((string) $gidOrId);
        if ($value === '') {
            return '';
        }
        // gid://shopify/Order/123 or …/InventoryLevel/123?inventory_item_id=456
        if (preg_match('#/(\d+)(?:\?|$)#', $value, $m)) {
            return $m[1];
        }
        if (preg_match('#^\d+$#', $value)) {
            return $value;
        }

        return $value;
    }

    /**
     * @param  string|int  $id
     */
    public static function of(string $resource, $id): string
    {
        $numeric = self::toId((string) $id);
        $resource = trim($resource);

        return 'gid://shopify/'.$resource.'/'.$numeric;
    }

    /**
     * Pull inventory_item_id from inventory_levels webhook bodies (REST + GID).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function inventoryItemIdFromPayload(array $payload): string
    {
        $gid = trim((string) ($payload['admin_graphql_api_id'] ?? ''));
        if ($gid !== '' && preg_match('#inventory_item_id=(\d+)#', $gid, $m)) {
            return $m[1];
        }

        return self::numericIdString($payload['inventory_item_id'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function locationIdFromPayload(array $payload): string
    {
        return self::numericIdString($payload['location_id'] ?? null);
    }

    /**
     * @param  mixed  $value
     */
    public static function numericIdString($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        // Floats mean json_decode lost bigint precision — refuse rather than store a wrong id.
        if (is_float($value)) {
            return '';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^\d+$#', $value)) {
            return $value;
        }

        return self::toId($value);
    }
}
