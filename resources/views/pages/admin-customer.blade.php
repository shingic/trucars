<?php

use App\Mail\EmailVerificationCode;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — one customer's account, for support (customers.view).
|
| Shows what's needed to help: who they are, whether their email is verified,
| and their reservations. Never passwords, codes or tokens. Opening the page
| is audited.
|
| customers.assist adds the limited actions support can take: resend the
| email verification code, and keep internal notes. Each is audited.
*/
new #[Layout('layouts.dealer')] #[Title('Customer')] class extends Component {
    #[Locked]
    public int $customerId;

    public string $noteBody = '';

    public string $statusMessage = '';

    public function mount(User $customer): void
    {
        Gate::authorize(Permission::CUSTOMERS_VIEW);

        // Customer accounts only — support can't open staff or dealer accounts here.
        abort_unless($customer->isCustomer(), 404);

        $this->customerId = $customer->id;

        AuditLog::record('customer.viewed', $customer);
    }

    public function customer(): User
    {
        return User::query()->where('role', User::ROLE_CUSTOMER)->findOrFail($this->customerId);
    }

    public function reservations(): Collection
    {
        return $this->customer()->deals()->with(['vehicle', 'dealer'])->latest()->get();
    }

    public function notes(): Collection
    {
        return $this->customer()->supportNotes()->with('author')->get();
    }

    public function resendVerificationCode(): void
    {
        Gate::authorize(Permission::CUSTOMERS_ASSIST);
        $customer = $this->customer();

        if ($customer->email_verified_at !== null) {
            $this->statusMessage = 'This email is already verified.';

            return;
        }

        $code = $customer->issueEmailVerificationCode();

        try {
            Mail::to($customer->email)->send(new EmailVerificationCode($customer, $code));
        } catch (\Throwable $exception) {
            Log::error('Support resend of verification code failed', ['user_id' => $customer->id, 'error' => $exception->getMessage()]);
            $this->addError('noteBody', 'The email couldn’t be sent. Please try again.');

            return;
        }

        AuditLog::record('customer.verification_resent', $customer);
        $this->statusMessage = "Sent a new verification code to {$customer->email}.";
    }

    public function addNote(): void
    {
        Gate::authorize(Permission::CUSTOMERS_ASSIST);

        $this->validate(['noteBody' => ['required', 'string', 'max:2000']], attributes: ['noteBody' => 'note']);

        $note = $this->customer()->supportNotes()->create([
            'author_user_id' => auth()->id(),
            'body'           => trim($this->noteBody),
        ]);

        AuditLog::record('customer.note_added', $this->customer(), null, ['note_id' => $note->id]);

        $this->noteBody = '';
        $this->statusMessage = 'Note added.';
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    <a href="{{ route('admin.customers') }}" wire:navigate>Customers</a>
    <span>›</span>
    <span class="cur">{{ $this->customer()->name }}</span>
@endpush

@push('styles')
    <style>
        .cx-head h1 { font-family:var(--font-display); font-size:36px; font-weight:700; line-height:1; text-transform:uppercase; margin:0 0 10px; overflow-wrap:anywhere; }
        .cx-head p { margin:0 0 22px; font-size:15px; color:var(--ink-2); }
        .cx-status { background:var(--good-soft); color:#0B7355; border-left:3px solid var(--good); border-radius:5px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; max-width:880px; }
        .card { max-width:880px; margin-bottom:32px; border-top:2px solid var(--ink); }
        .card-head { padding:12px 0; border-bottom:1px solid var(--line-strong); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .card-head .ttl { font-family:var(--font-display); font-size:22px; font-weight:700; line-height:1.05; text-transform:uppercase; flex:1; }
        .kv { display:grid; grid-template-columns:180px 1fr; font-size:15px; }
        .kv > span { padding:11px 0; border-bottom:1px solid var(--line-strong); overflow-wrap:anywhere; }
        .kv .k { color:var(--ink-3); font-size:14px; padding-right:16px; }
        .pill { display:inline-block; font-family:var(--font-display); font-size:13px; font-weight:600; text-transform:uppercase; padding:1px 7px; border-radius:5px; background:var(--good-soft); color:#0B7355; margin-left:4px; vertical-align:1px; }
        .pill.warn { background:#FFF8DD; color:#7A4E00; }
        .kv .pill:first-child { margin-left:0; }
        .row { display:flex; align-items:center; gap:14px; padding:12px 0; border-bottom:1px solid var(--line-strong); font-size:15px; }
        .row > div { flex:1; min-width:0; }
        .row .meta { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .row a { font-weight:700; color:var(--primary-press); font-size:14px; text-decoration:underline; text-underline-offset:3px; white-space:nowrap; }
        .row a:hover { color:var(--ink); }
        .empty { padding:14px 0; border-bottom:1px solid var(--line-strong); font-size:15px; color:var(--ink-3); }
        .btn { font-size:14px; font-weight:700; border-radius:8px; padding:8px 15px; white-space:nowrap; border:1.5px solid transparent; transition:background .15s ease, color .15s ease; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:16px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .btn-ghost { background:#fff; border-color:var(--ink); color:var(--ink); }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .note-form { padding:16px 0 0; display:flex; flex-direction:column; gap:8px; }
        .note-form textarea { background:#fff; border:1.5px solid var(--line-strong); border-radius:8px; padding:10px 13px; font-size:15px; font-family:inherit; color:var(--ink); min-height:80px; }
        .note-form textarea:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 3px var(--primary-soft); }
        .note-form .end { display:flex; justify-content:flex-end; }
        .err { font-size:13px; color:#D81A48; font-weight:600; }
        .note-body { white-space:pre-wrap; }
        @media (max-width:640px){ .kv { grid-template-columns:1fr; } .kv .k { padding-bottom:0; border-bottom:none; } .cx-head h1 { font-size:30px; } }
    </style>
@endpush

@php($customer = $this->customer())

<div>
    <div class="cx-head">
        <h1>{{ $customer->name }}</h1>
        <p>Customer account · internal view, logged</p>
    </div>

    @if ($statusMessage !== '')
        <div class="cx-status">{{ $statusMessage }}</div>
    @endif

    <div class="card">
        <div class="card-head">
            <span class="ttl">Account</span>
            @can('customers.assist')
                @if ($customer->email_verified_at === null)
                    <button type="button" class="btn btn-ghost" wire:click="resendVerificationCode">Resend verification code</button>
                @endif
            @endcan
        </div>
        <div class="kv">
            <span class="k">Email</span>
            <span>
                {{ $customer->email }}
                @if ($customer->email_verified_at) <span class="pill">Verified</span> @else <span class="pill warn">Not verified</span> @endif
            </span>
            <span class="k">Customer since</span>
            <span>{{ $customer->created_at?->format('M j, Y') }}</span>
            <span class="k">Saved cars</span>
            <span>{{ $customer->favourite_vehicles_count }}</span>
            @if ($customer->isSuspended())
                <span class="k">Status</span>
                <span><span class="pill warn">Suspended</span></span>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-head"><span class="ttl">Reservations</span></div>
        @forelse ($this->reservations() as $deal)
            <div class="row" wire:key="deal-{{ $deal->id }}">
                <div>
                    <div><b>{{ $deal->reference }}</b> · {{ $deal->vehicle?->model_year }} {{ $deal->vehicle?->make }} {{ $deal->vehicle?->model }}</div>
                    <div class="meta">{{ $deal->seller_name }} · {{ $deal->stage_label }} · {{ $deal->created_at->format('M j, Y') }}</div>
                </div>
                @can('reservations.view')
                    <a href="{{ route('admin.reservations.show', $deal) }}" wire:navigate>Open →</a>
                @endcan
            </div>
        @empty
            <div class="empty">No reservations.</div>
        @endforelse
    </div>

    <div class="card">
        <div class="card-head"><span class="ttl">Support notes</span></div>
        @forelse ($this->notes() as $note)
            <div class="row" wire:key="note-{{ $note->id }}">
                <div>
                    <div class="note-body">{{ $note->body }}</div>
                    <div class="meta">{{ $note->author?->name ?? 'Removed user' }} · {{ $note->created_at->format('M j, Y g:i a') }} UTC</div>
                </div>
            </div>
        @empty
            <div class="empty">No notes yet.</div>
        @endforelse

        @can('customers.assist')
            <form class="note-form" wire:submit="addNote">
                <textarea wire:model="noteBody" maxlength="2000" placeholder="Internal note — never shown to the customer or dealer" aria-label="Note"></textarea>
                @error('noteBody') <span class="err">{{ $message }}</span> @enderror
                <div class="end"><button type="submit" class="btn btn-primary">Add note</button></div>
            </form>
        @endcan
    </div>
</div>
