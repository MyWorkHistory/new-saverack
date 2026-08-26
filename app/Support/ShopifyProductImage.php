<?php

namespace App\Support;

final class ShopifyProductImage
{
    /**
     * Resolve a product/variant image URL from Shopify GraphQL or REST-shaped raw JSON.
     *
     * @param  array<string, mixed>|null  ...$raws
     */
    public static function url(?array ...$raws): ?string
    {
        foreach ($raws as $raw) {
            if ($raw === null) {
                continue;
            }
            $url = self::urlFromRaw($raw);
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private static function urlFromRaw(array $raw): ?string
    {
        $candidates = [
            $raw['featuredImage']['url'] ?? null,
            $raw['featuredImage']['src'] ?? null,
            $raw['featured_image']['url'] ?? null,
            $raw['featured_image']['src'] ?? null,
            $raw['image']['url'] ?? null,
            $raw['image']['src'] ?? null,
            $raw['images']['edges'][0]['node']['url'] ?? null,
            $raw['images']['edges'][0]['node']['src'] ?? null,
            $raw['images']['edges'][0]['node']['originalSrc'] ?? null,
            $raw['images'][0]['src'] ?? null,
            $raw['images'][0]['url'] ?? null,
            $raw['media']['edges'][0]['node']['image']['url'] ?? null,
            $raw['media']['edges'][0]['node']['preview']['image']['url'] ?? null,
        ];

        // REST webhook sometimes sends image as a bare URL string.
        if (isset($raw['image']) && is_string($raw['image'])) {
            $candidates[] = $raw['image'];
        }
        if (isset($raw['featuredImage']) && is_string($raw['featuredImage'])) {
            $candidates[] = $raw['featuredImage'];
        }

        foreach ($candidates as $url) {
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }
}
