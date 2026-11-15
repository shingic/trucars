<x-email::layout title="Still needed for your reservation"
    :preheader="'Upload these in My Garage so ' . $deal->seller_name . ' can keep your ' . $deal->vehicle_label . ' moving.'"
    :link="route('garage')"
    reason="Sent because these documents are still outstanding on your reservation.">
    <x-email::heading>Still needed, {{ $deal->first_name }}</x-email::heading>
    <x-email::text>{{ $deal->seller_name }} is waiting on {{ $documents->count() === 1 ? 'one document' : 'a couple of documents' }} for your {{ $deal->vehicle_label }}. It takes a minute from your phone.</x-email::text>
    @include('mail.partials.documents')
    <x-email::button :url="route('garage')">Upload in My Garage</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
