<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .sku { font-size: 13px; font-weight: 700; margin-bottom: 8px; word-break: break-all; }
        .barcode { margin: 0 auto; }
        .value { font-size: 11px; margin-top: 6px; letter-spacing: 0.04em; }
    </style>
</head>
<body>
    <div class="sku">{{ $sku }}</div>
    <div class="barcode">{!! $barcodeHtml !!}</div>
    <div class="value">{{ $barcode }}</div>
</body>
</html>
