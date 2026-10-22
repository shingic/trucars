@php($delivery = $deal->handover_mode === 'delivery')
@extends('mail.layout')

@section('title', 'Your car is ready')
@section('preheader', "Your {$deal->vehicle_label} is prepped and ready.")
@section('tag', $delivery ? 'Ready for delivery' : 'Ready for pickup')
@section('headline', "It's ready, {$deal->first_name}")
@section('intro')
    Your {{ $deal->vehicle_label }} is prepped and ready.
    @if ($deal->pickup_at === null)
        {{ $deal->seller_name }} will be in touch to set a time.
    @endif
@endsection

@section('body')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#F4F4F2; border-radius:12px;">
        <tr>
            <td style="padding:16px 20px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                <p style="margin:0 0 3px; font-size:11px; font-weight:700; letter-spacing:0.06em; text-transform:uppercase; color:#9AA0A6;">When and where</p>
                <p style="margin:0; font-size:14px; font-weight:600; color:#16181D;">{{ $deal->handover_summary }}</p>
            </td>
        </tr>
    </table>
    @if ($documents->isNotEmpty())
        <p style="margin:20px 0 10px; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.6; color:#3A3F46;">
            Before the handover, the dealership still needs:
        </p>
        @include('mail.partials.documents')
    @endif
    <p style="margin:20px 0 0; font-family:'Geist', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.6; color:#3A3F46;">
        Bring your driver's licence. Your ${{ number_format($deal->deposit_in_cents / 100) }} deposit comes off what you owe.
    </p>
@endsection

@section('reason', 'Sent because the dealership marked your car ready.')
