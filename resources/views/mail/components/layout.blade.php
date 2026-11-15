{{--
    The frame for every email: wordmark and lane line, a white card holding
    the slot, then the footer. Props:
      title       <title>, also a fallback preheader
      preheader   inbox preview line
      link        the main button's URL, repeated as "if the button doesn't work"
      reason      why they got it, printed in the footer
      unsubscribe ['user' => User, 'type' => EmailTypes key] for marketing (CASL links)
--}}
@props(['title', 'preheader' => null, 'link' => null, 'reason' => null, 'unsubscribe' => null])
@use('App\Support\Email\Theme', 'T')
@use('App\Support\PlatformSettings')
@use('Illuminate\Support\Facades\URL')
@php
    $brand = config('site.name');
    $address = PlatformSettings::get('business_mailing_address');
    $contactEmail = PlatformSettings::get('contact_email');
    $contactPhone = PlatformSettings::get('contact_phone');
    $site = url('/');
    $siteLabel = preg_replace('#^https?://(www\.)?#', '', rtrim($site, '/'));
    $footerLink = 'color:' . T::MUTED . '; text-decoration:underline;';
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        @media only screen and (max-width:620px) {
            .mail-card { padding:28px 22px !important; }
            .mail-shell { padding:24px 12px !important; }
            .mail-h1 { font-size:24px !important; }
            .mail-stack { display:block !important; width:100% !important; text-align:left !important; padding-left:0 !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:{{ T::PAGE }}; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:{{ T::PAGE }}; opacity:0;">
    {{ $preheader ?? $title }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:{{ T::PAGE }};">
    <tr>
        <td align="center" class="mail-shell" style="padding:40px 16px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">

                {{-- Wordmark, lane line, tagline --}}
                <tr>
                    <td style="padding:0 0 28px;">
                        <a href="{{ $site }}" target="_blank" style="text-decoration:none;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="{!! T::FONT !!} font-size:40px; line-height:1; letter-spacing:0.01em; color:{{ T::INK }};"><span style="font-weight:400;">TRU</span><span style="font-weight:800;">CARS</span></td>
                                </tr>
                                <tr>
                                    <td style="padding-top:8px; font-size:0; line-height:0;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                @for ($i = 0; $i < 4; $i++)
                                                    <td width="16" height="3" style="width:16px; height:3px; background-color:{{ T::ACCENT }}; font-size:0; line-height:0;">&nbsp;</td>
                                                    <td width="7" height="3" style="width:7px; height:3px; font-size:0; line-height:0;">&nbsp;</td>
                                                @endfor
                                                <td height="3" style="height:3px; background-color:{{ T::ACCENT }}; font-size:0; line-height:0;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-top:9px; {!! T::FONT !!} font-size:10px; font-weight:600; line-height:1; letter-spacing:0.42em; text-transform:uppercase; color:{{ T::TEXT }};">Certified pre-owned</td>
                                </tr>
                            </table>
                        </a>
                    </td>
                </tr>

                {{-- Card --}}
                <tr>
                    <td class="mail-card" style="background-color:{{ T::CARD }}; border:1px solid {{ T::LINE }}; border-radius:8px; padding:36px 40px;">
                        {{-- Lane rule --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="40%" style="width:40%; border-top:3px dashed {{ T::ACCENT }}; font-size:0; line-height:0;">&nbsp;</td>
                                <td width="60%" style="width:60%; border-top:3px solid {{ T::ACCENT }}; font-size:0; line-height:0;">&nbsp;</td>
                            </tr>
                        </table>

                        <div style="padding-top:24px;">
                            {{ $slot }}
                        </div>

                        @if ($link)
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:28px;">
                                <tr>
                                    <td style="border-top:1px solid {{ T::LINE }}; padding-top:20px; {!! T::FONT !!} font-size:13px; line-height:1.6; color:{{ T::MUTED }};">
                                        If the button doesn't work, copy this link into your browser:<br>
                                        <a href="{{ $link }}" target="_blank" style="color:{{ T::ACCENT }}; text-decoration:underline; word-break:break-all;">{{ $link }}</a>
                                    </td>
                                </tr>
                            </table>
                        @endif
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:28px 2px 0; {!! T::FONT !!} font-size:13px; line-height:1.7; color:{{ T::MUTED }};">
                        <p style="margin:0 0 8px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:{{ T::INK }};">{{ $brand }} · Certified pre-owned</p>
                        @if ($address !== '')
                            <p style="margin:0 0 6px;">{{ $address }}</p>
                        @endif
                        <p style="margin:0 0 10px;">
                            <a href="{{ $site }}" target="_blank" style="{{ $footerLink }}">{{ $siteLabel }}</a>
                            @if ($contactEmail !== '')
                                &nbsp;·&nbsp; <a href="mailto:{{ $contactEmail }}" style="{{ $footerLink }}">{{ $contactEmail }}</a>
                            @endif
                            @if ($contactPhone !== '')
                                &nbsp;·&nbsp; <a href="tel:{{ preg_replace('/[^0-9+]/', '', $contactPhone) }}" style="{{ $footerLink }}">{{ $contactPhone }}</a>
                            @endif
                        </p>
                        @if ($reason)
                            <p style="margin:0 0 6px;">{{ $reason }}</p>
                        @endif
                        @if ($unsubscribe)
                            <p style="margin:0 0 6px;">
                                To stop {{ strtolower(\App\Support\Marketing\EmailTypes::label($unsubscribe['type'])) }}, <a href="{{ URL::signedRoute('marketing.unsubscribe.type', ['user' => $unsubscribe['user']->id, 'type' => $unsubscribe['type']]) }}" target="_blank" style="{{ $footerLink }}">click here</a>.
                                Manage email preferences or unsubscribe from all <a href="{{ URL::signedRoute('email.preferences', ['user' => $unsubscribe['user']->id]) }}" target="_blank" style="{{ $footerLink }}">here</a>.
                            </p>
                        @endif
                        <p style="margin:10px 0 0;">© {{ now()->year }} {{ $brand }}. All rights reserved.</p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
