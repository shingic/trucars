@php
    $vehicle = $deal->vehicle;
    $carName = "{$vehicle->model_year} {$vehicle->make} {$vehicle->model}";
    $deposit = '$' . number_format($deal->deposit_in_cents / 100);
@endphp
<x-email::layout title="New reservation"
    :preheader="$deal->customer_full_name . ' reserved a ' . $carName . ', reference ' . $deal->reference . '. Deposit placed, identity verified. Reach out to confirm financing.'"
    :link="route('dealer.login')"
    reason="Sent because a buyer reserved a vehicle from your inventory.">
    <x-email::photo :src="$vehicle->primary_photo_url" :alt="$carName" />
    <x-email::heading>{{ $deal->first_name }} just reserved a car</x-email::heading>
    <x-email::text>A deposit is down and identity is already verified. Here's the buyer and the car. Work it in your console.</x-email::text>
    <x-email::callout lead="{{ $deposit }} deposit placed · identity verified.">This is a committed buyer, with money down and ID checked at checkout. Not a cold lead.</x-email::callout>

    <x-email::details label="The buyer" :rows="[
        'Name'     => $deal->customer_full_name,
        'Phone'    => $deal->phone,
        'Email'    => $deal->email,
        'Location' => $deal->city ? $deal->city . ($deal->province ? ', ' . $deal->province : '') : null,
    ]" />

    <x-email::details label="The vehicle" :rows="[
        'Vehicle'   => trim($carName . ' ' . $vehicle->trim),
        'Price'     => $vehicle->display_price,
        'Plan'      => ucfirst($deal->purchase_type) . ($deal->purchase_type === 'finance' && $deal->term_months ? ' · ' . $deal->term_months . ' mo' : ''),
        'Reference' => $deal->reference,
    ]" />

    <x-email::section>What to do next</x-email::section>
    <x-email::steps :items="[
        'Reach out to confirm financing and final numbers. Any figures the buyer saw at checkout were estimates. Your finance office sets the real rate.',
        'Identity is verified, but your KYC/AML stays yours. Capture it as usual against the deal.',
        'Work the deal and advance the stages in your console. Every move syncs to the buyer\'s My Garage.',
    ]" />

    <x-email::button :url="route('dealer.login')">Open your console</x-email::button>
    <x-email::text small>Trumo originated this reservation and handled checkout. You own the vehicle, the financing and the delivery, and confirm all final figures with the buyer before anything is signed.</x-email::text>
</x-email::layout>
