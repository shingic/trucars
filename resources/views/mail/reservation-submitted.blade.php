<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>New reservation</title>
</head>
<body style="margin:0; padding:0; background-color:#F9F9F9; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">

{{-- Preheader: shows in the inbox preview, hidden in the body. --}}
<div style="display:none; max-height:0; overflow:hidden; font-size:1px; line-height:1px; color:#F9F9F9; opacity:0;">
    {{ $deal->customer_full_name }} reserved a {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }} — reference {{ $deal->reference }}. Deposit placed, identity verified. Reach out to confirm financing.
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
                                    New reservation
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
                            {{ $deal->first_name }} just reserved a car.
                        </h1>
                        <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; line-height:1.6; color:#4B5675;">
                            A deposit is down and identity is already verified. Here's the buyer and the car — work it in your console.
                        </p>
                    </td>
                </tr>

                {{-- Buyer card --}}
                <tr>
                    <td style="padding:22px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #D5D7DD; border-top:2px solid #071437;">
                            <tr>
                                <td style="padding:20px 22px;">
                                    <p style="margin:0 0 4px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">
                                        The buyer
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:18px; font-weight:700; color:#071437;">
                                        {{ $deal->customer_full_name }}
                                    </p>
                                    <p style="margin:7px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.6; color:#4B5675;">
                                        @if ($deal->phone)
                                            <a href="tel:{{ $deal->phone }}" style="color:#071437; text-decoration:none; font-weight:600;">{{ $deal->phone }}</a>
                                        @endif
                                        @if ($deal->phone && $deal->email)
                                            ·
                                        @endif
                                        @if ($deal->email)
                                            <a href="mailto:{{ $deal->email }}" style="color:#071437; text-decoration:none; font-weight:600;">{{ $deal->email }}</a>
                                        @endif
                                    </p>
                                    @if ($deal->city)
                                        <p style="margin:4px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; color:#78829D;">
                                            {{ $deal->city }}@if ($deal->province), {{ $deal->province }}@endif
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Reference chip --}}
                <tr>
                    <td style="padding:18px 36px 0;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background-color:#071437; border-radius:8px; padding:6px 12px; font-family:'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace; font-size:13px; font-weight:700; letter-spacing:0.02em; color:#FFFFFF;">
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
                                        The vehicle
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:18px; font-weight:700; color:#071437;">
                                        {{ $deal->vehicle->model_year }} {{ $deal->vehicle->make }} {{ $deal->vehicle->model }}
                                    </p>
                                    <p style="margin:5px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; color:#4B5675;">
                                        @if ($deal->vehicle->trim){{ $deal->vehicle->trim }} · @endif{{ $deal->vehicle->display_price }} · {{ ucfirst($deal->purchase_type) }}@if ($deal->purchase_type === 'finance' && $deal->term_months) · {{ $deal->term_months }} mo @endif
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Deposit / status callout --}}
                <tr>
                    <td style="padding:16px 36px 0;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#E7F8F1; border-left:4px solid #17C653;">
                            <tr>
                                <td style="padding:18px 22px;">
                                    <p style="margin:0 0 3px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:15px; font-weight:700; color:#0B7355;">
                                        ${{ number_format($deal->deposit_in_cents / 100) }} deposit placed · identity verified
                                    </p>
                                    <p style="margin:0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#1B6B53;">
                                        This is a committed buyer — money down and ID checked at checkout — not a cold lead.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- What to do next --}}
                <tr>
                    <td style="padding:28px 36px 0;">
                        <p style="margin:0 0 14px; padding-top:14px; border-top:2px solid #071437; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:20px; line-height:1.1; font-weight:700; text-transform:uppercase; color:#071437;">
                            What to do next
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">1.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Reach out to confirm financing and final numbers. Any figures the buyer saw at checkout were estimates — your finance office authors the real rate.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">2.</td>
                                <td style="padding-bottom:12px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Identity is verified, but your KYC/AML stays yours — capture it as usual against the deal.
                                </td>
                            </tr>
                            <tr>
                                <td width="28" valign="top" style="font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; color:#1B84FF; line-height:1.3;">3.</td>
                                <td style="font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.55; color:#252F4A;">
                                    Work the deal and advance the stages in your console — every move syncs to the buyer's My Garage.
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
                                    <a href="{{ route('dealer.login') }}" target="_blank" style="display:inline-block; padding:14px 28px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:17px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#FFFFFF; text-decoration:none; border-radius:8px;">
                                        Open your console →
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
                                        TruCars originated this reservation and handled checkout. You own the vehicle, the financing and the delivery, and confirm all final figures with the buyer before anything is signed.
                                    </p>
                                    <p style="margin:10px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.6; color:#78829D;">
                                        Sent because a buyer reserved a vehicle from your inventory.
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
