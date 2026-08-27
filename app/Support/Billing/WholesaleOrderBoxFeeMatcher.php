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
                // Each box row is one physical carton; line quantity is units packed, not carton count.
                $qty = 1;
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
            ? WholesaleOrderFeeChargeCatalog::accountBoxBillingFeeOptions($account)
            : [];

        $dimMatched = self::findMatchingOption($options, $length, $width, $height);
        $matched = $dimMatched;
        if ($matched === null) {
            // Account Fees often have a single "Box" row (e.g. $0.85) without size in the name.
            $matched = self::findGenericBoxOption($options);
        }

        // Prefer sized fee labels when matched by dims; otherwise keep the measured size visible.
        $displayName = $dimMatched !== null
            ? (string) ($dimMatched['display_name'] ?? self::formatSizeLabel($length, $width, $height))
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
            'matched' => $matched !== null && $unitCents > 0,
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
     * Fallback when no packaging fee label includes matching L×W×H (e.g. fee named "Box" at $0.85).
     *
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    public static function findGenericBoxOption(array $options): ?array
    {
        $best = null;
        $bestScore = -1;

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }
            $label = trim((string) ($option['display_name'] ?? ''));
            if ($label === '') {
                continue;
            }
            // Skip sized SKUs — those are handled by findMatchingOption.
            if (self::parseDimensionsFromLabel($label) !== null) {
                continue;
            }

            $score = self::genericBoxLabelScore($label);
            if ($score < 0) {
                continue;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $option;
            }
        }

        return $best;
    }

    /**
     * Higher score = better generic box match. Negative = not a box fee.
     */
    public static function genericBoxLabelScore(string $label): int
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $label) ?? '');
        if ($key === '') {
            return -1;
        }

        if ($key === 'box' || $key === 'boxes' || $key === 'boxprice') {
            return 100;
        }
        if (strpos($key, 'boxprice') !== false) {
            return 80;
        }
        // "Box Fee", "Shipping Box", etc. — avoid mailer/poly/bubble unless they say box.
        if ($key === 'shippingbox' || $key === 'cartonbox') {
            return 60;
        }
        if (preg_match('/^box/', $key) === 1 && strpos($key, 'mailbox') === false) {
            return 40;
        }
        // Plain "carton" packaging fees are often used as the default box price.
        if ($key === 'carton' || $key === 'cartons') {
            return 30;
        }

        return -1;
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

        // Account Fees labels use x or × (e.g. "12 x 9 x 6", "12×9×6", "Box (12 X 9 X 6)").
        $normalized = str_replace(['×', "\xC3\x97"], 'x', $compact);
        $normalized = preg_replace('/\bin\b\.?/i', ' ', $normalized) ?? $normalized;
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
        $normalized = preg_replace('/\s*[xX]\s*/', 'x', $normalized) ?? $normalized;

        if (preg_match('/(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)x(\d+(?:\.\d+)?)/', $normalized, $m) === 1) {
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

    /**
     * @param  mixed  $value
     */
    private static function toFloat($value): ?float
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
