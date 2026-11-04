<?php

use App\Models\AuditLog;
use App\Models\CheckoutDraft;
use App\Support\Marketing\CheckoutRecovery;
use App\Support\Permission;
use App\Support\ShopperLocation;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Command Centre: shoppers who started a checkout but haven't paid the deposit
| (customers.view). These shoppers belong to the platform, never a dealer:
| they appear here and nowhere in the dealer portal. Once they pay, the draft
| becomes the reservation and the dealer gets the customer.
|
| Rows carry names, emails and phones, so opening the list, searching and
| editing notes are audited.
*/
new #[Layout('layouts.dealer')] #[Title('Unfinished checkouts')] class extends Component {
    use WithPagination;

    public const STATUS_TABS = [
        CheckoutDraft::STATUS_ACTIVE   => 'Following up',
        CheckoutDraft::STATUS_CAR_GONE => 'Car gone',
        CheckoutDraft::STATUS_RESERVED => 'Reserved',
    ];

    public string $status = CheckoutDraft::STATUS_ACTIVE;

    public string $search = '';

    #[Locked]
    public ?int $openId = null;

    public string $note = '';

    public string $flash = '';

    public function mount(): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        AuditLog::record('checkout_draft.listed');
    }

    public function showStatus(string $status): void
    {
        abort_unless(array_key_exists($status, self::STATUS_TABS), 422);
        $this->status = $status;
        $this->openId = null;
        $this->resetPage();
    }

    public function applySearch(): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        $this->search = trim($this->search);
        $this->validate(['search' => ['nullable', 'string', 'max:120']]);
        $this->resetPage();

        if ($this->search !== '') {
            AuditLog::record('checkout_draft.searched', null, null, ['search' => $this->search]);
        }
    }

    public function toggle(int $id): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);

        if ($this->openId === $id) {
            $this->openId = null;

            return;
        }

        $draft = CheckoutDraft::findOrFail($id);
        $this->openId = $draft->id;
        $this->note = (string) $draft->staff_note;
        $this->flash = '';
    }

    public function saveNote(): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $draft = CheckoutDraft::findOrFail($this->openId);
        $before = $draft->staff_note;
        $draft->update(['staff_note' => trim($this->note) ?: null]);
        AuditLog::record('checkout_draft.noted', $draft, ['note' => $before], ['note' => $draft->staff_note]);

        $this->flash = 'Note saved.';
    }

    public function with(): array
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);
        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $this->search) . '%';

        return [
            'drafts' => CheckoutDraft::query()
                ->with(['user', 'vehicle.rooftop', 'vehicle.dealer', 'emails'])
                ->where('status', $this->status)
                ->when($this->search !== '', fn ($query) => $query->whereHas('user', fn ($user) => $user
                    ->where('name', 'like', $term)->orWhere('email', 'like', $term)))
                ->orderByDesc('last_active_at')
                ->paginate(30),
            'counts'  => CheckoutDraft::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'blocked' => CheckoutRecovery::blockedReason(),
        ];
    }

    public function distance(CheckoutDraft $draft): ?string
    {
        $km = ShopperLocation::distanceKm($draft->shopper_location, $draft->vehicle?->locatedAt());

        return $km === null ? null : ($km < 10 ? number_format($km, 1) : number_format($km)) . ' km away';
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Unfinished checkouts</span>
@endpush

@push('styles')
    <style>
        .ck-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .ck-head p { margin:0 0 18px; font-size:15px; color:var(--ink-2); max-width:760px; }
        .ck-warn { margin:0 0 18px; padding:12px 14px; border-left:4px solid #C59A00; background:#FFF8DD; color:#5C3D00; font-size:14.5px; font-weight:600; border-radius:6px; }
        .ck-warn a { color:inherit; }
        .ck-tabs { display:flex; gap:4px; border-bottom:1px solid var(--line-strong); margin-bottom:16px; overflow-x:auto; }
        .ck-tab { font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; color:var(--ink-3); padding:10px 16px 11px; border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .ck-tab:hover { color:var(--ink); }
        .ck-tab.on { color:var(--ink); border-bottom-color:var(--primary); }
        .ck-tab .n { font-family:var(--font); font-size:13px; color:var(--ink-2); background:rgba(7,20,55,.07); padding:1px 7px; border-radius:5px; margin-left:6px; }
        .ck-search { display:flex; gap:10px; margin-bottom:16px; max-width:560px; }
        .ck-search input { flex:1; min-width:0; background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font-size:14px; font-family:inherit; }
        .ck-search input:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .btn { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; border-radius:8px; padding:9px 18px; background:var(--primary); color:#fff; }
        .btn:hover { background:var(--primary-press); }
        .card { border-top:2px solid var(--ink); }
        .ck-row { display:grid; grid-template-columns:minmax(0, 1.3fr) minmax(0, 1.4fr) 150px minmax(0, 1fr) 70px; gap:14px; align-items:center; padding:12px 6px; border-bottom:1px solid var(--line-strong); font-size:14px; width:100%; text-align:left; background:none; }
        .ck-row:hover { background:#fff; }
        .ck-row > span { min-width:0; overflow-wrap:anywhere; }
        .ck-row b { font-weight:700; color:var(--ink); }
        .meta { display:block; font-size:13px; color:var(--ink-3); }
        .step { display:inline-block; font-size:12.5px; font-weight:700; padding:3px 8px; border-radius:5px; background:var(--primary-soft); color:var(--primary-press); }
        .step.pay { background:#FFF3C4; color:#7A4E00; }
        .ck-more { font-weight:700; color:var(--primary-press); text-align:right; }
        .ck-detail { padding:16px 6px 22px; border-bottom:1px solid var(--line-strong); background:#fff; display:grid; grid-template-columns:1fr 1fr; gap:18px 30px; }
        .ck-detail h3 { font-family:var(--font-display); font-size:15px; text-transform:uppercase; margin:0 0 8px; }
        .ck-detail ul { margin:0; padding-left:18px; font-size:14px; color:var(--ink-2); }
        .ck-detail textarea { width:100%; min-height:84px; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font:inherit; font-size:14px; }
        .ck-copy { display:flex; gap:8px; }
        .ck-copy input { flex:1; min-width:0; font-family:var(--mono); font-size:12.5px; border:1.5px solid var(--line-strong); border-radius:8px; padding:8px 10px; background:var(--bg-2); }
        .ck-copy button, .ck-save { font-size:13.5px; font-weight:700; border:1.5px solid var(--ink); border-radius:8px; padding:7px 12px; background:#fff; color:var(--ink); }
        .ck-copy button:hover, .ck-save:hover { background:var(--ink); color:#fff; }
        .ok { font-size:13px; color:#0E8A3A; font-weight:700; margin-left:8px; }
        .empty { margin-top:14px; padding:18px 20px; border:1.5px dashed var(--line-strong); border-radius:8px; color:var(--ink-3); font-size:15px; }
        .pages { margin-top:16px; }
        @media (max-width:900px) { .ck-row { grid-template-columns:1fr 1fr; } .ck-detail { grid-template-columns:1fr; } }
    </style>
@endpush

<div>
    <div class="ck-head">
        <h1>Unfinished checkouts</h1>
        <p>Shoppers who started reserving a car but haven't paid the deposit. They're yours, not the dealer's: dealers only see a customer once the deposit is paid. Follow-up emails go out automatically at 1 hour, 1 day, 3 days and 7 days. Viewing is logged.</p>
    </div>

    @if ($blocked)
        <div class="ck-warn">Follow-up emails are paused. {{ $blocked }} @can('settings.manage') <a href="{{ route('admin.settings') }}" wire:navigate>Open Platform settings →</a> @endcan</div>
    @endif

    <div class="ck-tabs">
        @foreach (self::STATUS_TABS as $key => $label)
            <button type="button" class="ck-tab {{ $status === $key ? 'on' : '' }}" wire:click="showStatus('{{ $key }}')">{{ $label }}<span class="n">{{ $counts[$key] ?? 0 }}</span></button>
        @endforeach
    </div>

    <form class="ck-search" wire:submit="applySearch">
        <input type="search" wire:model="search" placeholder="Shopper name or email" maxlength="120" aria-label="Search">
        <button type="submit" class="btn">Search</button>
    </form>

    <div class="card">
        @forelse ($drafts as $draft)
            @php $car = $draft->vehicle; @endphp
            <button type="button" class="ck-row" wire:click="toggle({{ $draft->id }})" wire:key="ck-{{ $draft->id }}" aria-expanded="{{ $openId === $draft->id ? 'true' : 'false' }}">
                <span><b>{{ $draft->user?->name }}</b><span class="meta">{{ $draft->user?->email }}{{ $draft->phone() ? ' · ' . $draft->phone() : '' }}</span></span>
                <span>{{ $car ? "{$car->model_year} {$car->make} {$car->model}" : 'Car removed' }}<span class="meta">{{ $car?->display_price }}{{ $this->distance($draft) ? ' · ' . $this->distance($draft) : '' }}</span></span>
                <span><span class="step {{ $draft->step_key === 'reserve' ? 'pay' : '' }}">{{ $draft->stepLabel() }}</span></span>
                <span>{{ $draft->last_active_at->diffForHumans() }}<span class="meta">{{ $draft->emails->count() }} {{ $draft->emails->count() === 1 ? 'email' : 'emails' }} sent{{ $draft->user?->marketing_unsubscribed_at ? ' · unsubscribed' : '' }}</span></span>
                <span class="ck-more">{{ $openId === $draft->id ? 'Close' : 'Open' }}</span>
            </button>

            @if ($openId === $draft->id)
                <div class="ck-detail" wire:key="ck-detail-{{ $draft->id }}">
                    <div>
                        <h3>Follow-up emails</h3>
                        @if ($draft->emails->isEmpty())
                            <p class="meta">None sent yet.</p>
                        @else
                            <ul>
                                @foreach ($draft->emails as $email)
                                    <li>{{ $email->label() }} · {{ $email->sent_at->format('M j, g:i a') }}</li>
                                @endforeach
                            </ul>
                        @endif

                        <h3 style="margin-top:16px">Resume link</h3>
                        <div class="ck-copy" x-data="{ copied: false }">
                            <input type="text" readonly value="{{ $draft->resumeUrl() }}" aria-label="Resume link" x-ref="link">
                            <button type="button" @click="navigator.clipboard.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 1500)" x-text="copied ? 'Copied' : 'Copy'">Copy</button>
                        </div>
                        <p class="meta" style="margin-top:6px">Sends them back to the step they stopped at, with everything they entered. They sign in first if needed.</p>
                        @if ($car)
                            <p class="meta" style="margin-top:6px">Car: <a href="{{ url('/cars/' . $car->id) }}" target="_blank" rel="noopener">view listing</a> · {{ $car->dealer?->name }} (internal only)</p>
                        @endif
                    </div>
                    <form wire:submit="saveNote">
                        <h3>Staff note</h3>
                        <textarea wire:model="note" maxlength="2000" placeholder="e.g. Called Nov 4, wants to see it Saturday"></textarea>
                        <div style="margin-top:8px"><button type="submit" class="ck-save">Save note</button>@if ($flash)<span class="ok">{{ $flash }}</span>@endif</div>
                    </form>
                </div>
            @endif
        @empty
            <div class="empty">{{ $search !== '' ? 'Nobody matches that search.' : 'Nothing here.' }}</div>
        @endforelse
    </div>

    <div class="pages">{{ $drafts->links() }}</div>
</div>
