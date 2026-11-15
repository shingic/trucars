{{-- One Platform settings text field. Takes $key (a PlatformSettings::DEFINITIONS key). --}}
@php
    [$label, $help, , $maxLength] = \App\Support\PlatformSettings::DEFINITIONS[$key];
@endphp
<div class="field">
    <label for="setting-{{ $key }}">{{ $label }}</label>
    @if ($maxLength >= 200)
        <textarea id="setting-{{ $key }}" wire:model="values.{{ $key }}" maxlength="{{ $maxLength }}" rows="2"></textarea>
    @else
        <input id="setting-{{ $key }}" type="text" wire:model="values.{{ $key }}" maxlength="{{ $maxLength }}">
    @endif
    <span class="hint">{{ $help }}</span>
    @error("values.{$key}") <span class="err">{{ $message }}</span> @enderror
</div>
