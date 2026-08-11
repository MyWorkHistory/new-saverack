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
        if (preg_match('#/(\d+)\s*$#', $value, $m)) {
            return $m[1];
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
}
