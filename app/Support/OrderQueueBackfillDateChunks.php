<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

final class OrderQueueBackfillDateChunks
{
    /**
     * @return list<array{from: string, to: string, label: string}>
     */
    public static function between(
        string $fromDate,
        string $toDate,
        string $chunkUnit,
        string $timezone = 'America/New_York'
    ): array {
        $chunkUnit = strtolower(trim($chunkUnit));
        if (! in_array($chunkUnit, ['month', 'week'], true)) {
            throw new InvalidArgumentException('chunk must be month or week.');
        }

        $start = Carbon::parse($fromDate, $timezone)->startOfDay();
        $end = Carbon::parse($toDate, $timezone)->endOfDay();
        if ($start->gt($end)) {
            throw new InvalidArgumentException('from must be on or before to.');
        }

        $chunks = [];
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($chunkUnit === 'month') {
                $chunkEnd = $cursor->copy()->endOfMonth();
                $label = $cursor->format('Y-m');
            } else {
                $chunkEnd = $cursor->copy()->addDays(6)->endOfDay();
                $label = $cursor->toDateString();
            }

            if ($chunkEnd->gt($end)) {
                $chunkEnd = $end->copy();
            }

            $chunks[] = [
                'from' => $cursor->toDateString(),
                'to' => $chunkEnd->toDateString(),
                'label' => $label,
            ];

            $cursor = $chunkEnd->copy()->addDay()->startOfDay();
        }

        return $chunks;
    }
}
