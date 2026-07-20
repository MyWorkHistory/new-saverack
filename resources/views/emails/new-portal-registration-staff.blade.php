<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New 3PL signup — Save Rack</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#151515;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
    <tr>
        <td align="center" style="padding:32px 18px;">
            <table role="presentation" width="100%" style="max-width:520px;margin:0 auto;">
                <tr>
                    <td align="center" style="padding-bottom:24px;">
                        <img src="{{ $logoUrl }}" alt="Save Rack" width="92" style="display:block;width:92px;height:auto;">
                    </td>
                </tr>
                <tr>
                    <td style="font-size:20px;font-weight:700;padding-bottom:16px;">
                        New 3PL account signup
                    </td>
                </tr>
                <tr>
                    <td style="font-size:15px;line-height:1.6;color:#374151;padding-bottom:12px;">
                        <strong>Company:</strong> {{ $account->company_name }}<br>
                        <strong>Contact:</strong> {{ $user->name }}<br>
                        <strong>Email:</strong> {{ $user->email }}<br>
                        <strong>Phone:</strong> {{ $account->phone ?: '—' }}<br>
                        <strong>Status:</strong> {{ $account->status }}<br>
                        <strong>ShipHero customer ID:</strong>
                        {{ $account->shiphero_customer_account_id ? $account->shiphero_customer_account_id : 'Not set' }}
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 0;">
                        <a href="{{ $staffAccountUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;padding:14px 32px;border-radius:999px;font-weight:700;font-size:16px;">
                            Open in CRM
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
