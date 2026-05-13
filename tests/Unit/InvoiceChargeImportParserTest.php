<?php

namespace Tests\Unit;

use App\Services\InvoiceChargeImportParser;
use PHPUnit\Framework\TestCase;

final class InvoiceChargeImportParserTest extends TestCase
{
    public function test_storage_by_volume_parsed_and_zero_dollar_row_omitted(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Charge Name,Charge Type (charge),Category (charge),Fee (charge),Description (charge),Charge Qty,Avg Rate,Charge Subtotal
Storage Per Cu FT,storing_by_volume_charge,storage,Storage Per Cu FT,"SKU POSTreat- F - US with a volume of 0.06 cu ft stored in location S-47-0 of type Pallet (Medium) for 1 day(s).",1,0.56,0.56
Storage Per Cu FT,storing_by_volume_charge,storage,Storage Per Cu FT,"SKU POSTreat- F - US with a volume of 0.06 cu ft stored in location S-47-0 of type Pallet (Medium) for 1 day(s).",1,0,0
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $lines);
        $line = $lines[0];
        $this->assertSame('Storage by Volume', $line['display_name']);
        $this->assertSame('POSTreat- F - US (0.06 cu ft)', $line['description']);
        $this->assertSame(56, $line['line_total_cents']);
        $this->assertSame('storage:storage-by-volume', $line['group_key']);
    }
}
