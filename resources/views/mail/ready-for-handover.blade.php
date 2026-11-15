@php($delivery = $deal->handover_mode === 'delivery')
<x-email::layout title="Your car is ready"
    :preheader="'Your ' . $deal->vehicle_label . ' is prepped and ready.'"
    :link="route('garage')"
    reason="Sent because the dealership marked your car ready.">
    <x-email::heading>It's ready, {{ $deal->first_name }}</x-email::heading>
    <x-email::text>
        Your {{ $deal->vehicle_label }} is prepped and ready{{ $delivery ? ' for delivery' : ' for pickup' }}.
        @if ($deal->pickup_at === null)
            {{ $deal->seller_name }} will be in touch to set a time.
        @endif
    </x-email::text>
    <x-email::callout lead="Bring your driver's licence.">Your ${{ number_format($deal->deposit_in_cents / 100) }} deposit comes off what you owe.</x-email::callout>
    <x-email::details :label="$delivery ? 'Delivery' : 'Pickup'" :rows="[
        'Vehicle'        => $deal->vehicle_label,
        'When and where' => $deal->handover_summary,
        'Reference'      => $deal->reference,
    ]" />
    @if ($documents->isNotEmpty())
        <x-email::text>Before the handover, the dealership still needs:</x-email::text>
        @include('mail.partials.documents')
    @endif
    <x-email::button :url="route('garage')">Open My Garage</x-email::button>
    @include('mail.partials.deal-signoff')
</x-email::layout>
