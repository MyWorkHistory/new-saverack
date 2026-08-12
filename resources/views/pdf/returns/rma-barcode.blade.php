<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .rma {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin: 0 0 10px 0;
            line-height: 1.2;
            letter-spacing: 0.06em;
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
            line-height: 1.2;
            letter-spacing: 0.04em;
        }
    </style>
</head>
<body>
    <div class="rma">{{ $rmaNumber }}</div>
    <div class="barcode">{!! $barcodeHtml !!}</div>
    <div class="value">{{ $rmaNumber }}</div>
</body>
</html>
