<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Your reservation is confirmed</title>
</head>
<body style="margin:0; padding:0; background-color:#F4F4F2; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F4F4F2; opacity:0;">
    Your {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }} is reserved — reference {{ $deal->reference }}. Your $150 deposit is refundable and credited to your purchase.
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F4F2;">
    <tr>
        <td align="center" style="padding:32px 16px;">

            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background-color:#FFFFFF; border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(22,24,29,0.08);">

                {{-- Header band --}}
                <tr>
                    <td style="background-color:#F5631F; padding:26px 36px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:20px; font-weight:800; letter-spacing:-0.02em; color:#FFFFFF;">
                                    Trueleads
                                </td>
                                <td align="right" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; font-weight:700; letter-spacing:0.04em; text-transform:uppercase; color:#FFE2D2;">
                                    Reservation confirmed
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Hero --}}
                <tr>
                    <td style="padding:38px 36px 8px;">
                        <h1 style="margin:0 0 10px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:26px; line-height:1.2; font-weight:800; letter-spacing:-0.025em; color:#16181D;">
                            You're reserved, {{ $deal->first_name }}.
                        </h1>
                        <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#5B6068;">
                            Your car is locked in. Here's your full reservation, what's in your price, and what happens next.
                        </p>
                    </td>
                </tr>

                {{-- Reference chip --}}
                <tr>
                    <td style="padding:18px 36px 0;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background-color:#F4F4F2; border-radius:999px; padding:8px 16px; font-family:'Geist Mono', 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size:13px; font-weight:700; letter-spacing:0.02em; color:#16181D;">
                                    Reference&nbsp;&nbsp;{{ $deal->reference }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Vehicle card --}}
                <tr>
                    <td style="padding:22px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #ECECEA; border-radius:12px;">
                            <tr>
                                <td style="padding:20px 22px;">
                                    <p style="margin:0 0 4px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                                        Your vehicle
                                    </p>
                                    <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:18px; font-weight:700; letter-spacing:-0.01em; color:#16181D;">
                                        {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}
                                    </p>
                                    @if ($deal->vehicle->trim)
                                        <p style="margin:5px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; color:#5B6068;">
                                            {{ $deal->vehicle->trim }}
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- All-in price breakdown --}}
                <tr>
                    <td style="padding:16px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #ECECEA; border-radius:12px;">
                            <tr>
                                <td style="padding:20px 22px;">
                                    <p style="margin:0 0 8px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                                        Your all-in price
                                    </p>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:22px; font-weight:800; letter-spacing:-0.02em; color:#16181D;">
                                                ${{ number_format($deal->vehicle->price_in_cents / 100) }}
                                            </td>
                                            <td align="right" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; color:#9AA0A6;">
                                                @if ($deal->purchase_type === 'finance' && $deal->term_months){{ $deal->term_months }}-month finance estimate@elseif ($deal->purchase_type === 'lease')Lease estimate@else Cash @endif
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:10px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.55; color:#5B6068;">
                                        Freight, PDI, admin and the OMVIC fee are already inside this price — no surprise add-ons. Only HST and licensing are added on top.
                                    </p>

                                    @if (count($deal->fees_by_kind['included']))
                                        <div style="border-top:1px solid #ECECEA; margin-top:16px; padding-top:14px;"></div>
                                        <p style="margin:0 0 8px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                                            Already included in this price
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($deal->fees_by_kind['included'] as $includedFee)
                                                <tr>
                                                    <td style="padding:3px 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#5B6068;">
                                                        {{ $includedFee['label'] }}
                                                    </td>
                                                    <td align="right" style="padding:3px 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#9AA0A6;">
                                                        ${{ number_format($includedFee['amount_in_cents'] / 100, 2) }} included
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif

                                    @if ($deal->fees_by_kind['passThroughTotalInCents'] > 0)
                                        <div style="border-top:1px solid #ECECEA; margin-top:16px; padding-top:14px;"></div>
                                        <p style="margin:0 0 8px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                                            Added at delivery · never financed
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($deal->fees_by_kind['passThrough'] as $passThroughFee)
                                                <tr>
                                                    <td style="padding:3px 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#3A3F46;">
                                                        {{ $passThroughFee['label'] }}
                                                    </td>
                                                    <td align="right" style="padding:3px 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; font-weight:600; color:#16181D;">
                                                        ${{ number_format($passThroughFee['amount_in_cents'] / 100, 2) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                            <tr>
                                                <td style="padding:3px 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#3A3F46;">
                                                    HST
                                                </td>
                                                <td align="right" style="padding:3px 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#9AA0A6;">
                                                    calculated at delivery
                                                </td>
                                            </tr>
                                        </table>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Trade-in (only when one was submitted) --}}
                @if ($deal->tradeIn)
                    <tr>
                        <td style="padding:16px 36px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #ECECEA; border-radius:12px;">
                                <tr>
                                    <td style="padding:20px 22px;">
                                        <p style="margin:0 0 4px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                                            Your trade-in
                                        </p>
                                        <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:16px; font-weight:700; color:#16181D;">
                                            {{ $deal->tradeIn->model_year }} {{ $deal->tradeIn->make }} {{ $deal->tradeIn->model }}
                                        </p>
                                        <p style="margin:5px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; color:#5B6068;">
                                            Preliminary estimate ${{ number_format($deal->tradeIn->estimated_value_low_in_cents / 100) }}&ndash;${{ number_format($deal->tradeIn->estimated_value_high_in_cents / 100) }}
                                        </p>
                                        <p style="margin:8px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.55; color:#9AA0A6;">
                                            Non-binding and self-reported — the dealership confirms your final value after a quick inspection, and can meet or beat it.@if ($deal->tradeIn->lien_owing_in_cents > 0) They'll also pay off your remaining balance.@endif
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- Deposit callout --}}
                <tr>
                    <td style="padding:16px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#E7F8F1; border-radius:12px;">
                            <tr>
                                <td style="padding:18px 22px;">
                                    <p style="margin:0 0 3px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700; color:#0B7355;">
                                        ${{ number_format($deal->deposit_in_cents / 100) }} deposit held
                                    </p>
                                    <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#1B6B53;">
                                        It's fully refundable, and it comes straight off your purchase price — it isn't an extra charge.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- What happens next --}}
                <tr>
                    <td style="padding:28px 36px 0;">
                        <p style="margin:0 0 14px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                            What happens next
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="28" valign="top" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:800; color:#F5631F; line-height:1.5;">1.</td>
                                <td style="padding-bottom:12px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#3A3F46;">
                                    {{ $deal->vehicle->dealer?->name ?? 'The dealership' }}'s finance office reaches out shortly to confirm your real financing terms. Every payment figure you've seen is an estimate until they confirm it.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:800; color:#F5631F; line-height:1.5;">2.</td>
                                <td style="padding-bottom:12px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#3A3F46;">
                                    Your identity is already verified — one less thing to sort out at delivery.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:800; color:#F5631F; line-height:1.5;">3.</td>
                                <td style="padding-bottom:12px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#3A3F46;">
                                    Upload any remaining documents in My Garage so the dealership can finalize your deal without delays.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:800; color:#F5631F; line-height:1.5;">4.</td>
                                <td style="padding-bottom:12px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#3A3F46;">
                                    Once your terms are confirmed, you review and e-sign your paperwork.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:800; color:#F5631F; line-height:1.5;">5.</td>
                                <td style="font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#3A3F46;">
                                    You confirm the handover together and drive off — everything stays tracked in your My Garage.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Handover --}}
                <tr>
                    <td style="padding:24px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F4F2; border-radius:12px;">
                            <tr>
                                <td style="padding:16px 20px;">
                                    <p style="margin:0 0 3px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">
                                        Handover
                                    </p>
                                    <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:600; color:#16181D;">
                                        {{ $deal->handover_summary }}
                                    </p>
                                    <p style="margin:4px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; color:#9AA0A6;">
                                        The dealership confirms the exact time with you.
                                    </p>
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
                                <td align="center" bgcolor="#F5631F" style="border-radius:10px;">
                                    <a href="{{ route('garage') }}" target="_blank" style="display:inline-block; padding:14px 30px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700; color:#FFFFFF; text-decoration:none; border-radius:10px;">
                                        Open My Garage →
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
                                <td style="border-top:1px solid #ECECEA; padding-top:20px;">
                                    <p style="margin:0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; line-height:1.6; color:#9AA0A6;">
                                        Trueleads handles your reservation and checkout. {{ $deal->vehicle->dealer?->name ?? 'The dealership' }} owns the vehicle, the financing and the delivery, and confirms all final figures with you before anything is signed.
                                    </p>
                                    <p style="margin:10px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:12px; line-height:1.6; color:#C2C6CB;">
                                        Sent because you placed a reservation at Trueleads.
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
