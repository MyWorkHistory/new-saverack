<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice_number }} — {{ $issuer_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #2f2f2f; margin: 28px 36px; }
        .brand { font-size: 18px; font-weight:700; color: #111; margin-bottom: 4px; }
        .brand-sub { font-size: 10px; color: #666; }
        .top { width: 100%; margin-bottom: 22px; }
        .top td { vertical-align: top; }
        .inv-title { font-size: 20px; font-weight: 700; color: #111; text-align: right; }
        .inv-meta { text-align: right; font-size: 10px; color: #555; margin-top: 6px; line-height: 1.5; }
        .inv-meta strong { color: #222; }
        .section-label { font-size: 9px; text-transform: uppercase; letter-spacing: 0.06em; color: #888; margin: 18px 0 6px; }
        .bill-to { font-size: 12px; font-weight: 600; color: #111; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.lines th {
            text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.04em;
            color: #666; border-bottom: 1px solid #ccc; padding: 8px 6px;
        }
        table.lines th.num { text-align: right; }
        table.lines td { border-bottom: 1px solid #e8e8e8; padding: 8px 6px; vertical-align: top; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        .totals { width: 240px; margin-left: auto; margin-top: 16px; font-size: 11px; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 4px 0; }
        .totals td.lbl { color: #666; }
        .totals td.amt { text-align: right; font-weight: 600; }
        .totals tr.due td { padding-top: 10px; font-size: 13px; border-top: 1px solid #ccc; }
        .notes { margin-top: 22px; font-size: 10px; color: #555; line-height: 1.45; }
        .footer { margin-top: 28px; font-size: 9px; color: #999; text-align: center; }
    </style>
</head>
<body>
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
        <th style="width:46%">Description</th>
        <th class="num" style="width:12%">Qty</th>
        <th class="num" style="width:18%">Unit price</th>
        <th class="num" style="width:18%">Amount</th>
    </tr>
    </thead>
    <tbody>
    @forelse ($items as $row)
        <tr>
            <td>{{ $row['description'] }}</td>
            <td class="num">{{ $row['quantity'] }}</td>
            <td class="num">{{ $row['unit'] }}</td>
            <td class="num">{{ $row['line_total'] }}</td>
        </tr>
    @empty
        <tr>
            <td colspan="4" style="color:#888;">No line items.</td>
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
