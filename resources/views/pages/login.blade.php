<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use App\Models\AuditLog;
use App\Models\User;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

new #[Layout('layouts.auth')] class extends Component {
    /**
     * Which portal this sign-in belongs to: 'dealer' (dealer host) or 'owner'
     * (admin host). Fixed at mount and locked, so an action request can't
     * switch it. Each portal admits exactly one role.
     */
    #[Locked]
    public string $portal = 'dealer';

    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $rememberMe = false;

    /** $portal comes from the route's defaults (admin.login sets 'owner'), never the client. */
    public function mount(string $portal = 'dealer'): void
    {
        $this->portal = $portal === 'owner' ? 'owner' : 'dealer';
    }

    public function signIn()
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $this->addError('email', 'Too many attempts — try again in ' . RateLimiter::availableIn($this->throttleKey()) . ' seconds.');

            return;
        }

        $credentialsMatched = Auth::attempt(
            ['email' => $this->email, 'password' => $this->password],
            $this->rememberMe,
        );

        // Right password, wrong portal (a buyer or owner here, staff on the admin
        // host) is treated exactly like a wrong password — no session is kept
        // and nothing says which accounts exist.
        if ($credentialsMatched && ! $this->accountBelongsHere(Auth::user())) {
            Auth::logout();
            $credentialsMatched = false;
        }

        if (! $credentialsMatched) {
            RateLimiter::hit($this->throttleKey());
            $this->addError('email', $this->portal === 'owner'
                ? 'Those credentials don’t match a TruCars staff account.'
                : 'Those credentials don’t match any dealer account.');

            return;
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        if ($this->portal === 'owner') {
            AuditLog::record('command_centre.signed_in', Auth::user());
        }

        return redirect()->to($this->landingUrl());
    }

    /**
     * Back to the page that sent them here — but only if it's on this portal's
     * own host. A "return to" URL left over from another TruCars site is ignored.
     */
    private function landingUrl(): string
    {
        $home = $this->portal === 'owner' ? route('admin.home') : route('dealer.reservations');
        $intended = session()->pull('url.intended');

        return is_string($intended) && parse_url($intended, PHP_URL_HOST) === parse_url($home, PHP_URL_HOST)
            ? $intended
            : $home;
    }

    private function accountBelongsHere(User $user): bool
    {
        if ($user->isSuspended()) {
            return false;
        }

        return $this->portal === 'owner'
            ? $user->canUseCommandCentre()
            : $user->isDealerStaff() && ! $user->dealer?->isSuspended();
    }

    private function throttleKey(): string
    {
        return $this->portal . '|' . Str::lower($this->email) . '|' . request()->ip();
    }
}; ?>

@push('styles')
    <style>
        .auth-screen { min-height:100vh; display:grid; grid-template-columns:1.05fr .95fr; }

        .auth-brand { color:#fff; padding:46px 54px; display:flex; flex-direction:column; background:var(--ink); }

        .auth-brand-mark { display:inline-flex; align-items:center; }
        .auth-brand .wordmark { color:#fff; font-size:28px; }
        .auth-brand .wordmark b { color:var(--primary-bright); }

        .auth-brand-body { margin:auto 0; max-width:440px; padding-top:22px; border-top:2px solid #fff; }
        .auth-brand-headline { font-family:var(--font-display); font-size:52px; line-height:1; font-weight:700; text-transform:uppercase; letter-spacing:0; margin:0 0 18px; }
        .auth-brand-sub { font-size:16px; line-height:1.6; color:rgba(255,255,255,.78); margin:0; max-width:400px; }

        .auth-brand-foot { display:inline-flex; align-items:center; gap:9px; padding-top:16px; border-top:1px solid rgba(255,255,255,.22); font-size:13px; font-weight:600; color:rgba(255,255,255,.72); }
        .auth-brand-foot-dot { width:8px; height:8px; border-radius:1px; background:var(--good); flex-shrink:0; }

        .auth-form-panel { display:flex; flex-direction:column; justify-content:center; align-items:center; padding:46px; position:relative; background:#fff; }
        .auth-mobile-brand { display:none; align-items:center; margin-bottom:26px; }
        .auth-form { width:100%; max-width:372px; }

        .auth-kicker { display:inline-block; font-family:var(--font-display); font-size:14px; font-weight:700; letter-spacing:.02em; text-transform:uppercase; color:#fff; background:var(--ink); padding:4px 8px; border-radius:2px; margin-bottom:16px; }
        .auth-title { font-family:var(--font-display); font-size:40px; font-weight:700; line-height:1; text-transform:uppercase; letter-spacing:0; margin:0 0 10px; }
        .auth-lead { font-size:15px; color:var(--ink-2); margin:0 0 24px; padding-bottom:22px; border-bottom:2px solid var(--ink); }

        .auth-fields { display:flex; flex-direction:column; gap:18px; }
        .auth-field { display:flex; flex-direction:column; gap:7px; }
        .auth-field label { font-size:14px; font-weight:700; color:var(--ink); }
        .auth-field input { width:100%; border:1.5px solid var(--line-strong); border-radius:3px; padding:13px 15px; font-size:15px; color:var(--ink); outline:none; background:#fff; transition:border-color .15s ease; }
        .auth-field input::placeholder { color:var(--ink-3); }
        .auth-field input:focus { border-color:var(--ink); box-shadow:0 0 0 2px var(--primary-soft); }
        .auth-field input.has-error { border-color:#D81A48; }

        .auth-password { position:relative; display:flex; align-items:center; }
        .auth-password input { padding-right:64px; }
        .auth-password-toggle { position:absolute; right:11px; font-size:13px; font-weight:700; color:var(--ink-2); padding:6px 8px; border-radius:2px; text-decoration:underline; text-underline-offset:3px; }
        .auth-password-toggle:hover { color:var(--primary); }
        .auth-password-toggle:focus-visible { outline:2px solid var(--ink); outline-offset:1px; }

        .auth-error { font-size:13px; color:#D81A48; font-weight:600; }

        .auth-remember { display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-size:14px; color:var(--ink-2); user-select:none; }
        .auth-remember input { position:absolute; opacity:0; width:0; height:0; }
        .auth-remember-box { width:20px; height:20px; border-radius:2px; border:1.5px solid var(--line-strong); background:#fff; display:grid; place-items:center; color:#fff; flex-shrink:0; transition:background-color .15s ease, border-color .15s ease; }
        .auth-remember-box svg { opacity:0; transition:opacity .12s ease; }
        .auth-remember input:checked + .auth-remember-box { background:var(--ink); border-color:var(--ink); }
        .auth-remember input:checked + .auth-remember-box svg { opacity:1; }
        .auth-remember input:focus-visible + .auth-remember-box { outline:2px solid var(--primary); outline-offset:2px; }

        .auth-submit { margin-top:6px; width:100%; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:18px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; padding:14px 20px; border-radius:3px; transition:background-color .15s ease; }
        .auth-submit:hover { background:var(--primary-press); }
        .auth-submit:focus-visible { outline:2px solid var(--ink); outline-offset:2px; }
        .auth-submit[disabled] { opacity:.65; cursor:wait; }

        .auth-legal { position:absolute; bottom:26px; font-size:13px; color:var(--ink-3); }

        @media (max-width:880px){
            .auth-screen { grid-template-columns:1fr; }
            .auth-brand { display:none; }
            .auth-form-panel { min-height:100vh; padding:46px 16px 80px; }
            .auth-mobile-brand { display:inline-flex; }
        }
    </style>
@endpush

<div class="auth-screen">
    <aside class="auth-brand">
        <div class="auth-brand-mark"><span class="wordmark">Tru<b>Cars</b></span></div>

        <div class="auth-brand-body">
            <h1 class="auth-brand-headline">Your digital retail desk.</h1>
            <p class="auth-brand-sub">Reservations, verified buyers and committed deals — assembled and waiting for you each morning.</p>
        </div>

        <div class="auth-brand-foot">
            <span class="auth-brand-foot-dot"></span> Certified inventory · Verified buyers · All-in pricing
        </div>
    </aside>

    <main class="auth-form-panel">
        <div class="auth-mobile-brand"><span class="wordmark">Tru<b>Cars</b></span></div>

        <div class="auth-form" x-data="{ showPassword: false }">
            <span class="auth-kicker">{{ $portal === 'owner' ? 'TruCars Command Centre' : 'Dealer desk' }}</span>
            <h2 class="auth-title">Welcome back</h2>
            <p class="auth-lead">{{ $portal === 'owner' ? 'Sign in with your TruCars staff account.' : 'Sign in to manage your reservations and inquiries.' }}</p>

            <form wire:submit="signIn" class="auth-fields">
                <div class="auth-field">
                    <label for="email">Email</label>
                    <input id="email" type="email" wire:model="email" autocomplete="username" autofocus
                           placeholder="you@dealership.com" @class(['has-error' => $errors->has('email')]) />
                    @error('email') <span class="auth-error">{{ $message }}</span> @enderror
                </div>

                <div class="auth-field">
                    <label for="password">Password</label>
                    <div class="auth-password">
                        <input id="password" :type="showPassword ? 'text' : 'password'" wire:model="password"
                               autocomplete="current-password" placeholder="••••••••"
                            @class(['has-error' => $errors->has('password')]) />
                        <button type="button" class="auth-password-toggle"
                                @click="showPassword = ! showPassword"
                                x-text="showPassword ? 'Hide' : 'Show'"></button>
                    </div>
                    @error('password') <span class="auth-error">{{ $message }}</span> @enderror
                </div>

                <label class="auth-remember">
                    <input type="checkbox" wire:model="rememberMe" />
                    <span class="auth-remember-box">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <span>Keep me signed in</span>
                </label>

                <button type="submit" class="auth-submit" wire:loading.attr="disabled" wire:target="signIn">
                    <span wire:loading.remove wire:target="signIn">Sign in</span>
                    <span wire:loading wire:target="signIn">Signing in…</span>
                </button>
            </form>
        </div>

        <p class="auth-legal">TruCars · {{ $portal === 'owner' ? 'TruCars staff only' : 'Dealer access only' }}</p>
    </main>
</div>
