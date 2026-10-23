<?php

use App\Livewire\Concerns\EditsProtectionProduct;
use App\Models\AuditLog;
use App\Models\DealerProtectionSetting;
use App\Models\ProtectionProduct;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — Settings › Protection & add-ons. The TruCars default list
| every dealership starts with: coverage plans, GAP, add-ons and accessories.
| Dealerships can hide a default or set their own price for it, and add their
| own items (managed on their dealer page or in their portal). settings.manage.
*/
new #[Layout('layouts.dealer')] #[Title('Protection & add-ons')] class extends Component {
    use EditsProtectionProduct;

    public function mount(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
    }

    public function startEdit(int $productId): void
    {
        $this->fillProductForm($this->defaultProduct($productId));
    }

    public function saveDefault(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $attributes = $this->validatedProduct();

        if ($this->editingProductId !== null) {
            $product = $this->defaultProduct($this->editingProductId);
            $before = $product->only(array_keys($attributes));
            $product->update($attributes);
            AuditLog::record('protection.updated', $product, $before, $product->only(array_keys($attributes)));
        } else {
            $position = (int) ProtectionProduct::defaults()->where('kind', $attributes['kind'])->max('position') + 1;
            $product = ProtectionProduct::create($attributes + ['dealer_id' => null, 'position' => $position]);
            AuditLog::record('protection.created', $product, null, $attributes);
        }

        $this->resetProductForm();
    }

    /** Switch a default off everywhere (or back on) without losing dealers' settings. */
    public function toggleActive(int $productId): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $product = $this->defaultProduct($productId);
        $product->update(['is_active' => ! $product->is_active]);

        AuditLog::record($product->is_active ? 'protection.activated' : 'protection.deactivated', $product);
    }

    public function move(int $productId, int $direction): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $product = $this->defaultProduct($productId);
        $siblings = ProtectionProduct::defaults()->where('kind', $product->kind)->ordered()->get()->values();
        $index = $siblings->search(fn ($sibling) => $sibling->id === $product->id);
        $swap = $siblings->get($index + ($direction < 0 ? -1 : 1));

        if ($swap === null) {
            return;
        }

        // Renumber the whole kind so equal positions can't leave the order stuck.
        $order = $siblings->pluck('id')->all();
        [$order[$index], $order[$index + ($direction < 0 ? -1 : 1)]] = [$swap->id, $product->id];
        foreach ($order as $position => $id) {
            ProtectionProduct::whereKey($id)->update(['position' => $position + 1]);
        }
    }

    public function remove(int $productId): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $product = $this->defaultProduct($productId);

        AuditLog::record('protection.removed', $product, ['name' => $product->name, 'kind' => $product->kind], null);
        $product->delete();
        $this->resetProductForm();
    }

    public function with(): array
    {
        $products = ProtectionProduct::defaults()->ordered()->get();
        $hidden = DealerProtectionSetting::where('is_enabled', false)->selectRaw('protection_product_id, count(*) as total')->groupBy('protection_product_id')->pluck('total', 'protection_product_id');
        $repriced = DealerProtectionSetting::whereNotNull('amount_in_cents')->selectRaw('protection_product_id, count(*) as total')->groupBy('protection_product_id')->pluck('total', 'protection_product_id');

        return [
            'sections' => collect(ProtectionProduct::KINDS)->mapWithKeys(fn ($label, $kind) => [$kind => $products->where('kind', $kind)->values()]),
            'hidden'   => $hidden,
            'repriced' => $repriced,
        ];
    }

    private function defaultProduct(int $productId): ProtectionProduct
    {
        return ProtectionProduct::defaults()->findOrFail($productId);
    }
}; ?>

@push('crumb')
    <span>Settings</span>
    <span>›</span>
    <span class="cur">Protection &amp; add-ons</span>
@endpush

<div>
    @include('partials.protection-styles')

    @include('partials.settings-tabs', ['activeTab' => 'protection'])

    <div class="pm-intro">
        <h1>Protection &amp; add-ons</h1>
        <p>The default list every dealership offers at checkout. Each dealership can hide an item or set its own price, and add items of its own. Buyers only flag interest; nothing is sold or charged online.</p>
    </div>

    @foreach (\App\Models\ProtectionProduct::KINDS as $kind => $kindLabel)
        <section class="pm-sec" wire:key="kind-{{ $kind }}">
            <div class="pm-head">
                <h2>{{ $kindLabel }}</h2>
                <button type="button" class="pm-btn" wire:click="startAddProduct('{{ $kind }}')">Add</button>
            </div>

            @forelse ($sections[$kind] as $product)
                <div class="pm-row {{ $product->is_active ? '' : 'is-hidden' }}" wire:key="product-{{ $product->id }}">
                    <div class="pm-main">
                        <div class="pm-name">
                            {{ $product->name }}
                            @if ($product->is_featured) <span class="pm-tag star">Recommended</span> @endif
                            @unless ($product->is_active) <span class="pm-tag off">Off everywhere</span> @endunless
                            @if ($hidden[$product->id] ?? 0) <span class="pm-tag">Hidden at {{ $hidden[$product->id] }} {{ $hidden[$product->id] === 1 ? 'dealer' : 'dealers' }}</span> @endif
                            @if ($repriced[$product->id] ?? 0) <span class="pm-tag">Own price at {{ $repriced[$product->id] }} {{ $repriced[$product->id] === 1 ? 'dealer' : 'dealers' }}</span> @endif
                        </div>
                        @if ($product->description) <div class="pm-desc">{{ $product->description }}</div> @endif
                        @if ($product->features)
                            <ul class="pm-feats">
                                @foreach ($product->features as $feature) <li>{{ $feature }}</li> @endforeach
                            </ul>
                        @endif
                    </div>
                    @php $price = \App\Models\ProtectionProduct::priceLabel($product->amount_in_cents, $product->price_basis); @endphp
                    <div class="pm-price {{ $price ? '' : 'none' }}">{{ $price ?? 'No price shown' }}</div>
                    <div class="pm-actions">
                        <button type="button" class="pm-btn" wire:click="move({{ $product->id }}, -1)" @disabled($loop->first) aria-label="Move up">↑</button>
                        <button type="button" class="pm-btn" wire:click="move({{ $product->id }}, 1)" @disabled($loop->last) aria-label="Move down">↓</button>
                        <button type="button" class="pm-btn" wire:click="startEdit({{ $product->id }})">Edit</button>
                        <button type="button" class="pm-btn" wire:click="toggleActive({{ $product->id }})">{{ $product->is_active ? 'Turn off' : 'Turn on' }}</button>
                        <button type="button" class="pm-btn danger" wire:click="remove({{ $product->id }})" wire:confirm="Remove {{ $product->name }} for every dealership? Their settings for it go too. Reservations already made keep what the buyer picked.">Remove</button>
                    </div>
                </div>

                @if ($showProductForm && $editingProductId === $product->id)
                    @include('partials.protection-form', ['saveAction' => 'saveDefault'])
                @endif
            @empty
                <div class="pm-empty">Nothing here yet.</div>
            @endforelse

            @if ($showProductForm && $editingProductId === null && $productKind === $kind)
                @include('partials.protection-form', ['saveAction' => 'saveDefault'])
            @endif
        </section>
    @endforeach
</div>
