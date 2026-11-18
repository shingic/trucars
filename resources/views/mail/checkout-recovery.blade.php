@php
    $brand = config('site.name');
    $car = $draft->vehicle;
    $resume = $draft->resumeUrl();
    $copy = match ($kind) {
        'recovery_1h' => [
            'headline' => "Pick up where you left off, {$firstName}",
            'intro' => "You were partway through reserving the {$carName}. Everything you entered is saved: you stopped at {$draft->stepLabel()}, so it's only a few minutes to finish.",
            'cta' => 'Finish reserving',
        ],
        'recovery_1d' => [
            'headline' => "The {$carName} is still here",
            'intro' => "Nobody has reserved it yet. If it isn't quite right, here are a few similar cars close to you.",
            'cta' => 'Finish reserving',
        ],
        'recovery_3d' => [
            'headline' => 'Hold it for $150',
            'intro' => "The {$carName} is still available. Reserving takes it off the market while you decide, and the \$150 is fully refundable any time before pickup. It comes off the price when you buy.",
            'cta' => 'Reserve it',
        ],
        default => [
            'headline' => "Cars like the {$carName}",
            'intro' => 'Based on the car you were looking at, these are close to you and priced all-in.',
            'cta' => 'Back to the ' . $car->model,
        ],
    };
@endphp
<x-email::layout :title="$copy['headline']" :preheader="$copy['intro']" :link="$resume"
    :reason="'You\'re getting this because you started reserving a car on ' . $brand . '. Once you reserve, these follow-ups stop.'"
    :unsubscribe="['user' => $draft->user, 'type' => \App\Support\Marketing\EmailTypes::forKind($kind)]">
    @if ($kind !== 'recovery_7d')
        <x-email::photo :src="$car->primary_photo_url" :alt="$carName" :href="$resume" />
    @endif
    <x-email::heading>{{ $copy['headline'] }}</x-email::heading>
    <x-email::text>{{ $copy['intro'] }}</x-email::text>

    @if ($kind !== 'recovery_7d')
        <x-email::details label="Your car" :rows="[
            'Vehicle'    => trim($carName . ' ' . $car->trim),
            'Kilometres' => number_format((int) $car->kilometres) . ' km',
            'Where'      => $whereLine($car),
            'All-in price' => $car->display_price,
            'Sold by'    => ($seller = $car->dealerDisclosure()) ? collect([$seller->name, $seller->registration, $seller->address])->filter()->implode(' · ') : null,
        ]" />
    @endif

    @if ($kind === 'recovery_3d')
        <x-email::callout lead="Why reserve now:">the price is all-in (freight, prep and dealer fees included; only {{ $car->salesTax()['excludes'] }} and licensing are added), the $150 is refundable any time before pickup, and you choose the pickup day. The car is inspected and certified first.</x-email::callout>
    @endif

    <x-email::button :url="$resume">{{ $copy['cta'] }}</x-email::button>

    @if ($suggestions->isNotEmpty())
        <x-email::section>{{ $kind === 'recovery_7d' ? 'Picked for you' : 'Similar cars near you' }}</x-email::section>
        @foreach ($suggestions as $other)
            <x-email::car :car="$other" :detail="number_format((int) $other->kilometres) . ' km · ' . $whereLine($other)" />
        @endforeach
    @endif
</x-email::layout>
