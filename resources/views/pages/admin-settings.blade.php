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

    /** @var array<string, array{0: bool, 1: string}> */
    public array $connection = [];

    public function checkConnection(Deposits $deposits): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->connection = $deposits->connectionReport();
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
        .ps-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .ps-head p { margin:0 0 22px; font-size:15px; color:var(--ink-2); }
        .ps-status { background:var(--good-soft); color:#0B7355; border-left:3px solid var(--good); border-radius:5px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; max-width:760px; }
        .card { background:#fff; border:1px solid var(--line-strong); border-radius:10px; max-width:760px; padding:20px; display:grid; gap:18px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field label { font-size:14px; font-weight:700; color:var(--ink); }
        .field .hint { font-size:13px; color:var(--ink-3); }
        .field input, .field textarea { background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:10px 13px; font-size:15px; font-family:inherit; color:var(--ink); }
        .field input:focus, .field textarea:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .err { font-size:13px; color:#D81A48; font-weight:600; }
        .end { display:flex; justify-content:flex-end; }
        .btn { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; border-radius:8px; padding:10px 20px; background:var(--primary); color:#fff; transition:background .15s ease; }
        .btn:hover { background:var(--primary-press); }
        .pay-card { margin-bottom:22px; border:1.5px solid var(--ink); border-radius:12px; overflow:hidden; }
        .pay-head { display:flex; align-items:center; gap:10px; padding-bottom:14px; border-bottom:1px solid var(--line-strong); }
        .pay-head h2 { margin:0; font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.05; text-transform:uppercase; }
        .pay-head p { margin:4px 0 0; font-size:14px; color:var(--ink-3); }
        .mode-pill { margin-left:auto; font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; padding:2px 9px; border-radius:5px; }
        .mode-pill.demo { background:#FFF8DD; color:#7A4E00; }
        .mode-pill.live { background:var(--good-soft); color:#0B7355; }
        .modes { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .mode { text-align:left; border:1.5px solid var(--line-strong); border-radius:8px; padding:14px 16px; background:#fff; transition:border-color .15s ease; }
        .mode:hover:not(:disabled) { border-color:var(--ink); }
        .mode b { display:block; font-family:var(--font-display); font-size:18px; font-weight:700; text-transform:uppercase; margin-bottom:3px; }
        .mode span { font-size:14px; color:var(--ink-2); line-height:1.45; }
        .mode.on { border-color:var(--ink); box-shadow:inset 0 0 0 1.5px var(--ink); }
        .mode.on b { color:var(--primary-press); }
        .mode:disabled { opacity:.55; cursor:not-allowed; }
        .keys-note { font-size:13px; color:var(--ink-3); }
        .conn { border-top:1px solid var(--line-strong); padding-top:14px; display:grid; gap:8px; }
        .conn-head { display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:15px; }
        .btn-ghost { font-size:14px; font-weight:700; border:1.5px solid var(--ink); border-radius:8px; padding:7px 14px; background:#fff; color:var(--ink); transition:background .15s ease, color .15s ease; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .conn-row { display:grid; grid-template-columns:22px 130px 1fr; gap:8px; align-items:start; font-size:14px; }
        .conn-icon { width:20px; text-align:center; font-size:15px; font-weight:800; line-height:1.4; }
        .conn-icon.ok { color:#0B7355; }
        .conn-icon.bad { color:#D81A48; }
        .conn-part { font-weight:700; }
        .conn-msg { color:var(--ink-2); }
        @media (max-width:640px) { .conn-row { grid-template-columns:22px 1fr; } .conn-msg { grid-column:2; } .ps-head h1 { font-size:30px; } }
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

        <div class="conn">
            <div class="conn-head">
                <b>Stripe connection</b>
                <button type="button" class="btn-ghost" wire:click="checkConnection" wire:loading.attr="disabled" wire:target="checkConnection">
                    <span wire:loading.remove wire:target="checkConnection">{{ $connection ? 'Check again' : 'Check connection' }}</span>
                    <span wire:loading wire:target="checkConnection">Checking…</span>
                </button>
            </div>
            @foreach ($connection as $part => [$ok, $message])
                <div class="conn-row">
                    <span class="conn-icon {{ $ok ? 'ok' : 'bad' }}">{{ $ok ? '✓' : '✕' }}</span>
                    <span class="conn-part">{{ $part }}</span>
                    <span class="conn-msg">{{ $message }}</span>
                </div>
            @endforeach
        </div>
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
