<?php

namespace Tests\Unit;

use App\Support\Inventory\RestockBetaCsvParser;
use PHPUnit\Framework\TestCase;

final class RestockBetaCsvParserTest extends TestCase
{
    public function test_parses_headers_aliases_and_numeric_fields(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'restockcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
SKU,Name,On hand,Allocated,Replenishment level,Available in pickable bins,Qty from Non-Pickable bins,Items to replenish,Top 3 Non-Pickable bins,Top 3 Pickable bins
ABC-123,Widget Alpha,"1,234",56,100,2,523,42,"test 3 (QTY: 523), test 2 (QTY: 42)","pick-a (QTY: 1), pick-b (QTY: 1)"
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new RestockBetaCsvParser;
            $rows = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('ABC-123', $row['sku']);
        $this->assertSame('Widget Alpha', $row['name']);
        $this->assertSame(1234, $row['on_hand']);
        $this->assertSame(56, $row['allocated']);
        $this->assertSame(2, $row['pickable_qty']);
        $this->assertSame(523, $row['backstock_qty']);
        $this->assertSame(42, $row['restock_needed']);
        $this->assertSame('test 3 (QTY: 523), test 2 (QTY: 42)', $row['backstock_locations']);
        $this->assertSame('pick-a (QTY: 1), pick-b (QTY: 1)', $row['pick_location']);
    }

    public function test_accepts_case_insensitive_header_variants(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'restockcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
sku,name,on hand,allocated,available in pickable bin,qty from non pickable bins,items to restock,top 3 non pickable bins
SKU-1,Sample,10,1,0,25,5,bin-a (QTY: 25)
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new RestockBetaCsvParser;
            $rows = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $rows);
        $this->assertSame('SKU-1', $rows[0]['sku']);
        $this->assertSame(25, $rows[0]['backstock_qty']);
        $this->assertSame(5, $rows[0]['restock_needed']);
    }

    public function test_skips_blank_sku_rows(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'restockcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
SKU,Name
,Empty SKU
GOOD-1,Good Row
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new RestockBetaCsvParser;
            $rows = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $rows);
        $this->assertSame('GOOD-1', $rows[0]['sku']);
    }

    public function test_missing_required_headers_throw(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'restockcsv');
        $this->assertNotFalse($f);
        file_put_contents($f, "On hand,Allocated\n1,2\n");

        try {
            $parser = new RestockBetaCsvParser;
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Missing required CSV columns');
            $parser->parseFile($f);
        } finally {
            unlink($f);
        }
    }
}
