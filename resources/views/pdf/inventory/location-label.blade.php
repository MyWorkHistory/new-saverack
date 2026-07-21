<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #111;
        }
        .sku {
            font-size: {{ (isset($labelType) && $labelType === 'small') ? '24px' : '33px' }};
            font-weight: bold;
            text-transform: none;
            line-height: 1.15;
        }
        .location {
            font-size: {{ (isset($labelType) && $labelType === 'small') ? '30px' : '50px' }};
            font-weight: bold;
            text-transform: none;
            line-height: 1.1;
        }
        .sku_1 {
            font-size: {{ (isset($labelType) && $labelType === 'small') ? '18px' : '24px' }};
            font-weight: bold;
            text-transform: none;
            line-height: 1.15;
        }
        .sku_2 {
            font-size: {{ (isset($labelType) && $labelType === 'small') ? '10px' : '14px' }};
            font-weight: bold;
            text-transform: none;
            line-height: 1.15;
        }
        @page {
            size: {{ (isset($labelType) && $labelType === 'small') ? '2.25in 0.75in' : '4in 1.5in' }};
            margin: 0;
        }
        .page-break {
            page-break-before: always;
        }
        .label_img {
            width: {{ (isset($labelType) && $labelType === 'small') ? '50px' : '100px' }};
            height: {{ (isset($labelType) && $labelType === 'small') ? '50px' : '100px' }};
            padding-right: 10px;
            padding-top: {{ (isset($labelType) && $labelType === 'small') ? '10.5px' : '21px' }};
        }
        .main_content {
            width: 100%;
            height: 100%;
            padding-left: 10px;
        }
        .main_content table {
            border-collapse: collapse;
            margin: 0 auto;
            width: 100%;
            height: 100%;
        }
        .main_content td {
            vertical-align: middle;
        }
        .text_cell {
            max-width: 240px;
            padding-right: 10px;
        }
    </style>
</head>
<body>
@foreach($data as $item)
    <div class="main_content">
        <table>
            <tbody>
                <tr>
                    <td style="width: {{ (isset($labelType) && $labelType === 'small') ? '60px' : '110px' }};">
                        <img src="{{ $item['qrDataUri'] }}" class="label_img" alt="QR Code">
                    </td>
                    <td class="text_cell">
                        @if($item['is_long'] == 1)
                            <div class="location">{!! $item['sku'] !!}</div>
                        @elseif($item['is_long'] == 2)
                            <div class="sku_1">{!! $item['sku'] !!}</div>
                        @else
                            <div class="sku_2">{!! $item['sku'] !!}</div>
                        @endif
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php $index++; ?>
    @if($index < $cnt)
        <div class="page-break"></div>
    @endif
@endforeach
</body>
</html>
