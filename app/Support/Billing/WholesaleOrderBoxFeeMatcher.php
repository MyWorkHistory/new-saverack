<?php

namespace App\Support\Billing;

use App\Models\ClientAccount;

class WholesaleOrderBoxFeeMatcher
{
    private const DIM_TOLERANCE = 0.01;

    /**
     * @param  list<array<string, mixed>>  $lines  Serialized wholesale lines with boxes[]
     * @return list<array<string, mixed>>
     */
    public static function aggregateLineBoxes(array $lines, ?ClientAccount $account = null): array
    {
        /** @var array<string, array{length: float, width: float, height: float, quantity: int}> $groups */
        $groups = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $boxes = is_array($line['boxes'] ?? null) ? $line['boxes'] : [];
            foreach ($boxes as $box) {
                if (! is_array($box)) {
                    continue;
                }
                $length = self::toFloat($box['length'] ?? null);
                $width = self::toFloat($box['width'] ?? null);
                $height = self::toFloat($box['height'] ?? null);
                if ($length === null || $width === null || $height === null) {
                    continue;
                }
                $qty = max(0, (int) ($box['quantity'] ?? 0));
                if ($qty <= 0) {
                    continue;
                }
                $key = self::dimensionKey($length, $width, $height);
                if (! isset($groups[$key])) {
                    $groups[$key] = [
                        'length' => $length,
                        'width' => $width,
                        'height' => $height,
                        'quantity' => 0,
                    ];
                }
                $groups[$key]['quantity'] += $qty;
            }
        }

        $out = [];
        foreach ($groups as $group) {
            $out[] = self::matchRow($group, $account);
        }

        usort($out, static function (array $a, array $b): int {
            return [$a['length'], $a['width'], $a['height']] <=> [$b['length'], $b['width'], $b['height']];
        });

        return $out;
    }

    /**
     * @param  array{length: float, width: float, height: float, quantity: int}  $group
     * @return array<string, mixed>
     */
    public static function matchRow(array $group, ?ClientAccount $account): array
    {
        $length = (float) $group['length'];
        $width = (float) $group['width'];
        $height = (float) $group['height'];
        $quantity = (int) $group['quantity'];

        $options = $account !== null
            ? WholesaleOrderFeeChargeCatalog::packagingOptionsForAccount($account)
            : [];

        $matched = self::findMatchingOption($options, $length, $width, $height);
        $displayName = $matched !== null
            ? (string) ($matched['display_name'] ?? self::formatSizeLabel($length, $width, $height))
            : self::formatSizeLabel($length, $width, $height);
        $unitCents = (int) ($matched['default_unit_price_cents'] ?? 0);

        return [
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'quantity' => $quantity,
            'display_name' => $displayName,
            'line_type' => $matched['line_type'] ?? 'custom_new',
            'client_account_fee_id' => $matched['client_account_fee_id'] ?? null,
            'default_unit_price_cents' => $unitCents,
            'unit_price' => round($unitCents / 100, 2),
            'matched' => $matched !== null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    public static function findMatchingOption(array $options, float $length, float $width, float $height): ?array
    {
        $target = self::normalizeTriple($length, $width, $height);
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $label = (string) ($option['display_name'] ?? '');
            $parsed = self::parseDimensionsFromLabel($label);
            if ($parsed === null) {
                continue;
            }
            if (self::triplesMatch($target, $parsed)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    public static function parseDimensionsFromLabel(string $label): ?array
    {
        $compact = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
        if ($compact === '') {
            return null;
        }

        $patterns = [
            '/^\(?\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*\)?$/i',
            '/^box\s*\(?\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*\)?$/i',
            '/^\(?\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*\)?\s*in\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $compact, $m) === 1) {
                return self::normalizeTriple((float) $m[1], (float) $m[2], (float) $m[3]);
            }
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*[x×]\s*(\d+(?:\.\d+)?)/i', $compact, $m) === 1) {
            return self::normalizeTriple((float) $m[1], (float) $m[2], (float) $m[3]);
        }

        return null;
    }

    public static function formatSizeLabel(float $length, float $width, float $height): string
    {
        return sprintf(
            '%s × %s × %s in',
            self::fmtDim($length),
            self::fmtDim($width),
            self::fmtDim($height),
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public static function normalizeTriple(float $a, float $b, float $c): array
    {
        $parts = [(float) $a, (float) $b, (float) $c];
        sort($parts, SORT_NUMERIC);

        return $parts;
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $a
     * @param  array{0: float, 1: float, 2: float}  $b
     */
    public static function triplesMatch(array $a, array $b): bool
    {
        for ($i = 0; $i < 3; $i++) {
            if (abs($a[$i] - $b[$i]) > self::DIM_TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    private static function dimensionKey(float $length, float $width, float $height): string
    {
        $triple = self::normalizeTriple($length, $width, $height);

        return implode('x', array_map(static fn (float $v) => number_format($v, 2, '.', ''), $triple));
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function fmtDim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }
}
