<?php

use App\Models\AuditLog;
use App\Support\Deposits;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — platform settings (settings.manage). Only the settings the
| app actually reads (App\Support\PlatformSettings). Every change is audited
| with its before/after values.
*/
new #[Layout('layouts.dealer')] #[Title('Platform settings')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->values = PlatformSettings::all();
    }

    /**
     * Demo: checkout works end to end and no money moves. Live: real $150
     * card payments through Stripe — only possible once the keys are set.
     */
    public function setPaymentsMode(string $mode, Deposits $deposits): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        abort_unless(in_array($mode, [PlatformSettings::PAYMENTS_DEMO, PlatformSettings::PAYMENTS_LIVE], true), 422);

        if ($mode === PlatformSettings::PAYMENTS_LIVE && ! $deposits->liveReady()) {
            $this->statusMessage = 'Live payments need the Stripe keys in the server’s .env first (STRIPE_KEY and STRIPE_SECRET). Still in demo mode.';

            return;
        }

        $before = PlatformSettings::get(PlatformSettings::PAYMENTS_MODE);
        if ($before === $mode) {
            return;
        }

        PlatformSettings::put(PlatformSettings::PAYMENTS_MODE, $mode, auth()->id());
        AuditLog::record('settings.updated', null, [PlatformSettings::PAYMENTS_MODE => $before], [PlatformSettings::PAYMENTS_MODE => $mode]);

        $this->statusMessage = $mode === PlatformSettings::PAYMENTS_LIVE
            ? 'Live payments on — reservations now charge a real $150 through Stripe.'
            : 'Demo mode on — checkout works, but no card is charged.';
    }

    public function save(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $rules = [];
        foreach (PlatformSettings::DEFINITIONS as $key => [$label, , , $maxLength]) {
            $rules["values.{$key}"] = [$key === 'marketplace_notice' ? 'nullable' : 'required', 'string', "max:{$maxLength}"];
        }

        $this->validate($rules, attributes: collect(PlatformSettings::DEFINITIONS)->mapWithKeys(fn ($definition, $key) => ["values.{$key}" => strtolower($definition[0])])->all());

        $before = PlatformSettings::all();
        $changedBefore = [];
        $changedAfter = [];

        foreach (array_keys(PlatformSettings::DEFINITIONS) as $key) {
            $value = trim((string) ($this->values[$key] ?? ''));

            if ($value !== $before[$key]) {
                PlatformSettings::put($key, $value, auth()->id());
                $changedBefore[$key] = $before[$key];
                $changedAfter[$key] = $value;
            }
        }

        if ($changedAfter !== []) {
            AuditLog::record('settings.updated', null, $changedBefore, $changedAfter);
        }

        $this->values = PlatformSettings::all();
        $this->statusMessage = $changedAfter === [] ? 'Nothing changed.' : 'Settings saved.';
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Platform settings</span>
@endpush

@push('styles')
    <style>
        .ps-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .ps-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); }
        .ps-status { background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:18px; max-width:760px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); max-width:760px; padding:20px; display:grid; gap:18px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field label { font-size:12.5px; font-weight:700; color:var(--ink-2); }
        .field .hint { font-size:12px; color:var(--ink-3); }
        .field input, .field textarea { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:10px 13px; font-size:14px; font-family:inherit; }
        .field input:focus, .field textarea:focus { outline:none; border-color:var(--primary); }
        .err { font-size:12px; color:var(--primary-press); font-weight:600; }
        .end { display:flex; justify-content:flex-end; }
        .btn { font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; background:var(--primary); color:#fff; }
        .pay-card { margin-bottom:18px; }
        .pay-head { display:flex; align-items:center; gap:10px; }
        .pay-head h2 { margin:0; font-size:16px; font-weight:800; }
        .pay-head p { margin:2px 0 0; font-size:13px; color:var(--ink-3); }
        .mode-pill { margin-left:auto; font-size:11px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; padding:4px 10px; border-radius:var(--radius-pill); }
        .mode-pill.demo { background:#FFF4D6; color:#8A5A00; }
        .mode-pill.live { background:var(--good-soft); color:var(--good-ink); }
        .modes { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .mode { text-align:left; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); padding:14px 16px; background:var(--card); }
        .mode b { display:block; font-size:14px; margin-bottom:3px; }
        .mode span { font-size:12.5px; color:var(--ink-3); line-height:1.45; }
        .mode.on { border-color:var(--primary); box-shadow:0 0 0 3px color-mix(in srgb, var(--primary) 15%, transparent); }
        .mode:disabled { opacity:.55; cursor:not-allowed; }
        .keys-note { font-size:12.5px; color:var(--ink-3); }
        @media (max-width:640px) { .modes { grid-template-columns:1fr; } }
    </style>
@endpush

<div>
    @include('partials.settings-tabs', ['activeTab' => 'general'])

    <div class="ps-head">
        <h1>Platform settings</h1>
        <p>Changes apply across the marketplace straight away and are recorded in the audit log.</p>
    </div>

    @if ($statusMessage !== '')
        <div class="ps-status">{{ $statusMessage }}</div>
    @endif

    @php
        $paymentsMode = \App\Support\PlatformSettings::get(\App\Support\PlatformSettings::PAYMENTS_MODE);
        $liveReady = app(\App\Support\Deposits::class)->liveReady();
    @endphp
    <div class="card pay-card">
        <div class="pay-head">
            <div>
                <h2>Payments</h2>
                <p>The $150 reservation deposit at checkout.</p>
            </div>
            <span class="mode-pill {{ $paymentsMode }}">{{ $paymentsMode === 'live' ? 'Live' : 'Demo' }}</span>
        </div>
        <div class="modes">
            <button type="button" class="mode {{ $paymentsMode !== 'live' ? 'on' : '' }}" wire:click="setPaymentsMode('demo')"
                    @if ($paymentsMode === 'live') wire:confirm="Switch to demo mode? Buyers can still reserve, but no card will be charged." @endif>
                <b>Demo</b>
                <span>Checkout works end to end with a sample card. Nothing is charged — for showing and testing.</span>
            </button>
            <button type="button" class="mode {{ $paymentsMode === 'live' ? 'on' : '' }}" wire:click="setPaymentsMode('live')" @disabled(! $liveReady)
                    @if ($paymentsMode !== 'live') wire:confirm="Switch to live payments? Every reservation will charge a real $150 to the buyer’s card." @endif>
                <b>Live</b>
                <span>Real $150 card payments through Stripe.</span>
            </button>
        </div>
        @unless ($liveReady)
            <div class="keys-note">Live needs the Stripe keys (STRIPE_KEY, STRIPE_SECRET) in the server’s .env.</div>
        @endunless
    </div>

    <form class="card" wire:submit="save">
        @foreach (\App\Support\PlatformSettings::DEFINITIONS as $key => [$label, $help, $default, $maxLength])
            <div class="field">
                <label for="setting-{{ $key }}">{{ $label }}</label>
                @if ($maxLength > 100)
                    <textarea id="setting-{{ $key }}" wire:model="values.{{ $key }}" maxlength="{{ $maxLength }}" rows="2"></textarea>
                @else
                    <input id="setting-{{ $key }}" type="text" wire:model="values.{{ $key }}" maxlength="{{ $maxLength }}">
                @endif
                <span class="hint">{{ $help }}</span>
                @error("values.{$key}") <span class="err">{{ $message }}</span> @enderror
            </div>
        @endforeach
        <div class="end"><button type="submit" class="btn">Save settings</button></div>
    </form>
</div>
