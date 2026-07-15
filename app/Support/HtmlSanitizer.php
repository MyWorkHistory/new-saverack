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
        'u',
        's',
        'ul',
        'ol',
        'li',
        'h2',
        'h3',
        'h4',
        'blockquote',
        'a',
    ];

    public static function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $allowed = implode('', array_map(static function ($tag) {
            return '<'.$tag.'>';
        }, self::ALLOWED_TAGS));

        $hrefMap = [];
        $tokenized = $html;
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>/iu', $html, $matches, PREG_SET_ORDER)) {
            $i = 0;
            foreach ($matches as $match) {
                $href = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (! preg_match('#^https?://#i', $href)) {
                    $tokenized = str_replace($match[0], '<a>', $tokenized);
                    continue;
                }
                $token = 'HREFTOKEN'.$i.'X';
                $hrefMap[$token] = htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tokenized = str_replace($match[0], '<a href="'.$token.'">', $tokenized);
                $i++;
            }
        }

        $cleaned = strip_tags($tokenized, $allowed);

        foreach ($hrefMap as $token => $safeHref) {
            $cleaned = str_replace(
                'href="'.$token.'"',
                'href="'.$safeHref.'" rel="noopener noreferrer" target="_blank"',
                $cleaned
            );
        }

        $cleaned = preg_replace('/\s+on\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/iu', '', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+(href|src)\s*=\s*("\s*javascript:[^"]*"|\'\s*javascript:[^\']*\'|[^\s>]*javascript:[^\s>]*)/iu', '', $cleaned) ?? $cleaned;

        return trim($cleaned);
    }
}
