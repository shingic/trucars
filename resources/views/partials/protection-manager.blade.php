{{--
    One dealership's protection menu (ManagesDealerProtection). Expects
    $sections (from protectionSections()) and $canManage.
--}}
@php
    use App\Models\ProtectionProduct;

    $blurbs = [
        ProtectionProduct::KIND_PLAN      => 'The coverage tiers buyers compare on the payment step.',
        ProtectionProduct::KIND_GAP       => 'Offered on the Extras step, with an example of how GAP works.',
        ProtectionProduct::KIND_ADDON     => 'Optional protection buyers can tick on the Extras step.',
        ProtectionProduct::KIND_ACCESSORY => 'Mats, racks, tints and the like, which buyers can ask for on the Extras step.',
    ];
@endphp
@include('partials.protection-styles')

<div class="pm">
    @foreach (ProtectionProduct::KINDS as $kind => $kindLabel)
        <section class="pm-sec" wire:key="kind-{{ $kind }}">
            <div class="pm-head">
                <h2>{{ $kindLabel }}</h2>
                @if ($canManage)
                    <button type="button" class="pm-btn" wire:click="startAddProduct('{{ $kind }}')">Add your own</button>
                @endif
            </div>
            <p class="pm-note">{{ $blurbs[$kind] }}</p>

            @forelse ($sections[$kind] as $row)
                @php $product = $row['product']; @endphp
                <div class="pm-row {{ $row['shown'] ? '' : 'is-hidden' }}" wire:key="product-{{ $product->id }}">
                    <div class="pm-main">
                        <div class="pm-name">
                            {{ $product->name }}
                            <span class="pm-tag {{ $row['own'] ? 'own' : '' }}">{{ $row['own'] ? 'Yours' : 'TruCars default' }}</span>
                            @if ($product->is_featured) <span class="pm-tag star">Recommended</span> @endif
                            @unless ($row['shown']) <span class="pm-tag off">Hidden from buyers</span> @endunless
                        </div>
                        @if ($product->description) <div class="pm-desc">{{ $product->description }}</div> @endif
                        @if ($product->features)
                            <ul class="pm-feats">
                                @foreach ($product->features as $feature) <li>{{ $feature }}</li> @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="pm-price {{ $row['price'] ? '' : 'none' }}">
                        {{ $row['price'] ?? 'No price shown' }}
                        @if ($row['repriced']) <small>Your price · TruCars: {{ $row['basePrice'] ?? 'none' }}</small> @endif
                    </div>
                    @if ($canManage)
                        <div class="pm-actions">
                            @if ($row['own'])
                                <button type="button" class="pm-btn" wire:click="startEditOwn({{ $product->id }})">Edit</button>
                                <button type="button" class="pm-btn danger" wire:click="removeOwnProduct({{ $product->id }})" wire:confirm="Remove {{ $product->name }}? Buyers won't see it any more.">Remove</button>
                            @else
                                <button type="button" class="pm-btn" wire:click="startPricing({{ $product->id }})">Set your price</button>
                                @if ($row['repriced'])
                                    <button type="button" class="pm-btn" wire:click="resetPricing({{ $product->id }})">Use TruCars price</button>
                                @endif
                                <button type="button" class="pm-btn" wire:click="toggleDefault({{ $product->id }})">{{ $row['shown'] ? 'Hide' : 'Show' }}</button>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($canManage && $pricingProductId === $product->id)
                    <form class="pm-inline" wire:submit="savePricing" wire:key="pricing-{{ $product->id }}">
                        <span>Your price for <b>{{ $product->name }}</b></span>
                        <div class="pf-money"><b>$</b><input type="number" step="0.01" min="0" wire:model="pricingAmount" inputmode="decimal" style="width:130px;padding:7px 10px;"></div>
                        <span style="color:var(--ink-3);font-size:13.5px;">{{ ProtectionProduct::BASES[$product->price_basis] }} · blank shows no price</span>
                        <button type="submit" class="pm-btn primary">Save</button>
                        <button type="button" class="pm-btn" wire:click="cancelPricing">Cancel</button>
                        @error('pricingAmount') <em>{{ $message }}</em> @enderror
                    </form>
                @endif

                @if ($canManage && $showProductForm && $editingProductId === $product->id)
                    @include('partials.protection-form', ['saveAction' => 'saveOwnProduct'])
                @endif
            @empty
                <div class="pm-empty">Nothing here yet.</div>
            @endforelse

            @if ($canManage && $showProductForm && $editingProductId === null && $productKind === $kind)
                @include('partials.protection-form', ['saveAction' => 'saveOwnProduct'])
            @endif
        </section>
    @endforeach
</div>
