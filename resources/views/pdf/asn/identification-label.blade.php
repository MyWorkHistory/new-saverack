<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 10px; }
        body { font-family: DejaVu Sans, sans-serif; color: #111; text-align: center; }
        .wrap { padding-top: 105px; }
        .account { font-size: 28px; font-weight: 800; line-height: 1.15; }
        .asn { font-size: 48px; font-weight: 800; line-height: 1.05; margin-top: 12px; letter-spacing: 0.04em; }
        .addr { font-size: 18px; line-height: 1.35; margin-top: 18px; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="account">{{ $accountName ?: 'Save Rack' }}</div>
        <div class="asn">{{ $asnLabel }}</div>
        <div class="addr">
            @foreach ($addressLines as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
    </div>
</body>
</html>
