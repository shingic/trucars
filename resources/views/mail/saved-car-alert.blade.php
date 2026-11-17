@use('App\Support\Email\Theme', 'T')
@php
    $brand = config('site.name');
    $name = fn ($car) => trim("{$car->model_year} {$car->make} {$car->model}");
    $lead = $drops->first()['car'] ?? $gone->first() ?? $still->first();
    $copy = match ($kind) {
        'saved_price_drop' => [
            'headline' => $drops->count() === 1 ? 'A car you saved just got cheaper' : 'Cars you saved just got cheaper',
            'intro'    => "Good news, {$firstName}. Prices are all-in, so what you see is what you pay before sales tax and licensing.",
        ],
        'saved_gone' => [
            'headline' => 'Gone, but these are close',
            'intro'    => 'The ' . $name($gone->first()) . " you saved has been reserved or taken off the market. Here are a few like it that are still available.",
        ],
        default => [
            'headline' => "Still thinking it over, {$firstName}?",
            'intro'    => 'The ' . $name($still->first()) . " you saved is still here. Reserve it for \$150, fully refundable before pickup, and nobody else can take it while you decide.",
        ],
    };
    $leadUrl = $lead && $kind !== 'saved_gone' ? url('/cars/' . $lead->id) : null;
@endphp
<x-email::layout :title="$copy['headline']" :preheader="$copy['intro']" :link="$leadUrl"
    :reason="'You\'re getting this because you saved this car on ' . $brand . '.'"
    :unsubscribe="['user' => $user, 'type' => \App\Support\Marketing\EmailTypes::forKind($kind)]">
    @if ($leadUrl)
        <x-email::photo :src="$lead->primary_photo_url" :alt="$name($lead)" :href="$leadUrl" />
    @endif
    <x-email::heading>{{ $copy['headline'] }}</x-email::heading>
    <x-email::text>{{ $copy['intro'] }}</x-email::text>

    @if ($drops->isNotEmpty() || $still->isNotEmpty())
        <x-email::section>{{ $drops->isNotEmpty() ? 'Price drops' : 'Still available' }}</x-email::section>
        @foreach ($drops as $item)
            <x-email::car :car="$item['car']" :thumb="false">
                <span style="font-weight:700; color:{{ T::GOOD }};">${{ number_format(($item['was'] - $item['car']->price_in_cents) / 100) }} less</span> · was <s>${{ number_format($item['was'] / 100) }}</s>
            </x-email::car>
        @endforeach
        @foreach ($still as $car)
            <x-email::car :car="$car" :thumb="false" />
        @endforeach
    @endif

    @if ($gone->isNotEmpty() && $kind !== 'saved_gone')
        <x-email::text small top="14">No longer available: {{ $gone->map(fn ($car) => $name($car))->implode(', ') }}.</x-email::text>
    @endif

    @if ($leadUrl)
        <div style="height:14px; line-height:14px; font-size:0;">&nbsp;</div>
        <x-email::button :url="$leadUrl">See it and reserve</x-email::button>
    @endif

    @if ($suggestions->isNotEmpty())
        <x-email::section>{{ $kind === 'saved_gone' ? 'Still available' : 'You might also like' }}</x-email::section>
        @foreach ($suggestions as $other)
            <x-email::car :car="$other" />
        @endforeach
    @endif
</x-email::layout>
