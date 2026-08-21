<?php

namespace App\Support;

final class OrderBatchNumberParser
{
    public const SHIPHERO_BATCH_URL_PREFIX = 'https://shipping.shiphero.com/bulk-ship/batch/?batchId=';

    /**
     * Parse free-text lines into unique batch number strings (digits only).
     *
     * Accepts:
     * - ShipHero links: https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768504
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

        $fromUrl = self::batchIdFromUrl($value);
        if ($fromUrl !== null) {
            return $fromUrl;
        }

        if (preg_match('/^batch\s+/i', $value) === 1) {
            $value = trim((string) preg_replace('/^batch\s+/i', '', $value));
        }
        if ($value === '' || preg_match('/^\d+$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    public static function batchIdFromUrl(string $raw): ?string
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        // Fast path: ?batchId= / &batchId= anywhere in the string.
        if (preg_match('/(?:\?|&|#)batchId=(\d+)/i', $value, $m) === 1) {
            return $m[1];
        }

        if (! preg_match('#^https?://#i', $value)) {
            return null;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return null;
        }
        $query = [];
        if (! empty($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }
        $id = isset($query['batchId']) ? trim((string) $query['batchId']) : '';
        if ($id === '' || preg_match('/^\d+$/', $id) !== 1) {
            return null;
        }

        return $id;
    }

    public static function shipHeroUrl(string $batchNumber): string
    {
        return self::SHIPHERO_BATCH_URL_PREFIX.rawurlencode($batchNumber);
    }
}
