<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice # {{ $invoice_number }} - Save Rack</title>
    <link rel="icon" href="{{ asset('brand/favicon.svg') }}" type="image/svg+xml">
    <link rel="shortcut icon" href="{{ asset('brand/favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('logo.jpg') }}">
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 14px; color: #2f2f2f; margin: 0; background: #f6f7fb; }
        .page { max-width: 1040px; margin: 0 auto; padding: 24px 20px 40px; }
        .public-toolbar { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; margin-bottom: 10px; align-items: center; }
        .public-toolbar a { display: inline-block; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; }
        .public-toolbar a.primary { background: #2573ba; color: #fff; }
        .public-toolbar a.secondary { background: #fff; color: #2f2b3d; border: 1px solid rgba(47, 43, 61, 0.12); }
        .public-toolbar a.success { background: #28c76f; color: #fff; }
        .public-mobile-actions, .mobile-invoice-details { display: none; }
        .public-icon { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .public-icon svg { width: 1em; height: 1em; display: block; }
        .public-status-chip { background: #eef2ff; color: #2f2b3d; border: 1px solid rgba(47, 43, 61, 0.12); padding: 5px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .public-status-chip.status-paid { background: #e8f7ef; color: #0f7a43; border-color: #b9e7cd; }
        .public-status-chip.status-past-due { background: #ffe9ea; color: #b4232d; border-color: #f4b8bc; }
        .public-status-chip.status-void { background: #eceef1; color: #4b5563; border-color: #d3d9e1; }
        .public-status-chip.status-open { background: #eaf2ff; color: #1e58b7; border-color: #c7dafd; }
        .public-status-chip.status-draft { background: #f1f3f5; color: #495057; border-color: #d8dee3; }
        .public-status-chip.status-collection { background: #fff4e6; color: #b35a00; border-color: #f7d7ae; }
        .public-toolbar a.is-disabled,
        .public-mobile-actions a.is-disabled { pointer-events: none; opacity: .55; cursor: not-allowed; }
        .invoice-card { background: #fff; border: 1px solid rgba(47, 43, 61, 0.08); border-radius: 14px; padding: 26px 24px 30px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
        .invoice-head { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; margin-bottom: 22px; }
        .invoice-title { font-size: 34px; font-weight: 700; color: #1f2430; margin: 0 0 4px; }
        .meta-line { margin: 2px 0; color: #555; }
        .bill-to { margin-top: 28px; }
        .bill-to-title, .balance-label { font-size: 22px; font-weight: 700; margin: 0 0 6px; color: #1f2430; }
        .bill-to-body { color: #555; line-height: 1.5; }
        .invoice-right { text-align: right; min-width: 240px; }
        .invoice-logo { width: 150px; max-width: 100%; object-fit: contain; margin-bottom: 20px; }
        .balance-due { color: #ea5455; font-size: 28px; font-weight: 700; margin: 0; }
        .invoice-table { width: 100%; border-collapse: collapse; margin-top: 18px; border: 1px solid #c5d9eb; border-radius: 0; overflow: hidden; }
        .invoice-table thead th {
            background: #2573ba;
            color: #fff;
            padding: 10px 12px;
            font-size: 13px;
            font-weight: 700;
            text-transform: none;
            letter-spacing: normal;
            border-bottom: none;
        }
        .invoice-table thead th.num { text-align: center; }
        .public-inv-sec { border-bottom: 1px solid #d8dee6; }
        .public-inv-sec:last-child { border-bottom: none; }
        details.public-inv-sec--expandable > summary.public-inv-summary { list-style: none; cursor: pointer; }
        details.public-inv-sec--expandable > summary.public-inv-summary::-webkit-details-marker { display: none; }
        .public-inv-row {
            display: grid;
            grid-template-columns: 31px 54% 12% 14% 18%;
            align-items: center;
            gap: 0;
            padding: 10px 12px;
        }
        tbody > tr:nth-child(odd) .public-inv-row { background: #eceff2; }
        tbody > tr:nth-child(even) .public-inv-row { background: #fff; }
        .public-inv-row-num {
            text-align: center;
            justify-self: stretch;
            width: 100%;
        }
        .public-inv-chev { display: inline-block; color: #4a5568; font-size: 11px; transition: transform 0.15s ease; }
        details.public-inv-sec--expandable[open] .public-inv-chev { transform: rotate(90deg); }
        .public-inv-breakdown { background: #f7f8fa; border-top: 1px solid #d8dee6; }
        .public-inv-breakdown table { width: 100%; border-collapse: collapse; }
        .public-inv-breakdown tbody tr:nth-child(odd) td { background: #eef1f4; }
        .public-inv-breakdown tbody tr:nth-child(even) td { background: #f7f8fa; }
        .public-inv-breakdown td { padding: 9px 12px; border-bottom: 1px solid #dde3ea; }
        .public-inv-breakdown tr:last-child td { border-bottom: none; }
        .public-inv-breakdown .num { text-align: center; white-space: nowrap; }
        .invoice-summary { margin-top: 18px; margin-left: auto; width: 260px; text-align: right; line-height: 1.85; }
        .invoice-summary .danger { color: #ea5455; }
        .invoice-summary .success { color: #28c76f; }
        .detail-note { margin-top: 16px; color: #555; line-height: 1.55; }
        .footer-note { margin-top: 28px; color: #555; line-height: 1.7; text-align: center; max-width: 820px; margin-left: auto; margin-right: auto; }
        .pay-feedback { margin: 0 0 12px; font-size: 13px; text-align: right; }
        .pay-feedback.success { color: #28c76f; }
        .pay-feedback.error { color: #ea5455; }
        @media print {
            .public-toolbar { display: none; }
            .public-mobile-actions, .mobile-invoice-details { display: none !important; }
            body { background: #fff; }
            .page { padding: 0; max-width: none; }
            .invoice-card { box-shadow: none; border: none; padding: 0; }
            details.public-inv-sec--expandable { break-inside: avoid; }
            details.public-inv-sec--expandable[open] .public-inv-chev,
            details.public-inv-sec--expandable .public-inv-chev { transform: rotate(90deg); }
        }
        @media (max-width: 720px) {
            body { background: #fff; font-size: 13px; color: #202938; }
            .page { max-width: none; padding: 12px; }
            .public-toolbar { display: none; }
            .invoice-card { border: none; border-radius: 0; box-shadow: none; padding: 0; }
            .invoice-head { display: block; margin-bottom: 18px; }
            .invoice-title { font-size: 26px; line-height: 1.15; margin-bottom: 14px; }
            .meta-line { display: flex; align-items: center; gap: 10px; margin: 9px 0; color: #596579; font-size: 13px; }
            .meta-line .public-icon { width: 18px; height: 18px; color: #2573ba; font-size: 18px; }
            .meta-line-label { min-width: 86px; color: #596579; }
            .meta-line-value { color: #475166; font-weight: 600; }
            .bill-to { margin-top: 22px; border-top: 1px solid #eef1f5; padding-top: 14px; }
            .bill-to-title { color: #596579; font-size: 12px; text-transform: uppercase; letter-spacing: .02em; margin-bottom: 8px; }
            .bill-to-company { display: block; color: #202938; font-size: 15px; text-transform: none; letter-spacing: 0; margin-top: 2px; }
            .bill-to-body { color: #394456; font-size: 12px; line-height: 1.45; }
            .invoice-right { text-align: left; min-width: 0; margin-top: 16px; }
            .invoice-logo { width: 86px; margin-bottom: 12px; }
            .balance-label { font-size: 12px; color: #596579; margin-bottom: 4px; }
            .balance-due { font-size: 24px; line-height: 1.1; }
            .invoice-right .balance-box { border: 1px solid #d6e4ff; background: linear-gradient(180deg, #f9fbff 0%, #f4f8ff 100%); border-radius: 6px; padding: 12px 14px; }
            .public-mobile-actions { display: grid; gap: 10px; margin: 14px 0 18px; }
            .public-mobile-actions a { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 12px 14px; border-radius: 6px; color: #fff; text-decoration: none; font-weight: 700; }
            .public-mobile-actions a.success { background: #22c76f; }
            .public-mobile-actions a.primary { background: #2573ba; }
            .public-mobile-actions .public-icon { font-size: 16px; }
            .invoice-table { display: none; }
            .mobile-invoice-details { display: block; margin-top: 8px; }
            .mobile-invoice-details h2 { font-size: 16px; color: #202938; margin: 0 0 10px; }
            .mobile-inv-card { display: grid; grid-template-columns: 42px minmax(0, 1fr) auto; align-items: center; gap: 12px; border: 1px solid #edf0f4; border-radius: 7px; background: #fff; box-shadow: 0 1px 3px rgba(16, 24, 40, .06); padding: 12px; margin-bottom: 10px; }
            .mobile-inv-card--sub { margin: 8px 10px 0; border-radius: 7px; align-items: start; }
            .mobile-inv-card-spacer { width: 42px; min-height: 1px; }
            .mobile-inv-card-icon { display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; color: #2573ba; background: #eef6ff; font-size: 20px; }
            .mobile-inv-card-icon--postage { color: #22a06b; background: #eafaf2; }
            .mobile-inv-card-icon--packaging { color: #7c4dff; background: #f2edff; }
            .mobile-inv-card-icon--credits { color: #dc3545; background: #fff1f2; }
            .mobile-inv-card-icon--cc_fee { color: #ea8a00; background: #fff7e6; }
            .mobile-inv-card-title { font-weight: 800; color: #202938; margin-bottom: 2px; }
            .mobile-inv-card-title .public-inv-chev { margin-right: 6px; display: inline-block; vertical-align: middle; }
            .mobile-inv-card-meta { color: #596579; font-size: 12px; line-height: 1.35; }
            .mobile-inv-card-total { font-weight: 800; font-size: 15px; color: #202938; white-space: nowrap; }
            details.mobile-inv-detail { border: 1px solid #edf0f4; border-radius: 7px; background: #fff; box-shadow: 0 1px 3px rgba(16, 24, 40, .06); margin-bottom: 10px; overflow: hidden; }
            details.mobile-inv-detail > summary.mobile-inv-detail__summary { list-style: none; cursor: pointer; }
            details.mobile-inv-detail > summary.mobile-inv-detail__summary::-webkit-details-marker { display: none; }
            details.mobile-inv-detail[open] > summary .mobile-inv-chev { transform: rotate(90deg); }
            .mobile-inv-detail__body { border-top: 1px solid #edf0f4; background: #fafbfc; padding: 0 0 8px; }
            details.mobile-inv-subdetail { border: none; border-radius: 0; background: transparent; overflow: visible; margin: 0; }
            details.mobile-inv-subdetail > summary.mobile-inv-subdetail__summary { list-style: none; cursor: pointer; padding: 0; }
            details.mobile-inv-subdetail > summary.mobile-inv-subdetail__summary::-webkit-details-marker { display: none; }
            details.mobile-inv-subdetail[open] > summary .mobile-inv-chev { transform: rotate(90deg); }
            .mobile-inv-subdetail__table { width: calc(100% - 20px); margin: 0 10px 8px; border-collapse: collapse; font-size: 12px; border: 1px solid #e8ecf1; border-radius: 6px; overflow: hidden; }
            .mobile-inv-subdetail__table td { padding: 8px 10px; border-top: 1px solid #eef1f5; color: #394456; }
            .mobile-inv-subdetail__table tr:first-child td { border-top: none; }
            .mobile-inv-subdetail__table td.num { text-align: center; white-space: nowrap; }
            .mobile-inv-flat-service { margin: 8px 10px 0; }
            .mobile-inv-flat-service strong { color: #202938; }
            .mobile-inv-detail .mobile-inv-card { margin-bottom: 0; border: none; border-radius: 0; box-shadow: none; }
            .detail-note { display: none; }
            .invoice-summary { width: 100%; margin-top: 18px; padding: 13px 14px; border: 1px solid #d7dde8; border-radius: 6px; background: linear-gradient(180deg, #f5f7fa 0%, #e8edf3 100%); text-align: right; line-height: 1.65; }
            .invoice-summary strong:first-child { color: #596579; }
            .invoice-summary .danger { font-size: 18px; font-weight: 800; }
            .footer-note { margin-top: 22px; padding: 0 12px 12px; color: #6b7280; font-size: 13px; line-height: 1.55; }
        }
    </style>
</head>
<body>
<div class="page">
    @php
        $statusText = trim((string) ($status_label ?? 'Draft'));
        $statusSlug = strtolower(str_replace('_', '-', $statusText));
        $statusClass = 'status-'.$statusSlug;
        $statusKeyRaw = strtolower(trim((string) ($status_key ?? $status ?? $statusText)));
        $isPayDisabled = in_array($statusKeyRaw, ['paid', 'processing'], true);
        $payHref = $isPayDisabled ? '#' : ($public_pay_path ?? '#');
    @endphp
    <div class="public-toolbar">
        <span class="public-status-chip {{ $statusClass }}">{{ $statusText }}</span>
        <a class="success {{ $isPayDisabled ? 'is-disabled' : '' }}" href="{{ $payHref }}" aria-disabled="{{ $isPayDisabled ? 'true' : 'false' }}">Pay Now</a>
        <a class="primary" href="{{ $public_pdf_path ?? '#' }}">Download PDF</a>
    </div>
    @php
        $paymentState = request()->query('payment');
    @endphp
    @if($paymentState === 'success')
        <div class="pay-feedback success">Payment submitted successfully. Please allow a short moment for invoice status updates.</div>
    @elseif($paymentState === 'cancel')
        <div class="pay-feedback error">Payment was canceled.</div>
    @elseif($paymentState === 'error')
        <div class="pay-feedback error">Invoice is already paid!</div>
    @endif
    @php
        $iconCalendar = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></span>';
        $iconClock = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>';
        $iconLock = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg></span>';
        $iconDoc = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></svg></span>';
        $iconGift = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="18" height="13" rx="2"/><path d="M12 8v13M3 12h18M7.5 8A2.5 2.5 0 1112 6.5V8M16.5 8A2.5 2.5 0 1012 6.5V8"/></svg></span>';
        $iconTruck = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h11v10H3zM14 11h4l3 3v3h-7z"/><circle cx="7" cy="18" r="2"/><circle cx="18" cy="18" r="2"/></svg></span>';
        $iconBox = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8l-9-5-9 5 9 5 9-5z"/><path d="M3 8v8l9 5 9-5V8M12 13v8"/></svg></span>';
        $iconCard = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span>';
        $iconCredit = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M8 12h8"/></svg></span>';
        $iconOther = '<span class="public-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/></svg></span>';
        $mobileIconFor = static function ($type) use ($iconGift, $iconTruck, $iconBox, $iconCard, $iconCredit, $iconOther) {
            $key = strtolower(trim((string) $type));
            if (strpos($key, 'postage') !== false) return ['icon' => $iconTruck, 'class' => 'postage'];
            if (strpos($key, 'packaging') !== false) return ['icon' => $iconBox, 'class' => 'packaging'];
            if (strpos($key, 'credit card') !== false) return ['icon' => $iconCard, 'class' => 'cc_fee'];
            if (strpos($key, 'credit') !== false) return ['icon' => $iconCredit, 'class' => 'credits'];
            if (strpos($key, 'fulfillment') !== false) return ['icon' => $iconGift, 'class' => 'fulfillment'];
            return ['icon' => $iconOther, 'class' => 'other'];
        };
    @endphp

    <div class="invoice-card">
        <div class="invoice-head">
            <div>
                <h1 class="invoice-title">Invoice #{{ $invoice_number }}</h1>
                <div class="meta-line">{!! $iconCalendar !!} &nbsp;<span class="meta-line-label">Invoice Date &nbsp;</span><span class="meta-line-value">{{ $invoice_date_label ?? '—' }}</span></div>
                <div class="meta-line">{!! $iconClock !!} &nbsp;<span class="meta-line-label">Invoice Due &nbsp;</span><span class="meta-line-value">{{ $due_long ?? '—' }}</span></div>

                <div class="bill-to">
                    <h2 class="bill-to-title">BILL TO <span class="bill-to-company">{{ $client_company_name ?: '—' }}</span></h2>
                    @if (!empty($account_address))
                        <div class="bill-to-body">
                            @if (!empty($account_address['line1'])){{ $account_address['line1'] }}<br>@endif
                            @if (!empty($account_address['line2'])){{ $account_address['line2'] }}<br>@endif
                            {{ trim(implode(' ', array_filter([$account_address['city'] ?? '', !empty($account_address['state']) ? ',' : '', $account_address['state'] ?? '', $account_address['zip'] ?? '']))) }}
                            @if (!empty($account_address['country']))
                                , {{ $account_address['country'] }}
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <div class="invoice-right">
                <img src="{{ asset('logo.jpg') }}?v=20260402a" alt="Save Rack" class="invoice-logo" />
                <div class="balance-box">
                    <div class="balance-label">Amount Due</div>
                    <p class="balance-due">{{ $balance_due }}</p>
                </div>
            </div>
        </div>

        <div class="public-mobile-actions">
            <a class="success {{ $isPayDisabled ? 'is-disabled' : '' }}" href="{{ $payHref }}" aria-disabled="{{ $isPayDisabled ? 'true' : 'false' }}">{!! $iconLock !!} Pay Now</a>
            <a class="primary" href="{{ $public_pdf_path ?? '#' }}">{!! $iconDoc !!} Download PDF</a>
        </div>

        <table class="invoice-table">
            <thead>
            <tr>
                <th style="width:34px"></th>
                <th style="width:56%">Service</th>
                <th class="num" style="width:12%">QTY</th>
                <th class="num" style="width:14%">Price</th>
                <th class="num" style="width:18%">Total</th>
            </tr>
            </thead>
            <tbody>
            @forelse (($line_sections ?? []) as $sec)
                @php
                    $isStorageSection = strcasecmp((string) ($sec['label'] ?? ''), 'Storage') === 0;
                    $secQtyDisplay = (string) ($sec['qty_display'] ?? '0');
                    if ($isStorageSection) {
                        $secQtyDisplay .= ' Locations';
                    }
                @endphp
                <tr>
                    <td colspan="5" style="padding:0;">
                        @if (!empty($sec['is_expandable']))
                            <details class="public-inv-sec public-inv-sec--expandable">
                                <summary class="public-inv-summary">
                                    <div class="public-inv-row">
                                        <div class="public-inv-row-num"><span class="public-inv-chev" aria-hidden="true">&#9654;</span></div>
                                        <div><strong>{{ $sec['label'] }}</strong></div>
                                        <div class="public-inv-row-num">{{ $secQtyDisplay }}</div>
                                        <div class="public-inv-row-num">{{ $sec['unit'] }}</div>
                                        <div class="public-inv-row-num"><strong>{{ $sec['line_total'] }}</strong></div>
                                    </div>
                                </summary>

                                @if (!empty($sec['services']))
                                    <div class="public-inv-breakdown">
                                        <table>
                                            <tbody>
                                            @foreach ($sec['services'] as $service)
                                                <tr>
                                                    <td style="width:34px;"></td>
                                                    <td colspan="4" style="padding:0;">
                                                        @if (!empty($service['is_expandable']))
                                                            <details class="public-inv-sec public-inv-sec--expandable">
                                                                <summary class="public-inv-summary">
                                                                    <div class="public-inv-row">
                                                                        <div class="public-inv-row-num"><span class="public-inv-chev" aria-hidden="true">&#9654;</span></div>
                                                                        <div><strong>{{ $service['label'] }}</strong></div>
                                                                        <div class="public-inv-row-num">{{ $isStorageSection ? ($service['qty_display'].' Locations') : $service['qty_display'] }}</div>
                                                                        <div class="public-inv-row-num">{{ $service['unit'] }}</div>
                                                                        <div class="public-inv-row-num"><strong>{{ $service['line_total'] }}</strong></div>
                                                                    </div>
                                                                </summary>
                                                                <div class="public-inv-breakdown">
                                                                    <table>
                                                                        <tbody>
                                                                        @foreach (($service['orders'] ?? []) as $order)
                                                                            <tr>
                                                                                <td style="width:34px;"></td>
                                                                                <td style="width:56%; padding-left: 18px;">{{ $order['label'] }}</td>
                                                                                <td class="num" style="width:12%;">{{ $order['qty_display'] }}</td>
                                                                                <td class="num" style="width:14%;">{{ $order['unit'] }}</td>
                                                                                <td class="num" style="width:18%;">{{ $order['line_total'] }}</td>
                                                                            </tr>
                                                                        @endforeach
                                                                        </tbody>
                                                                    </table>
                                                                </div>
                                                            </details>
                                                        @else
                                                            <div class="public-inv-row">
                                                                <div class="public-inv-row-num"></div>
                                                                <div><strong>{{ $service['label'] }}</strong></div>
                                                                <div class="public-inv-row-num">{{ $isStorageSection ? ($service['qty_display'].' Locations') : $service['qty_display'] }}</div>
                                                                <div class="public-inv-row-num">{{ $service['unit'] }}</div>
                                                                <div class="public-inv-row-num"><strong>{{ $service['line_total'] }}</strong></div>
                                                            </div>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </details>
                        @else
                            <div class="public-inv-sec public-inv-sec--flat">
                                <div class="public-inv-row">
                                    <div class="public-inv-row-num"></div>
                                    <div><strong>{{ $sec['label'] }}</strong></div>
                                    <div class="public-inv-row-num">{{ $secQtyDisplay }}</div>
                                    <div class="public-inv-row-num">{{ $sec['unit'] }}</div>
                                    <div class="public-inv-row-num"><strong>{{ $sec['line_total'] }}</strong></div>
                                </div>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="padding:16px; color:#888;">No line items.</td>
                </tr>
            @endforelse
            </tbody>
        </table>

        <div class="mobile-invoice-details">
            <h2>Invoice Details</h2>
            @forelse (($line_sections ?? []) as $sec)
                @php
                    $mobileIcon = $mobileIconFor($sec['label'] ?? $sec['type'] ?? '');
                    $isStorageSection = strcasecmp((string) ($sec['label'] ?? ''), 'Storage') === 0;
                    $secQtyDisplay = (string) ($sec['qty_display'] ?? '0');
                    if ($isStorageSection) {
                        $secQtyDisplay .= ' Locations';
                    }
                @endphp
                @if (!empty($sec['is_expandable']))
                    <details class="mobile-inv-detail public-inv-sec--expandable">
                        <summary class="mobile-inv-detail__summary">
                            <div class="mobile-inv-card">
                                <div class="mobile-inv-card-icon mobile-inv-card-icon--{{ $mobileIcon['class'] }}">{!! $mobileIcon['icon'] !!}</div>
                                <div>
                                    <div class="mobile-inv-card-title">
                                        <span class="public-inv-chev" aria-hidden="true">&#9654;</span>
                                        {{ $sec['label'] }}
                                    </div>
                                    <div class="mobile-inv-card-meta">{{ $isStorageSection ? 'Locations' : 'Qty' }}: {{ $secQtyDisplay }}</div>
                                    <div class="mobile-inv-card-meta">Price: {{ $sec['unit'] }}</div>
                                </div>
                                <div class="mobile-inv-card-total">{{ $sec['line_total'] }}</div>
                            </div>
                        </summary>
                        <div class="mobile-inv-detail__body">
                            @foreach (($sec['services'] ?? []) as $service)
                                @if (!empty($service['is_expandable']))
                                    @php
                                        $svcQtyLine = $isStorageSection
                                            ? (($service['qty_display'] ?? '').' Locations')
                                            : ($service['qty_display'] ?? '');
                                    @endphp
                                    <details class="mobile-inv-subdetail public-inv-sec--expandable">
                                        <summary class="mobile-inv-subdetail__summary">
                                            <div class="mobile-inv-card mobile-inv-card--sub">
                                                <div class="mobile-inv-card-spacer" aria-hidden="true"></div>
                                                <div>
                                                    <div class="mobile-inv-card-title">
                                                        <span class="public-inv-chev" aria-hidden="true">&#9654;</span>
                                                        {{ $service['label'] }}
                                                    </div>
                                                    <div class="mobile-inv-card-meta">{{ $isStorageSection ? 'Locations' : 'Qty' }}: {{ $svcQtyLine }}</div>
                                                    <div class="mobile-inv-card-meta">Price: {{ $service['unit'] ?? '—' }}</div>
                                                </div>
                                                <div class="mobile-inv-card-total">{{ $service['line_total'] ?? '—' }}</div>
                                            </div>
                                        </summary>
                                        <table class="mobile-inv-subdetail__table">
                                            <tbody>
                                            @foreach (($service['orders'] ?? []) as $order)
                                                <tr>
                                                    <td>{{ $order['label'] }}</td>
                                                    <td class="num">{{ $order['qty_display'] ?? '' }}</td>
                                                    <td class="num">{{ $order['unit'] ?? '' }}</td>
                                                    <td class="num">{{ $order['line_total'] ?? '' }}</td>
                                                </tr>
                                            @endforeach
                                            </tbody>
                                        </table>
                                    </details>
                                @else
                                    @php
                                        $svcQtyLineFlat = $isStorageSection
                                            ? (($service['qty_display'] ?? '').' Locations')
                                            : ($service['qty_display'] ?? '');
                                    @endphp
                                    <div class="mobile-inv-flat-service">
                                        <div class="mobile-inv-card mobile-inv-card--sub">
                                            <div class="mobile-inv-card-spacer" aria-hidden="true"></div>
                                            <div>
                                                <div class="mobile-inv-card-title">{{ $service['label'] }}</div>
                                                <div class="mobile-inv-card-meta">{{ $isStorageSection ? 'Locations' : 'Qty' }}: {{ $svcQtyLineFlat }}</div>
                                                <div class="mobile-inv-card-meta">Price: {{ $service['unit'] ?? '—' }}</div>
                                            </div>
                                            <div class="mobile-inv-card-total">{{ $service['line_total'] ?? '—' }}</div>
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </details>
                @else
                    <div class="mobile-inv-card">
                        <div class="mobile-inv-card-icon mobile-inv-card-icon--{{ $mobileIcon['class'] }}">{!! $mobileIcon['icon'] !!}</div>
                        <div>
                            <div class="mobile-inv-card-title">{{ $sec['label'] }}</div>
                            <div class="mobile-inv-card-meta">{{ $isStorageSection ? 'Locations' : 'Qty' }}: {{ $secQtyDisplay }}</div>
                            <div class="mobile-inv-card-meta">Price: {{ $sec['unit'] }}</div>
                        </div>
                        <div class="mobile-inv-card-total">{{ $sec['line_total'] }}</div>
                    </div>
                @endif
            @empty
                <div class="mobile-inv-card">
                    <div class="mobile-inv-card-icon mobile-inv-card-icon--other">{!! $iconOther !!}</div>
                    <div>
                        <div class="mobile-inv-card-title">No line items</div>
                    </div>
                    <div class="mobile-inv-card-total">—</div>
                </div>
            @endforelse
        </div>

        <div class="detail-note">
            For a detailed breakdown of charges associated with each order, please log in to your account.
        </div>

        <div class="invoice-summary">
            <strong>Total :</strong> {{ $total }}<br>
            <strong>Paid :</strong> <span class="success">{{ $amount_paid }}</span><br>
            <strong>Balance Due :</strong> <span class="danger">{{ $balance_due }}</span>
        </div>

        <div class="footer-note">
            We truly appreciate your business! Please kindly submit payment at your earliest convenience. If your account is set up for autopay, no action is needed-this invoice is simply for your records. If you have any questions, feel free to reach out or email us at billing@saverack.com
        </div>
    </div>
</div>
<script>
(function () {
    function expandableSections() {
        return document.querySelectorAll('details.public-inv-sec--expandable');
    }
    window.addEventListener('beforeprint', function () {
        expandableSections().forEach(function (d) {
            d.dataset.wasOpen = d.open ? '1' : '0';
            d.setAttribute('open', 'open');
        });
    });
    window.addEventListener('afterprint', function () {
        expandableSections().forEach(function (d) {
            if (d.dataset.wasOpen !== '1') {
                d.removeAttribute('open');
            }
            delete d.dataset.wasOpen;
        });
    });
})();
</script>
</body>
</html>
