@php
    $font = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $display = "font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif;";
    $brand = config('site.name');
    $car = $draft->vehicle;
    $resume = $draft->resumeUrl();
    $copy = match ($kind) {
        'recovery_1h' => [
            'tag' => 'Your car is waiting',
            'headline' => "Pick up where you left off, {$firstName}",
            'intro' => "You were partway through reserving the {$carName}. Everything you entered is saved: you stopped at {$draft->stepLabel()}, so it's only a few minutes to finish.",
            'cta' => 'Finish reserving →',
        ],
        'recovery_1d' => [
            'tag' => 'Still available',
            'headline' => "The {$carName} is still here",
            'intro' => "Nobody has reserved it yet. If it isn't quite right, here are a few similar cars close to you.",
            'cta' => 'Finish reserving →',
        ],
        'recovery_3d' => [
            'tag' => 'Fully refundable',
            'headline' => 'Hold it for $150',
            'intro' => "The {$carName} is still available. Reserving takes it off the market while you decide, and the \$150 is fully refundable any time before pickup. It comes off the price when you buy.",
            'cta' => 'Reserve it →',
        ],
        default => [
            'tag' => 'Picked for you',
            'headline' => "Cars like the {$carName}",
            'intro' => 'Based on the car you were looking at, these are close to you and priced all-in.',
            'cta' => 'Back to the ' . $car->model . ' →',
        ],
    };
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $copy['headline'] }}</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">{{ $copy['intro'] }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F9F9F9;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border:1px solid #D5D7DD; border-radius:10px; overflow:hidden;">
                <tr>
                    <td style="background-color:#071437; padding:22px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                            <td><img src="{{ asset('images/trumo-tagline-white-1200.png') }}" alt="{{ $brand }}" width="113" height="44" style="display:block; width:113px; height:44px; border:0;"></td>
                            <td align="right" style="{{ $display }} font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#C9D3E6;">{{ $copy['tag'] }}</td>
                        </tr></table>
                    </td>
                </tr>

                @if ($car->primary_photo_url && $kind !== 'recovery_7d')
                    <tr><td style="padding:0;"><img src="{{ $car->primary_photo_url }}" width="600" alt="{{ $carName }}" style="display:block; width:100%; max-width:600px; height:auto; border:0;"></td></tr>
                @endif

                <tr>
                    <td style="padding:34px 36px 8px;">
                        <h1 style="margin:0 0 12px; {{ $display }} font-size:32px; line-height:1.05; font-weight:700; text-transform:uppercase; color:#071437;">{{ $copy['headline'] }}</h1>
                        <p style="margin:0; {{ $font }} font-size:15px; line-height:1.6; color:#4B5675;">{{ $copy['intro'] }}</p>
                    </td>
                </tr>

                @if ($kind !== 'recovery_7d')
                    <tr>
                        <td style="padding:20px 36px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                                <tr>
                                    <td style="padding:14px 18px; {{ $font }} font-size:14px; line-height:1.5; color:#4B5675;">
                                        <b style="color:#071437;">{{ $carName }}{{ $car->trim ? ' ' . $car->trim : '' }}</b><br>
                                        {{ number_format((int) $car->kilometres) }} km · {{ $whereLine($car) }}
                                    </td>
                                    <td align="right" style="padding:14px 18px; {{ $display }} font-size:24px; font-weight:700; color:#071437; white-space:nowrap;">{{ $car->display_price }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                @if ($kind === 'recovery_3d')
                    <tr>
                        <td style="padding:18px 36px 0; {{ $font }} font-size:14.5px; line-height:1.7; color:#4B5675;">
                            ✓ The price is all-in: freight, prep and dealer fees included. Only HST and licensing are added.<br>
                            ✓ The $150 is refundable any time before pickup.<br>
                            ✓ You choose the pickup day, and the car is inspected and certified first.
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:26px 36px 6px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                            <td align="center" bgcolor="#1B84FF" style="border-radius:8px;">
                                <a href="{{ $resume }}" target="_blank" style="display:inline-block; padding:14px 28px; {{ $display }} font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:8px;">{{ $copy['cta'] }}</a>
                            </td>
                        </tr></table>
                    </td>
                </tr>

                @if ($suggestions->isNotEmpty())
                    <tr>
                        <td style="padding:30px 36px 0;">
                            <p style="margin:0 0 10px; {{ $display }} font-size:16px; font-weight:700; text-transform:uppercase; color:#071437;">{{ $kind === 'recovery_7d' ? 'Picked for you' : 'Similar cars near you' }}</p>
                            @foreach ($suggestions as $other)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #E3E5EA;">
                                    <tr>
                                        @if ($other->primary_photo_url)
                                            <td width="120" style="padding:12px 14px 12px 0;"><a href="{{ url('/cars/' . $other->id) }}" target="_blank"><img src="{{ $other->primary_photo_url }}" width="120" alt="" style="display:block; width:120px; height:auto; border-radius:6px; border:0;"></a></td>
                                        @endif
                                        <td style="padding:12px 0; {{ $font }} font-size:14px; line-height:1.5; color:#4B5675;">
                                            <a href="{{ url('/cars/' . $other->id) }}" target="_blank" style="color:#071437; font-weight:700; text-decoration:none;">{{ $other->model_year }} {{ $other->make }} {{ $other->model }}</a><br>
                                            {{ number_format((int) $other->kilometres) }} km · {{ $whereLine($other) }}
                                        </td>
                                        <td align="right" style="padding:12px 0 12px 12px; {{ $display }} font-size:19px; font-weight:700; color:#071437; white-space:nowrap;">{{ $other->display_price }}</td>
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    </tr>
                @endif

                <tr>
                    <td style="padding:28px 36px 34px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                            <td style="border-top:2px solid #071437; padding-top:18px; {{ $font }} font-size:12.5px; line-height:1.6; color:#78829D;">
                                You're getting this because you started reserving a car on {{ $brand }}. Once you reserve, these follow-ups stop.<br>
                                {{ $brand }} · {{ \App\Support\PlatformSettings::get('business_mailing_address') }}<br>
                                <a href="{{ $unsubscribeUrl }}" target="_blank" style="color:#4B5675; text-decoration:underline;">Unsubscribe from marketing emails</a>
                            </td>
                        </tr></table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
