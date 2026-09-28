<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $headline }} · POSPilot</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f6f3;color:#243a30;font-family:Arial,Helvetica,sans-serif;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">{{ $preheader }}</div>
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#f1f6f3;">
        <tr>
            <td align="center" style="padding:38px 16px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;">
                    <tr>
                        <td align="center" style="padding:0 0 20px;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                <tr>
                                    <td width="40" height="40" align="center" valign="middle" style="width:40px;height:40px;border-radius:12px;background-color:#0ca46a;color:#ffffff;font-size:21px;font-weight:700;">P</td>
                                    <td style="padding-left:10px;color:#143b2a;font-size:23px;font-weight:700;letter-spacing:-0.7px;">POSPilot</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background-color:#ffffff;border:1px solid #e1ebe4;border-radius:20px;">
                                <tr>
                                    <td style="padding:40px 42px 18px;">
                                        <p style="margin:0 0 20px;color:#08784f;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;">POSPilot account security</p>
                                        <p style="margin:0 0 12px;color:#52685d;font-size:15px;line-height:1.6;">Hi {{ $recipientName }},</p>
                                        <h1 style="margin:0;color:#173b2b;font-size:30px;line-height:1.2;letter-spacing:-0.7px;">{{ $headline }}</h1>
                                        <p style="margin:18px 0 0;color:#5a6e63;font-size:16px;line-height:1.7;">{{ $intro }}</p>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="left" style="padding:12px 42px 8px;">
                                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                                            <tr>
                                                <td align="center" bgcolor="#078457" style="border-radius:10px;background-color:#078457;">
                                                    <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display:inline-block;padding:15px 23px;border:1px solid #078457;border-radius:10px;color:#ffffff;font-size:15px;font-weight:700;line-height:1.2;text-decoration:none;">{{ $actionLabel }}</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:20px 42px 40px;">
                                        <p style="margin:0;color:#697a71;font-size:13px;line-height:1.65;">{{ $closingNote }}</p>
                                        <p style="margin:18px 0 7px;color:#849188;font-size:12px;line-height:1.5;">If the button does not work, copy this link into your browser:</p>
                                        <p style="margin:0;overflow-wrap:anywhere;font-size:12px;line-height:1.6;"><a href="{{ $actionUrl }}" style="color:#08784f;text-decoration:underline;">{{ $actionUrl }}</a></p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:23px 20px 0;color:#7a8981;font-size:12px;line-height:1.7;">
                            <p style="margin:0;">A secure workspace for the people behind the counter.</p>
                            <p style="margin:7px 0 0;">© {{ now()->year }} POSPilot</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
