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
        .label {
            width: {{ $pageW }}pt;
            height: {{ $pageH }}pt;
        }
        .label table {
            border-collapse: collapse;
            width: 100%;
        }
        .label td {
            vertical-align: top;
            padding: 0;
        }
        .qr-cell {
            width: {{ $qrCellW }}pt;
            text-align: center;
            /* Explicit top padding centers content; dompdf's vertical-align is unreliable. */
            padding-top: {{ $qrPadTop }}pt;
        }
        .qr-cell img {
            width: {{ $qrSize }}pt;
            height: {{ $qrSize }}pt;
        }
        .text-cell {
            padding-right: {{ $labelType === 'small' ? 4 : 8 }}pt;
        }
        .display-name {
            font-weight: bold;
            line-height: 1.12;
            word-wrap: break-word;
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
        <table>
            <tbody>
                <tr>
                    <td class="qr-cell">
                        <img src="{{ $item['qrDataUri'] }}" alt="QR Code">
                    </td>
                    <td class="text-cell" style="padding-top: {{ $item['textPadTop'] }}pt;">
                        <div class="display-name" style="font-size: {{ $item['font'] }}pt;">{!! $item['html'] !!}</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
@endforeach
</body>
</html>
