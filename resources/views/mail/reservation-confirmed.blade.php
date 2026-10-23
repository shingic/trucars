<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Your reservation is confirmed</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">
    Your {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }} is reserved — reference {{ $deal->reference }}. Your $150 deposit is refundable and credited to your purchase.
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
                                    Reservation confirmed
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Vehicle hero photo — omitted cleanly when the feed has no photo --}}
                @if ($deal->vehicle->primary_photo_url)
                    <tr>
                        <td style="padding:0; font-size:0; line-height:0;">
                            <img src="{{ $deal->vehicle->primary_photo_url }}" alt="{{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}" width="600" style="display:block; width:100%; max-width:600px; height:auto; border:0; outline:none; text-decoration:none;">
                        </td>
                    </tr>
                @endif

                {{-- Hero --}}
                <tr>
                    <td style="padding:38px 36px 8px;">
                        <h1 style="margin:0 0 12px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:34px; line-height:1; font-weight:700; letter-spacing:0; text-transform:uppercase; color:#071437;">
                            You're reserved, {{ $deal->first_name }}.
                        </h1>
                        <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#4B5675;">
                            Your car is locked in. Here's your full reservation, what's in your price, and what happens next.
                        </p>
                    </td>
                </tr>

                {{-- Reference chip --}}
                <tr>
                    <td style="padding:18px 36px 0;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background-color:#071437; border-radius:3px; padding:6px 12px; font-family:'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size:13px; font-weight:700; letter-spacing:0.02em; color:#FFFFFF;">
                                    Reference&nbsp;&nbsp;{{ $deal->reference }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Vehicle card --}}
                <tr>
                    <td style="padding:22px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                            <tr>
                                <td style="padding:20px 22px;">
                                    <p style="margin:0 0 4px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                        Your vehicle
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:18px; font-weight:700; color:#071437;">
                                        {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}
                                    </p>
                                    @if ($deal->vehicle->trim)
                                        <p style="margin:5px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; color:#4B5675;">
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
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                            <tr>
                                <td style="padding:20px 22px;">
                                    <p style="margin:0 0 8px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                        Your all-in price
                                    </p>
                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                        <tr>
                                            <td style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:30px; line-height:1; font-weight:700; color:#071437;">
                                                {{ $deal->vehicle->display_price }}
                                            </td>
                                            <td align="right" style="font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; color:#78829D;">
                                                @if ($deal->purchase_type === 'finance' && $deal->term_months)
                                                    {{ $deal->term_months }}-month finance estimate
                                                @elseif ($deal->purchase_type === 'lease')
                                                    Lease estimate
                                                @else
                                                    Cash
                                                @endif
                                            </td>
                                        </tr>
                                    </table>
                                    <p style="margin:10px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.55; color:#4B5675;">
                                        Freight, PDI, admin and the OMVIC fee are already inside this price — no surprise add-ons. Only HST and licensing are added on top.
                                    </p>

                                    @if (count($deal->fees_by_kind['included']))
                                        <div style="border-top:1px solid #D5D7DD; margin-top:16px; padding-top:14px;"></div>
                                        <p style="margin:0 0 8px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                            Already included in this price
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($deal->fees_by_kind['included'] as $includedFee)
                                                <tr>
                                                    <td style="padding:3px 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#4B5675;">
                                                        {{ $includedFee['label'] }}
                                                    </td>
                                                    <td align="right" style="padding:3px 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#78829D;">
                                                        ${{ number_format($includedFee['amount_in_cents'] / 100, 2) }} included
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    @endif

                                    @if ($deal->fees_by_kind['passThroughTotalInCents'] > 0)
                                        <div style="border-top:1px solid #D5D7DD; margin-top:16px; padding-top:14px;"></div>
                                        <p style="margin:0 0 8px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                            Added at delivery · never financed
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($deal->fees_by_kind['passThrough'] as $passThroughFee)
                                                <tr>
                                                    <td style="padding:3px 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#252F4A;">
                                                        {{ $passThroughFee['label'] }}
                                                    </td>
                                                    <td align="right" style="padding:3px 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; font-weight:600; color:#071437;">
                                                        ${{ number_format($passThroughFee['amount_in_cents'] / 100, 2) }}
                                                    </td>
                                                </tr>
                                            @endforeach
                                            <tr>
                                                <td style="padding:3px 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#252F4A;">
                                                    HST
                                                </td>
                                                <td align="right" style="padding:3px 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13.5px; color:#78829D;">
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
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                                <tr>
                                    <td style="padding:20px 22px;">
                                        <p style="margin:0 0 4px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                            Your trade-in
                                        </p>
                                        <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:16px; font-weight:700; color:#071437;">
                                            {{ $deal->tradeIn->model_year }} {{ $deal->tradeIn->make }} {{ $deal->tradeIn->model }}
                                        </p>
                                        <p style="margin:5px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; color:#4B5675;">
                                            Preliminary estimate ${{ number_format($deal->tradeIn->estimated_value_low_in_cents / 100) }}&ndash;${{ number_format($deal->tradeIn->estimated_value_high_in_cents / 100) }}
                                        </p>
                                        <p style="margin:8px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.55; color:#78829D;">
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
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#E7F8F1; border-left:4px solid #17C653;">
                            <tr>
                                <td style="padding:18px 22px;">
                                    <p style="margin:0 0 3px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700; color:#0B7355;">
                                        ${{ number_format($deal->deposit_in_cents / 100) }} deposit held
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#1B6B53;">
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
                        <p style="margin:0 0 14px; padding-top:14px; border-top:2px solid #071437; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:20px; line-height:1.1; font-weight:700; text-transform:uppercase; color:#071437;">
                            What happens next
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">1.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    {{ $deal->seller_name }}'s finance office reaches out shortly to confirm your real financing terms. Every payment figure you've seen is an estimate until they confirm it.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">2.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Your identity is already verified — one less thing to sort out at delivery.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">3.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    @if ($deal->purchase_type === 'cash')
                                        Once the deal is finalized, we'll email you to upload proof of insurance in My Garage.
                                    @else
                                        Upload your two most recent pay stubs and a void cheque in My Garage, so the lender can approve you without delays. Once the deal is finalized, we'll ask for proof of insurance.
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">4.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Once your terms are confirmed, you review and e-sign your paperwork.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">5.</td>
                                <td style="font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    You confirm the handover together and drive off — everything stays tracked in your My Garage.
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Handover --}}
                <tr>
                    <td style="padding:24px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FFFFFF; border:1px solid #D5D7DD; border-top:2px solid #071437;">
                            <tr>
                                <td style="padding:16px 20px;">
                                    <p style="margin:0 0 3px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                        Handover
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; font-weight:600; color:#071437;">
                                        {{ $deal->handover_summary }}
                                    </p>
                                    <p style="margin:4px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; color:#78829D;">
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
                                <td align="center" bgcolor="#1B84FF" style="border-radius:3px;">
                                    <a href="{{ route('garage') }}" target="_blank" style="display:inline-block; padding:14px 28px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:3px;">
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
                                <td style="border-top:2px solid #071437; padding-top:20px;">
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#4B5675;">
                                        TruCars handles your reservation and checkout. {{ $deal->seller_name }} owns the vehicle, the financing and the delivery, and confirms all final figures with you before anything is signed.
                                    </p>
                                    <p style="margin:10px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#78829D;">
                                        Sent because you placed a reservation at TruCars.
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
