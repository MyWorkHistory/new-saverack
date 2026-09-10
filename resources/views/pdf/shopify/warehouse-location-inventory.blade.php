<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <style>
        * { box-sizing: border-box; }
        @page {
            margin: 36pt 40pt;
        }
        body {
            margin: 0;
            padding: 0;
            font-family: DejaVu Sans, sans-serif;
            color: #0f172a;
            font-size: 10pt;
        }
        .eyebrow {
            font-size: 9pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #0f172a;
            margin: 0 0 4pt;
        }
        .location-name {
            font-size: 28pt;
            font-weight: 800;
            line-height: 1.1;
            margin: 0 0 14pt;
        }
        .rule {
            border: 0;
            border-top: 2pt solid #0f172a;
            margin: 0 0 12pt;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        thead th {
            background: #e2e8f0;
            text-align: left;
            font-size: 8.5pt;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            padding: 8pt 10pt;
            color: #0f172a;
        }
        thead th.qty,
        tbody td.qty,
        tfoot td.qty {
            text-align: right;
        }
        tbody td {
            padding: 10pt;
            border-bottom: 1pt solid #e2e8f0;
            vertical-align: top;
        }
        .product-title {
            font-weight: 700;
            font-size: 10.5pt;
            line-height: 1.25;
        }
        .product-sku {
            margin-top: 2pt;
            font-size: 9pt;
            color: #64748b;
        }
        .account {
            font-size: 10pt;
        }
        .qty-value {
            font-weight: 700;
            font-size: 11pt;
        }
        tfoot td {
            padding: 12pt 10pt 0;
            border: 0;
            vertical-align: bottom;
        }
        .total-label {
            font-size: 10pt;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            text-align: right;
        }
        .total-qty {
            font-size: 18pt;
            font-weight: 800;
            text-align: right;
            line-height: 1;
        }
        .empty {
            padding: 24pt 10pt;
            color: #64748b;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="eyebrow">Location</div>
    <div class="location-name">{{ $locationName }}</div>
    <hr class="rule">

    <table>
        <thead>
            <tr>
                <th style="width: 54%">Product</th>
                <th style="width: 30%">Account</th>
                <th class="qty" style="width: 16%">Qty</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($items as $row)
                <tr>
                    <td>
                        <div class="product-title">{{ $row['product_title'] }}</div>
                        @if ($row['sku'] !== '')
                            <div class="product-sku">SKU: {{ $row['sku'] }}</div>
                        @endif
                    </td>
                    <td class="account">{{ $row['account_name'] }}</td>
                    <td class="qty"><span class="qty-value">{{ number_format($row['available']) }}</span></td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="empty">No inventory at this location.</td>
                </tr>
            @endforelse
        </tbody>
        @if (count($items) > 0)
            <tfoot>
                <tr>
                    <td></td>
                    <td class="total-label">Total Qty</td>
                    <td class="qty total-qty">{{ number_format($totalQty) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
