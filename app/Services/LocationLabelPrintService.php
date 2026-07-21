<?php

namespace App\Services;

use App\Models\LocationLabel;
use App\Support\Barcode\QrCodeSvg;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class LocationLabelPrintService
{
    public const LABEL_LARGE = 'large';
    public const LABEL_SMALL = 'small';

    /**
     * @param  list<LocationLabel>  $labels
     */
    public function stream(array $labels, string $labelType): Response
    {
        $labelType = strtolower(trim($labelType)) === self::LABEL_SMALL
            ? self::LABEL_SMALL
            : self::LABEL_LARGE;

        $items = [];
        foreach ($labels as $label) {
            $display = trim((string) ($label->type ?? ''));
            $barcode = trim((string) ($label->location ?? ''));
            $text = $display !== '' ? $display : $barcode;

            $isLong = 1;
            $rowCnt = 10;
            $len = mb_strlen($text);
            if ($len > 18) {
                if ($len > 32) {
                    $isLong = 3;
                    $rowCnt = 30;
                } else {
                    $isLong = 2;
                    $rowCnt = 16;
                }
            }

            $chunks = $text === '' ? [''] : str_split($text, $rowCnt);
            $wrapped = implode('<br>', array_map('e', $chunks));

            $items[] = [
                'qrDataUri' => QrCodeSvg::dataUri($barcode !== '' ? $barcode : $text, $labelType === self::LABEL_SMALL ? 120 : 200),
                'is_long' => $isLong,
                'sku' => $wrapped,
            ];
        }

        $paper = $labelType === self::LABEL_SMALL
            ? [0, 0, 162, 54]   // 2.25in x 0.75in
            : [0, 0, 288, 108]; // 4in x 1.5in

        $pdf = Pdf::loadView('pdf.inventory.location-label', [
            'data' => $items,
            'cnt' => count($items),
            'index' => 0,
            'labelType' => $labelType === self::LABEL_SMALL ? 'small' : 'normal',
        ])->setPaper($paper, 'landscape');

        $filename = 'location-labels-'.$labelType.'-'.time().'.pdf';

        return $pdf->stream($filename);
    }
}
