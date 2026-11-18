<?php

use App\Mail\JobApplicationReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
| Careers. Open roles come from config('site.careers_roles'); with none listed
| the page takes general applications. An application is emailed to
| site.careers_email with the CV attached straight from the upload: nothing is
| saved to the database and the CV isn't kept on our server.
*/
new #[Title('Careers | Trumo')] class extends Component {
    use WithFileUploads;

    public const GENERAL = 'General application';

    public string $firstName = '';
    public string $lastName = '';
    public string $email = '';
    public string $phone = '';
    public string $role = self::GENERAL;
    public string $link = '';
    public string $message = '';
    public $resume = null;

    /** Left empty by people; bots fill it in. */
    public string $website = '';

    public bool $sent = false;

    public function mount(): void
    {
        if ($user = auth()->user()) {
            [$this->firstName, $this->lastName] = array_pad(explode(' ', trim((string) $user->name), 2), 2, '');
            $this->email = (string) $user->email;
        }
    }

    /** @return array<int, array{title: string, team: ?string, location: ?string, type: ?string, summary: ?string}> */
    public function roles(): array
    {
        return collect(config('site.careers_roles', []))
            ->filter(fn ($r) => filled($r['title'] ?? null))
            ->map(fn ($r) => [
                'title'    => (string) $r['title'],
                'team'     => $r['team'] ?? null,
                'location' => $r['location'] ?? null,
                'type'     => $r['type'] ?? null,
                'summary'  => $r['summary'] ?? null,
            ])->values()->all();
    }

    public function applyFor(string $title): void
    {
        if (in_array($title, array_column($this->roles(), 'title'), true)) {
            $this->role = $title;
        }
    }

    public function send(): void
    {
        $data = $this->validate([
            'firstName' => ['required', 'string', 'max:60'],
            'lastName'  => ['required', 'string', 'max:60'],
            'email'     => ['required', 'email', 'max:160'],
            'phone'     => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{7,}$/'],
            'role'      => ['required', Rule::in([self::GENERAL, ...array_column($this->roles(), 'title')])],
            'link'      => ['nullable', 'url:http,https', 'max:300'],
            'message'   => ['required', 'string', 'min:20', 'max:4000'],
            'resume'    => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
        ], [
            'phone.regex'  => 'Enter a phone number, or leave it blank.',
            'link.url'     => 'Paste the full link, starting with https://',
            'message.min'  => 'Tell us a little more about yourself.',
            'resume.mimes' => 'Upload your CV as a PDF or Word file.',
            'resume.max'   => 'Your CV needs to be under 5 MB.',
        ], [
            'firstName' => 'first name',
            'lastName'  => 'last name',
            'resume'    => 'CV',
        ]);

        if ($this->website !== '') {
            $this->sent = true; // a bot: pretend it worked

            return;
        }

        $key = 'careers:' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->addError('message', 'You\'ve sent a few applications already. Try again in an hour.');

            return;
        }
        RateLimiter::hit($key, 3600);

        $application = [
            'name'    => trim($data['firstName'] . ' ' . $data['lastName']),
            'email'   => $data['email'],
            'phone'   => $data['phone'] ?: null,
            'role'    => $data['role'],
            'link'    => $data['link'] ?: null,
            'message' => $data['message'],
        ];

        try {
            Mail::to(config('site.careers_email'))->send(new JobApplicationReceived(
                $application,
                $this->resume?->getRealPath(),
                $this->resume ? $this->resumeFileName() : null,
            ));
        } catch (\Throwable $e) {
            Log::error('Careers application email failed', ['email' => $application['email'], 'error' => $e->getMessage()]);
            RateLimiter::clear($key);
            $this->addError('message', 'Something went wrong sending your application. Please try again in a few minutes.');

            return;
        }

        $this->resume?->delete();
        $this->resume = null;
        $this->sent = true;
    }

    public function removeResume(): void
    {
        $this->resume?->delete();
        $this->resume = null;
    }

    /** "Sam-Lee-CV.pdf": named after the applicant, not whatever the file was called. */
    private function resumeFileName(): string
    {
        $ext = strtolower($this->resume->getClientOriginalExtension() ?: 'pdf');

        return \Illuminate\Support\Str::slug($this->firstName . ' ' . $this->lastName) . '-CV.' . $ext;
    }
}; ?>

<div class="careers-page">
    <style>
        .careers-page { max-width:860px; margin:0 auto; padding:48px 24px 90px; }
        .careers-page h1 { font-family:var(--font-display); font-size:44px; font-weight:700; text-transform:uppercase; line-height:1; margin:0 0 12px; }
        .careers-lede { font-size:17px; color:var(--ink-2); line-height:1.55; margin:0 0 34px; max-width:620px; }
        .careers-page h2 { font-family:var(--font-display); font-size:26px; font-weight:700; text-transform:uppercase; margin:0 0 16px; }

        .careers-values { display:grid; grid-template-columns:repeat(3, 1fr); gap:0; border-top:3px solid var(--ink); border-bottom:1px solid var(--line-strong); margin:0 0 46px; }
        .careers-value { padding:20px 22px 22px 0; }
        .careers-value + .careers-value { padding-left:22px; border-left:1px solid var(--line); }
        .careers-value b { display:block; font-family:var(--font-display); font-size:19px; font-weight:700; text-transform:uppercase; margin-bottom:6px; }
        .careers-value p { margin:0; font-size:14.5px; color:var(--ink-2); line-height:1.5; }

        .careers-roles { margin:0 0 46px; }
        .careers-role { display:flex; align-items:flex-start; justify-content:space-between; gap:20px; padding:20px 0; border-bottom:1px solid var(--line); }
        .careers-role:first-of-type { border-top:1px solid var(--line); }
        .careers-role h3 { margin:0 0 5px; font-size:18px; font-weight:700; color:var(--ink); }
        .careers-role-meta { font-size:13.5px; color:var(--ink-3); font-weight:600; }
        .careers-role p { margin:8px 0 0; font-size:14.5px; color:var(--ink-2); line-height:1.5; max-width:560px; }
        .careers-role-apply { flex:none; background:none; border:1.5px solid var(--ink); color:var(--ink); border-radius:8px; padding:9px 16px; font:inherit; font-size:14px; font-weight:700; cursor:pointer; white-space:nowrap; }
        .careers-role-apply:hover { background:var(--ink); color:#fff; }
        .careers-empty { padding:20px 22px; background:var(--surface-2, #F4F6FA); border-radius:var(--radius); font-size:15px; color:var(--ink-2); line-height:1.55; }
        .careers-empty b { color:var(--ink); }

        .careers-card { background:var(--card); border:1px solid var(--line-strong); border-top:3px solid var(--ink); border-radius:var(--radius); padding:30px; scroll-margin-top:90px; }
        .careers-page .field { margin:0; min-width:0; }
        .careers-page .field-label { display:block; font-size:14px; font-weight:600; color:var(--ink-2); margin-bottom:7px; }
        .careers-page .field-input { width:100%; box-sizing:border-box; padding:13px 15px; border:1.5px solid var(--line-strong); border-radius:8px; font:inherit; font-size:15px; outline:none; background:#fff; color:var(--ink); }
        .careers-page .field-input:focus { border-color:var(--ink); box-shadow:0 0 0 1px var(--ink); }
        .careers-page .btn { display:inline-flex; align-items:center; justify-content:center; gap:9px; padding:15px 30px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; background:var(--primary); color:#fff; font-family:var(--font-display); font-size:18px; font-weight:600; text-transform:uppercase; }
        .careers-page .btn:hover { background:var(--primary-press); }
        .careers-page .btn[disabled] { opacity:.6; }
        .careers-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; }
        .careers-grid .full { grid-column:1 / -1; }
        .careers-grid input.field-input { height:50px; }
        .careers-grid textarea.field-input { min-height:150px; resize:vertical; line-height:1.5; }
        .careers-grid select.field-input { -webkit-appearance:none; appearance:none; height:50px; padding:0 44px 0 15px; line-height:1.2; cursor:pointer;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23101828' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");
            background-repeat:no-repeat; background-position:right 15px center; background-size:16px; }
        .careers-opt { font-weight:500; color:var(--ink-3); }
        .careers-file { display:flex; align-items:center; gap:14px; padding:14px 16px; border:1.5px dashed var(--line-strong); border-radius:8px; background:#fff; }
        .careers-file label.pick { flex:none; border:1.5px solid var(--ink); border-radius:7px; padding:8px 14px; font-size:14px; font-weight:700; cursor:pointer; color:var(--ink); }
        .careers-file label.pick:hover { background:var(--ink); color:#fff; }
        .careers-file input[type=file] { position:absolute; width:1px; height:1px; opacity:0; }
        .careers-file-name { font-size:14px; color:var(--ink-2); min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .careers-file-name b { color:var(--ink); }
        .careers-file-remove { margin-left:auto; background:none; border:none; color:var(--ink-3); font:inherit; font-size:13.5px; font-weight:600; cursor:pointer; text-decoration:underline; }
        .careers-hp { position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden; }
        .careers-actions { margin-top:22px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        .careers-note { font-size:13.5px; color:var(--ink-2); max-width:420px; line-height:1.45; }
        .careers-done p { font-size:16px; color:var(--ink-2); line-height:1.55; margin:0 0 18px; }
        .field-error { color:#D81A48; font-size:13px; font-weight:600; margin:7px 0 0; }

        @media (max-width:720px) {
            .careers-page { padding:28px 16px 70px; }
            .careers-page h1 { font-size:34px; }
            .careers-values { grid-template-columns:1fr; }
            .careers-value, .careers-value + .careers-value { padding:16px 0; border-left:none; }
            .careers-value + .careers-value { border-top:1px solid var(--line); }
            .careers-role { flex-direction:column; gap:12px; }
            .careers-card { padding:22px 16px; }
            .careers-grid { grid-template-columns:1fr; }
        }
    </style>

    <h1>Careers at Trumo</h1>
    <p class="careers-lede">We're making buying a used car in Canada simple and honest: the real all-in price up front, a refundable deposit to hold the car, and pickup at the dealership. If that sounds worth building, we'd like to hear from you.</p>

    <div class="careers-values">
        <div class="careers-value">
            <b>Shoppers first</b>
            <p>Every decision starts with what makes buying a car clearer and fairer for the person paying for it.</p>
        </div>
        <div class="careers-value">
            <b>Own it</b>
            <p>We're a small team. You'll take on real problems end to end and see what you ship in the hands of shoppers and dealers.</p>
        </div>
        <div class="careers-value">
            <b>Say it straight</b>
            <p>No fine print and no runaround, with customers, with dealers and with each other.</p>
        </div>
    </div>

    @php $roles = $this->roles(); @endphp
    <section class="careers-roles">
        <h2>Open roles</h2>
        @forelse ($roles as $r)
            <div class="careers-role">
                <div>
                    <h3>{{ $r['title'] }}</h3>
                    <div class="careers-role-meta">{{ collect([$r['team'], $r['location'], $r['type']])->filter()->implode(' · ') }}</div>
                    @if ($r['summary']) <p>{{ $r['summary'] }}</p> @endif
                </div>
                <button type="button" class="careers-role-apply" wire:click="applyFor(@js($r['title']))"
                    x-on:click="document.getElementById('apply').scrollIntoView({ behavior: 'smooth' })">Apply →</button>
            </div>
        @empty
            <div class="careers-empty">
                <b>No open roles right now.</b> We're always glad to meet good people, though. Send a general application below and we'll be in touch when something fits.
            </div>
        @endforelse
    </section>

    <div class="careers-card" id="apply">
        @if ($sent)
            <div class="careers-done">
                <h2>Thanks, {{ $firstName }}</h2>
                <p>We've got your application and will reply to <b>{{ $email }}</b>. We read every one, so give us a little time.</p>
                <a href="{{ route('shop') }}" wire:navigate class="btn">Browse cars on Trumo</a>
            </div>
        @else
            <h2>Apply</h2>
            <form wire:submit="send" novalidate>
                <div class="careers-grid">
                    <div class="field">
                        <label class="field-label" for="j-first">First name</label>
                        <input id="j-first" type="text" class="field-input" wire:model="firstName" autocomplete="given-name">
                        @error('firstName') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-label" for="j-last">Last name</label>
                        <input id="j-last" type="text" class="field-input" wire:model="lastName" autocomplete="family-name">
                        @error('lastName') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-label" for="j-email">Email</label>
                        <input id="j-email" type="email" class="field-input" wire:model="email" autocomplete="email">
                        @error('email') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field">
                        <label class="field-label" for="j-phone">Phone <span class="careers-opt">(optional)</span></label>
                        <input id="j-phone" type="tel" class="field-input" wire:model="phone" autocomplete="tel">
                        @error('phone') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field full">
                        <label class="field-label" for="j-role">Role</label>
                        <select id="j-role" class="field-input" wire:model="role">
                            @foreach ($roles as $r)
                                <option value="{{ $r['title'] }}">{{ $r['title'] }}</option>
                            @endforeach
                            <option value="{{ $this::GENERAL }}">{{ $this::GENERAL }}</option>
                        </select>
                        @error('role') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field full">
                        <label class="field-label" for="j-link">LinkedIn or portfolio <span class="careers-opt">(optional)</span></label>
                        <input id="j-link" type="url" class="field-input" wire:model="link" placeholder="https://" autocomplete="url">
                        @error('link') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field full">
                        <span class="field-label">CV <span class="careers-opt">(optional, PDF or Word, up to 5 MB)</span></span>
                        <div class="careers-file">
                            <label class="pick" for="j-resume">{{ $resume ? 'Replace' : 'Choose file' }}</label>
                            <input id="j-resume" type="file" wire:model="resume" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                            <span class="careers-file-name">
                                <span wire:loading wire:target="resume">Uploading…</span>
                                <span wire:loading.remove wire:target="resume">
                                    @if ($resume && ! $errors->has('resume'))
                                        <b>{{ $resume->getClientOriginalName() }}</b>
                                    @else
                                        No file chosen
                                    @endif
                                </span>
                            </span>
                            @if ($resume)
                                <button type="button" class="careers-file-remove" wire:click="removeResume">Remove</button>
                            @endif
                        </div>
                        @error('resume') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="field full">
                        <label class="field-label" for="j-message">About you</label>
                        <textarea id="j-message" class="field-input" wire:model="message" placeholder="What you've worked on, what you're good at, and why Trumo."></textarea>
                        @error('message') <p class="field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="careers-hp" aria-hidden="true">
                        <label for="j-website">Website</label>
                        <input id="j-website" type="text" wire:model="website" tabindex="-1" autocomplete="off">
                    </div>
                </div>
                <div class="careers-actions">
                    <button type="submit" class="btn" wire:loading.attr="disabled" wire:target="send, resume">
                        <span wire:loading.remove wire:target="send">Send application →</span>
                        <span wire:loading wire:target="send">Sending…</span>
                    </button>
                    <span class="careers-note">We only use your details to consider you for a role at Trumo, and we don't keep your CV on our website.</span>
                </div>
            </form>
        @endif
    </div>
</div>
