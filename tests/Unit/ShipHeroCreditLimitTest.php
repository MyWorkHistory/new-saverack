<?php

namespace Tests\Unit;

use App\Support\ShipHeroCreditLimit;
use PHPUnit\Framework\TestCase;

class ShipHeroCreditLimitTest extends TestCase
{
    public function test_detects_credit_limit_error(): void
    {
        $message = 'ShipHero: There are not enough credits to perform the requested operation, which requires 1301 credits, but there are only 263 left. In 18 seconds you will have enough credits to perform the operation';

        $this->assertTrue(ShipHeroCreditLimit::isCreditLimitError($message));
        $this->assertSame(1301, ShipHeroCreditLimit::requiredCredits($message));
        $this->assertSame(263, ShipHeroCreditLimit::availableCredits($message));
        $this->assertSame(18, ShipHeroCreditLimit::retrySeconds($message));
    }

    public function test_retry_seconds_from_credit_delta_when_message_omits_wait(): void
    {
        $message = 'ShipHero: There are not enough credits to perform the requested operation, which requires 500 credits, but there are only 100 left.';

        $this->assertSame(5, ShipHeroCreditLimit::retrySeconds($message));
    }
}
