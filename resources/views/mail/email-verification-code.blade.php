<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Your verification code</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">
    {{ $code }} is your TruCars verification code. It expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F9F9F9;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border:1px solid #D5D7DD; border-radius:4px; overflow:hidden;">

                {{-- Header band --}}
                <tr>
                    <td style="background-color:#071437; padding:24px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:24px; font-weight:800; line-height:1; letter-spacing:0.01em; text-transform:uppercase; color:#FFFFFF;">Tru<span style="color:#6CB0FF;">Cars</span></td>
                                <td align="right" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#C9D3E6;">
                                    Verify your email
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Hero --}}
                <tr>
                    <td style="padding:38px 36px 8px;">
                        <h1 style="margin:0 0 12px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:34px; line-height:1; font-weight:700; letter-spacing:0; text-transform:uppercase; color:#071437;">
                            Here's your code, {{ $firstName }}.
                        </h1>
                        <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#4B5675;">
                            Enter this code back on TruCars to confirm your email and finish setting up your account.
                        </p>
                    </td>
                </tr>

                {{-- Code box --}}
                <tr>
                    <td style="padding:24px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:2px solid #071437; border-radius:4px; background-color:#FFFFFF;">
                            <tr>
                                <td align="center" style="padding:26px 22px; font-family:'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size:38px; font-weight:700; letter-spacing:0.18em; color:#071437;">
                                    {{ $code }}
                                </td>
                            </tr>
                        </table>
                        <p style="margin:14px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.55; color:#78829D;">
                            This code expires in {{ $expiresInMinutes }} minutes. Don't share it with anyone — TruCars will never ask you to read it out.
                        </p>
                    </td>
                </tr>

                {{-- Reassurance --}}
                <tr>
                    <td style="padding:24px 36px 0;">
                        <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.6; color:#4B5675;">
                            Didn't try to create a TruCars account? You can safely ignore this email — nothing happens without this code.
                        </p>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:28px 36px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="border-top:2px solid #071437; padding-top:20px;">
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#78829D;">
                                        Sent because someone entered this email to create a TruCars account.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
