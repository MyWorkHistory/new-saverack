<?php

namespace App\Services;

use App\Models\LocationLabel;
use App\Support\Barcode\QrCodeSvg;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Symfony\Component\HttpFoundation\Response;

class LocationLabelPrintService
{
    public const LABEL_LARGE = 'large';
    public const LABEL_SMALL = 'small';

    /** Distance between consecutive line tops, as a multiple of font size. */
    private const LINE_SPACING = 1.3;
    /** Dompdf/DejaVu span box height as a fraction of font size (measured). */
    private const FONT_BOX = 1.16;
    /** Offset from a line div's CSS top to the rendered span box top (measured). */
    private const CSS_TO_BOX = 0.095;
    private const MIN_FONT = 6;
    private const MAX_LINES = 3;

    /** @var FontMetrics|null */
    private static $fontMetrics;

    /** @var string|null */
    private static $fontFile;

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
        $qrLeft = $isSmall ? 5 : 8;
        $textLeft = $qrLeft + $qrSize + ($isSmall ? 6 : 10);
        // Text area inside the remaining width, minus side padding.
        $textW = $pageW - $textLeft - ($isSmall ? 5 : 8);
        $textH = $pageH - ($isSmall ? 6 : 10);
        $maxFont = $isSmall ? 20 : 36;

        $items = [];
        foreach ($labels as $label) {
            $display = trim((string) ($label->type ?? ''));
            $barcode = trim((string) ($label->location ?? ''));
            $text = $display !== '' ? $display : $barcode;

            $layout = $this->fitText($text, $textW, $textH, $maxFont);

            // Position every line individually at an exact top offset; dompdf's
            // own line stacking and vertical alignment are unreliable.
            $font = $layout['font'];
            $count = count($layout['lines']);
            $spacing = $font * self::LINE_SPACING;
            // Full font box of first..last line; constants calibrated against
            // measured dompdf output so the box mid lands on the page mid.
            $bandH = ($count - 1) * $spacing + self::FONT_BOX * $font;
            $bandTop = max(($pageH - $bandH) / 2, 0);
            $firstLineTop = $bandTop - self::CSS_TO_BOX * $font;

            $lines = [];
            foreach ($layout['lines'] as $i => $line) {
                $lines[] = [
                    'text' => $line,
                    'top' => round($firstLineTop + $i * $spacing, 1),
                ];
            }

            $items[] = [
                'qrDataUri' => QrCodeSvg::dataUri($barcode !== '' ? $barcode : $text, $isSmall ? 120 : 200),
                'lines' => $lines,
                'font' => $font,
            ];
        }

        $pdf = Pdf::loadView('pdf.inventory.location-label', [
            'data' => $items,
            'cnt' => count($items),
            'pageW' => $pageW,
            'pageH' => $pageH,
            'qrSize' => $qrSize,
            'qrLeft' => $qrLeft,
            'qrTop' => round(($pageH - $qrSize) / 2, 1),
            'textLeft' => $textLeft,
            'textW' => $textW,
            'labelType' => $isSmall ? 'small' : 'normal',
        ])->setPaper([0, 0, $pageW, $pageH], 'landscape');

        $filename = 'location-labels-'.$labelType.'-'.time().'.pdf';

        return $pdf->stream($filename);
    }

    /**
     * Largest font that fits the text box, measured with dompdf's real font
     * metrics. Prefers fewer lines: extra lines are only used when they allow
     * a clearly larger font.
     *
     * @return array{lines:list<string>, font:int}
     */
    private function fitText(string $text, float $boxW, float $boxH, int $maxFont): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['lines' => [''], 'font' => $maxFont];
        }

        $bestFont = 0;
        $bestLines = [''];

        for ($lineCap = 1; $lineCap <= self::MAX_LINES; $lineCap++) {
            $sizeCap = min($maxFont, (int) floor($boxH / ($lineCap * self::LINE_SPACING)));
            for ($size = $sizeCap; $size >= self::MIN_FONT; $size--) {
                $lines = $this->wrapMeasured($text, $size, $boxW);
                if (count($lines) > $lineCap) {
                    continue;
                }
                if ($size > $bestFont * 1.25) {
                    $bestFont = $size;
                    $bestLines = $lines;
                }
                break;
            }
        }

        if ($bestFont === 0) {
            // Nothing fits within MAX_LINES even at the minimum size: truncate.
            $bestFont = self::MIN_FONT;
            $lines = $this->wrapMeasured($text, $bestFont, $boxW);
            $maxLines = max(min((int) floor($boxH / ($bestFont * self::LINE_SPACING)), self::MAX_LINES), 1);
            if (count($lines) > $maxLines) {
                $lines = array_slice($lines, 0, $maxLines);
                $last = $lines[$maxLines - 1];
                while ($last !== '' && ! $this->fitsWidth($last.'…', $bestFont, $boxW)) {
                    $last = mb_substr($last, 0, mb_strlen($last) - 1);
                }
                $lines[$maxLines - 1] = $last.'…';
            }
            $bestLines = $lines;
        }

        return [
            'lines' => $bestLines,
            'font' => $bestFont,
        ];
    }

    /**
     * Greedy word wrap using measured widths; hard-splits oversized words,
     * preferring hyphen boundaries in codes like A-00-001-EXTRA.
     *
     * @return list<string>
     */
    private function wrapMeasured(string $text, int $font, float $boxW): array
    {
        $words = preg_split('/\s+/u', $text) ?: [$text];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->fitsWidth($candidate, $font, $boxW)) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            while (! $this->fitsWidth($word, $font, $boxW)) {
                $chunk = $this->longestFittingPrefix($word, $font, $boxW);
                $lines[] = $chunk;
                $word = mb_substr($word, mb_strlen($chunk));
            }
            $current = $word;
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function longestFittingPrefix(string $word, int $font, float $boxW): string
    {
        $len = mb_strlen($word);
        $best = mb_substr($word, 0, 1);
        for ($i = 2; $i < $len; $i++) {
            $prefix = mb_substr($word, 0, $i);
            if (! $this->fitsWidth($prefix, $font, $boxW)) {
                break;
            }
            $best = $prefix;
        }

        // Break right after a hyphen when one sits in the latter half of the prefix.
        $hyphen = mb_strrpos($best, '-');
        if ($hyphen !== false && $hyphen + 1 >= (int) ceil(mb_strlen($best) / 2) && $hyphen + 1 < mb_strlen($word)) {
            return mb_substr($best, 0, $hyphen + 1);
        }

        return $best;
    }

    private function fitsWidth(string $text, int $font, float $boxW): bool
    {
        return $this->measure($text, $font) <= $boxW;
    }

    private function measure(string $text, int $font): float
    {
        return (float) $this->fontMetrics()->getTextWidth($text, $this->fontFile(), $font);
    }

    private function fontMetrics(): FontMetrics
    {
        if (self::$fontMetrics === null) {
            self::$fontMetrics = (new Dompdf())->getFontMetrics();
        }

        return self::$fontMetrics;
    }

    private function fontFile(): string
    {
        if (self::$fontFile === null) {
            self::$fontFile = (string) $this->fontMetrics()->getFont('DejaVu Sans', 'bold');
        }

        return self::$fontFile;
    }
}
