<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 8pt; }
        * { margin: 0; padding: 0; }
        html, body {
            font-family: DejaVu Sans, sans-serif;
            color: #111;
            text-align: center;
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        .wrap {
            width: 100%;
            padding-top: 4pt;
        }
        .sku {
            font-size: 12pt;
            font-weight: 700;
            line-height: 1.15;
            margin-bottom: 6pt;
            word-break: break-all;
        }
        .barcode {
            line-height: 0;
            margin: 0 auto;
        }
        .barcode img {
            display: block;
            margin: 0 auto;
            max-width: 100%;
            height: 56pt;
        }
        .value {
            font-size: 10pt;
            line-height: 1.2;
            margin-top: 6pt;
            letter-spacing: 0.04em;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="sku">{{ $sku }}</div>
        <div class="barcode"><img src="{{ $barcodeSvg }}" alt="Barcode"></div>
        <div class="value">{{ $barcode }}</div>
    </div>
</body>
</html>
