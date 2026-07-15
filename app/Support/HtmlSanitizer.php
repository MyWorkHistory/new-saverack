<?php

namespace App\Support;

/**
 * Allowlist-based HTML sanitizer for Terms of Service and similar rich text.
 */
class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p',
        'br',
        'strong',
        'b',
        'em',
        'i',
        'ul',
        'ol',
        'li',
        'h2',
        'h3',
        'h4',
    ];

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $allowed = implode('', array_map(static function ($tag) {
            return '<'.$tag.'>';
        }, self::ALLOWED_TAGS));

        $cleaned = strip_tags($html, $allowed);
        // Drop on* attributes and javascript: URLs if any attributes slipped through strip_tags.
        $cleaned = preg_replace('/\s+on\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|[^\s>]*javascript:[^\s>]*)/iu', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
