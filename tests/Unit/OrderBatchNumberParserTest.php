<?php

namespace Tests\Unit;

use App\Support\OrderBatchNumberParser;
use Tests\TestCase;

class OrderBatchNumberParserTest extends TestCase
{
    public function test_parses_batch_prefix_and_bare_numbers(): void
    {
        $parsed = OrderBatchNumberParser::parseLines("Batch 7763953\n7763954\n\nBatch 7763924\n");
        $this->assertSame(['7763953', '7763954', '7763924'], $parsed['numbers']);
        $this->assertSame([], $parsed['invalid']);
    }

    public function test_parses_shiphero_batch_links(): void
    {
        $parsed = OrderBatchNumberParser::parseLines(
            "https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768504\n".
            "https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768505\n"
        );
        $this->assertSame(['7768504', '7768505'], $parsed['numbers']);
        $this->assertSame([], $parsed['invalid']);
    }

    public function test_rejects_non_numeric_lines(): void
    {
        $parsed = OrderBatchNumberParser::parseLines("Batch ABC\n7763953");
        $this->assertSame(['7763953'], $parsed['numbers']);
        $this->assertCount(1, $parsed['invalid']);
    }

    public function test_dedupes_numbers_and_links(): void
    {
        $parsed = OrderBatchNumberParser::parseLines(
            "7763953\nBatch 7763953\nhttps://shipping.shiphero.com/bulk-ship/batch/?batchId=7763953"
        );
        $this->assertSame(['7763953'], $parsed['numbers']);
    }

    public function test_ship_hero_url_builder(): void
    {
        $this->assertSame(
            'https://shipping.shiphero.com/bulk-ship/batch/?batchId=7768504',
            OrderBatchNumberParser::shipHeroUrl('7768504')
        );
    }
}
