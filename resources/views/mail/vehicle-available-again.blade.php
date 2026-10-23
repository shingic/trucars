@php
    $font = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $car = $vehicle->model_year . ' ' . $vehicle->make . ' ' . $vehicle->model;
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>It's available again</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">
    The {{ $car }} you were watching is back on sale. Reserve it before someone else does.
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
                                <td align="right" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#C9D3E6;">Available again</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @if ($vehicle->primary_photo_url)
                    <tr>
                        <td style="padding:0;">
                            <img src="{{ $vehicle->primary_photo_url }}" width="600" alt="{{ $car }}" style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                        </td>
                    </tr>
                @endif

                {{-- Hero --}}
                <tr>
                    <td style="padding:34px 36px 8px;">
                        <h1 style="margin:0 0 12px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:34px; line-height:1; font-weight:700; letter-spacing:0; text-transform:uppercase; color:#071437;">
                            Good news, {{ $firstName }} — it's back.
                        </h1>
                        <p style="margin:0; {{ $font }} font-size:15px; line-height:1.6; color:#4B5675;">
                            The {{ $car }}{{ $vehicle->trim ? ' ' . $vehicle->trim : '' }} you were watching at {{ ucwords(strtolower((string) $vehicle->dealer?->name)) }} is available again. Its last reservation didn't go through, so it's open to reserve right now — first come, first served.
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:20px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FFFFFF; border:1px solid #D5D7DD; border-top:2px solid #071437;">
                            <tr>
                                <td style="padding:16px 20px; {{ $font }} font-size:14px; color:#4B5675;">{{ number_format((int) $vehicle->kilometres) }} km</td>
                                <td align="right" style="padding:16px 20px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:26px; line-height:1; font-weight:700; color:#071437;">{{ $vehicle->display_price }}</td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- CTA --}}
                <tr>
                    <td style="padding:26px 36px 6px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" bgcolor="#1B84FF" style="border-radius:3px;">
                                    <a href="{{ url('/cars/' . $vehicle->id) }}" target="_blank" style="display:inline-block; padding:14px 28px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:3px;">
                                        See it &amp; reserve →
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
                                    <p style="margin:0; {{ $font }} font-size:13px; line-height:1.6; color:#78829D;">
                                        Sent once, because you tapped the bell on this car. We won't email you about it again.
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
