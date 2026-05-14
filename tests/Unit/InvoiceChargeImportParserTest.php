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
        $this->assertStringStartsWith('storage:vol:', $line['group_key']);
        $this->assertIsArray($line['metadata'] ?? null);
        $this->assertStringContainsString('POSTreat- F - US with a volume', (string) ($line['metadata']['storage_volume_prose'] ?? ''));
    }

    /**
     * Wide ShipHero export (Label (charge) but no Charge Name column) uses the legacy parser;
     * storage-by-volume must still map correctly.
     */
    public function test_storage_by_volume_shiphero_headers_without_charge_name_column(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Date,Category (charge),Fee (charge),Type (charge),Label (charge),Description (charge),Unit rate (charge),Quantity (charge),Total (charge)
2026-05-11,storage,Storage Per Cu FT,storing_by_volume_charge,Storage Per Cu FT,"SKU POSTreat- F - US with a volume of 0.06 cu ft stored in location S-47-0 of type Pallet (Medium) for 1 day(s).",0.56,1,0.56
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $lines);
        $this->assertSame('Storage by Volume', $lines[0]['display_name']);
        $this->assertSame('POSTreat- F - US (0.06 cu ft)', $lines[0]['description']);
    }

    /**
     * "Type (charge)" must map to charge type, not to the "fee" column: bare "type" used to match
     * the fee alias when Type appeared before Fee, dropping storing_by_volume_charge from parsing.
     */
    public function test_shiphero_type_charge_column_before_fee_still_maps_volume_and_location_types(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Date,Category (charge),Type (charge),Fee (charge),Label (charge),Description (charge),Unit rate (charge),Quantity (charge),Total (charge)
2026-05-13,storage,storing_by_volume_charge,Storage Per Cu FT,Storage Per Cu FT,"SKU P24C2F2SSD2-US with a volume of 1.44 cu ft stored in location Q-55-0 of type Pallet (Medium) for 1 day(s).",0.76,1,0.76
2026-05-04,storage,storing_by_location_charge,Storage,Storage,"Location A-16-031 of type Bin (Large) occupied for 7 day(s).",0.12,7,0.84
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(2, $lines);
        $this->assertSame('Storage by Volume', $lines[0]['display_name']);
        $this->assertSame('P24C2F2SSD2-US (1.44 cu ft)', $lines[0]['description']);
        $this->assertSame('storing_by_volume_charge', $lines[0]['service_code']);
        $this->assertSame('Bin (Large)', $lines[1]['display_name']);
        $this->assertSame('storing_by_location_charge', $lines[1]['service_code']);
        $this->assertStringContainsString('Location A-16-031', $lines[1]['description']);
    }

    public function test_storing_by_location_charge_maps_to_bin_not_storage_by_volume(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Charge Name,Charge Type (charge),Category (charge),Fee (charge),Description (charge),Charge Qty,Avg Rate,Charge Subtotal
Storage,storing_by_location_charge,storage,Storage,"Location A-16-031 of type Bin (Large) occupied for 7 day(s).",7,0.12,0.84
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $lines);
        $this->assertSame('Bin (Large)', $lines[0]['display_name']);
        $this->assertStringContainsString('Location A-16-031', $lines[0]['description']);
        $this->assertNotSame('Storage by Volume', $lines[0]['display_name']);
    }

    /** Volume CSV copy mentions "of type Pallet (Medium)" — must not create a separate Pallet storage line. */
    /** ShipHero sometimes uses "cu. ft" / "cubic feet" — must not fall through to pallet/bin storage. */
    public function test_storage_by_volume_cu_ft_dot_and_cubic_feet_copy(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Charge Name,Charge Type (charge),Category (charge),Fee (charge),Description (charge),Charge Qty,Avg Rate,Charge Subtotal
Storage Per Cu FT,storing_by_volume_charge,storage,Storage Per Cu FT,"SKU ABC-US with a volume of 2.00 cu. ft stored in location X of type Pallet (Medium) for 1 day(s).",1,1.00,1.00
Storage Per Cu FT,storing_by_volume_charge,storage,Storage Per Cu FT,"SKU DEF-US having a volume of 3 cubic feet stored in location Y of type Bin (Large) for 1 day(s).",1,1.00,1.00
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(2, $lines);
        $this->assertSame('Storage by Volume', $lines[0]['display_name']);
        $this->assertSame('ABC-US (2.00 cu ft)', $lines[0]['description']);
        $this->assertStringStartsWith('storage:vol:', $lines[0]['group_key']);
        $this->assertSame('Storage by Volume', $lines[1]['display_name']);
        $this->assertSame('DEF-US (3 cu ft)', $lines[1]['description']);
        $this->assertStringNotContainsString('Pallet', $lines[0]['display_name']);
        $this->assertStringNotContainsString('Bin', $lines[1]['display_name']);
    }

    public function test_volume_description_never_classifies_as_pallet_from_of_type_clause(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Charge Name,Charge Type (charge),Category (charge),Fee (charge),Description (charge),Charge Qty,Avg Rate,Charge Subtotal
Storage Per Cu FT,storing_by_volume_charge,storage,Storage Per Cu FT,"SKU P24C2F2SSD2-US with a volume of 1.44 cu ft stored in location Q-55-0 of type Pallet (Medium) for 1 day(s).",1,0.76,0.76
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $lines);
        $this->assertSame('Storage by Volume', $lines[0]['display_name']);
        $this->assertSame('P24C2F2SSD2-US (1.44 cu ft)', $lines[0]['description']);
        $this->assertStringNotContainsString('Pallet', $lines[0]['display_name']);
    }

    public function test_volume_prose_overrides_wrong_storing_by_location_type_column(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Charge Name,Charge Type (charge),Category (charge),Fee (charge),Description (charge),Charge Qty,Avg Rate,Charge Subtotal
Storage,storing_by_location_charge,storage,Storage,"SKU P24C2F2SSD2-US with a volume of 1.44 cu ft stored in location Q-55-0 of type Pallet (Medium) for 1 day(s).",1,0.76,0.76
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(1, $lines);
        $this->assertSame('Storage by Volume', $lines[0]['display_name']);
        $this->assertSame('P24C2F2SSD2-US (1.44 cu ft)', $lines[0]['description']);
        $this->assertStringNotContainsString('Pallet', $lines[0]['display_name']);
    }

    /**
     * Legacy wide ShipHero rows (no Charge Name): $0 volume storage must not fall through to a fake "Storage" line.
     */
    public function test_legacy_wide_csv_zero_dollar_volume_storage_emits_no_line(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Date,Category (charge),Fee (charge),Type (charge),Label (charge),Description (charge),Unit rate (charge),Quantity (charge),Total (charge)
2026-05-11,storage,Storage Per Cu FT,storing_by_volume_charge,Storage Per Cu FT,"SKU P24C2D2 - US with a volume of 1.50 cu ft stored in location W-27-0 of type Pallet (Medium) for 1 day(s).",0,1,0
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(0, $lines);
    }

    public function test_storing_by_location_zero_dollar_row_skipped(): void
    {
        $f = tempnam(sys_get_temp_dir(), 'invcsv');
        $this->assertNotFalse($f);
        $csv = <<<'CSV'
Charge Name,Charge Type (charge),Category (charge),Fee (charge),Description (charge),Charge Qty,Avg Rate,Charge Subtotal
Storage,storing_by_location_charge,storage,Storage,"Location A-16-031 of type Bin (Large) occupied for 7 day(s).",7,0,0
CSV;
        file_put_contents($f, $csv);

        try {
            $parser = new InvoiceChargeImportParser;
            $lines = $parser->parseFile($f);
        } finally {
            unlink($f);
        }

        $this->assertCount(0, $lines);
    }
}
