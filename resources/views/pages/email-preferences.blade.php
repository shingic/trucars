<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Marketing\EmailTypes;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Email preferences: one switch per type of marketing email, plus "all
| marketing". Reached by a signed link (from every marketing email, and from
| My Garage), so it works without signing in; the signature is checked when
| the page opens and the account id is locked after that. Every change is
| audited. Transactional email can't be switched off.
*/
new #[Layout('layouts.checkout')] #[Title('Email preferences · TruCars')] class extends Component {
    #[Locked]
    public int $userId;

    public string $saved = '';

    public function mount(User $user): void
    {
        abort_unless($user->isCustomer(), 404);
        $this->userId = $user->id;
    }

    private function account(): User
    {
        return User::findOrFail($this->userId);
    }

    public function toggleAll(): void
    {
        $user = $this->account();
        $before = $user->marketing_unsubscribed_at;

        if ($before === null) {
            $user->marketing_unsubscribed_at = now();
        } else {
            // Back on: every type on again, as when they signed up.
            $user->marketing_unsubscribed_at = null;
            $user->email_preferences = ['off' => []];
        }

        $user->save();
        AuditLog::record($before === null ? 'marketing.unsubscribed' : 'marketing.resubscribed', $user);
        $this->saved = $before === null ? 'You’re unsubscribed from all marketing emails.' : 'Marketing emails are back on.';
    }

    public function toggleType(string $type): void
    {
        abort_unless(EmailTypes::exists($type), 422);

        $user = $this->account();
        $offBefore = $user->emailTypesOff();
        $turningOn = in_array($type, $offBefore, true) || $user->marketing_unsubscribed_at !== null;

        if ($turningOn && $user->marketing_unsubscribed_at !== null) {
            // Turning one type on while unsubscribed from all: only that one comes back.
            $user->marketing_unsubscribed_at = null;
            $user->email_preferences = ['off' => array_values(array_diff(array_keys(EmailTypes::ALL), [$type]))];
        } else {
            $user->setEmailType($type, $turningOn);
        }

        $user->save();
        AuditLog::record('marketing.preferences_updated', $user, ['off' => $offBefore], ['off' => $user->emailTypesOff(), 'all_off' => $user->marketing_unsubscribed_at !== null]);
        $this->saved = EmailTypes::label($type) . ($turningOn ? ' on.' : ' off.');
    }

    public function with(): array
    {
        $user = $this->account();
        $allOff = $user->marketing_unsubscribed_at !== null;
        $on = collect(array_keys(EmailTypes::ALL))->filter(fn ($type) => ! $allOff && ! in_array($type, $user->emailTypesOff(), true));

        return ['account' => $user, 'allOff' => $allOff, 'onTypes' => $on->values()->all()];
    }
}; ?>

<div>
    <style>
        .ep-top { border-bottom:1px solid var(--line-strong); background:#fff; }
        .ep-top-inner { max-width:880px; margin:0 auto; padding:0 16px; height:64px; display:flex; align-items:center; }
        .ep-top .wordmark { font-family:var(--font-display); font-size:24px; font-weight:800; text-transform:uppercase; text-decoration:none; color:var(--ink); }
        .ep-top .wordmark b { color:var(--primary); }
        .ep { max-width:880px; margin:0 auto; padding:40px 16px 60px; }
        .ep h1 { font-size:40px; text-transform:uppercase; line-height:1; margin:0 0 8px; }
        .ep .who { color:var(--ink-2); margin:0 0 28px; }
        .ep-row { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; padding:20px 0; border-bottom:1px solid var(--line-strong); }
        .ep-row h2 { font-family:var(--font-ui); font-size:17px; font-weight:700; margin:0 0 4px; }
        .ep-row p { margin:0; font-size:14.5px; color:var(--ink-2); max-width:600px; }
        .ep-count { font-size:13.5px; color:var(--ink-3); margin-top:4px; }
        .ep-types { padding-left:18px; border-left:2px solid var(--line-strong); margin:4px 0 0; }
        .ep-types .ep-row { padding:14px 0; }
        .ep-types h3 { font-family:var(--font-ui); font-size:15.5px; font-weight:600; margin:0 0 2px; }
        .sw { flex:none; width:48px; height:28px; border-radius:999px; background:#D5D7DD; position:relative; transition:background .15s; }
        .sw::after { content:""; position:absolute; top:3px; left:3px; width:22px; height:22px; border-radius:50%; background:#fff; box-shadow:0 1px 2px rgba(0,0,0,.2); transition:left .15s; }
        .sw.on { background:var(--ink); }
        .sw.on::after { left:23px; }
        .sw:disabled { opacity:.55; cursor:not-allowed; }
        .ep-saved { margin:18px 0 0; font-size:14px; font-weight:600; color:#0E8A3A; }
        @media (max-width:560px) { .ep h1 { font-size:32px; } }
    </style>

    <header class="ep-top">
        <div class="ep-top-inner"><a href="{{ route('shop') }}" style="display:inline-block;">@include('partials.logo', ['variant' => 'dark', 'height' => 36])</a></div>
    </header>

    <main class="ep">
        <h1>Email preferences</h1>
        <p class="who">For {{ $account->email }}</p>

        <div class="ep-row">
            <div>
                <h2>Transactional messages</h2>
                <p>Receipts, confirmations and next steps for a car you reserve, your ID check and paperwork. These always go out while a reservation is in progress, so you don’t miss something you need to act on.</p>
            </div>
            <button type="button" class="sw on" disabled aria-label="Transactional messages are always on"></button>
        </div>

        <div class="ep-row">
            <div>
                <h2>All marketing messages</h2>
                <div class="ep-count">{{ count($onTypes) }} of {{ count(\App\Support\Marketing\EmailTypes::ALL) }} on</div>
            </div>
            <button type="button" class="sw {{ $allOff ? '' : 'on' }}" wire:click="toggleAll" aria-pressed="{{ $allOff ? 'false' : 'true' }}" aria-label="All marketing messages"></button>
        </div>

        <div class="ep-types">
            @foreach (\App\Support\Marketing\EmailTypes::ALL as $type => [$label, $about])
                <div class="ep-row" wire:key="type-{{ $type }}">
                    <div>
                        <h3>{{ $label }}</h3>
                        <p>{{ $about }}</p>
                    </div>
                    <button type="button" class="sw {{ in_array($type, $onTypes, true) ? 'on' : '' }}" wire:click="toggleType('{{ $type }}')" aria-pressed="{{ in_array($type, $onTypes, true) ? 'true' : 'false' }}" aria-label="{{ $label }}"></button>
                </div>
            @endforeach
        </div>

        @if ($saved !== '')
            <p class="ep-saved" wire:key="saved-{{ md5($saved) }}">✓ {{ $saved }}</p>
        @endif
    </main>
</div>
