<?php

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/** The page the reset email links to: choose a new password, then you're signed in. */
new #[Layout('layouts.checkout')] #[Title('Choose a new password | Trumo')] class extends Component {
    #[Locked]
    public string $token = '';

    #[Locked]
    public string $email = '';

    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request()->query('email', '');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.confirmed' => 'These passwords don\'t match.',
        ]);

        $user = User::where('email', $this->email)->first();

        // Only shopper accounts reset here. Anything else fails like a bad link.
        if ($user === null || ! $user->isCustomer() || $user->isSuspended()) {
            $this->addError('password', 'This link has expired or was already used. Ask for a new one.');

            return;
        }

        $status = Password::broker()->reset(
            ['email' => $this->email, 'token' => $this->token, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password, // hashed by the model's cast
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('password', 'This link has expired or was already used. Ask for a new one.');

            return;
        }

        Auth::login($user->fresh(), remember: true);
        session()->regenerate();
        session()->flash('status', 'Your password has been changed.');

        $this->redirect(route('garage'));
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
            <h1 class="auth-h1">Choose a new password</h1>
            <p class="auth-lede">For <b>{{ $email }}</b>. At least 8 characters.</p>

            <form class="auth-form" wire:submit="resetPassword">
                <div class="field">
                    <label class="field-label" for="new-password">New password</label>
                    <input id="new-password" type="password" class="field-input" wire:model="password" autocomplete="new-password" autofocus>
                    @error('password') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="field">
                    <label class="field-label" for="new-password-2">Confirm new password</label>
                    <input id="new-password-2" type="password" class="field-input" wire:model="password_confirmation" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="resetPassword">
                    <span wire:loading.remove wire:target="resetPassword">Save and sign in →</span>
                    <span wire:loading wire:target="resetPassword">Saving…</span>
                </button>
            </form>
        </div>

        <p class="auth-swap">Link not working? <a href="{{ route('buyer.password.request', ['email' => $email]) }}" class="text-link">Send a new one</a></p>
    </main>
</div>
