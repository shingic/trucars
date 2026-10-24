<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your TruCars Command Centre invitation</title>
</head>
<body style="margin:0; padding:0; background:#F9F9F9; font-family:'Barlow',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; color:#071437;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F9F9F9; padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; background:#FFFFFF; border:1px solid #D5D7DD; border-radius:10px; overflow:hidden;">
                    <tr>
                        <td style="background-color:#071437; padding:22px 28px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:24px; font-weight:800; line-height:1; letter-spacing:0.01em; text-transform:uppercase; color:#FFFFFF;">Tru<span style="color:#6CB0FF;">Cars</span></td>
                    </tr>
                    <tr>
                        <td style="padding:28px 28px 4px;">
                            <h1 style="font-size:21px; margin:0 0 10px;">Hi {{ $invitation->name }},</h1>
                            <p style="font-size:15px; line-height:1.55; color:#4B5675; margin:0 0 20px;">
                                You've been invited to join the <b style="color:#071437;">TruCars Command Centre</b>,
                                TruCars' internal workspace. Set your password to finish creating your account.
                            </p>
                            <a href="{{ $acceptUrl }}" style="display:inline-block; background:#1B84FF; color:#FFFFFF; text-decoration:none; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-weight:700; font-size:17px; letter-spacing:0.02em; text-transform:uppercase; padding:13px 24px; border-radius:8px;">Set up my account</a>
                            <p style="font-size:13px; line-height:1.55; color:#78829D; margin:22px 0 0;">
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
