<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .wrap { padding-top: 72px; }
        .account { font-size: 26px; font-weight: 800; line-height: 1.15; }
        .rma { font-size: 40px; font-weight: 800; line-height: 1.05; margin-top: 10px; letter-spacing: 0.04em; }
        .addr { font-size: 16px; line-height: 1.35; margin-top: 14px; }
        .barcode { margin-top: 18px; }
        .barcode img { max-width: 100%; height: 52px; }
        .rma-value { font-size: 11px; margin-top: 6px; letter-spacing: 0.06em; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="account">{{ $accountName ?: 'Save Rack' }}</div>
        <div class="rma">{{ $rmaLabel }}</div>
        <div class="addr">
            @foreach ($addressLines as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
        <div class="barcode"><img src="{{ $barcodeSvg }}" alt="RMA barcode"></div>
        <div class="rma-value">{{ $return->rma_number }}</div>
    </div>
</body>
</html>
