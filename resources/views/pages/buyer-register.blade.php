<?php

use App\Models\User;
use Livewire\Component;
use Livewire\Attributes\Layout;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.checkout')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        if (Auth::check()) {
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

        Auth::login($newBuyer, remember: true);
        session()->regenerate();

        // Straight back into the reserve journey if that's what brought them here.
        $this->redirectIntended(default: route('garage'));
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
            <a href="/" class="brand"><span class="glyph">T</span> Trueleads</a>
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
