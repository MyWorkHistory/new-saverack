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
            color: #111;
        }
        /* Absolute positioning throughout: dompdf's table vertical-align and
           line stacking are unreliable, so every element (including each text
           line) is pinned at exact centered coordinates. */
        .label {
            position: relative;
            width: {{ $pageW }}pt;
            height: {{ $pageH }}pt;
            overflow: hidden;
        }
        .label-qr {
            position: absolute;
            top: {{ $qrTop }}pt;
            left: {{ $qrLeft }}pt;
            width: {{ $qrSize }}pt;
            height: {{ $qrSize }}pt;
        }
        .label-line {
            position: absolute;
            left: {{ $textLeft }}pt;
            width: {{ $textW }}pt;
            font-weight: bold;
            line-height: 1;
            white-space: nowrap;
        }
        .page-break {
            page-break-before: always;
        }
    </style>
</head>
<body>
@foreach($data as $item)
    @if(! $loop->first)
        <div class="page-break"></div>
    @endif
    <div class="label">
        <img class="label-qr" src="{{ $item['qrDataUri'] }}" alt="QR Code">
        @foreach($item['lines'] as $line)
            <div class="label-line" style="top: {{ $line['top'] }}pt; font-size: {{ $item['font'] }}pt;">{{ $line['text'] }}</div>
        @endforeach
    </div>
@endforeach
</body>
</html>
