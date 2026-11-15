@php
    $car = $vehicle->model_year . ' ' . $vehicle->make . ' ' . $vehicle->model;
    $href = url('/cars/' . $vehicle->id);
@endphp
<x-email::layout title="It's available again"
    :preheader="'The ' . $car . ' you were watching is back on sale. Reserve it before someone else does.'"
    :link="$href"
    reason="Sent once, because you tapped the bell on this car. We won't email you about it again."
    :unsubscribe="['user' => $buyer, 'type' => \App\Support\Marketing\EmailTypes::AVAILABILITY]">
    <x-email::photo :src="$vehicle->primary_photo_url" :alt="$car" :href="$href" />
    <x-email::heading>Good news, {{ $firstName }}. It's back.</x-email::heading>
    <x-email::text>The {{ $car }}{{ $vehicle->trim ? ' ' . $vehicle->trim : '' }} you were watching{{ $vehicle->display_location ? ' in ' . $vehicle->display_location : '' }} is available again.</x-email::text>
    <x-email::callout lead="First come, first served:">its last reservation didn't go through, so it's open to reserve right now.</x-email::callout>
    <x-email::details label="The car" :rows="[
        'Vehicle'      => trim($car . ' ' . $vehicle->trim),
        'Kilometres'   => number_format((int) $vehicle->kilometres) . ' km',
        'All-in price' => $vehicle->display_price,
    ]" />
    <x-email::button :url="$href">See it and reserve</x-email::button>
</x-email::layout>
