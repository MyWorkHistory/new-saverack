<?php

namespace App\Support;

/**
 * Parse pasted Quick Add lead text into structured fields.
 */
final class LeadQuickAddParser
{
    /**
     * @return array{
     *     company_name: string,
     *     email: string,
     *     website: ?string,
     *     name: ?string,
     *     comment: ?string
     * }
     */
    public static function parse(string $text, string $format = 'bizy'): array
    {
        $format = strtolower(trim($format));
        if ($format === 'google') {
            return self::parseGoogle($text);
        }

        return self::parseBizy($text);
    }

    /**
     * @return array{
     *     company_name: string,
     *     email: string,
     *     website: ?string,
     *     name: ?string,
     *     comment: ?string
     * }
     */
    private static function parseBizy(string $text): array
    {
        $raw = trim(str_replace("\r\n", "\n", $text));
        if ($raw === '') {
            return [
                'company_name' => '',
                'email' => '',
                'website' => null,
                'name' => null,
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
            'name' => null,
            'comment' => $thread !== '' ? $thread : null,
        ];
    }

    /**
     * @return array{
     *     company_name: string,
     *     email: string,
     *     website: ?string,
     *     name: ?string,
     *     comment: ?string
     * }
     */
    private static function parseGoogle(string $text): array
    {
        $raw = trim(str_replace("\r\n", "\n", $text));
        if ($raw === '') {
            return [
                'company_name' => '',
                'email' => '',
                'website' => null,
                'name' => null,
                'comment' => null,
            ];
        }

        $fields = [
            'full_name' => '',
            'company_name' => '',
            'email' => '',
            'phone' => '',
            'website' => '',
            'requirements' => '',
        ];

        $freeform = '';
        $lines = preg_split('/\n/', $raw) ?: [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                $i++;
                continue;
            }

            if (! preg_match(
                '/^(Full\s*Name|Company\s*Name|Email|Phone\s*Number|Store\s*Website\s*URL|Tell\s*us\s*about\s*any\s*special\s*requirements)\s*[:\t]+\s*(.*)$/iu',
                $line,
                $m
            )) {
                $rest = [];
                for ($j = $i; $j < $count; $j++) {
                    $rest[] = $lines[$j];
                }
                $freeform = trim(implode("\n", $rest));
                break;
            }

            $label = mb_strtolower(preg_replace('/\s+/', ' ', trim($m[1])) ?? '');
            $value = trim((string) ($m[2] ?? ''));

            if ($label === 'full name') {
                $fields['full_name'] = $value;
            } elseif ($label === 'company name') {
                $fields['company_name'] = $value;
            } elseif ($label === 'email') {
                $fields['email'] = $value;
            } elseif ($label === 'phone number') {
                $fields['phone'] = $value;
            } elseif ($label === 'store website url') {
                $fields['website'] = $value;
            } elseif ($label === 'tell us about any special requirements') {
                $fields['requirements'] = $value;
            }

            $i++;
        }

        $commentParts = [];
        if ($fields['phone'] !== '') {
            $commentParts[] = 'Phone: '.$fields['phone'];
        }
        if ($fields['requirements'] !== '') {
            $commentParts[] = $fields['requirements'];
        }
        if ($freeform !== '') {
            $commentParts[] = $freeform;
        }
        $comment = $commentParts !== [] ? implode("\n\n", $commentParts) : null;

        $name = self::normalizeFullName($fields['full_name']);

        return [
            'company_name' => $fields['company_name'],
            'email' => $fields['email'],
            'website' => $fields['website'] !== '' ? $fields['website'] : null,
            'name' => $name !== '' ? $name : null,
            'comment' => $comment,
        ];
    }

    private static function normalizeFullName(string $raw): string
    {
        $name = trim($raw);
        if ($name === '') {
            return '';
        }

        // Google paste often uses "First, Last" (or "Last, First"); keep order and drop the comma.
        $name = preg_replace('/\s*,\s*/', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }
}
