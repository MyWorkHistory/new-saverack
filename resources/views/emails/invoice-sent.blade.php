<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0;padding:24px;background:#f6f7fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#2f2b3d;">
@php
    $sym = $invoice->currency === 'USD' ? '$' : $invoice->currency.' ';
@endphp
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e8e7ed;border-radius:10px;overflow:hidden;">
    <tr>
        <td style="padding:18px 22px;background:#2573ba;color:#fff;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                <tr>
                    <td style="vertical-align:middle;">
                        <img src="{{ asset('logo.jpg') }}?v=20260402a" alt="Save Rack" width="46" height="46" style="display:block;border-radius:4px;">
                    </td>
                    <td style="padding-left:12px;vertical-align:middle;">
                        <div style="font-size:18px;font-weight:700;">Save Rack</div>
                        <div style="font-size:12px;opacity:0.95;">Invoice Notification</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:22px;">
            <p style="margin:0 0 12px;">Hello,</p>
            @if (!empty($customMessage))
                <p style="margin:0 0 12px;">{{ $customMessage }}</p>
            @endif
            <p style="margin:0 0 14px;">
                Invoice <strong>{{ $invoice->invoice_number }}</strong>
                @if ($invoice->clientAccount)
                    for <strong>{{ $invoice->clientAccount->company_name }}</strong>
                @endif
                is ready.
            </p>
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 0 14px;border:1px solid #ecebf1;border-radius:8px;background:#fbfbfd;">
                <tr>
                    <td style="padding:12px 14px;font-size:14px;">
                        <div style="margin-bottom:6px;"><strong>Total:</strong> {{ $sym }}{{ number_format(((int) $invoice->total_cents) / 100, 2) }}</div>
                        <div style="margin-bottom:6px;"><strong>Balance Due:</strong> {{ $sym }}{{ number_format(((int) $invoice->balance_due_cents) / 100, 2) }}</div>
                        @if ($invoice->due_at)
                            <div><strong>Due:</strong> {{ $invoice->due_at->format('m/d/Y') }}</div>
                        @endif
                    </td>
                </tr>
            </table>
            @if (!empty($customerViewUrl))
                <p style="margin:0;">
                    <a href="{{ $customerViewUrl }}" style="display:inline-block;background:#2573ba;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-weight:600;">
                        View Invoice
                    </a>
                </p>
            @endif
        </td>
    </tr>
</table>
</body>
</html>
