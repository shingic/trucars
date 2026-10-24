<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Welcome to TruCars</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">
    Your TruCars account is ready. Browse certified cars, reserve in minutes, and track everything in My Garage. Creating an account never starts a purchase.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F9F9F9;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border:1px solid #D5D7DD; border-radius:10px; overflow:hidden;">

                {{-- Header band --}}
                <tr>
                    <td style="background-color:#071437; padding:24px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:24px; font-weight:800; line-height:1; letter-spacing:0.01em; text-transform:uppercase; color:#FFFFFF;">Tru<span style="color:#6CB0FF;">Cars</span></td>
                                <td align="right" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#C9D3E6;">
                                    Welcome
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Hero --}}
                <tr>
                    <td style="padding:38px 36px 8px;">
                        <h1 style="margin:0 0 12px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:34px; line-height:1; font-weight:700; letter-spacing:0; text-transform:uppercase; color:#071437;">
                            Welcome, {{ $firstName }}.
                        </h1>
                        <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#4B5675;">
                            Your account is ready. It's where your reservations, your deal status, and — once the keys are yours — your garage all live.
                        </p>
                    </td>
                </tr>

                {{-- Reassurance callout --}}
                <tr>
                    <td style="padding:22px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#E7F8F1; border-left:4px solid #17C653;">
                            <tr>
                                <td style="padding:18px 22px;">
                                    <p style="margin:0 0 3px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700; color:#0B7355;">
                                        No purchase started
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#1B6B53;">
                                        Creating an account is free. Reserving a car is always a separate, deliberate step you choose to take.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- What you can do now --}}
                <tr>
                    <td style="padding:28px 36px 0;">
                        <p style="margin:0 0 14px; padding-top:14px; border-top:2px solid #071437; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:20px; line-height:1.1; font-weight:700; text-transform:uppercase; color:#071437;">
                            What you can do now
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">1.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Browse cars with all-in pricing — certified cars list every inspection point, and the number you see is the number you pay (before HST &amp; licensing).
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">2.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Reserve in minutes — a $150 fully-refundable deposit holds your exact car, and it credits straight to the purchase. It isn't an extra charge.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">3.</td>
                                <td style="font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Track everything in My Garage — your deal status, documents and financing stay in one place, start to finish.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:30px 36px 6px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" bgcolor="#1B84FF" style="border-radius:8px;">
                                    <a href="{{ route('shop') }}" target="_blank" style="display:inline-block; padding:14px 28px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:8px;">
                                        Browse certified cars →
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:28px 36px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="border-top:2px solid #071437; padding-top:20px;">
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#4B5675;">
                                        TruCars handles your reservation and checkout. The dealership owns the vehicle, the financing and the delivery, and confirms all final figures with you before anything is signed.
                                    </p>
                                    <p style="margin:10px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#78829D;">
                                        Sent because you created a TruCars account.
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
