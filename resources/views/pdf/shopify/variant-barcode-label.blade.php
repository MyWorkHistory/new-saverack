<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        * { box-sizing: border-box; }
        @page {
            size: {{ $pageW }}pt {{ $pageH }}pt;
            margin: 0;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #1e293b;
        }
        .label {
            position: relative;
            width: {{ $pageW }}pt;
            height: {{ $pageH }}pt;
            overflow: hidden;
            background: #fff;
        }
        .label-qr {
            position: absolute;
            top: {{ $qrTop }}pt;
            left: {{ $qrLeft }}pt;
            width: {{ $qrSize }}pt;
            height: {{ $qrSize }}pt;
        }
        .label-code {
            position: absolute;
            left: {{ $textLeft }}pt;
            top: {{ $codeTop }}pt;
            width: {{ $textW }}pt;
            font-size: {{ $codeFont }}pt;
            font-weight: bold;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .label-name {
            position: absolute;
            left: {{ $textLeft }}pt;
            top: {{ $nameTop }}pt;
            width: {{ $textW }}pt;
            font-size: {{ $nameFont }}pt;
            font-weight: normal;
            color: #64748b;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>
    <div class="label">
        <img class="label-qr" src="{{ $qrDataUri }}" alt="QR Code">
        <div class="label-code">{{ $displayCode }}</div>
        @if ($productName !== '')
            <div class="label-name">{{ $productName }}</div>
        @endif
    </div>
</body>
</html>
