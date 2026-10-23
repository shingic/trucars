<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your TruCars dealer portal invitation</title>
</head>
<body style="margin:0; padding:0; background:#F6F7F7; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color:#16181D;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F7F7; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#FFFFFF; border-radius:16px; border:1px solid rgba(22,24,29,.09);">
                    <tr>
                        <td style="padding:28px 28px 8px;">
                            <span style="display:inline-block; width:28px; height:28px; line-height:28px; text-align:center; border-radius:9px; background:#1F55D6; color:#FFFFFF; font-weight:800;">T</span>
                            <span style="font-weight:800; font-size:17px; margin-left:6px; vertical-align:middle;">TruCars</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:12px 28px 4px;">
                            <h1 style="font-size:21px; margin:0 0 10px; letter-spacing:-.02em;">Hi {{ $invitation->name }},</h1>
                            <p style="font-size:15px; line-height:1.55; color:#585E66; margin:0 0 20px;">
                                You've been invited to join the <b style="color:#16181D;">{{ ucwords(strtolower($invitation->dealer->name)) }}</b>
                                dealer portal on TruCars, where you'll manage reservations and inventory. Set your password to finish creating your account.
                            </p>
                            <a href="{{ $acceptUrl }}" style="display:inline-block; background:#1F55D6; color:#FFFFFF; text-decoration:none; font-weight:700; font-size:15px; padding:13px 24px; border-radius:999px;">Set up my account</a>
                            <p style="font-size:13px; line-height:1.55; color:#969CA3; margin:22px 0 0;">
                                This link works once and expires {{ $invitation->expires_at->format('F j, Y') }}. If you weren't expecting it, you can ignore this email — no account is created until you set a password.
                            </p>
                        </td>
                    </tr>
                    <tr><td style="padding:20px 28px 28px;"></td></tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
