@extends('mail.layout')

@section('title', 'Your reservation was ' . ($byDealer ? 'declined' : 'cancelled'))
@section('preheader', $refunded ? 'Your $' . number_format($deal->deposit_in_cents / 100) . ' deposit has been refunded to your card.' : "Your reservation {$deal->reference} is no longer active.")
@section('tag', $byDealer ? 'Reservation declined' : 'Reservation cancelled')
@section('headline', "Sorry, {$deal->first_name}")
@section('intro')
    @if ($byDealer)
        {{ $deal->seller_name }} isn't able to go ahead with your reservation for the {{ $deal->vehicle_label }}.
    @else
        Your reservation for the {{ $deal->vehicle_label }} has been cancelled.
    @endif
    @if ($refunded)
        Your ${{ number_format($deal->deposit_in_cents / 100) }} deposit has been refunded to your card. It can take 5 to 10 business days to show on your statement.
    @endif
@endsection

@if (filled($note))
    @section('body')
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#FFFFFF; border:1px solid #D5D7DD; border-top:2px solid #071437;">
            <tr>
                <td style="padding:16px 20px; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                    <p style="margin:0 0 4px; font-family:'Barlow Semi Condensed','Barlow',Arial,sans-serif; font-size:14px; font-weight:700; letter-spacing:0.02em; text-transform:uppercase; color:#071437;">A note from {{ $byDealer ? $deal->seller_name : 'TruCars' }}</p>
                    <p style="margin:0; font-size:14px; line-height:1.6; color:#071437;">{{ $note }}</p>
                </td>
            </tr>
        </table>
    @endsection
@endif

@section('cta', 'Browse other cars →')
@section('cta_url', route('shop'))
@section('reason', 'Sent because your reservation is no longer active.')
