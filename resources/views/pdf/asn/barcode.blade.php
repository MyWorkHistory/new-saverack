<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .sku {
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin: 0 0 10px 0;
            padding: 0;
            line-height: 1.2;
            word-break: break-all;
        }
        .barcode {
            display: block;
            margin: 0 auto 8px auto;
            padding: 0;
            line-height: 0;
            text-align: center;
        }
        .barcode table {
            margin: 0 auto;
        }
        .value {
            display: block;
            font-size: 11px;
            margin: 8px 0 0 0;
            padding: 0;
            line-height: 1.2;
            letter-spacing: 0.04em;
        }
        .name {
            display: block;
            font-size: 11px;
            font-weight: 400;
            color: #333;
            margin: 8px 4px 0;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>
    @php
        $productName = trim((string) ($line->name ?? ''));
        if (mb_strlen($productName) > 48) {
            $productName = rtrim(mb_substr($productName, 0, 47)).'…';
        }
        $bars = $barcodeHtml ?? null;
        if ($bars === null && ! empty($barcodeSvg)) {
            $bars = '<img src="'.e($barcodeSvg).'" alt="Barcode" style="max-width:100%;height:56px;">';
        }
    @endphp
    <div class="sku">{{ $line->sku }}</div>
    <div class="barcode">{!! $bars !!}</div>
    <div class="value">{{ $barcode }}</div>
    @if ($productName !== '')
        <div class="name">{{ $productName }}</div>
    @endif
</body>
</html>
