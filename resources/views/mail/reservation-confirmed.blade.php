@php
    $vehicle = $deal->vehicle;
    $carName = "{$vehicle->model_year} {$vehicle->make} {$vehicle->model}";
    $deposit = '$' . number_format($deal->deposit_in_cents / 100);
    $plan = match (true) {
        $deal->purchase_type === 'finance' && (bool) $deal->term_months => "{$deal->term_months}-month finance estimate",
        $deal->purchase_type === 'lease' => 'Lease estimate',
        default => 'Cash',
    };
    $included = collect($deal->fees_by_kind['included'])->mapWithKeys(fn ($fee) => [$fee['label'] => '$' . number_format($fee['amount_in_cents'] / 100, 2) . ' included'])->all();
    $tax = $vehicle->salesTax();
    $includedPhrase = \App\Support\ProvinceRules::includedFeesPhrase(collect($deal->fees_by_kind['included'])->pluck('label'));
    $atPickup = $deal->fees_by_kind['passThroughTotalInCents'] > 0
        ? collect($deal->fees_by_kind['passThrough'])->mapWithKeys(fn ($fee) => [$fee['label'] => '$' . number_format($fee['amount_in_cents'] / 100, 2)])->put($tax['label'], 'calculated at pickup')->all()
        : [];
@endphp
<x-email::layout title="Your reservation is confirmed"
    :preheader="'Your ' . $carName . ' is reserved, reference ' . $deal->reference . '. Your ' . $deposit . ' deposit is refundable and credited to your purchase.'"
    :link="route('garage')"
    reason="Sent because you placed a reservation at Trumo.">
    <x-email::photo :src="$vehicle->primary_photo_url" :alt="$carName" />
    <x-email::heading>Your {{ $carName }} is reserved</x-email::heading>
    <x-email::text>Hi {{ $deal->first_name }}, thanks for reserving with Trumo. Your car is locked in. Here's your full reservation, what's in your price, and what happens next.</x-email::text>
    <x-email::callout lead="What happens next:">{{ $deal->seller_name }}'s finance office reaches out shortly to confirm your real financing terms.</x-email::callout>

    <x-email::details label="Reservation" :rows="[
        'Vehicle'   => trim($carName . ' ' . $vehicle->trim),
        'Dealer'    => $deal->seller_name,
        'Reference' => $deal->reference,
        'Deposit'   => $deposit . ', fully refundable',
        'Plan'      => $plan,
        'Handover'  => $deal->handover_summary,
    ]" />

    <x-email::details label="Your all-in price" :rows="['Price' => $vehicle->display_price] + $included" />
    <x-email::text small>{{ ucfirst($includedPhrase) }} {{ str_contains($includedPhrase, ' & ') || str_contains($includedPhrase, ', ') ? 'are' : 'is' }} already inside this price, with no surprise add-ons. Only {{ $tax['excludes'] }} and licensing are added on top. Your {{ $deposit }} deposit comes straight off the purchase price. It isn't an extra charge.</x-email::text>

    @if ($atPickup)
        <x-email::details label="Added at pickup · never financed" :rows="$atPickup" />
    @endif

    @if ($deal->tradeIn)
        <x-email::details label="Your trade-in" :rows="[
            'Vehicle'               => $deal->tradeIn->model_year . ' ' . $deal->tradeIn->make . ' ' . $deal->tradeIn->model,
            'Preliminary estimate'  => '$' . number_format($deal->tradeIn->estimated_value_low_in_cents / 100) . '–$' . number_format($deal->tradeIn->estimated_value_high_in_cents / 100),
        ]" />
        <x-email::text small>Non-binding and self-reported. The dealership confirms your final value after a quick inspection, and can meet or beat it.@if ($deal->tradeIn->lien_owing_in_cents > 0) They'll also pay off your remaining balance.@endif</x-email::text>
    @endif

    <x-email::section>Step by step</x-email::section>
    <x-email::steps :items="[
        $deal->seller_name . '\'s finance office reaches out to confirm your real financing terms. Every payment figure you\'ve seen is an estimate until they confirm it.',
        'Your identity is already verified, so that\'s one less thing to sort out at pickup.',
        $deal->purchase_type === 'cash'
            ? 'Once the deal is finalized, we\'ll email you to upload proof of insurance in My Garage.'
            : 'Upload your two most recent pay stubs and a void cheque in My Garage, so the lender can approve you without delays. Once the deal is finalized, we\'ll ask for proof of insurance.',
        'Once your terms are confirmed, you review and e-sign your paperwork.',
        'You confirm the handover together and drive off. Everything stays tracked in My Garage.',
    ]" />

    <x-email::button :url="route('garage')">View your reservation</x-email::button>
    <x-email::text small>Trumo handles your reservation and checkout. {{ $deal->seller_name }} owns the vehicle, the financing and the handover, confirms the exact handover time with you, and confirms all final figures before anything is signed. Your reference is {{ $deal->reference }}.</x-email::text>
</x-email::layout>
