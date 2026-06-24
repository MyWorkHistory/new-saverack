<?php

namespace Tests\Unit;

use App\Services\InvoiceDutiesTaxesImportParser;
use PHPUnit\Framework\TestCase;

class InvoiceDutiesTaxesImportParserTest extends TestCase
{
    public function test_parse_file_creates_duty_and_tax_lines_per_row(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'duties_csv_');
        $this->assertNotFalse($path);
        file_put_contents(
            $path,
            "Order Number,Product,Duty,Tax\n#100,Duty & Taxes,10.00,5.50\n"
        );

        try {
            $parser = new InvoiceDutiesTaxesImportParser();
            $parsed = $parser->parseFile($path);
            $lines = $parsed['lines'];

            $this->assertCount(2, $lines);
            $this->assertSame('International Duties (Asendia)', $lines[0]['display_name']);
            $this->assertSame(1000, $lines[0]['line_total_cents']);
            $this->assertSame('#100', $lines[0]['metadata']['order_number']);
            $this->assertSame('International Taxes (Asendia)', $lines[1]['display_name']);
            $this->assertSame(550, $lines[1]['line_total_cents']);
        } finally {
            @unlink($path);
        }
    }

    public function test_parse_file_throws_when_order_number_column_missing(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'duties_csv_');
        $this->assertNotFalse($path);
        file_put_contents($path, "Product,Duty,Tax\nDuty & Taxes,10.00,5.50\n");

        try {
            $parser = new InvoiceDutiesTaxesImportParser();
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Order Number');
            $parser->parseFile($path);
        } finally {
            @unlink($path);
        }
    }
}
