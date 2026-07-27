<?php

namespace App\Support;

/**
 * Parse pasted Quick Add lead text into structured fields.
 */
final class LeadQuickAddParser
{
    /**
     * @return array{company_name: string, email: string, website: ?string, comment: ?string}
     */
    public static function parse(string $text): array
    {
        $raw = trim(str_replace("\r\n", "\n", $text));
        if ($raw === '') {
            return [
                'company_name' => '',
                'email' => '',
                'website' => null,
                'comment' => null,
            ];
        }

        $company = '';
        $website = '';
        $email = '';
        $thread = '';

        if (preg_match('/^\s*Email\s*Thread\s*:\s*/im', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $threadStart = (int) $m[0][1] + strlen($m[0][0]);
            $thread = trim(substr($raw, $threadStart));
            $raw = trim(substr($raw, 0, (int) $m[0][1]));
        }

        foreach (preg_split('/\n+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^Company\s*:\s*(.+)$/i', $line, $m)) {
                $company = trim($m[1]);
                continue;
            }
            if (preg_match('/^Website\s*:\s*(.+)$/i', $line, $m)) {
                $website = trim($m[1]);
                continue;
            }
            if (preg_match('/^Email\s*:\s*(.+)$/i', $line, $m)) {
                $email = trim($m[1]);
            }
        }

        return [
            'company_name' => $company,
            'email' => $email,
            'website' => $website !== '' ? $website : null,
            'comment' => $thread !== '' ? $thread : null,
        ];
    }
}
