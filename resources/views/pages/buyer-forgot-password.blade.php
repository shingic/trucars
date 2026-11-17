<?php

use App\Mail\PasswordResetLink;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * "Forgot password?" for shoppers. Always answers the same way, so it never
 * tells anyone whether an email has an account. Only shopper accounts get a
 * link; staff and dealer accounts are left alone here.
 */
new #[Layout('layouts.checkout')] #[Title('Reset your password | Trumo')] class extends Component {
    public string $email = '';
    public bool $sent = false;

    public function mount(): void
    {
        $this->email = (string) request()->query('email', '');
    }

    public function sendLink(): void
    {
        $this->validate(['email' => ['required', 'email', 'max:160']]);

        $key = 'forgot:' . Str::lower($this->email) . '|' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('email', 'Too many requests. Try again in ' . ceil(RateLimiter::availableIn($key) / 60) . ' minutes.');

            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $user = User::where('email', $this->email)->first();

        if ($user !== null && $user->isCustomer() && ! $user->isSuspended()) {
            $token = Password::broker()->createToken($user);
            $url = route('buyer.password.reset', ['token' => $token, 'email' => $user->email]);
            Mail::to($user->email)->send(new PasswordResetLink($user, $url, (int) config('auth.passwords.users.expire', 60)));
        }

        $this->sent = true;
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
            @if ($sent)
                <h1 class="auth-h1">Check your email</h1>
                <p class="auth-lede">If <b>{{ $email }}</b> has a Trumo account, we've sent it a link to choose a new password. It works for 60 minutes.</p>
                <p class="auth-lede" style="margin-bottom:0">Nothing there? Check your spam folder, or <button type="button" class="text-link auth-inline-btn" wire:click="$set('sent', false)">try another email</button>.</p>
            @else
                <h1 class="auth-h1">Forgot your password?</h1>
                <p class="auth-lede">Enter the email you signed up with and we'll send you a link to choose a new one.</p>

                <form class="auth-form" wire:submit="sendLink">
                    <div class="field">
                        <label class="field-label" for="forgot-email">Email</label>
                        <input id="forgot-email" type="email" class="field-input" wire:model="email" autocomplete="email" autofocus>
                        @error('email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="sendLink">
                        <span wire:loading.remove wire:target="sendLink">Send reset link →</span>
                        <span wire:loading wire:target="sendLink">Sending…</span>
                    </button>
                </form>
            @endif
        </div>

        <p class="auth-swap">Remembered it? <a href="{{ route('buyer.login') }}" class="text-link">Sign in</a></p>
    </main>
</div>
