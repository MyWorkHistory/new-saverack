<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your password — Save Rack</title>
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
                    <td align="center" style="padding:0 8px 26px 8px;font-size:20px;line-height:1.35;font-weight:600;color:#0f0f0f;">
                        You requested a password reset for your Save Rack account.
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 8px 28px 8px;font-size:15px;line-height:1.5;color:#4b5563;">
                        Click the button below to choose a new password. This link expires in {{ $expireMinutes }} minutes.
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 8px 36px 8px;">
                        <a href="{{ $resetUrl }}" style="display:inline-block;background:#2563eb;color:#ffffff !important;text-decoration:none;padding:16px 40px;border-radius:999px;font-weight:700;font-size:17px;line-height:1;">
                            Reset Password
                        </a>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 8px 24px 8px;font-size:13px;line-height:1.5;color:#6b7280;word-break:break-all;">
                        If the button does not work, copy and paste this link into your browser:<br>
                        <a href="{{ $resetUrl }}" style="color:#2563eb;">{{ $resetUrl }}</a>
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:0 8px 0 8px;font-size:14px;line-height:1.5;color:#6b7280;">
                        If you did not request a password reset, you can ignore this email.
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
