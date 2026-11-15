<x-email::layout :title="$updated ? 'Your financing terms were updated' : 'You\'re approved'"
    :preheader="$deal->financing_lender . ' approved your ' . $deal->vehicle_label . '. Here are your terms.'"
    :link="route('garage')"
    reason="Sent because the dealership entered your financing approval.">
    <x-email::heading>{{ $updated ? "New terms, {$deal->first_name}" : "You're approved, {$deal->first_name}" }}</x-email::heading>
    <x-email::text>
        @if ($updated)
            {{ $deal->seller_name }} updated the financing on your {{ $deal->vehicle_label }}. These are the terms now.
        @else
            {{ $deal->financing_lender }} approved your financing for the {{ $deal->vehicle_label }}. Here are the terms {{ $deal->seller_name }} got back.
        @endif
    </x-email::text>
    @include('mail.partials.financing-terms')
    <x-email::callout lead="Nothing is final yet:">you'll review and sign the full agreement with the dealership first.</x-email::callout>
    <x-email::button :url="route('garage')">See it in My Garage</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
