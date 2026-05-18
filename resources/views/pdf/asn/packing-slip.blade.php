<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 12px; }
        .company-name { font-size: 30px; font-weight: 800; letter-spacing: 0.02em; }
        .asn-number { font-size: 20px; font-weight: 700; margin-top: 4px; }
        .rule { border: 0; border-top: 1px solid #d0d0d0; margin: 22px 0; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border-bottom: 1px solid #e1e1e1; padding: 7px 6px; vertical-align: top; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; color: #555; }
        .item-col { width: 48%; }
        .sku-col { width: 38%; word-break: break-all; }
        .qty-col { width: 14%; text-align: right; }
        .item-name { font-size: 10px; line-height: 1.25; color: #333; }
        .sku { font-size: 12px; font-weight: 700; line-height: 1.25; }
    </style>
</head>
<body>
    <div class="company-name">{{ $accountName ?: 'Save Rack' }}</div>
    <div class="asn-number">{{ $asnLabel }}</div>
    <hr class="rule">
    <table>
        <thead>
            <tr>
                <th class="item-col">Item</th>
                <th class="sku-col">SKU</th>
                <th class="qty-col">Qty</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($asn->lines as $line)
                <tr>
                    <td class="item-col"><div class="item-name">{{ $line->name }}</div></td>
                    <td class="sku-col"><div class="sku">{{ $line->sku }}</div></td>
                    <td class="qty-col">{{ (int) $line->expected_qty }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
