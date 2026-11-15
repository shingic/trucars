@php($documents = $deal->documents()->outstanding()->get())
<x-email::layout title="Your deal is finalized"
    :preheader="'Your ' . $deal->vehicle_label . ' deal is done. Add proof of insurance so it\'s ready to drive home.'"
    :link="route('garage')"
    reason="Sent because your deal was finalized by the dealership.">
    <x-email::heading>You're nearly there, {{ $deal->first_name }}</x-email::heading>
    <x-email::text>
        {{ $deal->seller_name }} has finalized the deal on your {{ $deal->vehicle_label }}.
        @if ($documents->count() > 1)
            Before the car can be handed over, they still need the items below, including proof of insurance.
        @else
            The last thing they need is proof of insurance, since the car can't be handed over without it.
        @endif
    </x-email::text>
    <x-email::callout lead="How to get it:">call your insurer and tell them you're buying a {{ $deal->vehicle_label }}. They'll need the VIN, <strong>{{ $deal->vehicle()->withoutGlobalScopes()->value('vin') }}</strong>. Ask them to email you a pink slip (liability card) or a binder letter, then upload it.</x-email::callout>
    @include('mail.partials.documents')
    <x-email::button :url="route('garage')">Upload proof of insurance</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
