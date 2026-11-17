<?php

use App\Mail\ContactMessageReceived;
use App\Models\Lead;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Contact us. Messages land in Command Centre › Inquiries (as a lead with no
| car) and are emailed to site.contact_email, with reply-to set to the shopper.
*/
new #[Title('Contact us | Trumo')] class extends Component {
    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phone = '';
    public string $topic = 'general';
    public string $message = '';

    /** Left empty by people; bots fill it in. */
    public string $website = '';

    public bool $sent = false;

    public function mount(): void
    {
        if ($user = auth()->user()) {
            [$this->firstName, $this->lastName] = array_pad(explode(' ', trim((string) $user->name), 2), 2, '');
            $this->email = (string) $user->email;
            $this->phone = (string) ($user->phone ?? '');
        }

        if (array_key_exists($t = (string) request()->query('topic', ''), Lead::TOPICS)) {
            $this->topic = $t;
        }
    }

    public function send(): void
    {
        $data = $this->validate([
            'firstName' => ['required', 'string', 'max:60'],
            'lastName'  => ['required', 'string', 'max:60'],
            'email'     => ['required', 'email', 'max:160'],
            'phone'     => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{7,}$/'],
            'topic'     => ['required', Rule::in(array_keys(Lead::TOPICS))],
            'message'   => ['required', 'string', 'min:10', 'max:4000'],
        ], [
            'phone.regex' => 'Enter a phone number, or leave it blank.',
            'message.min' => 'Tell us a little more so we can help.',
        ], [
            'firstName' => 'first name',
            'lastName'  => 'last name',
        ]);

        if ($this->website !== '') {
            $this->sent = true; // a bot: pretend it worked

            return;
        }

        $key = 'contact:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('message', 'You\'ve sent a few messages already. Try again in an hour, or email us.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $lead = Lead::create([
            'name'    => trim($data['firstName'] . ' ' . $data['lastName']),
            'email'   => $data['email'],
            'phone'   => $data['phone'] ?: null,
            'topic'   => $data['topic'],
            'message' => $data['message'],
        ]);

        try {
            Mail::to(config('site.contact_email'))->send(new ContactMessageReceived($lead));
        } catch (\Throwable $e) {
            // The message is saved in Inquiries either way.
            Log::warning('Contact form email failed', ['lead_id' => $lead->id, 'error' => $e->getMessage()]);
        }

        $this->sent = true;
    }
}; ?>

<div class="contact-page">
    <style>
        .contact-page { max-width:760px; margin:0 auto; padding:48px 24px 90px; }
        .contact-page h1 { font-family:var(--font-display); font-size:44px; font-weight:700; text-transform:uppercase; line-height:1; margin:0 0 10px; }
        .contact-lede { font-size:16px; color:var(--ink-2); line-height:1.55; margin:0 0 30px; max-width:560px; }
        .contact-card { background:var(--card); border:1px solid var(--line-strong); border-top:3px solid var(--ink); border-radius:var(--radius); padding:30px; }
        .contact-card h2 { font-family:var(--font-display); font-size:26px; font-weight:700; text-transform:uppercase; margin:0 0 20px; }
        .contact-page .field { margin:0; min-width:0; }
        .contact-page .field-label { display:block; font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:7px; }
        .contact-page .field-input { width:100%; box-sizing:border-box; padding:13px 15px; border:1.5px solid var(--line-strong); border-radius:8px; font:inherit; font-size:15px; outline:none; background:#fff; color:var(--ink); }
        .contact-page .field-input:focus { border-color:var(--ink); box-shadow:0 0 0 1px var(--ink); }
        .contact-page .btn { display:inline-flex; align-items:center; justify-content:center; gap:9px; padding:15px 30px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:19px; font-weight:600; text-transform:uppercase; }
        .contact-page .btn:hover { background:var(--primary-press); }
        .contact-page .btn[disabled] { opacity:.6; }
        .contact-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; }
        .contact-grid .full { grid-column:1 / -1; }
        .contact-grid textarea.field-input { min-height:150px; resize:vertical; padding-top:12px; line-height:1.5; }
        .contact-grid select.field-input { appearance:auto; }
        .contact-opt { font-weight:500; color:var(--ink-3); }
        .contact-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
        .contact-actions { margin-top:22px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .contact-actions .btn-primary { font-family:var(--font-display); text-transform:uppercase; font-size:18px; font-weight:600; border-radius:8px; }
        .contact-note { font-size:13.5px; color:var(--ink-2); }
        .contact-done { text-align:left; }
        .contact-done p { font-size:16px; color:var(--ink-2); line-height:1.55; margin:0 0 18px; }
        .field-error { color:#D81A48; font-size:13px; font-weight:600; margin:7px 0 0; }
        @media (max-width:640px) {
            .contact-page { padding:28px 16px 70px; }
            .contact-page h1 { font-size:34px; }
            .contact-card { padding:22px 16px; }
            .contact-grid { grid-template-columns:1fr; }
        }
    </style>

    <h1>Contact us</h1>
    <p class="contact-lede">Questions about a car, your reservation, or how Trumo works? Send us a message and a real person will get back to you, usually within one business day.</p>

    <div class="contact-card">
        @if ($sent)
            <div class="contact-done">
                <h2>Thanks, {{ $firstName }}</h2>
                <p>We've got your message and will reply to <b>{{ $email }}</b>, usually within one business day.</p>
                <a href="{{ route('shop') }}" wire:navigate class="btn btn-primary">Keep browsing cars</a>
            </div>
        @else
            <h2>Send us a message</h2>
            <form wire:submit="send" novalidate>
                <div class="contact-grid">
                    <div class="field">
                        <label class="field-label" for="c-first">First name</label>
                        <input id="c-first" type="text" class="field-input" wire:model="firstName" autocomplete="given-name">
                        @error('firstName') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-label" for="c-last">Last name</label>
                        <input id="c-last" type="text" class="field-input" wire:model="lastName" autocomplete="family-name">
                        @error('lastName') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-label" for="c-email">Email</label>
                        <input id="c-email" type="email" class="field-input" wire:model="email" autocomplete="email">
                        @error('email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-label" for="c-phone">Phone <span class="contact-opt">(optional)</span></label>
                        <input id="c-phone" type="tel" class="field-input" wire:model="phone" autocomplete="tel">
                        @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field full">
                        <label class="field-label" for="c-topic">What can we help you with?</label>
                        <select id="c-topic" class="field-input" wire:model="topic">
                            @foreach (\App\Models\Lead::TOPICS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('topic') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field full">
                        <label class="field-label" for="c-message">Message</label>
                        <textarea id="c-message" class="field-input" wire:model="message" placeholder="If it's about a car, the year, make and model help."></textarea>
                        @error('message') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="contact-hp" aria-hidden="true">
                        <label for="c-website">Website</label>
                        <input id="c-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
                    </div>
                </div>
                <div class="contact-actions">
                    <button type="submit" class="btn btn-primary btn-lg" wire:loading.attr="disabled" wire:target="send">
                        <span wire:loading.remove wire:target="send">Send message →</span>
                        <span wire:loading wire:target="send">Sending…</span>
                    </button>
                    <span class="contact-note">We only use your details to answer you.</span>
                </div>
            </form>
        @endif
    </div>
</div>
