@extends('mail.layout')

@section('title', 'One more thing for your reservation')
@section('preheader', "{$deal->seller_name} needs one more thing for your {$deal->vehicle_label}.")
@section('tag', 'Documents')
@section('headline', "One more thing, {$deal->first_name}")
@section('intro')
    To keep your {{ $deal->vehicle_label }} moving, {{ $deal->seller_name }} needs the item below. Upload it in My Garage.
@endsection

@section('body')
    @include('mail.partials.documents')
@endsection

@section('cta', 'Upload in My Garage →')
@section('reason', 'Sent because the dealership asked for more information on your reservation.')
