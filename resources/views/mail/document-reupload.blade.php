<x-email::layout title="Please re-upload a document"
    :preheader="$deal->seller_name . ' needs a new copy of your ' . strtolower($document->name) . '.'"
    :link="route('garage')"
    reason="Sent because the dealership asked for a new copy of a document on your reservation.">
    <x-email::heading>One more try on your {{ strtolower($document->name) }}</x-email::heading>
    <x-email::text>{{ $deal->seller_name }} needs a new copy of your {{ strtolower($document->name) }} for your {{ $deal->vehicle_label }}.</x-email::text>
    @if (filled($reason))
        <x-email::callout lead="Their note:">"{{ $reason }}"</x-email::callout>
    @endif
    <x-email::text small>A PDF or a clear phone photo works. Files go straight to {{ $deal->seller_name }} and are kept private.</x-email::text>
    <x-email::button :url="route('garage')">Upload a new copy</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
