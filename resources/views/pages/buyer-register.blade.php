<?php

use App\Models\User;
use App\Mail\EmailVerificationCode;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

new #[Layout('layouts.checkout')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        if (Auth::check()) {
            if (Auth::user()->email_verified_at === null) {
                $this->redirect(route('verify'));
                return;
            }
            $this->redirectIntended(default: route('garage'));
        }
    }

    public function createAccount(): void
    {
        $accountDetails = $this->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:160', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique'       => 'There is already an account with this email — try signing in instead.',
            'password.confirmed' => 'These passwords don\'t match.',
        ]);

        $newBuyer = User::create([
            'name'     => $accountDetails['name'],
            'email'    => $accountDetails['email'],
            'password' => $accountDetails['password'], // hashed by the model's cast
        ]);

        // Stamp a fresh code on the account before logging in.
        $verificationCode = $this->issueVerificationCode($newBuyer);

        Auth::login($newBuyer, remember: true);
        session()->regenerate();

        // Send the code now. The welcome email is deliberately held until the
        // email is actually confirmed — it fires from the verify page instead.
        $this->emailVerificationCode($newBuyer, $verificationCode);

        $this->redirect(route('verify'));
    }

    /**
     * Stamp a fresh 6-digit code on the buyer, valid for 10 minutes, and reset
     * the failed-attempt counter. Returns the plain code for the email.
     */
    private function issueVerificationCode(User $buyer): string
    {
        $verificationCode = (string) random_int(100000, 999999);

        $buyer->forceFill([
            'email_verification_code'            => $verificationCode,
            'email_verification_code_expires_at' => now()->addMinutes(10),
            'email_verification_attempts'        => 0,
        ])->save();

        return $verificationCode;
    }

    /**
     * A mail failure must never block account creation, so it's swallowed and
     * logged — the buyer can always resend the code from the verify page.
     */
    private function emailVerificationCode(User $buyer, string $verificationCode): void
    {
        try {
            Mail::to($buyer->email)->send(new EmailVerificationCode($buyer, $verificationCode));
        } catch (\Throwable $sendFailure) {
            Log::error('Verification code email failed to send.', [
                'user_id' => $buyer->id,
                'email'   => $buyer->email,
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
        .auth-swap { text-align:center; font-size:13.5px; color:var(--ink-2); margin-top:18px; }
        .auth-fineprint { font-size:11.5px; color:var(--ink-3); line-height:1.5; margin:2px 0 0; }
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
            <h1 class="auth-h1">Create your account</h1>
            <p class="auth-lede">One account holds your reservation, your deal status, and — once the keys are yours — your garage.</p>

            <form class="auth-form" wire:submit="createAccount">
                <div class="field">
                    <label class="field-label">Full name</label>
                    <input type="text" class="field-input" wire:model="name" autocomplete="name" autofocus>
                    @error('name') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label">Email</label>
                    <input type="email" class="field-input" wire:model="email" autocomplete="email">
                    @error('email') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label">Password</label>
                    <input type="password" class="field-input" wire:model="password" autocomplete="new-password">
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label">Confirm password</label>
                    <input type="password" class="field-input" wire:model="password_confirmation" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="createAccount">
                    <span wire:loading.remove wire:target="createAccount">Create account →</span>
                    <span wire:loading wire:target="createAccount">Creating…</span>
                </button>
                <p class="auth-fineprint">Creating an account is free and never starts a purchase — reserving a car is always a separate, deliberate step.</p>
            </form>
        </div>

        <p class="auth-swap">Already have an account? <a href="{{ route('buyer.login') }}" class="text-link">Sign in</a></p>
    </main>
</div>
