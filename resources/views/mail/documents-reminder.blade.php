@extends('mail.layout')

@section('title', 'Still needed for your reservation')
@section('preheader', "Upload these in My Garage so {$deal->seller_name} can keep your {$deal->vehicle_label} moving.")
@section('tag', 'Reminder')
@section('headline', "Still needed, {$deal->first_name}")
@section('intro')
    {{ $deal->seller_name }} is waiting on {{ $documents->count() === 1 ? 'one document' : 'a couple of documents' }} for your {{ $deal->vehicle_label }}. It takes a minute from your phone.
@endsection

@section('body')
    @include('mail.partials.documents')
@endsection

@section('cta', 'Upload in My Garage →')
@section('reason', 'Sent because these documents are still outstanding on your reservation.')
