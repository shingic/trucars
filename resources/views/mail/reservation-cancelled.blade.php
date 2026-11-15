<x-email::layout :title="'Your reservation was ' . ($byDealer ? 'declined' : 'cancelled')"
    :preheader="$refunded ? 'Your $' . number_format($deal->deposit_in_cents / 100) . ' deposit has been refunded to your card.' : 'Your reservation ' . $deal->reference . ' is no longer active.'"
    :link="route('shop')"
    reason="Sent because your reservation is no longer active.">
    <x-email::heading>Sorry, {{ $deal->first_name }}</x-email::heading>
    <x-email::text>
        @if ($byDealer)
            {{ $deal->seller_name }} isn't able to go ahead with your reservation for the {{ $deal->vehicle_label }}.
        @else
            Your reservation for the {{ $deal->vehicle_label }} has been cancelled.
        @endif
    </x-email::text>
    @if ($refunded)
        <x-email::callout lead="Your deposit is on its way back:">${{ number_format($deal->deposit_in_cents / 100) }} has been refunded to your card. It can take 5 to 10 business days to show on your statement.</x-email::callout>
    @endif
    @if (filled($note))
        <x-email::callout :lead="'A note from ' . ($byDealer ? $deal->seller_name : config('site.name')) . ':'">{{ $note }}</x-email::callout>
    @endif
    <x-email::button :url="route('shop')">Browse other cars</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
