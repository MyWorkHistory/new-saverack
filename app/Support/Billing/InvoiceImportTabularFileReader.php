<?php

namespace App\Support\Billing;

use Illuminate\Http\UploadedFile;

/**
 * Normalizes invoice import uploads (CSV, TXT, XLSX) to a path readable by fgetcsv parsers.
 */
final class InvoiceImportTabularFileReader
{
    /**
     * @return array{path: string, cleanup: callable|null}
     */
    public function resolveParserPath(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if ($path === false) {
            throw new \RuntimeException('Invalid upload.');
        }

        if (! $this->isXlsxUpload($file, $path)) {
            return ['path' => $path, 'cleanup' => null];
        }

        $tempCsv = $this->convertXlsxFirstSheetToCsv($path);

        return [
            'path' => $tempCsv,
            'cleanup' => static function () use ($tempCsv): void {
                if (is_file($tempCsv)) {
                    @unlink($tempCsv);
                }
            },
        ];
    }

    private function isXlsxUpload(UploadedFile $file, string $path): bool
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        if ($ext === 'xlsx') {
            return true;
        }

        $mime = strtolower((string) $file->getMimeType());

        return in_array($mime, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
        ], true) || $this->fileStartsWithZipMagic($path);
    }

    private function fileStartsWithZipMagic(string $path): bool
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $bytes = fread($fh, 4);
        fclose($fh);

        return $bytes === "PK\x03\x04";
    }

    private function convertXlsxFirstSheetToCsv(string $path): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Excel import requires the PHP Zip extension.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not read Excel file.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->resolveFirstSheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false || trim($sheetXml) === '') {
                throw new \RuntimeException('Excel file has no readable worksheet.');
            }

            $rows = $this->readSheetRows($sheetXml, $sharedStrings);
            if ($rows === []) {
                throw new \RuntimeException('Excel worksheet is empty.');
            }

            $tempCsv = tempnam(sys_get_temp_dir(), 'invoice_import_');
            if ($tempCsv === false) {
                throw new \RuntimeException('Could not prepare Excel import file.');
            }

            $fh = fopen($tempCsv, 'wb');
            if ($fh === false) {
                @unlink($tempCsv);
                throw new \RuntimeException('Could not prepare Excel import file.');
            }

            try {
                foreach ($rows as $row) {
                    fputcsv($fh, $row);
                }
            } finally {
                fclose($fh);
            }

            return $tempCsv;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || trim($xml) === '') {
            return [];
        }

        $doc = $this->loadXml($xml);
        if ($doc === null) {
            return [];
        }

        $strings = [];
        foreach ($doc->xpath('//*[local-name()="si"]') ?: [] as $si) {
            $strings[] = $this->sharedStringValue($si);
        }

        return $strings;
    }

  /**
     * @param  \SimpleXMLElement  $si
     */
    private function sharedStringValue(\SimpleXMLElement $si): string
    {
        $parts = $si->xpath('.//*[local-name()="t"]') ?: [];
        if ($parts !== []) {
            $text = '';
            foreach ($parts as $part) {
                $text .= (string) $part;
            }

            return $text;
        }

        return trim((string) $si);
    }

    private function resolveFirstSheetPath(\ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new \RuntimeException('Excel file is missing workbook metadata.');
        }

        $workbook = $this->loadXml($workbookXml);
        $rels = $this->loadXml($relsXml);
        if ($workbook === null || $rels === null) {
            throw new \RuntimeException('Excel file is missing workbook metadata.');
        }

        $sheetNodes = $workbook->xpath('//*[local-name()="sheet"]') ?: [];
        if ($sheetNodes === []) {
            throw new \RuntimeException('Excel file has no worksheets.');
        }

        $relationshipId = (string) ($sheetNodes[0]['r:id'] ?? $sheetNodes[0]['id'] ?? '');
        if ($relationshipId === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        foreach ($rels->xpath('//*[local-name()="Relationship"]') ?: [] as $rel) {
            if ((string) ($rel['Id'] ?? '') !== $relationshipId) {
                continue;
            }
            $target = ltrim((string) ($rel['Target'] ?? ''), '/');
            if ($target === '') {
                break;
            }
            if (strpos($target, 'xl/') === 0) {
                return $target;
            }

            return 'xl/'.$target;
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /**
     * @param  list<string>  $sharedStrings
     * @return list<list<string>>
     */
    private function readSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $doc = $this->loadXml($sheetXml);
        if ($doc === null) {
            return [];
        }

        $rows = [];
        foreach ($doc->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $rowNode) {
            $cells = [];
            $maxIndex = -1;
            foreach ($rowNode->xpath('./*[local-name()="c"]') ?: [] as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                $index = $ref !== '' ? $this->columnIndexFromCellRef($ref) : $maxIndex + 1;
                $maxIndex = max($maxIndex, $index);
                $cells[$index] = $this->cellValue($cell, $sharedStrings);
            }
            if ($cells === []) {
                $rows[] = [];

                continue;
            }
            ksort($cells);
            $normalized = [];
            $lastIndex = max(array_keys($cells));
            for ($i = 0; $i <= $lastIndex; $i++) {
                $normalized[] = (string) ($cells[$i] ?? '');
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * @param  \SimpleXMLElement  $cell
     * @param  list<string>  $sharedStrings
     */
    private function cellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) ($cell['t'] ?? '');
        $valueNode = $cell->xpath('./*[local-name()="v"]');
        $value = ($valueNode !== false && isset($valueNode[0])) ? (string) $valueNode[0] : '';

        if ($type === 's') {
            $idx = is_numeric($value) ? (int) $value : -1;

            return $idx >= 0 && isset($sharedStrings[$idx]) ? $sharedStrings[$idx] : '';
        }
        if ($type === 'inlineStr') {
            $inline = $cell->xpath('.//*[local-name()="t"]');

            return ($inline !== false && isset($inline[0])) ? (string) $inline[0] : '';
        }
        if ($type === 'b') {
            return $value === '1' ? 'TRUE' : 'FALSE';
        }

        return $value;
    }

    private function columnIndexFromCellRef(string $ref): int
    {
        if (preg_match('/^([A-Z]+)/', strtoupper($ref), $m) !== 1) {
            return 0;
        }

        $letters = $m[1];
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    private function loadXml(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);

            return $doc instanceof \SimpleXMLElement ? $doc : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
