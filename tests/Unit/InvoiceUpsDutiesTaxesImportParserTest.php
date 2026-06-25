<?php

namespace Tests\Unit;

use App\Services\InvoiceUpsDutiesTaxesImportParser;
use PHPUnit\Framework\TestCase;

class InvoiceUpsDutiesTaxesImportParserTest extends TestCase
{
    public function test_parse_file_creates_one_combined_line_per_row(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ups_duties_csv_');
        $this->assertNotFalse($path);
        file_put_contents(
            $path,
            "Reference No.1,Billed Charge\n#20177,122.58\n#20163,75.94\n"
        );

        try {
            $parser = new InvoiceUpsDutiesTaxesImportParser();
            $parsed = $parser->parseFile($path);
            $lines = $parsed['lines'];

            $this->assertSame(2, $parsed['rows_processed']);
            $this->assertCount(2, $lines);
            $this->assertSame('International Duties & Taxes (UPS)', $lines[0]['display_name']);
            $this->assertSame('duties_taxes:international-duties-taxes-ups', $lines[0]['group_key']);
            $this->assertSame(12258, $lines[0]['line_total_cents']);
            $this->assertSame('#20177', $lines[0]['metadata']['order_number']);
            $this->assertSame(7594, $lines[1]['line_total_cents']);
            $this->assertSame('#20163', $lines[1]['metadata']['order_number']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_file_skips_zero_billed_charge(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ups_duties_csv_');
        $this->assertNotFalse($path);
        file_put_contents(
            $path,
            "Reference No.1,Billed Charge\n#20177,0\n#20163,10.00\n"
        );

        try {
            $parser = new InvoiceUpsDutiesTaxesImportParser();
            $parsed = $parser->parseFile($path);
            $lines = $parsed['lines'];

            $this->assertCount(1, $lines);
            $this->assertSame('#20163', $lines[0]['metadata']['order_number']);
            $this->assertSame(1000, $lines[0]['line_total_cents']);
            $this->assertSame(1, $parsed['skipped']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_file_throws_when_reference_column_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ups_duties_csv_');
        $this->assertNotFalse($path);
        file_put_contents($path, "Billed Charge\n122.58\n");

        try {
            $parser = new InvoiceUpsDutiesTaxesImportParser();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Reference No.1');
            $parser->parseFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_file_throws_when_billed_charge_column_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ups_duties_csv_');
        $this->assertNotFalse($path);
        file_put_contents($path, "Reference No.1\n#20177\n");

        try {
            $parser = new InvoiceUpsDutiesTaxesImportParser();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Billed Charge');
            $parser->parseFile($path);
        } finally {
            @unlink($path);
        }
    }
}
