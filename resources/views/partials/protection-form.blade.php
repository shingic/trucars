{{--
    Add/edit form for one protection product (EditsProtectionProduct).
    Expects $saveAction: the component method that saves it.
--}}
@php use App\Models\ProtectionProduct; @endphp
<form class="pf" wire:submit="{{ $saveAction }}">
    <div class="pf-title">{{ $editingProductId ? 'Edit ' . $productName : 'Add to ' . strtolower(ProtectionProduct::KINDS[$productKind]) }}</div>

    <div class="pf-grid">
        <label class="pf-field pf-wide">
            <span>Name</span>
            <input type="text" wire:model="productName" maxlength="80" placeholder="{{ $productKind === ProtectionProduct::KIND_ACCESSORY ? 'e.g. All-weather floor mats' : 'e.g. Tire & rim cover' }}">
            @error('productName') <em>{{ $message }}</em> @enderror
        </label>

        <label class="pf-field pf-wide">
            <span>Short description <small>shown under the name</small></span>
            <input type="text" wire:model="productDescription" maxlength="200">
            @error('productDescription') <em>{{ $message }}</em> @enderror
        </label>

        @if ($productKind === ProtectionProduct::KIND_PLAN)
            <label class="pf-field pf-wide">
                <span>What's included <small>one per line</small></span>
                <textarea wire:model="productFeatures" rows="5" placeholder="3-year / 60,000 km warranty&#10;Roadside assistance"></textarea>
                @error('productFeatures') <em>{{ $message }}</em> @enderror
            </label>
        @endif

        <label class="pf-field">
            <span>Price buyers see <small>leave blank for none</small></span>
            <div class="pf-money"><b>$</b><input type="number" step="0.01" min="0" wire:model="productAmount" inputmode="decimal"></div>
            @error('productAmount') <em>{{ $message }}</em> @enderror
        </label>

        <label class="pf-field">
            <span>Priced as</span>
            <select wire:model="productBasis">
                @foreach (ProtectionProduct::BASES as $basis => $basisLabel)
                    <option value="{{ $basis }}">{{ $basisLabel }}</option>
                @endforeach
            </select>
        </label>

        @if ($productKind === ProtectionProduct::KIND_PLAN)
            <label class="pf-check pf-wide">
                <input type="checkbox" wire:model="productFeatured">
                Mark as recommended
            </label>
        @endif
    </div>

    <div class="pf-actions">
        <button type="submit" class="pm-btn primary">Save</button>
        <button type="button" class="pm-btn" wire:click="cancelProductForm">Cancel</button>
    </div>
</form>
