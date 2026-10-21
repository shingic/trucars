{{--
    The bell on a reserved car (vehicle page). Tapping it asks to be emailed
    once if the reservation falls through — VehicleAlert. Guests are sent to
    sign in first. $compact: the short label for the mobile sticky bar.
--}}
@php $watching = $this->isWatching(); $compact ??= false; @endphp
<button type="button"
        class="btn btn-bell {{ $watching ? 'on' : '' }} {{ $compact ? 'sb-cta' : '' }}"
        wire:click="toggleAvailabilityAlert"
        wire:loading.attr="disabled" wire:target="toggleAvailabilityAlert"
        aria-pressed="{{ $watching ? 'true' : 'false' }}"
        title="{{ $watching ? 'Tap to stop the alert' : 'Email me if this car becomes available' }}">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="{{ $watching ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>
    @if ($watching)
        {{ $compact ? 'We’ll email you' : 'We’ll email you if it’s available' }}
    @else
        {{ $compact ? 'Notify me' : 'Notify me if it becomes available' }}
    @endif
</button>
