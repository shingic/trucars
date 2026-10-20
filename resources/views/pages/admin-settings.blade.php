<?php

use App\Models\AuditLog;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — platform settings (settings.manage). Only the settings the
| app actually reads (App\Support\PlatformSettings). Every change is audited
| with its before/after values.
*/
new #[Layout('layouts.dealer')] #[Title('Platform settings')] class extends Component {
    /** @var array<string, string> */
    public array $values = [];

    public string $statusMessage = '';

    public function mount(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->values = PlatformSettings::all();
    }

    public function save(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);

        $rules = [];
        foreach (PlatformSettings::DEFINITIONS as $key => [$label, , , $maxLength]) {
            $rules["values.{$key}"] = [$key === 'marketplace_notice' ? 'nullable' : 'required', 'string', "max:{$maxLength}"];
        }

        $this->validate($rules, attributes: collect(PlatformSettings::DEFINITIONS)->mapWithKeys(fn ($definition, $key) => ["values.{$key}" => strtolower($definition[0])])->all());

        $before = PlatformSettings::all();
        $changedBefore = [];
        $changedAfter = [];

        foreach (array_keys(PlatformSettings::DEFINITIONS) as $key) {
            $value = trim((string) ($this->values[$key] ?? ''));

            if ($value !== $before[$key]) {
                PlatformSettings::put($key, $value, auth()->id());
                $changedBefore[$key] = $before[$key];
                $changedAfter[$key] = $value;
            }
        }

        if ($changedAfter !== []) {
            AuditLog::record('settings.updated', null, $changedBefore, $changedAfter);
        }

        $this->values = PlatformSettings::all();
        $this->statusMessage = $changedAfter === [] ? 'Nothing changed.' : 'Settings saved.';
    }
}; ?>

@push('crumb')
    <span>Command Centre</span>
    <span>›</span>
    <span class="cur">Platform settings</span>
@endpush

@push('styles')
    <style>
        .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line); }
        .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; }
        .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
        .ps-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .ps-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); }
        .ps-status { background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:18px; max-width:760px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); max-width:760px; padding:20px; display:grid; gap:18px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field label { font-size:12.5px; font-weight:700; color:var(--ink-2); }
        .field .hint { font-size:12px; color:var(--ink-3); }
        .field input, .field textarea { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:10px 13px; font-size:14px; font-family:inherit; }
        .field input:focus, .field textarea:focus { outline:none; border-color:var(--primary); }
        .err { font-size:12px; color:var(--primary-press); font-weight:600; }
        .end { display:flex; justify-content:flex-end; }
        .btn { font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; background:var(--primary); color:#fff; }
    </style>
@endpush

<div>
    <nav class="tabs">
        <a href="{{ route('admin.settings') }}" wire:navigate class="on">General</a>
        <a href="{{ route('admin.settings.brand-checklists') }}" wire:navigate>Brand checklists</a>
    </nav>

    <div class="ps-head">
        <h1>Platform settings</h1>
        <p>Changes apply across the marketplace straight away and are recorded in the audit log.</p>
    </div>

    @if ($statusMessage !== '')
        <div class="ps-status">{{ $statusMessage }}</div>
    @endif

    <form class="card" wire:submit="save">
        @foreach (\App\Support\PlatformSettings::DEFINITIONS as $key => [$label, $help, $default, $maxLength])
            <div class="field">
                <label for="setting-{{ $key }}">{{ $label }}</label>
                @if ($maxLength > 100)
                    <textarea id="setting-{{ $key }}" wire:model="values.{{ $key }}" maxlength="{{ $maxLength }}" rows="2"></textarea>
                @else
                    <input id="setting-{{ $key }}" type="text" wire:model="values.{{ $key }}" maxlength="{{ $maxLength }}">
                @endif
                <span class="hint">{{ $help }}</span>
                @error("values.{$key}") <span class="err">{{ $message }}</span> @enderror
            </div>
        @endforeach
        <div class="end"><button type="submit" class="btn">Save settings</button></div>
    </form>
</div>
