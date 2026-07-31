<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .sku { font-size: 13px; font-weight: 700; margin-bottom: 4px; word-break: break-all; }
        .name {
            font-size: 11px;
            font-weight: 400;
            color: #333;
            margin: 0 4px 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .barcode img { max-width: 100%; height: 70px; }
        .value { font-size: 11px; margin-top: 6px; letter-spacing: 0.04em; }
    </style>
</head>
<body>
    @php
        $productName = trim((string) ($line->name ?? ''));
        if (mb_strlen($productName) > 48) {
            $productName = rtrim(mb_substr($productName, 0, 47)).'…';
        }
    @endphp
    <div class="sku">{{ $line->sku }}</div>
    @if ($productName !== '')
        <div class="name">{{ $productName }}</div>
    @endif
    <div class="barcode"><img src="{{ $barcodeSvg }}" alt="Barcode"></div>
    <div class="value">{{ $barcode }}</div>
</body>
</html>
