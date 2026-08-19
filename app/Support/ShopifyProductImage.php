<?php

namespace App\Support;

final class ShopifyProductImage
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function url(?array $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $candidates = [
            $raw['featuredImage']['url'] ?? null,
            $raw['featuredImage']['src'] ?? null,
            $raw['image']['src'] ?? null,
            $raw['image']['url'] ?? null,
            $raw['images']['edges'][0]['node']['url'] ?? null,
            $raw['images']['edges'][0]['node']['src'] ?? null,
            $raw['images'][0]['src'] ?? null,
            $raw['images'][0]['url'] ?? null,
        ];

        foreach ($candidates as $url) {
            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }
}
