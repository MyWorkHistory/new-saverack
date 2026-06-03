<?php

namespace App\Support;

/**
 * Runs per-account work under a wall-clock budget with optional early stop.
 * ShipHero calls remain synchronous; chunking keeps the loop structure ready for async fan-out later.
 */
final class TimeBudgetedFanout
{
    public const DEFAULT_CONCURRENCY = 6;

    /**
     * @template T of mixed
     * @template TResult
     *
     * @param  iterable<T>  $items
     * @param  callable(T, int): TResult  $callback
     * @param  callable(list<TResult>, int): bool|null  $shouldStop  Return true to stop after current item.
     * @return array{results: list<TResult>, processed: int, total: int, truncated: bool}
     */
    public static function run(
        iterable $items,
        callable $callback,
        float $deadline,
        ?callable $shouldStop = null,
        int $concurrency = self::DEFAULT_CONCURRENCY
    ): array {
        $list = $items instanceof \Traversable ? iterator_to_array($items) : array_values(is_array($items) ? $items : []);
        $total = count($list);
        $results = [];
        $processed = 0;
        $truncated = false;
        $concurrency = max(1, $concurrency);

        foreach (array_chunk($list, $concurrency, true) as $chunk) {
            if (microtime(true) >= $deadline) {
                $truncated = true;
                break;
            }

            foreach ($chunk as $item) {
                if (microtime(true) >= $deadline) {
                    $truncated = true;
                    break 2;
                }

                $results[] = $callback($item, $processed);
                $processed++;

                if ($shouldStop !== null && $shouldStop($results, $processed)) {
                    $truncated = $processed < $total;

                    return [
                        'results' => $results,
                        'processed' => $processed,
                        'total' => $total,
                        'truncated' => $truncated,
                    ];
                }
            }
        }

        if ($processed < $total) {
            $truncated = true;
        }

        return [
            'results' => $results,
            'processed' => $processed,
            'total' => $total,
            'truncated' => $truncated,
        ];
    }
}
