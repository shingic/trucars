<?php

use App\Models\AuditLog;
use App\Models\EmailLog;
use App\Models\User;
use App\Support\Email\Catalogue;
use App\Support\Marketing\CheckoutRecovery;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — the Email Centre: every email TruCars sends, how many went
| out, a preview of each, the marketing On/Off switch and pausing one type.
| Viewing needs marketing.view; changing anything needs settings.manage and is
| audited. Previews are built from a made-up shopper (Catalogue::preview).
*/
new #[Layout('layouts.dealer')] #[Title('Email Centre')] class extends Component {
    public string $logType = '';

    public ?string $previewSlug = null;

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::MARKETING_VIEW);
    }

    public function setMarketingEmails(string $state): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        abort_unless(in_array($state, [PlatformSettings::MARKETING_ON, PlatformSettings::MARKETING_OFF], true), 422);

        if ($state === PlatformSettings::MARKETING_ON && blank(PlatformSettings::get('business_mailing_address'))) {
            $this->statusMessage = 'Add the business mailing address in Platform settings first — Canada’s anti-spam law requires it in every marketing email. Still off.';

            return;
        }

        $before = PlatformSettings::get(PlatformSettings::MARKETING_EMAILS);
        if ($before === $state) {
            return;
        }

        PlatformSettings::put(PlatformSettings::MARKETING_EMAILS, $state, auth()->id());
        AuditLog::record('settings.updated', null, [PlatformSettings::MARKETING_EMAILS => $before], [PlatformSettings::MARKETING_EMAILS => $state]);

        $this->statusMessage = $state === PlatformSettings::MARKETING_ON
            ? 'Marketing emails on — the ones not paused below will start going out.'
            : 'Marketing emails off — nothing marketing goes out until you switch them back on.';
    }

    public function togglePaused(string $slug): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        abort_unless(Catalogue::ALL[$slug]['pausable'] ?? false, 422);

        $pause = ! Catalogue::paused($slug);
        Catalogue::setPaused($slug, $pause, auth()->id());
        AuditLog::record('email.' . ($pause ? 'paused' : 'resumed'), null, null, ['type' => $slug]);

        $this->statusMessage = Catalogue::ALL[$slug]['name'] . ($pause ? ' paused.' : ' back on.');
    }

    public function showPreview(string $slug): void
    {
        abort_unless(isset(Catalogue::ALL[$slug]), 422);
        $this->previewSlug = $slug;
    }

    public function closePreview(): void
    {
        $this->previewSlug = null;
    }

    public function with(): array
    {
        Gate::authorize(Permission::MARKETING_VIEW);

        $counts = fn ($since) => EmailLog::where('sent_at', '>=', $since)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');
        $optedOut = collect(\App\Support\Marketing\EmailTypes::ALL)->keys()->mapWithKeys(fn ($type) => [
            $type => User::where('role', User::ROLE_CUSTOMER)->whereNotNull('email_preferences')->get(['id', 'email_preferences'])
                ->filter(fn (User $user) => in_array($type, $user->emailTypesOff(), true))->count(),
        ]);

        return [
            'groups'      => collect(Catalogue::ALL)->groupBy('group', preserveKeys: true),
            'week'        => $counts(now()->subDays(7)),
            'month'       => $counts(now()->subDays(30)),
            'lastSent'    => EmailLog::selectRaw('type, max(sent_at) as last')->groupBy('type')->pluck('last', 'type'),
            'totals'      => [
                'Today'        => EmailLog::where('sent_at', '>=', now()->startOfDay())->count(),
                'Last 7 days'  => EmailLog::where('sent_at', '>=', now()->subDays(7))->count(),
                'Last 30 days' => EmailLog::where('sent_at', '>=', now()->subDays(30))->count(),
                'Unsubscribed from all' => User::where('role', User::ROLE_CUSTOMER)->whereNotNull('marketing_unsubscribed_at')->count(),
            ],
            'optedOut'    => $optedOut,
            'marketingOn' => PlatformSettings::get(PlatformSettings::MARKETING_EMAILS) === PlatformSettings::MARKETING_ON,
            'hasAddress'  => filled(PlatformSettings::get('business_mailing_address')),
            'blocked'     => CheckoutRecovery::blockedReason(),
            'recent'      => EmailLog::query()->when($this->logType !== '', fn ($q) => $q->where('type', $this->logType))->latest('sent_at')->latest('id')->limit(50)->get(),
            'preview'     => $this->previewSlug ? Catalogue::preview($this->previewSlug) : null,
        ];
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Email Centre</span>
@endpush

@push('styles')
    <style>
        .em-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .em-head p { margin:0 0 18px; font-size:15px; color:var(--ink-2); max-width:780px; }
        .em-status { background:var(--good-soft); color:#0B7355; border-left:3px solid var(--good); border-radius:5px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:18px; }
        .em-warn { margin:0 0 18px; padding:12px 14px; border-left:4px solid #C59A00; background:#FFF8DD; color:#5C3D00; font-size:14.5px; font-weight:600; border-radius:6px; }
        .em-top { display:grid; grid-template-columns:minmax(0, 1.3fr) minmax(0, 1fr); gap:20px; margin-bottom:28px; }
        .em-card { background:#fff; border:1px solid var(--line-strong); border-top:2px solid var(--ink); border-radius:8px; padding:18px 20px; }
        .em-card h2 { font-family:var(--font-display); font-size:20px; text-transform:uppercase; margin:0 0 4px; }
        .em-card p { margin:0 0 12px; font-size:14px; color:var(--ink-2); }
        .em-switch { display:flex; gap:10px; }
        .em-switch button { flex:1; text-align:left; border:1.5px solid var(--line-strong); border-radius:8px; padding:10px 12px; background:#fff; font-size:13.5px; color:var(--ink-2); }
        .em-switch button b { display:block; font-family:var(--font-display); font-size:17px; text-transform:uppercase; color:var(--ink); }
        .em-switch button.on { border-color:var(--ink); box-shadow:inset 0 0 0 1px var(--ink); }
        .em-switch button:disabled { opacity:.5; cursor:not-allowed; }
        .em-stats { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .em-stat b { display:block; font-family:var(--font-display); font-size:28px; line-height:1.1; }
        .em-stat span { font-size:13px; color:var(--ink-3); }
        .em-group { margin-bottom:28px; }
        .em-group h3 { font-family:var(--font-display); font-size:22px; text-transform:uppercase; margin:0 0 6px; }
        .em-row { display:grid; grid-template-columns:minmax(0, 2.2fr) 110px 70px 70px 110px 120px 90px; gap:12px; align-items:center; padding:12px 6px; border-bottom:1px solid var(--line-strong); font-size:14px; }
        .em-row > span { min-width:0; }
        .em-cols { font-size:12px; font-weight:700; text-transform:uppercase; color:var(--ink-3); border-top:2px solid var(--ink); }
        .em-row b { color:var(--ink); }
        .meta { display:block; font-size:13px; color:var(--ink-3); }
        .pill { display:inline-block; font-size:12.5px; font-weight:700; padding:3px 8px; border-radius:5px; }
        .pill.on { background:#E6F7EC; color:#0E8A3A; }
        .pill.paused { background:#FFF3C4; color:#7A4E00; }
        .pill.always { background:rgba(7,20,55,.07); color:var(--ink-3); }
        .em-btn { font-size:13px; font-weight:700; border:1.5px solid var(--ink); border-radius:7px; padding:5px 10px; background:#fff; color:var(--ink); white-space:nowrap; }
        .em-btn:hover { background:var(--ink); color:#fff; }
        .em-link { font-size:13px; font-weight:700; color:var(--primary-press); text-decoration:underline; background:none; padding:0; }
        .em-log-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin:0 0 8px; }
        .em-log-head select { border:1.5px solid var(--line-strong); border-radius:8px; padding:7px 10px; font:inherit; font-size:14px; background:#fff; }
        .em-log { display:grid; grid-template-columns:150px minmax(0, 1fr) minmax(0, 1.2fr) minmax(0, 1.6fr); gap:12px; padding:9px 6px; border-bottom:1px solid var(--line-strong); font-size:13.5px; }
        .em-log > span { min-width:0; overflow-wrap:anywhere; }
        .em-modal { position:fixed; inset:0; z-index:100; background:rgba(7,20,55,.55); display:flex; align-items:flex-start; justify-content:center; padding:4vh 16px; overflow:auto; }
        .em-modal-box { background:#fff; border-radius:10px; width:100%; max-width:760px; overflow:hidden; }
        .em-modal-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:14px 18px; border-bottom:1px solid var(--line-strong); }
        .em-modal-head b { font-size:15px; }
        .em-modal iframe { display:block; width:100%; height:75vh; border:0; background:#F9F9F9; }
        .empty { padding:16px 6px; color:var(--ink-3); font-size:14.5px; }
        @media (max-width:980px) { .em-top { grid-template-columns:1fr; } .em-row { grid-template-columns:1fr 1fr; } .em-cols { display:none; } .em-log { grid-template-columns:1fr; } }
    </style>
@endpush

<div>
    <div class="em-head">
        <h1>Email Centre</h1>
        <p>Every email TruCars sends: when it goes out, who gets it, how many went out, and what it looks like. Marketing emails can be switched off all at once or paused one by one. Emails a reservation or a sign-in depends on are always on.</p>
    </div>

    @if ($statusMessage !== '')
        <div class="em-status">{{ $statusMessage }}</div>
    @endif
    @if ($blocked)
        <div class="em-warn">Marketing emails aren’t going out: {{ $blocked }}</div>
    @endif

    <div class="em-top">
        <div class="em-card">
            <h2>Marketing emails</h2>
            <p>Checkout reminders, saved-car emails and back-on-sale alerts. Receipts and reservation emails always go out.</p>
            <div class="em-switch">
                <button type="button" class="{{ $marketingOn ? '' : 'on' }}" wire:click="setMarketingEmails('off')" @cannot('settings.manage') disabled @endcannot
                        @if ($marketingOn) wire:confirm="Switch marketing emails off? Nothing marketing goes out until you switch them back on." @endif>
                    <b>Off</b>None are sent.
                </button>
                <button type="button" class="{{ $marketingOn ? 'on' : '' }}" wire:click="setMarketingEmails('on')" @disabled(! $hasAddress || auth()->user()->cannot('settings.manage'))
                        @unless ($marketingOn) wire:confirm="Switch marketing emails on? Shoppers who are due one will start getting them within 15 minutes (8am–9pm Toronto time)." @endunless>
                    <b>On</b>The ones not paused below go out automatically.
                </button>
            </div>
            @unless ($hasAddress)
                <p class="meta" style="margin:10px 0 0;">On needs the business mailing address in <a href="{{ route('admin.settings') }}" wire:navigate>Platform settings</a>.</p>
            @endunless
        </div>
        <div class="em-card">
            <h2>Sent</h2>
            <div class="em-stats">
                @foreach ($totals as $label => $value)
                    <div class="em-stat"><b>{{ number_format($value) }}</b><span>{{ $label }}</span></div>
                @endforeach
            </div>
        </div>
    </div>

    @foreach ($groups as $group => $emails)
        <div class="em-group">
            <h3>{{ $group }}</h3>
            <div class="em-row em-cols"><span>Email</span><span>Who gets it</span><span>7 days</span><span>30 days</span><span>Last sent</span><span>Status</span><span></span></div>
            @foreach ($emails as $slug => $email)
                @php
                    $isPaused = \App\Support\Email\Catalogue::paused($slug);
                    $isMarketing = $email['group'] === \App\Support\Email\Catalogue::GROUP_MARKETING;
                @endphp
                <div class="em-row" wire:key="em-{{ $slug }}">
                    <span>
                        <b>{{ $email['name'] }}</b>
                        <span class="meta">{{ $email['trigger'] }}</span>
                        @if ($email['type'])
                            <span class="meta">{{ $optedOut[$email['type']] ?? 0 }} switched off “{{ \App\Support\Marketing\EmailTypes::label($email['type']) }}”</span>
                        @endif
                        <button type="button" class="em-link" wire:click="showPreview('{{ $slug }}')">Preview</button>
                    </span>
                    <span>{{ $email['audience'] }}</span>
                    <span>{{ $week[$slug] ?? 0 }}</span>
                    <span>{{ $month[$slug] ?? 0 }}</span>
                    <span>{{ isset($lastSent[$slug]) ? \Illuminate\Support\Carbon::parse($lastSent[$slug])->diffForHumans() : '—' }}</span>
                    <span>
                        @if (! $email['pausable'])
                            <span class="pill always">Always on</span>
                        @elseif ($isPaused)
                            <span class="pill paused">Paused</span>
                        @elseif ($isMarketing && ! $marketingOn)
                            <span class="pill paused">Off (all marketing)</span>
                        @else
                            <span class="pill on">On</span>
                        @endif
                    </span>
                    <span>
                        @if ($email['pausable'])
                            @can('settings.manage')
                                <button type="button" class="em-btn" wire:click="togglePaused('{{ $slug }}')"
                                        wire:confirm="{{ $isPaused ? 'Resume' : 'Pause' }} “{{ $email['name'] }}”?">{{ $isPaused ? 'Resume' : 'Pause' }}</button>
                            @endcan
                        @endif
                    </span>
                </div>
            @endforeach
        </div>
    @endforeach

    <div class="em-group">
        <div class="em-log-head">
            <h3 style="margin:0;">Recent sends</h3>
            <select wire:model.live="logType" aria-label="Filter by email">
                <option value="">All emails</option>
                @foreach (\App\Support\Email\Catalogue::ALL as $slug => $email)
                    <option value="{{ $slug }}">{{ $email['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="em-log em-cols" style="font-size:12px;"><span>When</span><span>Email</span><span>To</span><span>Subject</span></div>
        @forelse ($recent as $row)
            <div class="em-log" wire:key="log-{{ $row->id }}">
                <span>{{ $row->sent_at->timezone('America/Toronto')->format('M j, g:i a') }}</span>
                <span>{{ $row->label() }}</span>
                <span>
                    @if ($row->user_id && $row->user?->isCustomer())
                        @can('customers.view')<a href="{{ route('admin.customers.show', $row->user_id) }}" wire:navigate>{{ $row->to_email }}</a>@else{{ $row->to_email }}@endcan
                    @else
                        {{ $row->to_email }}
                    @endif
                </span>
                <span>{{ $row->subject }}</span>
            </div>
        @empty
            <div class="empty">Nothing sent yet.</div>
        @endforelse
    </div>

    @if ($previewSlug)
        <div class="em-modal" wire:click.self="closePreview" wire:key="preview-{{ $previewSlug }}">
            <div class="em-modal-box">
                <div class="em-modal-head">
                    <div>
                        <span class="meta">{{ \App\Support\Email\Catalogue::ALL[$previewSlug]['name'] }} · sample with made-up details</span>
                        <b>{{ $preview['subject'] ?? 'No sample available' }}</b>
                    </div>
                    <button type="button" class="em-btn" wire:click="closePreview">Close</button>
                </div>
                @if ($preview)
                    <iframe srcdoc="{{ $preview['html'] }}" title="Email preview" sandbox=""></iframe>
                @else
                    <div class="empty" style="padding:24px 18px;">A sample needs at least one listed car. Publish a car and try again.</div>
                @endif
            </div>
        </div>
    @endif
</div>
