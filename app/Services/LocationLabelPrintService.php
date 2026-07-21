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

    private const LINE_HEIGHT = 1.12;
    /** Approx glyph width / font size for bold DejaVu Sans. */
    private const CHAR_WIDTH_RATIO = 0.62;

    /**
     * @param  list<LocationLabel>  $labels
     */
    public function stream(array $labels, string $labelType): Response
    {
        $labelType = strtolower(trim($labelType)) === self::LABEL_SMALL
            ? self::LABEL_SMALL
            : self::LABEL_LARGE;
        $isSmall = $labelType === self::LABEL_SMALL;

        // Page: small 2.25in x 0.75in (162x54pt), large 4in x 1.5in (288x108pt).
        $pageW = $isSmall ? 162 : 288;
        $pageH = $isSmall ? 54 : 108;
        $qrSize = $isSmall ? 42 : 86;
        $qrCellW = $qrSize + ($isSmall ? 10 : 14);
        // Text area inside the remaining width, minus side padding.
        $textW = $pageW - $qrCellW - ($isSmall ? 10 : 16);
        $textH = $pageH - ($isSmall ? 8 : 12);
        $maxFont = $isSmall ? 20 : 36;

        $items = [];
        foreach ($labels as $label) {
            $display = trim((string) ($label->type ?? ''));
            $barcode = trim((string) ($label->location ?? ''));
            $text = $display !== '' ? $display : $barcode;

            $layout = $this->fitText($text, $textW, $textH, $maxFont);

            // Center the text block vertically by measuring it, since dompdf's
            // vertical-align support is unreliable.
            $textBlockH = $layout['lines'] * $layout['font'] * self::LINE_HEIGHT;
            $textPadTop = max(($pageH - $textBlockH) / 2, 0);

            $items[] = [
                'qrDataUri' => QrCodeSvg::dataUri($barcode !== '' ? $barcode : $text, $isSmall ? 120 : 200),
                'html' => $layout['html'],
                'font' => $layout['font'],
                'textPadTop' => round($textPadTop, 1),
            ];
        }

        $pdf = Pdf::loadView('pdf.inventory.location-label', [
            'data' => $items,
            'cnt' => count($items),
            'pageW' => $pageW,
            'pageH' => $pageH,
            'qrSize' => $qrSize,
            'qrCellW' => $qrCellW,
            'qrPadTop' => round(max(($pageH - $qrSize) / 2, 0), 1),
            'labelType' => $isSmall ? 'small' : 'normal',
        ])->setPaper([0, 0, $pageW, $pageH], 'landscape');

        $filename = 'location-labels-'.$labelType.'-'.time().'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Largest font that fits the text box in 1-3 lines; wraps on spaces/hyphens
     * where possible and hard-splits unbroken codes as a fallback.
     *
     * @return array{html:string, font:int, lines:int}
     */
    private function fitText(string $text, float $boxW, float $boxH, int $maxFont): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['html' => '', 'font' => $maxFont, 'lines' => 1];
        }

        $len = mb_strlen($text);
        $bestFont = 0;

        for ($lines = 1; $lines <= 3; $lines++) {
            $charsPerLine = (int) ceil($len / $lines);
            if ($charsPerLine < 1) {
                continue;
            }
            $fontFromWidth = $boxW / ($charsPerLine * self::CHAR_WIDTH_RATIO);
            $fontFromHeight = $boxH / ($lines * self::LINE_HEIGHT);
            $font = (int) floor(min($fontFromWidth, $fontFromHeight, $maxFont));
            // Only add lines when they buy a clearly bigger font; short codes
            // like A-00-001 should stay on one line rather than split.
            if ($font > $bestFont * 1.25) {
                $bestFont = $font;
            }
        }

        $font = max($bestFont, 6);
        $charsPerLine = max((int) floor($boxW / ($font * self::CHAR_WIDTH_RATIO)), 1);
        $maxLines = max((int) floor($boxH / ($font * self::LINE_HEIGHT)), 1);

        $chunks = $this->wrapText($text, $charsPerLine);
        if (count($chunks) > $maxLines) {
            $chunks = array_slice($chunks, 0, $maxLines);
            $last = $chunks[$maxLines - 1];
            $chunks[$maxLines - 1] = mb_substr($last, 0, max($charsPerLine - 1, 1)).'…';
        }

        return [
            'html' => implode('<br>', array_map('e', $chunks)),
            'font' => $font,
            'lines' => count($chunks),
        ];
    }

    /**
     * Greedy wrap preferring space/hyphen boundaries; hard-splits oversized words.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $charsPerLine): array
    {
        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            while (mb_strlen($word) > $charsPerLine) {
                // Prefer breaking after a hyphen inside long codes like A-00-001.
                $slice = mb_substr($word, 0, $charsPerLine);
                $hyphen = mb_strrpos($slice, '-');
                $cut = ($hyphen !== false && $hyphen >= (int) floor($charsPerLine / 2)) ? $hyphen + 1 : $charsPerLine;
                if ($current !== '') {
                    $lines[] = $current;
                    $current = '';
                }
                $lines[] = mb_substr($word, 0, $cut);
                $word = mb_substr($word, $cut);
            }
            if ($word === '') {
                continue;
            }
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current) + 1 + mb_strlen($word) <= $charsPerLine) {
                $current .= ' '.$word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }
}
