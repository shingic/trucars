@extends('mail.layout')

@section('title', 'Your deal is finalized')
@section('preheader', "Your {$deal->vehicle_label} deal is done. Add proof of insurance so it's ready to drive home.")
@section('tag', 'Deal finalized')
@section('headline', "You're nearly there, {$deal->first_name}")
@php($documents = $deal->documents()->outstanding()->get())
@section('intro')
    {{ $deal->seller_name }} has finalized the deal on your {{ $deal->vehicle_label }}.
    @if ($documents->count() > 1)
        Before the car can be handed over, they still need the items below, including proof of insurance.
    @else
        The last thing they need is proof of insurance, since the car can't be handed over without it.
    @endif
@endsection

@section('body')
    @include('mail.partials.documents')
    <p style="margin:16px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:14px; line-height:1.6; color:#252F4A;">
        Call your insurer and tell them you're buying a {{ $deal->vehicle_label }}. They'll need the VIN, <strong>{{ $deal->vehicle()->withoutGlobalScopes()->value('vin') }}</strong>. Ask them to email you a pink slip (liability card) or a binder letter, then upload it.
    </p>
@endsection

@section('cta', 'Upload proof of insurance →')
@section('reason', 'Sent because your deal was finalized by the dealership.')
