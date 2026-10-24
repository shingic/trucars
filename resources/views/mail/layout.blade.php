{{--
    Shared frame for the buyer's deal emails, matching reservation-confirmed:
    blue header band, headline, body, one button to My Garage, footer.
    Sections: title, preheader, tag, headline, intro, body (optional),
    cta and cta_url (optional, default to "Open My Garage →"), reason (footer line).
    Child sections render before this file, so they can't use $font; the
    document list has its own partial (mail.partials.documents).
--}}
@php($font = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;")
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title')</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">
    @yield('preheader')
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F9F9F9;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border:1px solid #D5D7DD; border-radius:10px; overflow:hidden;">

                <tr>
                    <td style="background-color:#071437; padding:24px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:24px; font-weight:800; line-height:1; letter-spacing:0.01em; text-transform:uppercase; color:#FFFFFF;">Tru<span style="color:#6CB0FF;">Cars</span></td>
                                <td align="right" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#C9D3E6;">@yield('tag')</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:38px 36px 8px;">
                        <h1 style="margin:0 0 12px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:34px; line-height:1; font-weight:700; letter-spacing:0; text-transform:uppercase; color:#071437;">@yield('headline')</h1>
                        <p style="margin:0; {{ $font }} font-size:15px; line-height:1.6; color:#4B5675;">@yield('intro')</p>
                    </td>
                </tr>

                @hasSection('body')
                    <tr>
                        <td style="padding:22px 36px 0;">
                            @yield('body')
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:30px 36px 6px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" bgcolor="#1B84FF" style="border-radius:8px;">
                                    <a href="@yield('cta_url', route('garage'))" target="_blank" style="display:inline-block; padding:14px 28px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:8px;">
                                        @hasSection('cta') @yield('cta') @else Open My Garage → @endif
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td style="padding:28px 36px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="border-top:2px solid #071437; padding-top:20px;">
                                    <p style="margin:0; {{ $font }} font-size:13px; line-height:1.6; color:#4B5675;">
                                        {{ $deal->seller_name }} is handling your purchase and can answer any questions. Your reference is {{ $deal->reference }}.
                                    </p>
                                    <p style="margin:10px 0 0; {{ $font }} font-size:13px; line-height:1.6; color:#78829D;">
                                        @yield('reason')
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
