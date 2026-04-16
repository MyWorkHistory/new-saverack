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
        .brand-wrap { display: flex; align-items: center; gap: 10px; }
        .brand-logo { width: 36px; height: 36px; object-fit: contain; }
        .brand { font-size: 20px; font-weight: 700; color: #111; margin-bottom: 0; }
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
        .public-inv-lines-wrap { margin-top: 12px; border: 1px solid #e8e8e8; border-radius: 4px; overflow: hidden; background: #fff; }
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
            <div class="brand-wrap">
                <img src="{{ asset('assets/images/dark-logo.png') }}" alt="Save Rack" class="brand-logo" />
                <div class="brand">Save Rack</div>
            </div>
            <div class="brand-sub">Fulfillment billing</div>
        </td>
        <td width="45%">
            <div class="inv-title">Invoice {{ $invoice_number }}</div>
            <div class="inv-meta">
                <div><strong>Invoice Date:</strong> {{ $invoice_date_label ?? '—' }}</div>
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
    <table class="lines">
        <thead>
        <tr>
            <th style="width:58%">Service</th>
            <th class="num" style="width:12%">QTY</th>
            <th class="num" style="width:14%">Price</th>
            <th class="num" style="width:16%">Total</th>
        </tr>
        </thead>
        <tbody>
        @forelse (($grouped_items ?? $items) as $row)
            <tr>
                <td>{{ $row['name'] ?? $row['item'] }}</td>
                <td class="num">{{ $row['qty'] ?? $row['quantity'] }}</td>
                <td class="num">
                    @if(isset($row['price']))
                        ${{ number_format((float) $row['price'], 2) }}
                    @else
                        {{ $row['unit'] }}
                    @endif
                </td>
                <td class="num">
                    @if(isset($row['total']))
                        ${{ number_format((float) $row['total'], 2) }}
                    @else
                        {{ $row['line_total'] }}
                    @endif
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="4" class="public-inv-empty">No line items.</td>
            </tr>
        @endforelse
        </tbody>
    </table>
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

<div class="notes">
    <strong>Please send payment to:</strong><br>
    Save Rack LLC<br>
    3025 Whitten Rd<br>
    Lakeland, FL 33815
</div>

<div class="footer">Thank you for your business.</div>
</body>
</html>
