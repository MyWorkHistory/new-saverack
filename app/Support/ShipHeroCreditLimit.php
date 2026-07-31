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

    /** Pause between order-queue pages so the credit bucket can refill. */
    public const INTER_PAGE_SLEEP_MICROS = 4000000; // 4s (~400 credits at ~100/sec refill)

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

        $required = self::requiredCredits($message);
        $available = self::availableCredits($message);
        if ($required !== null && $available !== null && $required > $available) {
            // ~100 credits/sec refill; add a small buffer.
            return max(1, (int) ceil(($required - $available) / 100) + 1);
        }

        return null;
    }

    public static function requiredCredits(string $message): ?int
    {
        if (preg_match('/requires\s+(\d+)\s+credits/i', $message, $matches) === 1) {
            return max(1, (int) $matches[1]);
        }

        return null;
    }

    public static function availableCredits(string $message): ?int
    {
        if (preg_match('/only\s+(\d+)\s+left/i', $message, $matches) === 1) {
            return max(0, (int) $matches[1]);
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
    public static function run(callable $callback, int $maxAttempts = 12)
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
                sleep(min(45, $wait + 1));
            }
        }
    }
}
