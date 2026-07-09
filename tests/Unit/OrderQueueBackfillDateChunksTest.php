<?php

namespace Tests\Unit;

use App\Support\OrderQueueBackfillDateChunks;
use PHPUnit\Framework\TestCase;

final class OrderQueueBackfillDateChunksTest extends TestCase
{
    public function test_month_chunks_from_january_through_partial_july(): void
    {
        $chunks = OrderQueueBackfillDateChunks::between(
            '2026-01-01',
            '2026-07-09',
            'month',
            'America/New_York'
        );

        $this->assertCount(7, $chunks);
        $this->assertSame('2026-01-01', $chunks[0]['from']);
        $this->assertSame('2026-01-31', $chunks[0]['to']);
        $this->assertSame('2026-01', $chunks[0]['label']);
        $this->assertSame('2026-07-01', $chunks[6]['from']);
        $this->assertSame('2026-07-09', $chunks[6]['to']);
        $this->assertSame('2026-07', $chunks[6]['label']);
    }

    public function test_week_chunks_cover_full_range_without_gaps(): void
    {
        $chunks = OrderQueueBackfillDateChunks::between(
            '2026-01-01',
            '2026-01-20',
            'week',
            'America/New_York'
        );

        $this->assertNotEmpty($chunks);
        $this->assertSame('2026-01-01', $chunks[0]['from']);
        $this->assertSame('2026-01-20', $chunks[count($chunks) - 1]['to']);
    }
}
