<?php

namespace App\Support;

final class OrderBatchNumberParser
{
    /**
     * Parse free-text lines into unique batch number strings (digits only).
     *
     * Accepts:
     * - "Batch 7763953"
     * - "7763953"
     *
     * @return array{numbers: list<string>, invalid: list<array{line: int, raw: string}>}
     */
    public static function parseLines(string $text): array
    {
        $numbers = [];
        $seen = [];
        $invalid = [];
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $lineNo = 0;
        foreach ($lines as $rawLine) {
            $lineNo++;
            $raw = trim((string) $rawLine);
            if ($raw === '') {
                continue;
            }
            $parsed = self::parseOne($raw);
            if ($parsed === null) {
                $invalid[] = ['line' => $lineNo, 'raw' => $raw];
                continue;
            }
            if (isset($seen[$parsed])) {
                continue;
            }
            $seen[$parsed] = true;
            $numbers[] = $parsed;
        }

        return ['numbers' => $numbers, 'invalid' => $invalid];
    }

    /**
     * @param  list<string>|string  $input
     * @return list<string>
     */
    public static function normalizeList($input): array
    {
        if (is_string($input)) {
            return self::parseLines($input)['numbers'];
        }
        if (! is_array($input)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($input as $item) {
            $parsed = self::parseOne(trim((string) $item));
            if ($parsed === null || isset($seen[$parsed])) {
                continue;
            }
            $seen[$parsed] = true;
            $out[] = $parsed;
        }

        return $out;
    }

    public static function parseOne(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^batch\s+/i', $value) === 1) {
            $value = trim((string) preg_replace('/^batch\s+/i', '', $value));
        }
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
