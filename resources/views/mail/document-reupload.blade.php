@extends('mail.layout')

@section('title', 'Please re-upload a document')
@section('preheader', "{$deal->seller_name} needs a new copy of your " . strtolower($document->name) . '.')
@section('tag', 'Documents')
@section('headline', 'One more try on your ' . strtolower($document->name))
@section('intro')
    {{ $deal->seller_name }} needs a new copy of your {{ strtolower($document->name) }} for your {{ $deal->vehicle_label }}.
    @if (filled($reason))
        Their note: <strong style="color:#16181D;">"{{ $reason }}"</strong>
    @endif
@endsection

@section('cta', 'Upload a new copy →')
@section('reason', 'Sent because the dealership asked for a new copy of a document on your reservation.')
