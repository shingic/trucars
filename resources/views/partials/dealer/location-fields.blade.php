{{-- Rooftop address fields (inside .fields). Used by the new-dealer form and the Location tab. --}}
    <div class="field w4">
        <label for="streetAddress">Street address</label>
        <input id="streetAddress" type="text" wire:model="streetAddress" placeholder="45510 Yale Rd" maxlength="160">
        @error('streetAddress') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="unit">Unit <span class="opt">(optional)</span></label>
        <input id="unit" type="text" wire:model="unit" maxlength="30">
        @error('unit') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="city">City</label>
        <input id="city" type="text" wire:model="city" maxlength="80">
        @error('city') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="province">Province / territory</label>
        <select id="province" wire:model.live="province">
            <option value="">Choose…</option>
            @foreach (\App\Support\CanadianAddress::PROVINCES as $code => $provinceName)
                <option value="{{ $code }}">{{ $provinceName }}</option>
            @endforeach
        </select>
        @error('province') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="postalCode">Postal code</label>
        <input id="postalCode" type="text" wire:model="postalCode" placeholder="V2P 2M9" maxlength="7">
        @error('postalCode') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="country">Country</label>
        <select id="country" wire:model="country">
            <option value="">Choose…</option>
            <option value="CA">Canada</option>
        </select>
        @error('country') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="latitude">Latitude <span class="opt">(optional)</span></label>
        <input id="latitude" type="text" inputmode="decimal" wire:model="latitude" placeholder="49.151131">
        @error('latitude') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="longitude">Longitude <span class="opt">(optional)</span></label>
        <input id="longitude" type="text" inputmode="decimal" wire:model="longitude" placeholder="-121.963743">
        @error('longitude') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w6">
        <span class="hint">Coordinates power distance sorting for shoppers. There's no automatic geocoding yet — they come from the inventory feed or can be entered here.</span>
    </div>
    <div class="field w4">
        <label for="timezone">Timezone</label>
        <select id="timezone" wire:model="timezone">
            <option value="">Choose…</option>
            @foreach ($this->timezoneOptions() as $zone)
                <option value="{{ $zone }}">{{ $zone }}</option>
            @endforeach
        </select>
        @if ($this->suggestedTimezone() && $timezone !== '' && $timezone !== $this->suggestedTimezone())
            <span class="hint">Most of {{ \App\Support\CanadianAddress::PROVINCES[$province] ?? $province }} uses {{ $this->suggestedTimezone() }} — keep this only if the rooftop is in an exception area.</span>
        @elseif ($province === 'NU')
            <span class="hint">Nunavut spans three zones — choose the one this rooftop observes.</span>
        @else
            <span class="hint">Used for handover windows and dealer-local times. Filled from the province; correct it for exception areas.</span>
        @endif
        @error('timezone') <span class="err">{{ $message }}</span> @enderror
    </div>
