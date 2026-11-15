<x-email::layout title="One more thing for your reservation"
    :preheader="$deal->seller_name . ' needs one more thing for your ' . $deal->vehicle_label . '.'"
    :link="route('garage')"
    reason="Sent because the dealership asked for more information on your reservation.">
    <x-email::heading>One more thing, {{ $deal->first_name }}</x-email::heading>
    <x-email::text>To keep your {{ $deal->vehicle_label }} moving, {{ $deal->seller_name }} needs the item below. Upload it in My Garage.</x-email::text>
    @include('mail.partials.documents')
    <x-email::button :url="route('garage')">Upload in My Garage</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
