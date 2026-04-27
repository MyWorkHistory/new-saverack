<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice # {{ $invoice->invoice_number }} - Save Rack</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#151515;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#ffffff;">
    <tr>
        <td align="center" style="padding:38px 18px 28px 18px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:440px;margin:0 auto;">
    <tr>
        <td align="center" style="padding-bottom:28px;">
            <img src="{{ $logoUrl }}" alt="Save Rack" width="92" style="display:block;width:92px;height:auto;max-width:92px;">
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 20px 8px;font-size:23px;line-height:1.25;font-weight:400;color:#333333;">
            Hi {{ $greetingName }},
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 26px 8px;font-size:23px;line-height:1.28;font-weight:700;color:#0f0f0f;">
            You have a new invoice for {{ $invoiceAmountFormatted }}
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 28px 8px;font-size:24px;line-height:1.2;color:#2b2b2b;">
            <span style="font-weight:400;">Due Date</span><br>
            <strong style="font-size:22px;line-height:1.25;color:#111111;">{{ $dueDateFormatted }}</strong>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 48px 8px;font-size:24px;line-height:1.25;color:#333333;">
            Invoice # {{ $invoice->invoice_number }}
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 28px 8px;">
            @if (!empty($customerViewUrl))
                <a href="{{ $customerViewUrl }}" style="display:inline-block;background:#2f7fc8;color:#ffffff !important;text-decoration:none;padding:24px 54px;border-radius:999px;font-weight:700;font-size:28px;line-height:1;">
                    View Invoice
                </a>
            @endif
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:0 8px 28px 8px;font-size:23px;line-height:1.18;color:#303030;">
            <span style="font-weight:400;">Account</span><br>
            <strong style="font-size:20px;color:#111111;">{{ $accountName }}</strong>
        </td>
    </tr>
    @if (!empty($customMessage))
        <tr>
            <td align="center" style="padding:0 8px 24px 8px;font-size:15px;line-height:1.55;color:#333333;">
                {{ $customMessage }}
            </td>
        </tr>
    @endif
    <tr>
        <td align="center" style="padding:8px 8px 0 8px;font-size:16px;line-height:1.28;color:#333333;">
            We truly appreciate your business!<br>
            Please kindly submit payment at your earliest convenience.<br>
            If your account is set up for autopay, no action is needed.<br>
            this invoice is simply for your records.<br>
            If you have any questions, feel free to reach out or email us at
            <a href="mailto:billing@saverack.com" style="color:#333333;text-decoration:none;">billing@saverack.com</a>
        </td>
    </tr>
    <tr>
        <td align="center" style="padding:50px 8px 0 8px;font-size:12px;line-height:1.5;color:#666666;">
            Save Rack LLC | 3135 Drane Field Rd# 21 Lakeland, FL 33811
        </td>
    </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
