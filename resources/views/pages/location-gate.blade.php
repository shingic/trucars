<?php

use App\Support\CanadianAddress;
use App\Support\ShopperLocation;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
| Where the shopper is, asked for before any car is shown. Every car is
| shown by its distance from the shopper (the selling dealership stays
| anonymous until the deposit is paid), so the shop, vehicle pages, campaign
| storefronts and Saved cars all need it first.
|
| A postal code or the browser's location. Both give coordinates, so every
| page can show real kilometres. It can't be dismissed until one is given.
| After that, the header's location opens it again to change it. Kept in the
| session only (ShopperLocation).
*/
new class extends Component {
    /** The page to reload once the location is set, so every distance is drawn with it. */
    #[Locked]
    public string $returnUrl = '/';

    public string $postalCode = '';

    public function mount(?string $returnUrl = null): void
    {
        $this->returnUrl = $returnUrl ?? request()->fullUrl();
    }

    /** True while there's no location yet: the prompt is open and can't be closed. */
    public function required(): bool
    {
        return ShopperLocation::current() === null;
    }

    public function usePostalCode(): void
    {
        $this->validate(['postalCode' => ['required', 'string', 'max:10']], [
            'postalCode.required' => 'Enter your postal code.',
        ], ['postalCode' => 'postal code']);

        if (CanadianAddress::normalizePostalCode($this->postalCode) === null) {
            $this->addError('postalCode', 'That doesn’t look like a Canadian postal code. Try something like M5V 2T6.');

            return;
        }

        ShopperLocation::setManual($this->postalCode);
        $this->done();
    }

    public function useBrowserLocation($latitude, $longitude): void
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude) || abs((float) $latitude) > 90 || abs((float) $longitude) > 180) {
            $this->addError('postalCode', 'We couldn’t read your location. Enter your postal code instead.');

            return;
        }

        ShopperLocation::setFromBrowser((float) $latitude, (float) $longitude);
        $this->done();
    }

    private function done(): void
    {
        $this->dispatch('shopper-location-updated', label: ShopperLocation::current()['label'] ?? null);
        $this->redirect($this->safeReturnUrl(), navigate: true);
    }

    /** Only ever back to a page on this site. */
    private function safeReturnUrl(): string
    {
        $url = $this->returnUrl;

        return str_starts_with($url, url('/')) || (str_starts_with($url, '/') && ! str_starts_with($url, '//')) ? $url : url('/');
    }
}; ?>

<div x-data="{
         open: @js($this->required()),
         required: @js($this->required()),
         locating: false,
         geoError: '',
         locate() {
             this.geoError = '';
             if (! ('geolocation' in navigator)) { this.geoError = 'Your browser can’t share a location. Enter your postal code instead.'; return; }
             this.locating = true;
             navigator.geolocation.getCurrentPosition(
                 (p) => { this.$wire.useBrowserLocation(p.coords.latitude, p.coords.longitude); },
                 (e) => { this.locating = false; this.geoError = e.code === 1 ? 'Location access was declined. Enter your postal code instead.' : 'We couldn’t get your location. Enter your postal code instead.'; },
                 { timeout: 10000, maximumAge: 600000 },
             );
         },
         close() { if (! this.required) this.open = false; },
     }"
     x-init="$watch('open', (value) => { document.documentElement.style.overflow = value ? 'hidden' : ''; if (value) $nextTick(() => $refs.postal?.focus()); }); if (open) { document.documentElement.style.overflow = 'hidden'; $nextTick(() => $refs.postal?.focus()); }"
     @open-location-gate.window="open = true"
     @keydown.escape.window="close()">
    <style>
        .lg-scrim { position:fixed; inset:0; z-index:90; background:rgba(7,20,55,.62); display:flex; align-items:center; justify-content:center; padding:16px; }
        .lg { position:relative; width:100%; max-width:480px; background:#fff; border-radius:12px; border-top:6px solid var(--primary); padding:30px 30px 26px; box-shadow:0 24px 60px rgba(7,20,55,.28); }
        .lg-k { font-size:13px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--primary-press); display:flex; align-items:center; gap:7px; }
        .lg h2 { font-family:var(--font-display); font-size:32px; font-weight:800; text-transform:uppercase; line-height:1; color:var(--ink); margin:10px 0 10px; }
        .lg p { font-size:15.5px; color:var(--ink-2); line-height:1.5; margin:0 0 20px; }
        .lg-form { display:flex; gap:8px; }
        .lg-form input { flex:1; min-width:0; font-family:var(--font-display); font-size:22px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--ink); border:2px solid var(--ink); border-radius:8px; padding:11px 14px; background:#fff; }
        .lg-form input::placeholder { color:var(--ink-3); font-weight:600; letter-spacing:.04em; }
        .lg-form input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px var(--primary-soft); }
        .lg-go { flex-shrink:0; background:var(--primary); border:2px solid var(--primary); color:#fff; border-radius:8px; padding:0 20px; font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; cursor:pointer; }
        .lg-go:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .lg-go:disabled { opacity:.6; cursor:wait; }
        .lg-err { margin:8px 0 0; font-size:13.5px; font-weight:600; color:#D81A48; }
        .lg-or { display:flex; align-items:center; gap:12px; margin:18px 0 14px; font-size:13px; font-weight:600; color:var(--ink-3); }
        .lg-or::before, .lg-or::after { content:''; flex:1; height:1px; background:var(--line-strong); }
        .lg-geo { display:flex; align-items:center; justify-content:center; gap:8px; width:100%; background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:11px 14px; font-size:15px; font-weight:700; color:var(--ink); cursor:pointer; }
        .lg-geo:hover { border-color:var(--ink); }
        .lg-geo svg { color:var(--primary); }
        .lg-note { margin:16px 0 0; font-size:13px; color:var(--ink-3); }
        .lg-x { position:absolute; top:14px; right:14px; width:36px; height:36px; display:grid; place-items:center; border:none; background:none; border-radius:8px; color:var(--ink-2); cursor:pointer; }
        .lg-x:hover { background:var(--bg-2); color:var(--ink); }
        .lg-go:focus-visible, .lg-geo:focus-visible, .lg-x:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
        @media (max-width:480px) {
            .lg-scrim { align-items:flex-end; padding:0; }
            .lg { border-radius:12px 12px 0 0; padding:26px 16px calc(20px + env(safe-area-inset-bottom)); }
            .lg h2 { font-size:28px; }
            .lg-form { flex-direction:column; }
            .lg-go { padding:13px 20px; }
        }
    </style>

    <div class="lg-scrim" x-show="open" x-cloak x-transition.opacity @click.self="close()">
        <div class="lg" role="dialog" aria-modal="true" aria-labelledby="lg-title">
            <button type="button" class="lg-x" x-show="! required" @click="close()" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <div class="lg-k">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                Before you browse
            </div>
            <h2 id="lg-title">Where are you shopping from?</h2>
            <p>Every car shows how far it is from you, so you can pick one close enough to collect, or have it delivered.</p>

            <form class="lg-form" wire:submit="usePostalCode">
                <label for="lg-postal" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0);">Your postal code</label>
                <input id="lg-postal" x-ref="postal" type="text" wire:model="postalCode" placeholder="A1A 1A1" maxlength="10" autocomplete="postal-code" inputmode="text" aria-describedby="lg-postal-err">
                <button type="submit" class="lg-go" wire:loading.attr="disabled" wire:target="usePostalCode">Show cars</button>
            </form>
            @error('postalCode') <p class="lg-err" id="lg-postal-err">{{ $message }}</p> @enderror
            <p class="lg-err" x-show="geoError" x-text="geoError" x-cloak></p>

            <div class="lg-or">or</div>
            <button type="button" class="lg-geo" @click="locate()" :disabled="locating">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="7"/></svg>
                <span x-show="! locating">Use my current location</span>
                <span x-show="locating" x-cloak>Finding you…</span>
            </button>

            <p class="lg-note">Kept for this visit only. We never share it with dealerships.</p>
        </div>
    </div>
</div>
