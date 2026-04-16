<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice_number }} — {{ $issuer_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; font-size: 14px; color: #2f2f2f; margin: 0; padding: 24px 20px 40px; max-width: 920px; margin-left: auto; margin-right: auto; }
        .public-toolbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; justify-content: flex-end; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e5e5e5; }
        .public-toolbar a { display: inline-block; padding: 8px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; }
        .public-toolbar a.primary { background: #111; color: #fff; }
        .public-toolbar a.secondary { background: #f3f3f3; color: #222; }
        .brand { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 4px; }
        .brand-sub { font-size: 12px; color: #666; }
        .top { width: 100%; margin-bottom: 22px; border-collapse: collapse; }
        .top td { vertical-align: top; }
        .inv-title { font-size: 22px; font-weight: 700; color: #111; text-align: right; }
        .inv-meta { text-align: right; font-size: 12px; color: #555; margin-top: 8px; line-height: 1.5; }
        .inv-meta strong { color: #222; }
        .section-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; color: #888; margin: 18px 0 6px; }
        .bill-to { font-size: 15px; font-weight: 600; color: #111; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 0; }
        table.lines th {
            text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;
            color: #666; border-bottom: 1px solid #ccc; padding: 10px 8px;
        }
        table.lines th.num { text-align: right; }
        table.lines td { border-bottom: 1px solid #e8e8e8; padding: 10px 8px; vertical-align: top; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        .lines-nested { background: #fff; }
        .lines-nested td { font-size: 13px; }
        details.public-inv-sec { border-bottom: 1px solid #e8e8e8; }
        details.public-inv-sec:last-of-type { border-bottom: none; }
        summary.public-inv-summary { list-style: none; cursor: pointer; padding: 0; }
        summary.public-inv-summary::-webkit-details-marker { display: none; }
        .public-inv-sum-grid {
            display: grid;
            grid-template-columns: 18px minmax(0, 1.75fr) minmax(0, 0.55fr) minmax(0, 0.65fr) minmax(0, 0.8fr);
            gap: 8px;
            align-items: center;
            padding: 10px 8px;
        }
        .public-inv-chev {
            font-size: 10px;
            color: #666;
            transition: transform 0.15s ease;
            display: inline-block;
        }
        details.public-inv-sec[open] .public-inv-chev { transform: rotate(90deg); }
        .public-inv-nested { background: #f6f7f9; padding: 0 4px 12px 12px; border-bottom: 1px solid #e8e8e8; }
        .public-inv-lines-wrap { margin-top: 12px; border: 1px solid #e8e8e8; border-radius: 4px; overflow: hidden; }
        .totals { width: 280px; margin-left: auto; margin-top: 20px; font-size: 14px; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 6px 0; }
        .totals td.lbl { color: #666; }
        .totals td.amt { text-align: right; font-weight: 600; }
        .totals tr.due td { padding-top: 12px; font-size: 16px; border-top: 1px solid #ccc; }
        .notes { margin-top: 24px; font-size: 13px; color: #555; line-height: 1.45; }
        .footer { margin-top: 32px; font-size: 11px; color: #999; text-align: center; }
        .public-inv-empty { color: #888; padding: 16px 8px; }
        @media print {
            .public-toolbar { display: none; }
            body { padding-top: 0; }
            details.public-inv-sec > summary { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="public-toolbar">
    <a class="secondary" href="javascript:window.print()">Print</a>
    <a class="primary" href="{{ $public_pdf_path ?? '#' }}">Download PDF</a>
</div>
<table class="top">
    <tr>
        <td width="55%">
            <div class="brand">{{ $issuer_name }}</div>
            <div class="brand-sub">Fulfillment billing</div>
        </td>
        <td width="45%">
            <div class="inv-title">Invoice {{ $invoice_number }}</div>
            <div class="inv-meta">
                @if (!empty($issued_long))
                    <div><strong>Issue date:</strong> {{ $issued_long }}</div>
                @endif
                @if (!empty($due_long))
                    <div><strong>Due date:</strong> {{ $due_long }}</div>
                @endif
                @if (!empty($payment_terms))
                    <div><strong>Terms:</strong> {{ $payment_terms }}</div>
                @endif
                @if (!empty($po_number))
                    <div><strong>PO:</strong> {{ $po_number }}</div>
                @endif
                <div><strong>Status:</strong> {{ ucfirst($status) }}</div>
            </div>
        </td>
    </tr>
</table>

<div class="section-label">Invoice to</div>
<div class="bill-to">{{ $client_company_name ?: '—' }}</div>

<div class="section-label" style="margin-top: 20px;">Line items</div>
<div class="public-inv-lines-wrap">
@if (!empty($line_sections))
    @foreach ($line_sections as $sec)
        <details class="public-inv-sec">
            <summary class="public-inv-summary">
                <div class="public-inv-sum-grid">
                    <span class="public-inv-chev" aria-hidden="true">&#9654;</span>
                    <span class="public-inv-sum-service"><strong>{{ $sec['label'] }}</strong></span>
                    <span class="num">{{ $sec['qty_display'] }}</span>
                    <span class="num">{{ $sec['unit'] }}</span>
                    <span class="num" style="font-weight:600;">{{ $sec['line_total'] }}</span>
                </div>
            </summary>
            <div class="public-inv-nested">
                <table class="lines lines-nested">
                    <thead>
                    <tr>
                        <th style="width:56%">Service</th>
                        <th class="num" style="width:12%">Qty</th>
                        <th class="num" style="width:14%">Price</th>
                        <th class="num" style="width:14%">Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($sec['lines'] as $row)
                        <tr>
                            <td>{{ $row['item'] }}</td>
                            <td class="num">{{ $row['quantity'] }}</td>
                            <td class="num">{{ $row['unit'] }}</td>
                            <td class="num">{{ $row['line_total'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    @endforeach
@else
    <p class="public-inv-empty">No line items.</p>
@endif
</div>

<div class="totals">
    <table>
        <tr>
            <td class="lbl">Subtotal</td>
            <td class="amt">{{ $subtotal }}</td>
        </tr>
        <tr>
            <td class="lbl">Tax</td>
            <td class="amt">{{ $tax }}</td>
        </tr>
        <tr>
            <td class="lbl">Total</td>
            <td class="amt">{{ $total }}</td>
        </tr>
        <tr>
            <td class="lbl">Amount paid</td>
            <td class="amt">{{ $amount_paid }}</td>
        </tr>
        <tr class="due">
            <td class="lbl"><strong>Balance due</strong></td>
            <td class="amt">{{ $balance_due }}</td>
        </tr>
    </table>
</div>

@if (!empty($customer_notes))
    <div class="notes">
        <strong>Note:</strong> {{ $customer_notes }}
    </div>
@endif

<div class="footer">Thank you for your business.</div>
<script>
(function () {
    window.addEventListener('beforeprint', function () {
        document.querySelectorAll('details.public-inv-sec').forEach(function (d) {
            d.setAttribute('open', 'open');
        });
    });
})();
</script>
</body>
</html>
