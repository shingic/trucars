<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Marketing\CheckoutRecovery;
use App\Support\Marketing\Consent;
use App\Support\Marketing\EmailTypes;
use App\Support\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/*
| Command Centre: everyone who signed up as a shopper (marketing.view), with
| their saved cars, reservations and whether they can be emailed. Shoppers
| belong to the platform: dealers never see this list. Opening it and
| searching are audited.
*/
new #[Layout('layouts.dealer')] #[Title('Shoppers')] class extends Component {
    use WithPagination;

    public const FILTERS = [
        'all'       => 'Everyone',
        'saved'     => 'Has saved cars',
        'browsing'  => 'Never reserved',
        'emailable' => 'Can be emailed',
    ];

    public string $filter = 'all';

    public string $search = '';

    public function mount(): void
    {
        Gate::authorize(Permission::MARKETING_VIEW);
        AuditLog::record('shoppers.listed');
    }

    public function showFilter(string $filter): void
    {
        abort_unless(array_key_exists($filter, self::FILTERS), 422);
        $this->filter = $filter;
        $this->resetPage();
    }

    public function applySearch(): void
    {
        Gate::authorize(Permission::MARKETING_VIEW);
        $this->search = trim($this->search);
        $this->validate(['search' => ['nullable', 'string', 'max:120']]);
        $this->resetPage();

        if ($this->search !== '') {
            AuditLog::record('shoppers.searched', null, null, ['search' => $this->search]);
        }
    }

    public function with(): array
    {
        Gate::authorize(Permission::MARKETING_VIEW);

        return [
            'shoppers' => $this->filtered($this->filter)
                ->addSelect(['reservations_count' => DB::table('deals')->selectRaw('count(*)')->whereColumn('deals.user_id', 'users.id')])
                ->addSelect(['saved_count' => DB::table('favourites')->selectRaw('count(*)')->whereColumn('favourites.user_id', 'users.id')])
                ->addSelect(['last_saved_at' => DB::table('favourites')->selectRaw('max(created_at)')->whereColumn('favourites.user_id', 'users.id')])
                ->addSelect(['last_checkout_at' => DB::table('checkout_drafts')->selectRaw('max(last_active_at)')->whereColumn('checkout_drafts.user_id', 'users.id')])
                ->latest('users.created_at')
                ->paginate(30),
            'counts'  => collect(array_keys(self::FILTERS))->mapWithKeys(fn ($key) => [$key => $this->filtered($key, withSearch: false)->count()]),
            'blocked' => CheckoutRecovery::blockedReason(),
        ];
    }

    private function filtered(string $filter, bool $withSearch = true): Builder
    {
        $term = '%' . str_replace(['%', '_'], ['\%', '\_'], $this->search) . '%';

        return User::query()
            ->select('users.*')
            ->where('role', User::ROLE_CUSTOMER)
            ->when($withSearch && $this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('email', 'like', $term)))
            ->when($filter === 'saved', fn ($q) => $q->whereExists(fn ($e) => $e->from('favourites')->whereColumn('favourites.user_id', 'users.id')))
            ->when($filter === 'browsing', fn ($q) => $q->whereNotExists(fn ($e) => $e->from('deals')->whereColumn('deals.user_id', 'users.id')))
            ->when($filter === 'emailable', fn ($q) => $q
                ->whereNotNull('email_verified_at')->whereNull('marketing_unsubscribed_at')->whereNull('suspended_at')
                ->where(fn ($consent) => $consent
                    ->where('users.created_at', '>=', now()->subMonths(Consent::INQUIRY_MONTHS))
                    ->orWhereExists(fn ($e) => $e->from('favourites')->whereColumn('favourites.user_id', 'users.id')->where('favourites.created_at', '>=', now()->subMonths(Consent::INQUIRY_MONTHS)))
                    ->orWhereExists(fn ($e) => $e->from('checkout_drafts')->whereColumn('checkout_drafts.user_id', 'users.id')->where('checkout_drafts.last_active_at', '>=', now()->subMonths(Consent::INQUIRY_MONTHS)))
                    ->orWhereExists(fn ($e) => $e->from('deals')->whereColumn('deals.user_id', 'users.id')->where('deals.created_at', '>=', now()->subMonths(Consent::PURCHASE_MONTHS)))));
    }

    /** "All on", "3 of 5 on", "Unsubscribed", "Consent expired", "Email not verified". */
    public function emailStatus(User $shopper): array
    {
        $total = count(EmailTypes::ALL);
        $on = $total - count(array_intersect($shopper->emailTypesOff(), array_keys(EmailTypes::ALL)));

        return match (true) {
            $shopper->marketing_unsubscribed_at !== null => ['Unsubscribed', 'off'],
            $shopper->email_verified_at === null         => ['Email not verified', 'off'],
            ! Consent::implied($shopper)                 => ['Consent expired', 'off'],
            $on === $total                               => ['All on', 'on'],
            default                                      => ["{$on} of {$total} on", 'some'],
        };
    }

    public function lastActive(User $shopper): ?\Illuminate\Support\Carbon
    {
        return collect([$shopper->created_at, $shopper->last_saved_at, $shopper->last_checkout_at])
            ->filter()->map(fn ($at) => \Illuminate\Support\Carbon::parse($at))->max();
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Shoppers</span>
@endpush

@push('styles')
    <style>
        .sh-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; }
        .sh-head p { margin:0 0 18px; font-size:15px; color:var(--ink-2); max-width:760px; }
        .sh-warn { margin:0 0 18px; padding:12px 14px; border-left:4px solid #C59A00; background:#FFF8DD; color:#5C3D00; font-size:14.5px; font-weight:600; border-radius:6px; }
        .sh-warn a { color:inherit; }
        .sh-tabs { display:flex; gap:4px; border-bottom:1px solid var(--line-strong); margin-bottom:16px; overflow-x:auto; }
        .sh-tab { font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; color:var(--ink-3); padding:10px 16px 11px; border-bottom:3px solid transparent; margin-bottom:-1px; white-space:nowrap; }
        .sh-tab:hover { color:var(--ink); }
        .sh-tab.on { color:var(--ink); border-bottom-color:var(--primary); }
        .sh-tab .n { font-family:var(--font); font-size:13px; color:var(--ink-2); background:rgba(7,20,55,.07); padding:1px 7px; border-radius:5px; margin-left:6px; }
        .sh-search { display:flex; gap:10px; margin-bottom:16px; max-width:560px; }
        .sh-search input { flex:1; min-width:0; background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font-size:14px; font-family:inherit; }
        .sh-search input:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .btn { font-family:var(--font-display); font-size:16px; font-weight:700; text-transform:uppercase; border-radius:8px; padding:9px 18px; background:var(--primary); color:#fff; }
        .btn:hover { background:var(--primary-press); }
        .card { border-top:2px solid var(--ink); }
        .sh-row { display:grid; grid-template-columns:minmax(0, 1.6fr) 120px 120px 90px 100px 150px; gap:14px; align-items:center; padding:12px 6px; border-bottom:1px solid var(--line-strong); font-size:14px; color:inherit; text-decoration:none; }
        a.sh-row:hover { background:#fff; }
        .sh-row > span { min-width:0; overflow-wrap:anywhere; }
        .sh-row b { font-weight:700; color:var(--ink); }
        .sh-cols { font-size:12px; font-weight:700; text-transform:uppercase; color:var(--ink-3); }
        .meta { display:block; font-size:13px; color:var(--ink-3); }
        .pill { display:inline-block; font-size:12.5px; font-weight:700; padding:3px 8px; border-radius:5px; }
        .pill.on { background:#E6F7EC; color:#0E8A3A; }
        .pill.some { background:var(--primary-soft); color:var(--primary-press); }
        .pill.off { background:rgba(7,20,55,.07); color:var(--ink-3); }
        .empty { margin-top:14px; padding:18px 20px; border:1.5px dashed var(--line-strong); border-radius:8px; color:var(--ink-3); font-size:15px; }
        .pages { margin-top:16px; }
        @media (max-width:900px) { .sh-row { grid-template-columns:1fr 1fr; } .sh-cols { display:none; } }
    </style>
@endpush

<div>
    <div class="sh-head">
        <h1>Shoppers</h1>
        <p>Everyone who signed up, newest first. Saved-car emails (price drops, a saved car gone, a nudge after 3 days) go out automatically to those who can be emailed. Dealers never see shoppers until they pay a deposit. Viewing is logged.</p>
    </div>

    @if ($blocked)
        <div class="sh-warn">Marketing emails are paused. {{ $blocked }} @can('settings.manage') <a href="{{ route('admin.settings') }}" wire:navigate>Open Platform settings →</a> @endcan</div>
    @endif

    <div class="sh-tabs">
        @foreach (self::FILTERS as $key => $label)
            <button type="button" class="sh-tab {{ $filter === $key ? 'on' : '' }}" wire:click="showFilter('{{ $key }}')">{{ $label }}<span class="n">{{ $counts[$key] }}</span></button>
        @endforeach
    </div>

    <form class="sh-search" wire:submit="applySearch">
        <input type="search" wire:model="search" placeholder="Shopper name or email" maxlength="120" aria-label="Search">
        <button type="submit" class="btn">Search</button>
    </form>

    <div class="card">
        <div class="sh-row sh-cols"><span>Shopper</span><span>Signed up</span><span>Last active</span><span>Saved</span><span>Reserved</span><span>Emails</span></div>
        @forelse ($shoppers as $shopper)
            @php [$statusLabel, $statusTone] = $this->emailStatus($shopper); @endphp
            <a href="{{ route('admin.customers.show', $shopper) }}" class="sh-row" wire:navigate wire:key="sh-{{ $shopper->id }}">
                <span><b>{{ $shopper->name }}</b><span class="meta">{{ $shopper->email }}</span></span>
                <span>{{ $shopper->created_at?->format('M j, Y') }}</span>
                <span>{{ $this->lastActive($shopper)?->diffForHumans() }}</span>
                <span>{{ $shopper->saved_count }} {{ (int) $shopper->saved_count === 1 ? 'car' : 'cars' }}</span>
                <span>{{ $shopper->reservations_count ?: '—' }}</span>
                <span><span class="pill {{ $statusTone }}">{{ $statusLabel }}</span></span>
            </a>
        @empty
            <div class="empty">{{ $search !== '' ? 'Nobody matches that search.' : 'Nobody here yet.' }}</div>
        @endforelse
    </div>

    <div class="pages">{{ $shoppers->links() }}</div>
</div>
