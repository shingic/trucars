<?php

use App\Models\User;
use App\Mail\BuyerWelcome;
use App\Mail\EmailVerificationCode;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

new #[Layout('layouts.checkout')] class extends Component {
    public string $code = '';
    public string $statusMessage = '';

    public function mount(): void
    {
        if (! Auth::check()) {
            $this->redirect(route('buyer.login'));
            return;
        }

        if (Auth::user()->email_verified_at !== null) {
            $this->redirectIntended(default: route('garage'));
        }
    }

    public function verifyCode(): void
    {
        $this->statusMessage = '';

        $this->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.required' => 'Enter the 6-digit code from your email.',
            'code.digits'   => 'The code is 6 digits — check your email.',
        ]);

        $buyer = Auth::user();

        if ($buyer->email_verification_attempts >= 5) {
            $this->addError('code', 'Too many tries. Request a fresh code below.');
            return;
        }

        $codeHasExpired = $buyer->email_verification_code_expires_at === null
            || Carbon::parse($buyer->email_verification_code_expires_at)->isPast();

        if ($codeHasExpired) {
            $this->addError('code', 'That code has expired. Request a new one below.');
            return;
        }

        if (! hash_equals((string) $buyer->email_verification_code, $this->code)) {
            $buyer->increment('email_verification_attempts');
            $this->addError('code', 'That code isn\'t right — double-check and try again.');
            return;
        }

        // Confirmed. Mark verified, clear the code, and welcome the real account.
        $buyer->forceFill([
            'email_verified_at'                  => now(),
            'email_verification_code'            => null,
            'email_verification_code_expires_at' => null,
            'email_verification_attempts'        => 0,
        ])->save();

        $this->emailWelcome($buyer);

        $this->redirectIntended(default: route('garage'));
    }

    public function resendCode(): void
    {
        $buyer = Auth::user();
        $throttleKey = 'resend-otp:' . $buyer->id;

        if (RateLimiter::tooManyAttempts($throttleKey, maxAttempts: 3)) {
            $secondsLeft = RateLimiter::availableIn($throttleKey);
            $this->addError('code', "Hold on — you can request another code in {$secondsLeft}s.");
            return;
        }

        RateLimiter::hit($throttleKey, decaySeconds: 60);

        $verificationCode = (string) random_int(100000, 999999);

        $buyer->forceFill([
            'email_verification_code'            => $verificationCode,
            'email_verification_code_expires_at' => now()->addMinutes(10),
            'email_verification_attempts'        => 0,
        ])->save();

        $this->reset('code');

        try {
            Mail::to($buyer->email)->send(new EmailVerificationCode($buyer, $verificationCode));
            $this->statusMessage = 'A fresh code is on its way to your inbox.';
        } catch (\Throwable $sendFailure) {
            Log::error('Resend verification code email failed.', [
                'user_id' => $buyer->id,
                'error'   => $sendFailure->getMessage(),
            ]);
            $this->addError('code', 'We couldn\'t send a new code just now — try again in a moment.');
        }
    }

    /**
     * Welcome the confirmed account. A mail failure must never block the buyer
     * from getting into their garage, so it's swallowed and logged.
     */
    private function emailWelcome(User $buyer): void
    {
        try {
            Mail::to($buyer->email)->send(new BuyerWelcome($buyer));
        } catch (\Throwable $sendFailure) {
            Log::error('Welcome email failed to send.', [
                'user_id' => $buyer->id,
                'error'   => $sendFailure->getMessage(),
            ]);
        }
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
        .code-input { width:100%; text-align:center; font-family:'Geist Mono', ui-monospace, monospace; font-size:30px; font-weight:700; letter-spacing:0.32em; padding:14px 12px; }
        .auth-status { font-size:13px; font-weight:600; color:var(--good); margin:2px 0 0; }
        .field-error { color:var(--bad); font-size:12.5px; font-weight:600; margin:7px 0 0; }
        .resend-line { text-align:center; font-size:13px; color:var(--ink-2); margin-top:16px; }
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
            <h1 class="auth-h1">Check your email</h1>
            <p class="auth-lede">We sent a 6-digit code to <strong>{{ auth()->user()->email }}</strong>. Enter it below to confirm your account.</p>

            <form class="auth-form" wire:submit="verifyCode">
                <div class="field">
                    <input type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" class="field-input code-input" wire:model="code" placeholder="••••••" autofocus>
                    @error('code')
                    <p class="field-error">{{ $message }}</p>
                    @enderror
                    @if ($statusMessage)
                        <p class="auth-status">{{ $statusMessage }}</p>
                    @endif
                </div>
                <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="verifyCode">
                    <span wire:loading.remove wire:target="verifyCode">Confirm my email →</span>
                    <span wire:loading wire:target="verifyCode">Confirming…</span>
                </button>
            </form>

            <p class="resend-line">
                Didn't get it?
                <button type="button" class="text-link" wire:click="resendCode" wire:loading.attr="disabled" wire:target="resendCode">Send a new code</button>
            </p>
        </div>
    </main>
</div>
