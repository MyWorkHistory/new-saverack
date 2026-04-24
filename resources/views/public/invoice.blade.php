<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice_number }} — {{ $issuer_name }}</title>
    <link rel="icon" href="{{ asset('brand/favicon.svg') }}" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 14px; color: #2f2f2f; margin: 0; background: #f6f7fb; }
        .page { max-width: 1040px; margin: 0 auto; padding: 24px 20px 40px; }
        .public-toolbar { display: flex; flex-wrap: wrap; gap: 10px; justify-content: flex-end; margin-bottom: 10px; }
        .public-toolbar a { display: inline-block; padding: 10px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; }
        .public-toolbar a.primary { background: #2573ba; color: #fff; }
        .public-toolbar a.secondary { background: #fff; color: #2f2b3d; border: 1px solid rgba(47, 43, 61, 0.12); }
        .public-toolbar a.success { background: #28c76f; color: #fff; }
        .public-status-row { display: flex; justify-content: center; margin-bottom: 10px; }
        .public-status-chip { background: #eef2ff; color: #2f2b3d; border: 1px solid rgba(47, 43, 61, 0.12); padding: 5px 12px; border-radius: 999px; font-size: 13px; font-weight: 600; }
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
        .public-inv-row { display: grid; grid-template-columns: 34px minmax(0, 1.6fr) minmax(72px, 0.3fr) minmax(88px, 0.4fr) minmax(88px, 0.4fr); align-items: center; gap: 10px; padding: 10px 12px; }
        tbody > tr:nth-child(odd) .public-inv-row { background: #eceff2; }
        tbody > tr:nth-child(even) .public-inv-row { background: #fff; }
        .public-inv-row-num { text-align: center; }
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
            .public-status-row { display: none; }
            body { background: #fff; }
            .page { padding: 0; max-width: none; }
            .invoice-card { box-shadow: none; border: none; padding: 0; }
            details.public-inv-sec--expandable { break-inside: avoid; }
            details.public-inv-sec--expandable[open] .public-inv-chev,
            details.public-inv-sec--expandable .public-inv-chev { transform: rotate(90deg); }
        }
        @media (max-width: 720px) {
            .invoice-head { flex-direction: column; }
            .invoice-right { text-align: left; min-width: 0; }
            .public-inv-row { grid-template-columns: 24px minmax(0, 1fr); }
            .public-inv-row-num:nth-child(n+3) { text-align: left; }
            .public-inv-breakdown td.num { text-align: left; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="public-toolbar">
        <a class="success" href="{{ $public_pay_path ?? '#' }}">Pay Now</a>
        <a class="primary" href="{{ $public_pdf_path ?? '#' }}">Download PDF</a>
    </div>
    <div class="public-status-row">
        <span class="public-status-chip">Status: {{ $status_label ?? 'Draft' }}</span>
    </div>
    @php($paymentState = request()->query('payment'))
    @if($paymentState === 'success')
        <div class="pay-feedback success">Payment submitted successfully. Please allow a short moment for invoice status updates.</div>
    @elseif($paymentState === 'cancel')
        <div class="pay-feedback error">Payment was canceled.</div>
    @elseif($paymentState === 'error')
        <div class="pay-feedback error">Could not start payment. Please try again.</div>
    @endif

    <div class="invoice-card">
        <div class="invoice-head">
            <div>
                <h1 class="invoice-title">Invoice #{{ $invoice_number }}</h1>
                <div class="meta-line">Invoice Date : {{ $invoice_date_label ?? '—' }}</div>
                <div class="meta-line">Invoice Due : {{ $due_long ?? '—' }}</div>

                <div class="bill-to">
                    <h2 class="bill-to-title">BILL TO : {{ $client_company_name ?: '—' }}</h2>
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
                <div class="balance-label">Invoice Amount</div>
                <p class="balance-due">{{ $total }}</p>
            </div>
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
                <tr>
                    <td colspan="5" style="padding:0;">
                        @if (!empty($sec['is_expandable']))
                            <details class="public-inv-sec public-inv-sec--expandable">
                                <summary class="public-inv-summary">
                                    <div class="public-inv-row">
                                        <div class="public-inv-row-num"><span class="public-inv-chev" aria-hidden="true">&#9654;</span></div>
                                        <div><strong>{{ $sec['label'] }}</strong></div>
                                        <div class="public-inv-row-num">{{ $sec['qty_display'] }}</div>
                                        <div class="public-inv-row-num">{{ $sec['unit'] }}</div>
                                        <div class="public-inv-row-num"><strong>{{ $sec['line_total'] }}</strong></div>
                                    </div>
                                </summary>

                                @if (!empty($sec['lines']))
                                    <div class="public-inv-breakdown">
                                        <table>
                                            <tbody>
                                            @foreach ($sec['lines'] as $line)
                                                <tr>
                                                    <td style="width:34px;"></td>
                                                    <td style="width:56%; padding-left: 18px;">{{ $line['name'] }}</td>
                                                    <td class="num" style="width:12%;">{{ $line['qty_display'] }}</td>
                                                    <td class="num" style="width:14%;">{{ $line['unit'] }}</td>
                                                    <td class="num" style="width:18%;">{{ $line['line_total'] }}</td>
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
                                    <div class="public-inv-row-num">{{ $sec['qty_display'] }}</div>
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
