<?php

namespace App\Support;

use Throwable;

/**
 * ShipHero GraphQL credit bucket helpers (public API ~100 credits/sec refill).
 */
final class ShipHeroCreditLimit
{
    /** Minimum pause between heavy count queries when iterating many accounts. */
    public const INTER_ACCOUNT_SLEEP_MICROS = 1200000; // 1.2s

    public static function isCreditLimitError(string $message): bool
    {
        $lower = strtolower($message);

        return strpos($lower, 'not enough credits') !== false
            || strpos($lower, 'max allowed') !== false;
    }

    public static function retrySeconds(string $message): ?int
    {
        if (preg_match('/in\s+(\d+)\s+seconds?/i', $message, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }

    /**
     * Run a ShipHero API call with credit-bucket backoff when the API asks us to wait.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback, int $maxAttempts = 8)
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (Throwable $e) {
                $attempt++;
                if (! self::isCreditLimitError($e->getMessage()) || $attempt >= $maxAttempts) {
                    throw $e;
                }

                $wait = self::retrySeconds($e->getMessage()) ?? 2;
                sleep(min(30, $wait + 1));
            }
        }
    }
}
