<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice_number }} — {{ $issuer_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2f2f2f; margin: 26px 30px; }
        .invoice-head { width: 100%; margin-bottom: 18px; }
        .invoice-head td { vertical-align: top; }
        .invoice-title { font-size: 24px; font-weight: 700; color: #1f2430; margin: 0 0 6px; }
        .meta-line { margin: 2px 0; color: #555; }
        .bill-to { margin-top: 22px; }
        .bill-to-title, .balance-label { font-size: 16px; font-weight: 700; color: #1f2430; margin: 0 0 5px; }
        .invoice-logo { width: 120px; margin-bottom: 14px; }
        .invoice-right { text-align: right; }
        .balance-due { color: #ea5455; font-size: 22px; font-weight: 700; margin: 0; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.lines th { background: #2573ba; color: #fff; padding: 8px 10px; font-size: 10px; text-transform: uppercase; text-align: left; }
        table.lines th.num, table.lines td.num { text-align: center; }
        table.lines td { padding: 8px 10px; border-bottom: 1px solid #e8e7ed; vertical-align: top; }
        tr.group-row td { font-weight: 700; }
        tr.detail-row td { background: #f6f7fb; font-size: 10px; }
        .detail-service { padding-left: 22px !important; }
        .invoice-summary { width: 240px; margin-left: auto; margin-top: 16px; text-align: right; line-height: 1.8; }
        .invoice-summary .success { color: #28c76f; }
        .invoice-summary .danger { color: #ea5455; }
        .notes { margin-top: 22px; font-size: 10px; color: #555; line-height: 1.5; }
    </style>
</head>
<body>
@php
    $pdfLogoSrc = '';
    $logoPath = public_path('logo.jpg');
    if (is_string($logoPath) && file_exists($logoPath) && is_readable($logoPath)) {
        $raw = file_get_contents($logoPath);
        if ($raw !== false) {
            $pdfLogoSrc = 'data:image/jpeg;base64,'.base64_encode($raw);
        }
    }
    if ($pdfLogoSrc === '') {
        $pdfLogoSrc = url('/logo.jpg').'?v=20260402a';
    }
@endphp
<table class="invoice-head">
    <tr>
        <td width="55%">
            <h1 class="invoice-title">Invoice #{{ $invoice_number }}</h1>
            <div class="meta-line">Invoice Date : {{ $invoice_date_label ?? '—' }}</div>
            <div class="meta-line">Invoice Due : {{ $due_long ?? '—' }}</div>

            <div class="bill-to">
                <div class="bill-to-title">BILL TO : {{ $client_company_name ?: '—' }}</div>
                @if (!empty($account_address))
                    <div>
                        @if (!empty($account_address['line1'])){{ $account_address['line1'] }}<br>@endif
                        @if (!empty($account_address['line2'])){{ $account_address['line2'] }}<br>@endif
                        {{ trim(implode(' ', array_filter([$account_address['city'] ?? '', !empty($account_address['state']) ? ',' : '', $account_address['state'] ?? '', $account_address['zip'] ?? '']))) }}
                        @if (!empty($account_address['country']))
                            , {{ $account_address['country'] }}
                        @endif
                    </div>
                @endif
            </div>
        </td>
        <td width="45%" class="invoice-right">
            <img src="{{ $pdfLogoSrc }}" alt="Save Rack" class="invoice-logo" />
            <div class="balance-label">Balance Due</div>
            <div class="balance-due">{{ $balance_due }}</div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
    <tr>
        <th style="width:58%">Service</th>
        <th class="num" style="width:12%">Qty</th>
        <th class="num" style="width:14%">Price</th>
        <th class="num" style="width:16%">Total</th>
    </tr>
    </thead>
    <tbody>
    @forelse (($public_sections ?? []) as $row)
        <tr class="group-row">
            <td>{{ $row['label'] }}</td>
            <td class="num">{{ $row['qty_display'] }}</td>
            <td class="num">{{ $row['unit'] }}</td>
            <td class="num">{{ $row['line_total'] }}</td>
        </tr>
        @foreach (($row['lines'] ?? []) as $line)
            <tr class="detail-row">
                <td class="detail-service">{{ $line['name'] }}</td>
                <td class="num">{{ $line['qty_display'] }}</td>
                <td class="num">{{ $line['unit'] }}</td>
                <td class="num">{{ $line['line_total'] }}</td>
            </tr>
        @endforeach
    @empty
        <tr>
            <td colspan="4" style="color:#888;">No line items.</td>
        </tr>
    @endforelse
    </tbody>
</table>

<div class="invoice-summary">
    <strong>Total :</strong> {{ $total }}<br>
    <strong>Paid :</strong> <span class="success">{{ $amount_paid }}</span><br>
    <strong>Balance Due :</strong> <span class="danger">{{ $balance_due }}</span>
</div>

@if (!empty($customer_notes))
    <div class="notes">
        <strong>Note:</strong> {{ $customer_notes }}
    </div>
@endif

<div class="notes" style="font-size: 11px;">
    <strong>Please send payment to:</strong><br>
    Save Rack LLC<br>
    3025 Whitten Rd<br>
    Lakeland, FL 33815<br><br>
    Routing #: 063107513<br>
    Account #: 1157249176<br>
    Wire #: 121000248
</div>
</body>
</html>
