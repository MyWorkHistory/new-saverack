<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 12px; }
        .company-name { font-size: 24px; font-weight: 800; letter-spacing: 0.02em; }
        .order-number { font-size: 18px; font-weight: 700; margin-top: 4px; }
        .ship-block { margin-top: 16px; line-height: 1.45; }
        .ship-label { font-size: 10px; text-transform: uppercase; color: #666; margin-bottom: 4px; }
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
    <div class="order-number">Packing Slip — {{ $order->name }}</div>

    <div class="ship-block">
        <div class="ship-label">Ship To</div>
        <div>{{ $recipientName }}</div>
        @if (!empty($shippingAddress))
            @if (!empty($shippingAddress['address1']))<div>{{ $shippingAddress['address1'] }}</div>@endif
            @if (!empty($shippingAddress['address2']))<div>{{ $shippingAddress['address2'] }}</div>@endif
            @php
                $cityLine = trim(implode(', ', array_filter([
                    $shippingAddress['city'] ?? '',
                    $shippingAddress['provinceCode'] ?? $shippingAddress['province'] ?? '',
                    $shippingAddress['zip'] ?? '',
                ])));
            @endphp
            @if ($cityLine !== '')<div>{{ $cityLine }}</div>@endif
            @if (!empty($shippingAddress['countryCodeV2']) || !empty($shippingAddress['country']))
                <div>{{ $shippingAddress['countryCodeV2'] ?? $shippingAddress['country'] }}</div>
            @endif
        @endif
    </div>

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
            @foreach ($order->lineItems as $line)
                <tr>
                    <td class="item-col">
                        <div class="item-name">
                            {{ $line->title }}{{ $line->variant_title ? ' / '.$line->variant_title : '' }}
                        </div>
                    </td>
                    <td class="sku-col"><div class="sku">{{ $line->sku ?: '—' }}</div></td>
                    <td class="qty-col">{{ (int) $line->quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
