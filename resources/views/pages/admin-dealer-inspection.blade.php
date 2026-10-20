<?php

use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\InspectionPoint;
use App\Models\InspectionProgram;
use App\Support\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Command Centre — a dealer's inspection checklists. TruCars types the points
| in here; they show on the dealer's cars as "N-point inspection" with every
| point listed. Viewing needs dealers.view, changes need dealers.manage.
*/
new #[Layout('layouts.dealer')] #[Title('Inspection checklist')] class extends Component {
    #[Locked]
    public int $dealerId;

    #[Locked]
    public ?int $programId = null;

    public string $name = '';
    public string $badgeLabel = '';
    public string $summary = '';
    public string $appliesTo = InspectionProgram::APPLIES_TO_ALL_USED;
    public bool $isActive = true;

    public string $newSection = '';
    public string $newLabel = '';
    public string $pasted = '';

    public ?string $copyFromId = null;

    public string $statusMessage = '';

    public function mount(Dealer $dealer): void
    {
        Gate::authorize(Permission::DEALERS_VIEW);
        $this->dealerId = $dealer->id;

        if ($first = $this->programs()->first()) {
            $this->select($first->id);
        }
    }

    public function dealer(): Dealer
    {
        return Dealer::findOrFail($this->dealerId);
    }

    public function programs(): Collection
    {
        return InspectionProgram::where('dealer_id', $this->dealerId)->withCount('points')->orderBy('id')->get();
    }

    public function program(): ?InspectionProgram
    {
        return $this->programId
            ? InspectionProgram::where('dealer_id', $this->dealerId)->with('points')->find($this->programId)
            : null;
    }

    /** Other dealers' checklists, to start from instead of typing from scratch. */
    public function copySources(): Collection
    {
        return InspectionProgram::where('dealer_id', '!=', $this->dealerId)->with('dealer')->withCount('points')->orderBy('name')->get();
    }

    public function select(int $programId): void
    {
        $program = InspectionProgram::where('dealer_id', $this->dealerId)->findOrFail($programId);

        $this->programId = $program->id;
        $this->name = $program->name;
        $this->badgeLabel = (string) $program->badge_label;
        $this->summary = (string) $program->summary;
        $this->appliesTo = $program->applies_to;
        $this->isActive = $program->is_active;
        $this->resetValidation();
        $this->statusMessage = '';
    }

    /** The Live/Hidden switch takes effect straight away on a saved checklist. */
    public function updatedIsActive(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);

        if ($program = $this->program()) {
            $program->update(['is_active' => $this->isActive]);
            AuditLog::record('inspection_checklist.updated', $this->dealer(), null, ['program' => $program->name, 'is_active' => $this->isActive]);
            $this->statusMessage = $this->isActive ? 'Showing on listings.' : 'Hidden from listings.';
        }
    }

    public function startNew(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $this->reset(['programId', 'name', 'badgeLabel', 'summary', 'appliesTo', 'isActive', 'statusMessage']);
    }

    public function save(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);

        $this->validate([
            'name'       => ['required', 'string', 'max:120'],
            'badgeLabel' => ['nullable', 'string', 'max:60'],
            'summary'    => ['nullable', 'string', 'max:600'],
            'appliesTo'  => ['required', Rule::in(array_keys(InspectionProgram::APPLIES_TO_LABELS))],
        ]);

        $attributes = [
            'name'        => trim($this->name),
            'badge_label' => trim($this->badgeLabel) ?: null,
            'summary'     => trim($this->summary) ?: null,
            'applies_to'  => $this->appliesTo,
            'is_active'   => $this->isActive,
        ];

        if ($program = $this->program()) {
            $program->update($attributes);
            AuditLog::record('inspection_checklist.updated', $this->dealer(), null, ['program' => $program->name]);
        } else {
            $program = InspectionProgram::create($attributes + ['dealer_id' => $this->dealerId]);
            $this->programId = $program->id;
            AuditLog::record('inspection_checklist.created', $this->dealer(), null, ['program' => $program->name]);
        }

        $this->statusMessage = 'Saved.';
    }

    public function copyFrom(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $source = InspectionProgram::with('points')->where('dealer_id', '!=', $this->dealerId)->findOrFail((int) $this->copyFromId);

        DB::transaction(function () use ($source) {
            $copy = InspectionProgram::create($source->only(['name', 'badge_label', 'summary', 'applies_to']) + ['dealer_id' => $this->dealerId]);

            foreach ($source->points as $point) {
                $copy->points()->create($point->only(['position', 'section', 'label']));
            }

            AuditLog::record('inspection_checklist.created', $this->dealer(), null, ['program' => $copy->name, 'copied_from' => $source->id]);
            $this->copyFromId = null;
            $this->select($copy->id);
            $this->statusMessage = "Copied {$source->points->count()} points from {$source->dealer->name}.";
        });
    }

    public function deleteProgram(): void
    {
        Gate::authorize(Permission::DEALERS_MANAGE);
        $program = $this->program() ?? abort(404);

        AuditLog::record('inspection_checklist.deleted', $this->dealer(), ['program' => $program->name, 'points' => $program->points->count()]);
        $program->delete();
        $this->startNew();

        if ($first = $this->programs()->first()) {
            $this->select($first->id);
        }
    }

    /* ---- points ------------------------------------------------------- */

    private function editableProgram(): InspectionProgram
    {
        Gate::authorize(Permission::DEALERS_MANAGE);

        return $this->program() ?? abort(422, 'Save the checklist first.');
    }

    public function addPoint(): void
    {
        $program = $this->editableProgram();
        $this->validate(['newSection' => ['nullable', 'string', 'max:80'], 'newLabel' => ['required', 'string', 'max:200']], attributes: ['newLabel' => 'point']);

        $program->points()->create([
            'position' => (int) $program->points()->max('position') + 1,
            'section'  => trim($this->newSection) ?: null,
            'label'    => trim($this->newLabel),
        ]);

        // Keep the section, so a run of points in one section is quick to type.
        $this->newLabel = '';
        $this->statusMessage = '';
    }

    /** One point per line; "Section: Point" puts it in a section. */
    public function addPasted(): void
    {
        $program = $this->editableProgram();
        $this->validate(['pasted' => ['required', 'string', 'max:50000']], attributes: ['pasted' => 'list']);

        $position = (int) $program->points()->max('position');
        $added = 0;

        foreach (preg_split('/\R/', $this->pasted) as $line) {
            $line = trim($line, " \t-•*");
            [$section, $label] = str_contains($line, ':') ? array_map('trim', explode(':', $line, 2)) : [null, $line];

            if ($label === '') {
                continue;
            }

            $program->points()->create(['position' => ++$position, 'section' => $section ? mb_substr($section, 0, 80) : null, 'label' => mb_substr($label, 0, 200)]);
            $added++;
        }

        $this->pasted = '';
        $this->statusMessage = "Added {$added} " . ($added === 1 ? 'point' : 'points') . '.';
    }

    public function removePoint(int $pointId): void
    {
        $this->editableProgram()->points()->whereKey($pointId)->firstOrFail()->delete();
    }

    public function movePoint(int $pointId, int $direction): void
    {
        $points = $this->editableProgram()->points->values();
        $index = $points->search(fn (InspectionPoint $point) => $point->id === $pointId);
        $target = $index === false ? null : $index + ($direction < 0 ? -1 : 1);

        if ($target === null || ! $points->has($target)) {
            return;
        }

        $ids = $points->pluck('id')->all();
        [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

        foreach ($ids as $position => $id) {
            $points->firstWhere('id', $id)->update(['position' => $position + 1]);
        }
    }

    public function with(): array
    {
        $program = $this->program();

        return [
            'dealer'    => $this->dealer(),
            'programs'  => $this->programs(),
            'program'   => $program,
            'sections'  => $program?->points->groupBy(fn ($point) => $point->section ?: 'General') ?? collect(),
            'canManage' => Gate::allows(Permission::DEALERS_MANAGE),
        ];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.dealers') }}" wire:navigate>Dealers</a>
    <span>›</span>
    <a href="{{ route('admin.dealers.edit', $dealerId) }}" wire:navigate>{{ $dealer->name }}</a>
    <span>›</span>
    <span class="cur">Inspection</span>
@endpush

@push('styles')
    <style>
        .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line); }
        .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:2px solid transparent; margin-bottom:-1px; }
        .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
        .ip-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .ip-head p { margin:0 0 20px; font-size:14px; color:var(--ink-2); max-width:680px; }
        .msg { background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:10px 14px; font-size:13.5px; font-weight:600; margin-bottom:16px; }

        .ip { display:grid; grid-template-columns:260px minmax(0, 1fr); gap:20px; align-items:start; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); }

        .plist { padding:8px; display:flex; flex-direction:column; gap:4px; }
        .pitem { display:flex; align-items:center; gap:10px; padding:10px 12px; border-radius:10px; text-align:left; width:100%; border:1px solid transparent; }
        .pitem:hover { background:var(--bg); }
        .pitem.on { background:var(--primary-soft); border-color:var(--primary-line); }
        .pitem .ic { width:32px; height:32px; border-radius:9px; background:var(--bg); display:grid; place-items:center; color:var(--ink-2); flex-shrink:0; }
        .pitem.on .ic { background:var(--primary); color:#fff; }
        .pitem .ic svg { width:16px; height:16px; }
        .pitem .nm { font-size:13.5px; font-weight:700; line-height:1.25; }
        .pitem .sub { font-size:11.5px; color:var(--ink-3); }
        .pitem.off .nm { color:var(--ink-3); }
        .padd { margin:4px 8px 8px; width:calc(100% - 16px); display:flex; align-items:center; justify-content:center; gap:7px; padding:9px; border-radius:10px; border:1.5px dashed var(--line-strong); font-size:13px; font-weight:700; color:var(--ink-2); }
        .padd:hover { border-color:var(--primary); color:var(--primary-press); }
        .padd svg { width:15px; height:15px; }
        .pcopy { border-top:1px solid var(--line); padding:12px; display:flex; flex-direction:column; gap:8px; }
        .pcopy label { font-size:11px; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--ink-3); }
        .pcopy select { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:8px 10px; font-size:13px; font-family:inherit; background:var(--card); width:100%; }

        .ed-top { display:flex; align-items:center; gap:14px; padding:18px 20px; border-bottom:1px solid var(--line); }
        .ed-count { display:flex; flex-direction:column; align-items:center; justify-content:center; width:74px; height:74px; border-radius:16px; background:var(--hero-grad); color:#fff; flex-shrink:0; }
        .ed-count b { font-size:26px; font-weight:800; line-height:1; letter-spacing:-.02em; }
        .ed-count span { font-size:10px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; opacity:.85; margin-top:3px; }
        .ed-top .ttl { font-size:17px; font-weight:800; }
        .ed-top .sub { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .fields { padding:18px 20px; display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:14px; border-bottom:1px solid var(--line); }
        .field { display:flex; flex-direction:column; gap:6px; grid-column:span 3; }
        .field.w2 { grid-column:span 2; } .field.w4 { grid-column:span 4; } .field.w6 { grid-column:1 / -1; }
        .field label { font-size:12.5px; font-weight:700; color:var(--ink-2); }
        .field label .opt { font-weight:500; color:var(--ink-3); }
        .field input, .field textarea { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:9px 12px; font-size:14px; font-family:inherit; background:var(--card); width:100%; }
        .field input:focus, .field textarea:focus { outline:none; border-color:var(--primary); }
        .err { font-size:12px; color:var(--primary-press); font-weight:600; }
        .seg { display:inline-flex; background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-pill); padding:3px; gap:3px; flex-wrap:wrap; }
        .seg button { padding:7px 13px; border-radius:var(--radius-pill); font-size:12.5px; font-weight:700; color:var(--ink-2); }
        .seg button.on { background:var(--card); color:var(--ink); box-shadow:var(--shadow-sm); }
        .switch { margin-left:auto; display:inline-flex; align-items:center; gap:9px; cursor:pointer; user-select:none; }
        .switch input { position:absolute; opacity:0; pointer-events:none; }
        .switch .track { width:40px; height:23px; border-radius:999px; background:var(--line-strong); position:relative; transition:background .15s ease; }
        .switch .knob { position:absolute; top:3px; left:3px; width:17px; height:17px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.2); transition:transform .15s ease; }
        .switch input:checked + .track { background:var(--good-ink); }
        .switch input:checked + .track .knob { transform:translateX(17px); }
        .switch input:focus-visible + .track { outline:2px solid var(--primary); outline-offset:2px; }
        .switch-lbl { font-size:13px; font-weight:700; color:var(--ink-2); min-width:44px; }
        .pempty { padding:16px 16px 6px; font-size:13px; color:var(--ink-3); line-height:1.5; }
        .ed-actions { display:flex; gap:8px; padding:14px 20px; border-bottom:1px solid var(--line); justify-content:flex-end; }
        .btn { display:inline-flex; align-items:center; gap:7px; font-size:13.5px; font-weight:700; border-radius:var(--radius-pill); padding:9px 17px; white-space:nowrap; }
        .btn svg { width:15px; height:15px; }
        .btn-primary { background:var(--primary); color:#fff; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { border:1px solid var(--line-strong); color:var(--ink-2); }
        .btn-danger { color:var(--primary-press); margin-right:auto; }

        .sec { padding:14px 20px 4px; font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); display:flex; gap:8px; align-items:center; }
        .sec span { font-family:var(--mono); background:var(--bg); border-radius:999px; padding:1px 7px; letter-spacing:0; }
        .pt { display:flex; align-items:center; gap:10px; padding:7px 14px 7px 20px; font-size:14px; }
        .pt:hover { background:var(--bg); }
        .pt .ck { width:18px; height:18px; color:var(--good-ink); flex-shrink:0; }
        .pt .lb { flex:1; }
        .pt .tools { display:flex; gap:2px; opacity:0; transition:opacity .12s ease; }
        .pt:hover .tools { opacity:1; }
        .icon-btn { width:28px; height:28px; display:grid; place-items:center; border-radius:7px; color:var(--ink-3); }
        .icon-btn:hover { background:var(--card); color:var(--ink); box-shadow:var(--shadow-sm); }
        .icon-btn svg { width:14px; height:14px; }
        .empty { padding:22px 20px; font-size:13.5px; color:var(--ink-3); }

        .adder { display:grid; grid-template-columns:180px minmax(0, 1fr) auto; gap:8px; padding:14px 20px; background:var(--bg); border-top:1px solid var(--line); border-radius:0 0 var(--radius) var(--radius); }
        .adder input, .adder textarea { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:9px 12px; font-size:13.5px; font-family:inherit; background:var(--card); width:100%; }
        .adder .full { grid-column:1 / -1; }
        .adder .swap { grid-column:1 / -1; justify-self:start; font-size:12.5px; font-weight:700; color:var(--primary-press); }

        @media (max-width:900px) {
            .ip { grid-template-columns:1fr; }
            .fields { grid-template-columns:1fr; }
            .field, .field.w2, .field.w4 { grid-column:1 / -1; }
            .adder { grid-template-columns:1fr; }
            .pt .tools { opacity:1; }
        }
    </style>
@endpush

<div>
    <nav class="tabs">
        <a href="{{ route('admin.dealers.edit', $dealer) }}" wire:navigate>Profile &amp; users</a>
        <a href="{{ route('admin.dealers.inventory', $dealer) }}" wire:navigate>Inventory</a>
        <a href="{{ route('admin.dealers.inspection', $dealer) }}" wire:navigate class="on">Inspection</a>
    </nav>

    <div class="ip-head">
        <h1>Inspection checklist</h1>
        <p>Type in the points this dealer inspects. They show on the dealer’s cars as “{{ $program?->points->count() ?: 'N' }}-point inspection”, with every point listed.</p>
    </div>

    @if ($statusMessage !== '') <div class="msg">{{ $statusMessage }}</div> @endif

    <div class="ip">
        {{-- Checklists --}}
        <div class="card">
            @if ($programs->isEmpty())
                <div class="pempty">No checklists for {{ $dealer->name }} yet. Its cars show no inspection until you add one.</div>
            @endif
            <div class="plist">
                @foreach ($programs as $item)
                    <button type="button" class="pitem {{ $programId === $item->id ? 'on' : '' }} {{ $item->is_active ? '' : 'off' }}" wire:click="select({{ $item->id }})" wire:key="p-{{ $item->id }}">
                        <span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/></svg></span>
                        <span>
                            <span class="nm">{{ $item->name }}</span><br>
                            <span class="sub">{{ $item->points_count }} points{{ $item->is_active ? '' : ' · hidden' }}</span>
                        </span>
                    </button>
                @endforeach
            </div>
            @if ($canManage)
                <button type="button" class="padd" wire:click="startNew">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    New checklist
                </button>
                @if ($this->copySources()->isNotEmpty())
                    <div class="pcopy">
                        <label for="copyFrom">Or copy one</label>
                        <select id="copyFrom" wire:model.live="copyFromId">
                            <option value="">Another dealer’s checklist…</option>
                            @foreach ($this->copySources() as $source)
                                <option value="{{ $source->id }}">{{ $source->name }} · {{ $source->dealer->name }} ({{ $source->points_count }})</option>
                            @endforeach
                        </select>
                        @if ($copyFromId)
                            <button type="button" class="btn btn-ghost" style="justify-content:center" wire:click="copyFrom">Copy to {{ $dealer->name }}</button>
                        @endif
                    </div>
                @endif
            @endif
        </div>

        {{-- Editor --}}
        <div class="card">
            <div class="ed-top">
                <div class="ed-count"><b>{{ $program?->points->count() ?? 0 }}</b><span>points</span></div>
                <div>
                    <div class="ttl">{{ $program?->name ?? 'New checklist' }}</div>
                    <div class="sub">
                        @if ($program)
                            {{ $program->is_active ? 'Showing on' : 'Hidden from' }} {{ strtolower(InspectionProgram::APPLIES_TO_LABELS[$program->applies_to]) }}
                        @else
                            Name it, save, then add the points.
                        @endif
                    </div>
                </div>
                <label class="switch" title="{{ $isActive ? 'Showing on listings' : 'Hidden from listings' }}">
                    <input type="checkbox" wire:model.live="isActive" @disabled(! $canManage)>
                    <span class="track"><span class="knob"></span></span>
                    <span class="switch-lbl">{{ $isActive ? 'Live' : 'Hidden' }}</span>
                </label>
            </div>

            <fieldset @disabled(! $canManage) style="border:none; margin:0; padding:0;">
                <form wire:submit="save">
                    <div class="fields">
                        <div class="field w4">
                            <label for="name">Name</label>
                            <input id="name" type="text" wire:model="name" maxlength="120" placeholder="e.g. Mitsubishi Diamond Certified">
                            @error('name') <span class="err">{{ $message }}</span> @enderror
                        </div>
                        <div class="field w2">
                            <label for="badgeLabel">Badge on listings <span class="opt">(optional)</span></label>
                            <input id="badgeLabel" type="text" wire:model="badgeLabel" maxlength="60" placeholder="e.g. Diamond Certified">
                        </div>
                        <div class="field w6">
                            <label for="summary">Short description <span class="opt">(optional)</span></label>
                            <input id="summary" type="text" wire:model="summary" maxlength="600">
                        </div>
                        <div class="field w6">
                            <label>Show on</label>
                            <div class="seg">
                                @foreach (InspectionProgram::APPLIES_TO_LABELS as $value => $label)
                                    <button type="button" class="{{ $appliesTo === $value ? 'on' : '' }}" wire:click="$set('appliesTo', '{{ $value }}')">{{ $label }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    @if ($canManage)
                        <div class="ed-actions">
                            @if ($program)
                                <button type="button" class="btn btn-danger" wire:click="deleteProgram" wire:confirm="Delete “{{ $program->name }}” and its {{ $program->points->count() }} points?">Delete checklist</button>
                            @endif
                            <button type="submit" class="btn btn-primary">{{ $program ? 'Save' : 'Create checklist' }}</button>
                        </div>
                    @endif
                </form>
            </fieldset>

            @if ($program)
                @forelse ($sections as $section => $points)
                    <div class="sec">{{ $section }} <span>{{ $points->count() }}</span></div>
                    @foreach ($points as $point)
                        <div class="pt" wire:key="pt-{{ $point->id }}">
                            <svg class="ck" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                            <span class="lb">{{ $point->label }}</span>
                            @if ($canManage)
                                <span class="tools">
                                    <button type="button" class="icon-btn" wire:click="movePoint({{ $point->id }}, -1)" aria-label="Move up"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 15 6-6 6 6"/></svg></button>
                                    <button type="button" class="icon-btn" wire:click="movePoint({{ $point->id }}, 1)" aria-label="Move down"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="m6 9 6 6 6-6"/></svg></button>
                                    <button type="button" class="icon-btn" wire:click="removePoint({{ $point->id }})" aria-label="Remove"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/></svg></button>
                                </span>
                            @endif
                        </div>
                    @endforeach
                @empty
                    <div class="empty">No points yet. Add them below, or paste the whole list at once.</div>
                @endforelse

                @if ($canManage)
                    <div class="adder" x-data="{ paste: false }">
                        <template x-if="!paste">
                            <input type="text" wire:model="newSection" placeholder="Section (e.g. Brakes)" maxlength="80" list="sections">
                        </template>
                        <template x-if="!paste">
                            <input type="text" wire:model="newLabel" wire:keydown.enter.prevent="addPoint" placeholder="Inspection point" maxlength="200">
                        </template>
                        <template x-if="!paste">
                            <button type="button" class="btn btn-primary" wire:click="addPoint"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Add</button>
                        </template>
                        <template x-if="paste">
                            <textarea class="full" wire:model="pasted" rows="8" placeholder="One point per line. Put a section first with a colon:&#10;Brakes: Front pad thickness&#10;Brakes: Rear rotors&#10;Tires: Tread depth"></textarea>
                        </template>
                        <template x-if="paste">
                            <button type="button" class="btn btn-primary full" style="justify-self:end" wire:click="addPasted">Add all</button>
                        </template>
                        @error('newLabel') <span class="err full">{{ $message }}</span> @enderror
                        @error('pasted') <span class="err full">{{ $message }}</span> @enderror
                        <button type="button" class="swap" x-on:click="paste = ! paste" x-text="paste ? 'Add one at a time' : 'Paste a list instead'">Paste a list instead</button>
                        <datalist id="sections">@foreach ($sections->keys() as $existing)<option value="{{ $existing }}">@endforeach</datalist>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
