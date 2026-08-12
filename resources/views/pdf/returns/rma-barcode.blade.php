<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .rma { font-size: 14px; font-weight: 700; margin-bottom: 8px; letter-spacing: 0.06em; }
        .barcode { margin: 0 auto; }
        .value { font-size: 11px; margin-top: 6px; letter-spacing: 0.04em; }
    </style>
</head>
<body>
    <div class="rma">{{ $rmaNumber }}</div>
    <div class="barcode">{!! $barcodeHtml !!}</div>
    <div class="value">{{ $rmaNumber }}</div>
</body>
</html>
