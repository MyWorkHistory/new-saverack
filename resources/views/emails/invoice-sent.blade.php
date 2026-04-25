<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_number }}</title>
</head>
<body style="margin:0;padding:32px 16px;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1a1a1a;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;margin:0 auto;">
    <tr>
        <td align="center" style="padding-bottom:28px;">
            <img src="{{ $logoUrl }}" alt="Save Rack" width="120" height="40" style="display:block;height:40px;width:auto;max-width:120px;">
        </td>
    </tr>
    <tr>
        <td style="padding:0 8px 8px 8px;font-size:16px;line-height:1.5;">
            Hi {{ $greetingName }},
        </td>
    </tr>
    <tr>
        <td style="padding:0 8px 20px 8px;font-size:22px;line-height:1.35;font-weight:700;">
            You have a new invoice for {{ $invoiceAmountFormatted }}
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 8px 8px;font-size:15px;color:#333;">
            <span style="color:#666;">Due Date</span><br>
            <strong style="font-size:17px;">{{ $dueDateFormatted }}</strong>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 28px 8px;font-size:15px;color:#333;">
            Invoice # {{ $invoice->invoice_number }}
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 28px 8px;">
            @if (!empty($customerViewUrl))
                <a href="{{ $customerViewUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;padding:14px 36px;border-radius:999px;font-weight:600;font-size:16px;">
                    View Invoice
                </a>
            @endif
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 8px 8px;font-size:15px;color:#333;">
            <span style="color:#666;">Account</span><br>
            <strong>{{ $accountName }}</strong>
        </td>
    </tr>
    @if (!empty($customMessage))
        <tr>
            <td style="padding:16px 8px 0 8px;font-size:15px;line-height:1.55;color:#333;">
                {{ $customMessage }}
            </td>
        </tr>
    @endif
    <tr>
        <td align="center" style="padding:32px 12px 0 12px;font-size:13px;line-height:1.65;color:#555;">
            We truly appreciate your business!<br>
            Please kindly submit payment at your earliest convenience.<br>
            If your account is set up for autopay, no action is needed—<br>
            this invoice is simply for your records.<br>
            If you have any questions, feel free to reach out or email us at
            <a href="mailto:billing@saverack.com" style="color:#2563eb;">billing@saverack.com</a>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:28px 12px 0 12px;font-size:11px;line-height:1.5;color:#888;">
            Save Rack LLC | 3135 Drane Field Rd# 21 Lakeland, FL 33811
        </td>
    </tr>
</table>
</body>
</html>
