{{--
    The protection items the buyer added, as lines in the checkout rail's
    breakdown. Expects $items (checkout's flaggedProtection()) and $financing:
    whether the buyer is financing, since biweekly prices only apply then.
--}}
@if (count($items) > 0)
    <div class="rail-group">
        <div class="rail-included">Protection &amp; add-ons</div>
        @foreach ($items as $item)
            @php
                $biweeklyPriced = $item['basis'] === \App\Models\ProtectionProduct::BASIS_BIWEEKLY;
                $dollars = $item['amount'] === null ? '' : '$' . number_format($item['amount'] / 100, $item['amount'] % 100 === 0 ? 0 : 2);
                $value = match (true) {
                    $item['amount'] === null          => 'Dealer will quote',
                    $biweeklyPriced && ! $financing   => 'With financing',
                    $biweeklyPriced                   => '+' . $dollars . '/bw',
                    $financing                        => $dollars . ' financed',
                    default                           => '+' . $dollars,
                };
            @endphp
            <div class="rail-line sub"><span>{{ $item['name'] }}{{ $item['kind'] === 'plan' ? ' plan' : '' }}</span><span>{{ $value }}</span></div>
        @endforeach
    </div>
@endif
