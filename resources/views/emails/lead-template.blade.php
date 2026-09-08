<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#151515;text-align:left;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#ffffff;border-collapse:collapse;">
    <tr>
        <td align="left" valign="top" style="padding:16px 0;text-align:left;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;margin:0;text-align:left;">
                <tr>
                    <td align="left" style="font-size:15px;line-height:1.55;color:#151515;text-align:left;">
                        {!! $bodyHtml !!}
                    </td>
                </tr>
                <tr>
                    <td align="left" style="padding-top:24px;text-align:left;">
                        {!! $signatureHtml !!}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
