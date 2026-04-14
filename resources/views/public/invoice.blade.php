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
        table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.lines th {
            text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.04em;
            color: #666; border-bottom: 1px solid #ccc; padding: 10px 8px;
        }
        table.lines th.num { text-align: right; }
        table.lines td { border-bottom: 1px solid #e8e8e8; padding: 10px 8px; vertical-align: top; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        .totals { width: 280px; margin-left: auto; margin-top: 20px; font-size: 14px; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 6px 0; }
        .totals td.lbl { color: #666; }
        .totals td.amt { text-align: right; font-weight: 600; }
        .totals tr.due td { padding-top: 12px; font-size: 16px; border-top: 1px solid #ccc; }
        .notes { margin-top: 24px; font-size: 13px; color: #555; line-height: 1.45; }
        .footer { margin-top: 32px; font-size: 11px; color: #999; text-align: center; }
        @media print {
            .public-toolbar { display: none; }
            body { padding-top: 0; }
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

<table class="lines">
    <thead>
    <tr>
        <th style="width:30%">Item</th>
        <th style="width:26%">Description</th>
        <th class="num" style="width:12%">Qty</th>
        <th class="num" style="width:14%">Cost</th>
        <th class="num" style="width:14%">Price</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($items as $row)
        <tr>
            <td>{{ $row['item'] }}</td>
            <td style="color:#555;">{{ $row['description'] }}</td>
            <td class="num">{{ $row['quantity'] }}</td>
            <td class="num">{{ $row['unit'] }}</td>
            <td class="num">{{ $row['line_total'] }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="5" style="color:#888;">No line items.</td>
        </tr>
    @endforelse
    </tbody>
</table>

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
</body>
</html>
