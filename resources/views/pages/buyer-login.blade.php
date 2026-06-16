<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

new #[Layout('layouts.checkout')] class extends Component {
    public string $email = '';
    public string $password = '';
    public bool $remember = true;

    public function mount(): void
    {
        if (Auth::check()) {
            $this->redirectIntended(default: route('garage'));
        }
    }

    public function signIn(): void
    {
        $credentials = $this->validate([
            'email'    => ['required', 'email', 'max:160'],
            'password' => ['required', 'string'],
        ]);

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            $secondsLeft = RateLimiter::availableIn($this->throttleKey());
            $this->addError('email', "Too many attempts — try again in {$secondsLeft} seconds.");

            return;
        }

        if (! Auth::attempt($credentials, $this->remember)) {
            RateLimiter::hit($this->throttleKey());
            $this->addError('email', "Those details don't match an account.");

            return;
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        // Lands back wherever they were headed — usually the reserve journey
        // that sent them here — otherwise their garage.
        $this->redirectIntended(default: route('garage'));
    }

    protected function throttleKey(): string
    {
        return Str::lower($this->email) . '|' . request()->ip();
    }
}; ?>

@push('styles')
    <style>
        .auth-top { border-bottom:1px solid var(--line); background:var(--card); }
        .auth-top-inner { max-width:1080px; margin:0 auto; padding:0 26px; display:flex; align-items:center; height:64px; }
        .auth-wrap { max-width:440px; margin:0 auto; padding:56px 26px 90px; }
        .auth-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-md); padding:32px 30px; }
        .auth-h1 { font-size:26px; font-weight:800; letter-spacing:-.025em; margin:0 0 6px; }
        .auth-lede { font-size:14px; color:var(--ink-2); margin:0 0 24px; line-height:1.55; }
        .auth-form { display:flex; flex-direction:column; gap:16px; }
        .auth-remember { display:flex; align-items:center; gap:9px; font-size:13.5px; color:var(--ink-2); font-weight:500; cursor:pointer; user-select:none; }
        .auth-remember input { width:16px; height:16px; accent-color:var(--primary); }
        .auth-swap { text-align:center; font-size:13.5px; color:var(--ink-2); margin-top:18px; }
        .field-error { color:var(--bad); font-size:12.5px; font-weight:600; margin:7px 0 0; }
    </style>
@endpush

<div>
    <header class="auth-top">
        <div class="auth-top-inner">
            <a href="/" class="brand"><span class="glyph">T</span> TruCars</a>
        </div>
    </header>

    <main class="auth-wrap">
        <div class="auth-card">
            <h1 class="auth-h1">Welcome back</h1>
            <p class="auth-lede">Sign in to pick up your reservation, or to see everything in your garage.</p>

            <form class="auth-form" wire:submit="signIn">
                <div class="field">
                    <label class="field-label">Email</label>
                    <input type="email" class="field-input" wire:model="email" autocomplete="email" autofocus>
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label">Password</label>
                    <input type="password" class="field-input" wire:model="password" autocomplete="current-password">
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <label class="auth-remember">
                    <input type="checkbox" wire:model="remember"> Keep me signed in
                </label>
                <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="signIn">
                    <span wire:loading.remove wire:target="signIn">Sign in →</span>
                    <span wire:loading wire:target="signIn">Signing in…</span>
                </button>
            </form>
        </div>

        <p class="auth-swap">First time here? <a href="{{ route('buyer.register') }}" class="text-link">Create your account</a></p>
    </main>
</div>
