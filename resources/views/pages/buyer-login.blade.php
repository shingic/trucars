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

        $credentialsMatched = Auth::attempt($credentials, $this->remember);

        // The marketplace signs in customers only. Staff and owner accounts use
        // their own portals; here they fail exactly like a wrong password.
        if ($credentialsMatched && (! Auth::user()->isCustomer() || Auth::user()->isSuspended())) {
            Auth::logout();
            $credentialsMatched = false;
        }

        if (! $credentialsMatched) {
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
    @include('partials.buyer-auth-styles')
@endpush

<div>
    <header class="auth-top">
        <div class="auth-top-inner">
            <a href="/" class="brand">@include('partials.logo', ['variant' => 'dark', 'height' => 38])</a>
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
                    <div class="auth-label-row">
                        <label class="field-label">Password</label>
                        <a href="{{ route('buyer.password.request', array_filter(['email' => $email])) }}" class="text-link auth-forgot">Forgot password?</a>
                    </div>
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
