@php
    $font = "font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;";
    $display = "font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif;";
    $brand = config('site.name');
    $name = fn ($car) => trim("{$car->model_year} {$car->make} {$car->model}");
    $lead = $drops->first()['car'] ?? $gone->first() ?? $still->first();
    $copy = match ($kind) {
        'saved_price_drop' => [
            'tag'      => 'Price drop',
            'headline' => $drops->count() === 1 ? 'A car you saved just got cheaper' : 'Cars you saved just got cheaper',
            'intro'    => "Good news, {$firstName}. Prices are all-in, so what you see is what you pay before HST and licensing.",
        ],
        'saved_gone' => [
            'tag'      => 'Saved car update',
            'headline' => 'Gone — but these are close',
            'intro'    => 'The ' . $name($gone->first()) . " you saved has been reserved or taken off the market. Here are a few like it that are still available.",
        ],
        default => [
            'tag'      => 'Still available',
            'headline' => "Still thinking it over, {$firstName}?",
            'intro'    => 'The ' . $name($still->first()) . " you saved is still here. Reserve it for \$150, fully refundable before pickup, and nobody else can take it while you decide.",
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
                            <td style="{{ $display }} font-size:24px; font-weight:800; line-height:1; letter-spacing:0.01em; text-transform:uppercase; color:#FFFFFF;">Tru<span style="color:#6CB0FF;">Cars</span></td>
                            <td align="right" style="{{ $display }} font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#C9D3E6;">{{ $copy['tag'] }}</td>
                        </tr></table>
                    </td>
                </tr>

                @if ($kind !== 'saved_gone' && $lead?->primary_photo_url)
                    <tr><td style="padding:0;"><a href="{{ url('/cars/' . $lead->id) }}" target="_blank"><img src="{{ $lead->primary_photo_url }}" width="600" alt="{{ $name($lead) }}" style="display:block; width:100%; max-width:600px; height:auto; border:0;"></a></td></tr>
                @endif

                <tr>
                    <td style="padding:34px 36px 8px;">
                        <h1 style="margin:0 0 12px; {{ $display }} font-size:32px; line-height:1.05; font-weight:700; text-transform:uppercase; color:#071437;">{{ $copy['headline'] }}</h1>
                        <p style="margin:0; {{ $font }} font-size:15px; line-height:1.6; color:#4B5675;">{{ $copy['intro'] }}</p>
                    </td>
                </tr>

                {{-- Price drops: was → now --}}
                @foreach ($drops as $item)
                    @php $car = $item['car']; @endphp
                    <tr>
                        <td style="padding:16px 36px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                                <tr>
                                    <td style="padding:14px 18px; {{ $font }} font-size:14px; line-height:1.5; color:#4B5675;">
                                        <a href="{{ url('/cars/' . $car->id) }}" target="_blank" style="color:#071437; font-weight:700; text-decoration:none;">{{ $name($car) }}{{ $car->trim ? ' ' . $car->trim : '' }}</a><br>
                                        {{ number_format((int) $car->kilometres) }} km · {{ $car->display_location }}<br>
                                        <span style="color:#0E8A3A; font-weight:700;">${{ number_format(($item['was'] - $car->price_in_cents) / 100) }} less</span> · was <s>${{ number_format($item['was'] / 100) }}</s>
                                    </td>
                                    <td align="right" style="padding:14px 18px; {{ $display }} font-size:24px; font-weight:700; color:#071437; white-space:nowrap;">{{ $car->display_price }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach

                {{-- Still available --}}
                @foreach ($still as $car)
                    <tr>
                        <td style="padding:16px 36px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                                <tr>
                                    <td style="padding:14px 18px; {{ $font }} font-size:14px; line-height:1.5; color:#4B5675;">
                                        <a href="{{ url('/cars/' . $car->id) }}" target="_blank" style="color:#071437; font-weight:700; text-decoration:none;">{{ $name($car) }}{{ $car->trim ? ' ' . $car->trim : '' }}</a><br>
                                        {{ number_format((int) $car->kilometres) }} km · {{ $car->display_location }}
                                    </td>
                                    <td align="right" style="padding:14px 18px; {{ $display }} font-size:24px; font-weight:700; color:#071437; white-space:nowrap;">{{ $car->display_price }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endforeach

                {{-- Gone, alongside other news --}}
                @if ($gone->isNotEmpty() && $kind !== 'saved_gone')
                    <tr>
                        <td style="padding:16px 36px 0; {{ $font }} font-size:14px; line-height:1.6; color:#4B5675;">
                            No longer available: {{ $gone->map(fn ($car) => $name($car))->implode(', ') }}.
                        </td>
                    </tr>
                @endif

                @if ($lead && $kind !== 'saved_gone')
                    <tr>
                        <td style="padding:26px 36px 6px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                                <td align="center" bgcolor="#1B84FF" style="border-radius:8px;">
                                    <a href="{{ url('/cars/' . $lead->id) }}" target="_blank" style="display:inline-block; padding:14px 28px; {{ $display }} font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:8px;">See it &amp; reserve →</a>
                                </td>
                            </tr></table>
                        </td>
                    </tr>
                @endif

                @if ($suggestions->isNotEmpty())
                    <tr>
                        <td style="padding:30px 36px 0;">
                            <p style="margin:0 0 10px; {{ $display }} font-size:16px; font-weight:700; text-transform:uppercase; color:#071437;">{{ $kind === 'saved_gone' ? 'Still available' : 'You might also like' }}</p>
                            @foreach ($suggestions as $other)
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-top:1px solid #E3E5EA;">
                                    <tr>
                                        @if ($other->primary_photo_url)
                                            <td width="120" style="padding:12px 14px 12px 0;"><a href="{{ url('/cars/' . $other->id) }}" target="_blank"><img src="{{ $other->primary_photo_url }}" width="120" alt="" style="display:block; width:120px; height:auto; border-radius:6px; border:0;"></a></td>
                                        @endif
                                        <td style="padding:12px 0; {{ $font }} font-size:14px; line-height:1.5; color:#4B5675;">
                                            <a href="{{ url('/cars/' . $other->id) }}" target="_blank" style="color:#071437; font-weight:700; text-decoration:none;">{{ $name($other) }}</a><br>
                                            {{ number_format((int) $other->kilometres) }} km · {{ $other->display_location }}
                                        </td>
                                        <td align="right" style="padding:12px 0 12px 12px; {{ $display }} font-size:19px; font-weight:700; color:#071437; white-space:nowrap;">{{ $other->display_price }}</td>
                                    </tr>
                                </table>
                            @endforeach
                        </td>
                    </tr>
                @endif

                @include('mail.partials.marketing-footer', [
                    'user'   => $user,
                    'type'   => \App\Support\Marketing\EmailTypes::forKind($kind),
                    'reason' => "You're getting this because you saved this car on {$brand}.",
                ])
            </table>
        </td>
    </tr>
</table>
</body>
</html>
