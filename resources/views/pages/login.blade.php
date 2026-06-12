<?php

use Livewire\Component;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Illuminate\Support\Facades\Auth;

new #[Layout('layouts.auth')] class extends Component {
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $rememberMe = false;

    public function signIn()
    {
        $this->validate();

        $credentialsMatched = Auth::attempt(
            ['email' => $this->email, 'password' => $this->password],
            $this->rememberMe,
        );

        if (! $credentialsMatched) {
            $this->addError('email', 'Those credentials don’t match any dealer account.');

            return;
        }

        request()->session()->regenerate();

        return redirect()->intended(route('dealer.reservations'));
    }
}; ?>

@push('styles')
    <style>
        .auth-screen { min-height:100vh; display:grid; grid-template-columns:1.05fr .95fr; }

        .auth-brand {
            position:relative; overflow:hidden; color:#fff; padding:46px 54px;
            display:flex; flex-direction:column;
            background:
                radial-gradient(120% 80% at 12% 10%, rgba(245,99,31,.24), transparent 55%),
                radial-gradient(90% 70% at 88% 102%, rgba(255,138,61,.13), transparent 60%),
                linear-gradient(160deg, #1B1E24 0%, #121317 60%, #0E0F12 100%);
        }
        .auth-brand::after {
            content:""; position:absolute; inset:0; pointer-events:none; opacity:.5;
            background-image:radial-gradient(rgba(255,255,255,.05) 1px, transparent 1px);
            background-size:22px 22px;
        }
        .auth-brand > * { position:relative; z-index:1; }

        .auth-brand-mark { display:inline-flex; align-items:center; gap:11px; font-weight:800; font-size:18px; letter-spacing:-.02em; }
        .auth-brand-glyph { width:30px; height:30px; border-radius:9px; background:var(--primary); display:grid; place-items:center; font-size:15px; color:#fff; box-shadow:0 8px 22px rgba(245,99,31,.4); }

        .auth-brand-body { margin:auto 0; max-width:430px; }
        .auth-brand-headline { font-size:42px; line-height:1.08; font-weight:800; letter-spacing:-.035em; margin:0 0 18px; }
        .auth-brand-sub { font-size:16px; line-height:1.6; color:#B9BEC6; margin:0; max-width:400px; }

        .auth-brand-foot { display:inline-flex; align-items:center; gap:9px; font-size:12.5px; font-weight:600; color:#8A9098; }
        .auth-brand-foot-dot { width:7px; height:7px; border-radius:50%; background:var(--good); box-shadow:0 0 0 4px rgba(18,184,134,.18); }

        .auth-form-panel { display:flex; flex-direction:column; justify-content:center; align-items:center; padding:46px; position:relative; }
        .auth-mobile-brand { display:none; align-items:center; gap:10px; font-weight:800; font-size:17px; letter-spacing:-.02em; color:var(--ink); margin-bottom:26px; }
        .auth-form { width:100%; max-width:372px; }

        .auth-kicker { display:inline-block; font-size:11.5px; font-weight:700; letter-spacing:.13em; text-transform:uppercase; color:var(--primary); margin-bottom:14px; }
        .auth-title { font-size:30px; font-weight:800; letter-spacing:-.03em; margin:0 0 7px; }
        .auth-lead { font-size:14.5px; color:var(--ink-2); margin:0 0 30px; }

        .auth-fields { display:flex; flex-direction:column; gap:18px; }
        .auth-field { display:flex; flex-direction:column; gap:7px; }
        .auth-field label { font-size:12.5px; font-weight:600; color:var(--ink-2); }
        .auth-field input { width:100%; border:1.5px solid var(--line-strong); border-radius:12px; padding:13px 15px; font-size:14.5px; color:var(--ink); outline:none; background:#fff; transition:border-color .15s ease, box-shadow .15s ease; }
        .auth-field input::placeholder { color:var(--ink-3); }
        .auth-field input:focus { border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        .auth-field input.has-error { border-color:var(--primary-press); }

        .auth-password { position:relative; display:flex; align-items:center; }
        .auth-password input { padding-right:64px; }
        .auth-password-toggle { position:absolute; right:11px; font-size:12px; font-weight:700; color:var(--ink-3); padding:6px 8px; border-radius:7px; }
        .auth-password-toggle:hover { color:var(--primary); }

        .auth-error { font-size:12px; color:var(--primary-press); font-weight:600; }

        .auth-remember { display:inline-flex; align-items:center; gap:10px; cursor:pointer; font-size:13.5px; color:var(--ink-2); user-select:none; }
        .auth-remember input { position:absolute; opacity:0; width:0; height:0; }
        .auth-remember-box { width:20px; height:20px; border-radius:6px; border:1.5px solid var(--line-strong); display:grid; place-items:center; color:#fff; flex-shrink:0; transition:all .15s ease; }
        .auth-remember-box svg { opacity:0; transition:opacity .12s ease; }
        .auth-remember input:checked + .auth-remember-box { background:var(--primary); border-color:var(--primary); }
        .auth-remember input:checked + .auth-remember-box svg { opacity:1; }
        .auth-remember input:focus-visible + .auth-remember-box { box-shadow:0 0 0 4px var(--primary-soft); }

        .auth-submit { margin-top:6px; width:100%; background:var(--primary); color:#fff; font-size:15px; font-weight:700; padding:14px 20px; border-radius:12px; box-shadow:0 12px 26px rgba(245,99,31,.28); transition:all .15s ease; }
        .auth-submit:hover { background:var(--primary-press); }
        .auth-submit[disabled] { opacity:.65; cursor:wait; box-shadow:none; }

        .auth-legal { position:absolute; bottom:26px; font-size:12px; color:var(--ink-3); }

        @media (max-width:880px){
            .auth-screen { grid-template-columns:1fr; }
            .auth-brand { display:none; }
            .auth-form-panel { min-height:100vh; }
            .auth-mobile-brand { display:inline-flex; }
        }
    </style>
@endpush

<div class="auth-screen">
    <aside class="auth-brand">
        <div class="auth-brand-mark"><span class="auth-brand-glyph">T</span> Trueleads</div>

        <div class="auth-brand-body">
            <h1 class="auth-brand-headline">Your digital retail desk.</h1>
            <p class="auth-brand-sub">Reservations, verified buyers and committed deals — assembled and waiting for you each morning.</p>
        </div>

        <div class="auth-brand-foot">
            <span class="auth-brand-foot-dot"></span> Certified inventory · Verified buyers · All-in pricing
        </div>
    </aside>

    <main class="auth-form-panel">
        <div class="auth-mobile-brand"><span class="auth-brand-glyph">T</span> Trueleads</div>

        <div class="auth-form" x-data="{ showPassword: false }">
            <span class="auth-kicker">Dealer desk</span>
            <h2 class="auth-title">Welcome back</h2>
            <p class="auth-lead">Sign in to manage your reservations and inquiries.</p>

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

        <p class="auth-legal">Trueleads · Dealer access only</p>
    </main>
</div>
