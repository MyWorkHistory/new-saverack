<?php

namespace Tests\Unit;

use App\Services\InvoiceAsendiaDutiesTaxesImportParser;
use App\Support\Billing\InvoiceImportTabularFileReader;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\TestCase;

class InvoiceImportTabularFileReaderTest extends TestCase
{
    public function test_converts_xlsx_first_sheet_to_csv_for_parser(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Zip extension is not available.');
        }

        $xlsxPath = $this->createTestXlsx([
            ['Order Number', 'Product', 'Duty', 'Tax'],
            ['#20177', 'Duty & Taxes', '10.00', '5.50'],
        ]);

        try {
            $upload = new UploadedFile($xlsxPath, 'asendia.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
            $reader = new InvoiceImportTabularFileReader();
            $resolved = $reader->resolveParserPath($upload);

            $this->assertNotSame($xlsxPath, $resolved['path']);
            $this->assertIsCallable($resolved['cleanup']);

            try {
                $parser = new InvoiceAsendiaDutiesTaxesImportParser();
                $parsed = $parser->parseFile($resolved['path']);
                $this->assertCount(2, $parsed['lines']);
                $this->assertSame('#20177', $parsed['lines'][0]['metadata']['order_number']);
            } finally {
                if (is_callable($resolved['cleanup'])) {
                    ($resolved['cleanup'])();
                }
            }
        } finally {
            @unlink($xlsxPath);
        }
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function createTestXlsx(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'invoice_xlsx_');
        $this->assertNotFalse($path);
        $xlsxPath = $path.'.xlsx';
        rename($path, $xlsxPath);

        $shared = [];
        $sharedIndex = [];
        $indexOf = static function (string $value) use (&$shared, &$sharedIndex): int {
            if (! isset($sharedIndex[$value])) {
                $sharedIndex[$value] = count($shared);
                $shared[] = $value;
            }

            return $sharedIndex[$value];
        };

        $sheetRowsXml = '';
        foreach ($rows as $rowNumber => $row) {
            $rowXml = '<row r="'.($rowNumber + 1).'">';
            foreach ($row as $colIndex => $value) {
                $col = $this->columnLetters($colIndex);
                $ref = $col.($rowNumber + 1);
                $sharedIdx = $indexOf($value);
                $rowXml .= '<c r="'.$ref.'" t="s"><v>'.$sharedIdx.'</v></c>';
            }
            $rowXml .= '</row>';
            $sheetRowsXml .= $rowXml;
        }

        $sharedXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($shared).'" uniqueCount="'.count($shared).'">';
        foreach ($shared as $text) {
            $sharedXml .= '<si><t>'.htmlspecialchars($text, ENT_XML1).'</t></si>';
        }
        $sharedXml .= '</sst>';

        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$sheetRowsXml.'</sheetData></worksheet>';

        $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

        $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';

        $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>';

        $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($xlsxPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->addFromString('xl/sharedStrings.xml', $sharedXml);
        $zip->close();

        return $xlsxPath;
    }

    private function columnLetters(int $index): string
    {
        $index++;
        $letters = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $index = (int) floor(($index - $mod) / 26);
        }

        return $letters;
    }
}
