<?php

use App\Models\AuditLog;
use App\Support\Deposits;
use App\Support\IdentityChecks;
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

    /**
     * ID checks. Demo: buyers are verified on the spot with no ID — for
     * showing the site. Live: a real licence + selfie check through Didit,
     * only possible once the Didit keys are set.
     */
    public function setIdentityMode(string $mode, IdentityChecks $checks): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        abort_unless(in_array($mode, [PlatformSettings::IDENTITY_DEMO, PlatformSettings::IDENTITY_LIVE], true), 422);

        if ($mode === PlatformSettings::IDENTITY_LIVE && ! $checks->liveReady()) {
            $this->statusMessage = 'Live ID checks need the Didit keys in the server’s .env first (DIDIT_API_KEY and DIDIT_WORKFLOW_ID). Still in demo mode.';

            return;
        }

        $before = PlatformSettings::get(PlatformSettings::IDENTITY_MODE);
        if ($before === $mode) {
            return;
        }

        PlatformSettings::put(PlatformSettings::IDENTITY_MODE, $mode, auth()->id());
        AuditLog::record('settings.updated', null, [PlatformSettings::IDENTITY_MODE => $before], [PlatformSettings::IDENTITY_MODE => $mode]);

        $this->statusMessage = $mode === PlatformSettings::IDENTITY_LIVE
            ? 'Live ID checks on — buyers now scan their licence with Didit at checkout.'
            : 'ID checks in demo mode — buyers are verified without showing ID.';
    }

    /** @var array<string, array{0: bool, 1: string}> */
    public array $identityConnection = [];

    public function checkIdentityConnection(IdentityChecks $checks): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $lastSeen = PlatformSettings::get(PlatformSettings::DIDIT_WEBHOOK_LAST_SEEN);
        $this->identityConnection = [
            'API key'  => $checks->checkConnection(),
            'Webhook'  => config('services.didit.webhook_secret')
                ? [$lastSeen !== '', $lastSeen !== '' ? 'Last received ' . \Illuminate\Support\Carbon::parse($lastSeen)->diffForHumans() . '.' : 'Secret set; nothing received yet. Use “Try Webhook” in Didit’s console.']
                : [false, 'DIDIT_WEBHOOK_SECRET isn’t set — results still arrive when buyers return from Didit, but not if they close the tab.'],
        ];
    }

    public function save(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $rules = [];
        foreach (PlatformSettings::DEFINITIONS as $key => [$label, , , $maxLength]) {
            $rules["values.{$key}"] = [in_array($key, ['marketplace_notice', 'business_mailing_address', 'contact_email', 'contact_phone'], true) ? 'nullable' : 'required', 'string', "max:{$maxLength}"];
        }
        $rules['values.contact_email'][] = 'email';

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
        .ps-status { background:var(--good-soft); color:#0B7355; border-left:3px solid var(--good); border-radius:5px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; max-width:1240px; }
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
        .ps-label { max-width:1240px; margin:0 0 10px; font-family:var(--font-display); font-size:14px; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--ink-3); }
        .ps-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:20px; max-width:1240px; margin:0 0 28px; align-items:start; }
        .ps-grid .card, .ps-strip { max-width:none; margin:0; }
        .ps-grid .pay-head h2 { font-size:20px; }
        .pair { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .ps-strip { max-width:1240px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .ps-strip > div { flex:1; min-width:240px; }
        .ps-strip h2 { margin:0; font-family:var(--font-display); font-size:20px; font-weight:700; text-transform:uppercase; }
        .ps-strip p { margin:4px 0 0; font-size:14px; color:var(--ink-3); }
        .ps-strip .mode-pill { margin-left:0; }
        @media (max-width:1100px) { .ps-grid { grid-template-columns:1fr; } }
        @media (max-width:560px) { .pair { grid-template-columns:1fr; } }
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
        $identityMode = \App\Support\PlatformSettings::get(\App\Support\PlatformSettings::IDENTITY_MODE);
        $identityLiveReady = app(\App\Support\IdentityChecks::class)->liveReady();
        $marketingOn = \App\Support\PlatformSettings::get(\App\Support\PlatformSettings::MARKETING_EMAILS) === 'on';
    @endphp

    <div class="ps-label">Checkout</div>
    <div class="ps-grid">
        {{-- Payments --}}
        <div class="card pay-card">
            <div class="pay-head">
                <div>
                    <h2>Payments</h2>
                    <p>The $150 reservation deposit.</p>
                </div>
                <span class="mode-pill {{ $paymentsMode }}">{{ $paymentsMode === 'live' ? 'Live' : 'Demo' }}</span>
            </div>
            <div class="modes">
                <button type="button" class="mode {{ $paymentsMode !== 'live' ? 'on' : '' }}" wire:click="setPaymentsMode('demo')"
                        @if ($paymentsMode === 'live') wire:confirm="Switch to demo mode? Buyers can still reserve, but no card will be charged." @endif>
                    <b>Demo</b>
                    <span>Checkout works end to end with a sample card. Nothing is charged.</span>
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

        {{-- ID checks --}}
        <div class="card pay-card">
            <div class="pay-head">
                <div>
                    <h2>ID checks</h2>
                    <p>The licence check at checkout, through Didit.</p>
                </div>
                <span class="mode-pill {{ $identityMode }}">{{ $identityMode === 'live' ? 'Live' : 'Demo' }}</span>
            </div>
            <div class="modes">
                <button type="button" class="mode {{ $identityMode !== 'live' ? 'on' : '' }}" wire:click="setIdentityMode('demo')"
                        @if ($identityMode === 'live') wire:confirm="Switch ID checks to demo mode? Buyers will be verified without showing any ID." @endif>
                    <b>Demo</b>
                    <span>Buyers are verified with one tap, no ID scan.</span>
                </button>
                <button type="button" class="mode {{ $identityMode === 'live' ? 'on' : '' }}" wire:click="setIdentityMode('live')" @disabled(! $identityLiveReady)
                        @if ($identityMode !== 'live') wire:confirm="Switch to live ID checks? Every buyer will scan their licence and take a selfie with Didit, and each check is billed by Didit." @endif>
                    <b>Live</b>
                    <span>Real licence + selfie check. Billed per check by Didit.</span>
                </button>
            </div>
            @unless ($identityLiveReady)
                <div class="keys-note">Live needs the Didit keys (DIDIT_API_KEY, DIDIT_WORKFLOW_ID, and DIDIT_WEBHOOK_SECRET) in the server’s .env.</div>
            @endunless
            <div class="conn">
                <div class="conn-head">
                    <b>Didit connection</b>
                    <button type="button" class="btn-ghost" wire:click="checkIdentityConnection" wire:loading.attr="disabled" wire:target="checkIdentityConnection">
                        <span wire:loading.remove wire:target="checkIdentityConnection">{{ $identityConnection ? 'Check again' : 'Check connection' }}</span>
                        <span wire:loading wire:target="checkIdentityConnection">Checking…</span>
                    </button>
                </div>
                @foreach ($identityConnection as $part => [$ok, $message])
                    <div class="conn-row">
                        <span class="conn-icon {{ $ok ? 'ok' : 'bad' }}">{{ $ok ? '✓' : '✕' }}</span>
                        <span class="conn-part">{{ $part }}</span>
                        <span class="conn-msg">{{ $message }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    <div class="ps-label">Site &amp; business</div>
    <div class="ps-grid">
        {{-- Marketplace --}}
        <form class="card pay-card" wire:submit="save">
            <div class="pay-head">
                <div>
                    <h2>Marketplace</h2>
                    <p>What shoppers see on the home page.</p>
                </div>
            </div>
            @foreach (['marketplace_headline', 'marketplace_subheadline', 'marketplace_notice'] as $key)
                @include('partials.setting-field', ['key' => $key])
            @endforeach
            <div class="end"><button type="submit" class="btn">Save marketplace</button></div>
        </form>

        {{-- Business & contact --}}
        <form class="card pay-card" wire:submit="save">
            <div class="pay-head">
                <div>
                    <h2>Business &amp; contact</h2>
                    <p>Printed in the footer of every email.</p>
                </div>
            </div>
            @include('partials.setting-field', ['key' => 'business_mailing_address'])
            <div class="pair">
                @include('partials.setting-field', ['key' => 'contact_email'])
                @include('partials.setting-field', ['key' => 'contact_phone'])
            </div>
            <div class="end"><button type="submit" class="btn">Save details</button></div>
        </form>
    </div>

    {{-- Emails --}}
    <div class="card pay-card ps-strip">
        <div>
            <h2>Emails</h2>
            <p>Marketing switch, pausing a type, previews and the send log live in the Email Centre.</p>
        </div>
        <span class="mode-pill {{ $marketingOn ? 'live' : 'demo' }}">Marketing {{ $marketingOn ? 'on' : 'off' }}</span>
        <a class="btn-ghost" href="{{ route('admin.emails') }}" wire:navigate>Open the Email Centre →</a>
    </div>
</div>
