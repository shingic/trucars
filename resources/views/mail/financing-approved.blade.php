@extends('mail.layout')

@section('title', $updated ? 'Your financing terms were updated' : "You're approved")
@section('preheader', "{$deal->financing_lender} approved your {$deal->vehicle_label}. Here are your terms.")
@section('tag', $updated ? 'Terms updated' : 'Financing approved')
@section('headline', $updated ? "New terms, {$deal->first_name}" : "You're approved, {$deal->first_name}")
@section('intro')
    @if ($updated)
        {{ $deal->seller_name }} updated the financing on your {{ $deal->vehicle_label }}. These are the terms now.
    @else
        {{ $deal->financing_lender }} approved your financing for the {{ $deal->vehicle_label }}. Here are the terms {{ $deal->seller_name }} got back.
    @endif
@endsection

@section('body')
    @include('mail.partials.financing-terms')
    <p style="margin:12px 0 0; font-family:'Barlow', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size:13px; line-height:1.55; color:#99A1B7;">
        You'll review and sign the full agreement with the dealership before anything is final.
    </p>
@endsection

@section('cta', 'See it in My Garage →')
@section('reason', 'Sent because the dealership entered your financing approval.')
