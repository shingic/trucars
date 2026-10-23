{{-- Dealer info fields (inside .fields). Used by the new-dealer form and the Dealer info tab. --}}
    @php $settings = $this->brandSettings(); @endphp
    <div class="field w6">
        <label for="brandId">Franchise brand</label>
        @if ($dealerId === null)
            <select id="brandId" wire:model.live="brandId">
                <option value="">Choose a brand…</option>
                @foreach ($this->brands() as $brandOption)
                    <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                @endforeach
            </select>
            @error('brandId') <span class="err">{{ $message }}</span> @enderror
            <span class="hint">
                @if ($settings)
                    Will follow {{ $settings['brand']->name }} settings · {{ $settings['checklist'] ? $settings['checklist']->points_count . '-point CPO checklist' : 'no CPO checklist yet' }} · {{ $settings['offers'] }} current {{ $settings['offers'] === 1 ? 'offer' : 'offers' }}.
                @endif
                The brand’s CPO checklist and offers apply automatically. Changing it later is a separate, confirmed step.
            </span>
        @else
            <div class="brand-fixed">
                @if ($settings)
                    <span class="brand-chip">{{ $settings['brand']->name }}</span>
                    <span class="brand-follows-text">
                        Follows {{ $settings['brand']->name }} settings ·
                        {{ $settings['checklist'] ? $settings['checklist']->points_count . '-point CPO checklist' : 'no CPO checklist yet' }} ·
                        {{ $settings['offers'] }} current {{ $settings['offers'] === 1 ? 'offer' : 'offers' }}
                        @can('settings.manage') · <a href="{{ route('admin.brands.show', $settings['brand']) }}" wire:navigate>view</a> @endcan
                    </span>
                @else
                    <span class="brand-chip missing">No brand set</span>
                    <span class="brand-follows-text">This dealer follows no brand settings until one is set.</span>
                @endif
                @if ($this->canManage())
                    <button type="button" class="brand-change" wire:click="startBrandChange">{{ $settings ? 'Change brand…' : 'Set brand…' }}</button>
                @endif
            </div>
        @endif
    </div>
    <div class="field w6">
        <label for="name">Public dealership name</label>
        <input id="name" type="text" wire:model="name" maxlength="120">
        @error('name') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field">
        <label for="registrationNumber">Dealer registration number <span class="opt">(OMVIC, VSA, AMVIC…)</span></label>
        <input id="registrationNumber" type="text" wire:model="registrationNumber" maxlength="40">
        @error('registrationNumber') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field">
        <label for="groupName">Dealer group <span class="opt">(optional)</span></label>
        <input id="groupName" type="text" wire:model="groupName" maxlength="120" list="dealer-groups" autocomplete="off">
        <datalist id="dealer-groups">@foreach ($this->groupNames() as $existingGroup)<option value="{{ $existingGroup }}"></option>@endforeach</datalist>
        @error('groupName') <span class="err">{{ $message }}</span> @enderror
        <span class="hint">The organization that owns this dealer. It doesn't give anyone access to it.</span>
    </div>
    <div class="field">
        <label for="feedRooftopId">Main store feed rooftop id</label>
        <input id="feedRooftopId" type="text" value="{{ $feedRooftopId ?? 'Set by the first inventory import' }}" readonly>
    </div>
    <div class="field w2">
        <label for="phone">Public phone <span class="opt">(optional)</span></label>
        <input id="phone" type="tel" wire:model="phone" maxlength="30">
        @error('phone') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="email">Public email <span class="opt">(optional)</span></label>
        <input id="email" type="email" wire:model="email" maxlength="160">
        @error('email') <span class="err">{{ $message }}</span> @enderror
    </div>
    <div class="field w2">
        <label for="website">Website <span class="opt">(optional)</span></label>
        <input id="website" type="text" wire:model="website" placeholder="example.ca" maxlength="255">
        @error('website') <span class="err">{{ $message }}</span> @enderror
    </div>
