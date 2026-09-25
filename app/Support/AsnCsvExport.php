<?php

namespace App\Support;

use App\Models\ClientAccountAsn;
use App\Models\ClientAccountAsnLine;
use App\Services\AsnReceivingService;

class AsnCsvExport
{
    /**
     * @return list<string>
     */
    public static function headers(): array
    {
        return [
            'ASN ID',
            'ASN #',
            'Account',
            'Account ID',
            'Status',
            'Date Created',
            'Date Received',
            'Processed At',
            'Processed By',
            'ASN Expected QTY',
            'ASN Received QTY',
            'ASN Rejected QTY',
            'Total Boxes',
            'Total Pallets',
            'Tracking Count',
            'All Tracking',
            'Vendor Lines',
            'Warehouse Notes',
            'Line ID',
            'SKU',
            'Item Name',
            'Line Status',
            'Expected QTY',
            'Received QTY',
            'Rejected QTY',
            'Barcode',
            'Weight',
            'Length',
            'Width',
            'Height',
        ];
    }

    /**
     * Write one row per line item (header fields repeated). Empty ASNs still get a header-only row.
     *
     * @param  resource  $out
     */
    public static function writeAsn($out, ClientAccountAsn $asn, ?AsnReceivingService $receiving = null): void
    {
        $asn->loadMissing(['lines', 'trackings', 'vendorLines', 'clientAccount', 'processedBy']);

        $header = self::headerCells($asn);
        $lines = $asn->lines;
        if ($lines->isEmpty()) {
            fputcsv($out, array_merge($header, self::emptyLineCells()));

            return;
        }

        foreach ($lines as $line) {
            fputcsv($out, array_merge($header, self::lineCells($line, $receiving)));
        }
    }

    /**
     * @return list<string>
     */
    private static function headerCells(ClientAccountAsn $asn): array
    {
        $accountName = $asn->clientAccount !== null
            ? trim((string) $asn->clientAccount->company_name)
            : '';
        $processedBy = $asn->processedBy !== null
            ? trim((string) $asn->processedBy->name)
            : '';

        $trackingParts = [];
        foreach ($asn->trackings as $tracking) {
            $number = trim((string) $tracking->tracking_number);
            $carrier = trim((string) ($tracking->carrier ?? ''));
            if ($number === '' && $carrier === '') {
                continue;
            }
            $trackingParts[] = $carrier !== '' && $number !== ''
                ? $carrier.': '.$number
                : ($number !== '' ? $number : $carrier);
        }

        $vendorParts = [];
        foreach ($asn->vendorLines as $vendor) {
            $label = trim((string) ($vendor->label ?? ''));
            if ($label !== '') {
                $vendorParts[] = $label;
            }
        }

        return [
            CsvExporter::cell($asn->id),
            CsvExporter::cell($asn->asn_number),
            CsvExporter::cell($accountName),
            CsvExporter::cell($asn->client_account_id),
            CsvExporter::cell($asn->status),
            CsvExporter::cell($asn->created_at),
            CsvExporter::cell($asn->date_received),
            CsvExporter::cell($asn->processed_at),
            CsvExporter::cell($processedBy),
            CsvExporter::cell($asn->expected_qty),
            CsvExporter::cell($asn->accepted_qty),
            CsvExporter::cell($asn->rejected_qty),
            CsvExporter::cell($asn->total_boxes),
            CsvExporter::cell($asn->total_pallets),
            CsvExporter::cell(count($trackingParts)),
            CsvExporter::cell(implode('; ', $trackingParts)),
            CsvExporter::cell(implode('; ', $vendorParts)),
            CsvExporter::cell($asn->warehouse_notes),
        ];
    }

    /**
     * @return list<string>
     */
    private static function emptyLineCells(): array
    {
        return array_fill(0, 13, '');
    }

    /**
     * @return list<string>
     */
    private static function lineCells(ClientAccountAsnLine $line, ?AsnReceivingService $receiving): array
    {
        $serialized = $receiving !== null
            ? $receiving->serializeLine($line)
            : [
                'id' => $line->id,
                'sku' => $line->sku,
                'name' => $line->name,
                'line_status' => $line->line_status,
                'expected_qty' => $line->expected_qty,
                'accepted_qty' => $line->accepted_qty,
                'rejected_qty' => $line->rejected_qty,
                'barcode' => $line->barcode,
                'weight' => $line->weight,
                'length' => $line->length,
                'width' => $line->width,
                'height' => $line->height,
            ];

        return [
            CsvExporter::cell($serialized['id'] ?? $line->id),
            CsvExporter::cell($serialized['sku'] ?? $line->sku),
            CsvExporter::cell($serialized['name'] ?? $line->name),
            CsvExporter::cell($serialized['line_status'] ?? $line->line_status),
            CsvExporter::cell($serialized['expected_qty'] ?? $line->expected_qty),
            CsvExporter::cell($serialized['accepted_qty'] ?? $line->accepted_qty),
            CsvExporter::cell($serialized['rejected_qty'] ?? $line->rejected_qty),
            CsvExporter::cell($serialized['barcode'] ?? $line->barcode),
            CsvExporter::cell($serialized['weight'] ?? $line->weight),
            CsvExporter::cell($serialized['length'] ?? $line->length),
            CsvExporter::cell($serialized['width'] ?? $line->width),
            CsvExporter::cell($serialized['height'] ?? $line->height),
        ];
    }
}
