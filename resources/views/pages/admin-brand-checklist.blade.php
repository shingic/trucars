<?php

use App\Models\AuditLog;
use App\Models\Brand;
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
| Settings › Brands › CPO checklist (settings.manage). TruCars types the
| brand's inspection points in here; they show on every dealer of the brand's
| cars as "N-point inspection", with every point listed. A brand normally has
| one checklist; a second can cover a different set of cars (e.g. all used
| cars vs. only those the feed marks certified).
*/
new #[Layout('layouts.dealer')] #[Title('CPO checklist')] class extends Component {
    #[Locked]
    public int $brandId;

    #[Locked]
    public ?int $programId = null;

    public string $name = '';
    public string $badgeLabel = '';
    public string $summary = '';
    public string $appliesTo = InspectionProgram::APPLIES_TO_FEED_CERTIFIED;
    public bool $isActive = true;

    public string $newSection = '';
    public string $newLabel = '';
    public string $pasted = '';

    public ?string $copyFromId = null;

    public string $statusMessage = '';

    public function mount(Brand $brand): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->brandId = $brand->id;

        if ($first = $this->programs()->first()) {
            $this->select($first->id);
        }
    }

    private function managePermission(): string
    {
        return Permission::SETTINGS_MANAGE;
    }

    public function brand(): Brand
    {
        return Brand::findOrFail($this->brandId);
    }

    /** This brand's checklists (brand checklists are keyed by make). */
    private function scoped()
    {
        return $this->brand()->checklists();
    }

    public function programs(): Collection
    {
        return $this->scoped()->withCount('points')->orderByDesc('is_active')->orderBy('id')->get();
    }

    public function program(): ?InspectionProgram
    {
        return $this->programId ? $this->scoped()->with('points')->find($this->programId) : null;
    }

    /** Other brands' checklists, to start from instead of typing from scratch. */
    public function copySources(): Collection
    {
        return InspectionProgram::whereNull('dealer_id')->whereRaw('lower(make) != ?', [mb_strtolower($this->brand()->name)])
            ->withCount('points')->orderBy('make')->get();
    }

    private function auditTarget(InspectionProgram $program): InspectionProgram
    {
        return $program;
    }

    public function select(int $programId): void
    {
        $program = $this->scoped()->findOrFail($programId);

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
        Gate::authorize($this->managePermission());

        if ($program = $this->program()) {
            $program->update(['is_active' => $this->isActive]);
            AuditLog::record('inspection_checklist.updated', $this->auditTarget($program), null, ['program' => $program->name, 'is_active' => $this->isActive]);
            $this->statusMessage = $this->isActive ? 'Showing on listings.' : 'Hidden from listings.';
        }
    }

    public function startNew(): void
    {
        Gate::authorize($this->managePermission());
        $this->reset(['programId', 'name', 'badgeLabel', 'summary', 'appliesTo', 'isActive', 'statusMessage']);
    }

    public function save(): void
    {
        Gate::authorize($this->managePermission());

        $this->validate([
            'name'       => ['required', 'string', 'max:120'],
            'badgeLabel' => ['nullable', 'string', 'max:60'],
            'summary'    => ['nullable', 'string', 'max:600'],
            'appliesTo'  => ['required', Rule::in(array_keys(InspectionProgram::APPLIES_TO_LABELS))],
        ]);

        $attributes = [
            'name'        => trim($this->name),
            'make'        => $this->brand()->name,
            'badge_label' => trim($this->badgeLabel) ?: null,
            'summary'     => trim($this->summary) ?: null,
            'applies_to'  => $this->appliesTo,
            'is_active'   => $this->isActive,
        ];

        if ($program = $this->program()) {
            $program->update($attributes);
            AuditLog::record('inspection_checklist.updated', $this->auditTarget($program), null, ['program' => $program->name]);
        } else {
            $program = InspectionProgram::create($attributes + ['dealer_id' => null]);
            $this->programId = $program->id;
            AuditLog::record('inspection_checklist.created', $this->auditTarget($program), null, ['program' => $program->name]);
        }

        $this->statusMessage = 'Saved.';
    }

    public function copyFrom(): void
    {
        Gate::authorize($this->managePermission());
        $source = $this->copySources()->firstWhere('id', (int) $this->copyFromId) ?? abort(404);
        $source->load('points');

        DB::transaction(function () use ($source) {
            $copy = InspectionProgram::create($source->only(['name', 'badge_label', 'summary', 'applies_to']) + [
                'dealer_id' => null,
                'make'      => $this->brand()->name,
            ]);

            foreach ($source->points as $point) {
                $copy->points()->create($point->only(['position', 'section', 'label']));
            }

            AuditLog::record('inspection_checklist.created', $this->auditTarget($copy), null, ['program' => $copy->name, 'copied_from' => $source->id]);
            $this->copyFromId = null;
            $this->select($copy->id);
            $this->statusMessage = "Copied {$source->points->count()} points from the {$source->make} checklist — rename it and adjust the points for {$this->brand()->name}.";
        });
    }

    public function deleteProgram(): void
    {
        Gate::authorize($this->managePermission());
        $program = $this->program() ?? abort(404);

        AuditLog::record('inspection_checklist.deleted', $this->auditTarget($program), ['program' => $program->name, 'points' => $program->points->count()]);
        $program->delete();
        $this->startNew();

        if ($first = $this->programs()->first()) {
            $this->select($first->id);
        }
    }

    /* ---- points ------------------------------------------------------- */

    private function editableProgram(): InspectionProgram
    {
        Gate::authorize($this->managePermission());

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
            'brand'     => $this->brand(),
            'programs'  => $this->programs(),
            'program'   => $program,
            'sections'  => $program?->points->groupBy(fn ($point) => $point->section ?: 'General') ?? collect(),
            'canManage' => Gate::allows($this->managePermission()),
        ];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.settings') }}" wire:navigate>Platform settings</a>
    <span>›</span>
    <a href="{{ route('admin.brands') }}" wire:navigate>Brands</a>
    <span>›</span>
    <a href="{{ route('admin.brands.show', $brandId) }}" wire:navigate>{{ $brand->name }}</a>
    <span>›</span>
    <span class="cur">CPO checklist</span>
@endpush

@push('styles')
    <style>
        .tabs { display:flex; gap:6px; margin-bottom:18px; border-bottom:1px solid var(--line-strong); }
        .tabs a { padding:9px 14px; font-size:14px; font-weight:600; color:var(--ink-2); border-bottom:3px solid transparent; margin-bottom:-1px; }
        .tabs a.on { color:var(--ink); border-bottom-color:var(--primary); }
        .ip-head h1 { font-family:var(--font-display); font-size:32px; font-weight:700; text-transform:uppercase; line-height:1; margin:0 0 8px; }
        .ip-head p { margin:0 0 20px; font-size:15px; color:var(--ink-2); max-width:680px; }
        .msg { background:#E6F8EC; color:#0B7355; border-left:4px solid var(--good); border-radius:5px; padding:10px 14px; font-size:14px; font-weight:600; margin-bottom:16px; }

        .ip { display:grid; grid-template-columns:260px minmax(0, 1fr); gap:24px; align-items:start; }
        .card { background:#fff; border:1px solid var(--line-strong); border-top:2px solid var(--ink); border-radius:8px; }

        /* checklist picker: a ruled list */
        .plist { display:flex; flex-direction:column; }
        .pitem { display:flex; align-items:center; gap:10px; padding:11px 14px 11px 11px; text-align:left; width:100%; border-bottom:1px solid var(--line-strong); border-left:3px solid transparent; }
        .pitem:hover { background:var(--bg); }
        .pitem.on { background:var(--primary-soft); border-left-color:var(--primary); }
        .pitem:focus-visible { outline:2px solid var(--primary); outline-offset:-2px; }
        .pitem .nm { font-family:var(--font-display); font-size:17px; font-weight:700; text-transform:uppercase; line-height:1.1; color:var(--ink); }
        .pitem .sub { font-size:13px; color:var(--ink-3); }
        .pitem.off .nm { color:var(--ink-3); }
        .padd { margin:12px; width:calc(100% - 24px); display:flex; align-items:center; justify-content:center; gap:7px; padding:9px; border-radius:8px; border:1.5px dashed var(--line-strong); font-size:14px; font-weight:700; color:var(--ink); }
        .padd:hover { border-color:var(--ink); border-style:solid; }
        .padd svg { width:15px; height:15px; }
        .pcopy { border-top:1px solid var(--line-strong); padding:12px; display:flex; flex-direction:column; gap:8px; }
        .pcopy label { font-size:13px; font-weight:700; color:var(--ink-2); }
        .pcopy select { border:1.5px solid var(--line-strong); border-radius:8px; padding:8px 10px; font-size:14px; font-family:inherit; color:var(--ink); background:#fff; width:100%; }
        .pcopy select:focus { outline:none; border-color:var(--ink); }

        /* editor head: count as a stat cell */
        .ed-top { display:flex; align-items:center; gap:16px; padding:16px 20px; border-bottom:2px solid var(--ink); flex-wrap:wrap; }
        .ed-count { display:flex; flex-direction:column; justify-content:center; padding-right:16px; border-right:1px solid var(--line-strong); flex-shrink:0; }
        .ed-count b { order:2; font-family:var(--font-display); font-size:44px; font-weight:700; line-height:1; color:var(--ink); }
        .ed-count span { order:1; font-size:13px; font-weight:600; color:var(--ink-3); margin-bottom:2px; }
        .ed-top .ttl { font-family:var(--font-display); font-size:24px; font-weight:700; text-transform:uppercase; line-height:1.05; }
        .ed-top .sub { font-size:13px; color:var(--ink-3); margin-top:3px; }
        .fields { padding:18px 20px; display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:14px; border-bottom:1px solid var(--line-strong); }
        .field { display:flex; flex-direction:column; gap:6px; grid-column:span 3; }
        .field.w2 { grid-column:span 2; } .field.w4 { grid-column:span 4; } .field.w6 { grid-column:1 / -1; }
        .field label { font-size:13px; font-weight:700; color:var(--ink-2); }
        .field label .opt { font-weight:500; color:var(--ink-3); }
        .field input, .field textarea { border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font-size:14px; font-family:inherit; color:var(--ink); background:#fff; width:100%; }
        .field input:focus, .field textarea:focus { outline:none; border-color:var(--ink); }
        .err { font-size:13px; color:#D81A48; font-weight:600; }
        .seg { display:inline-flex; flex-wrap:wrap; border:1.5px solid var(--ink); border-radius:8px; background:#fff; overflow:hidden; align-self:flex-start; }
        .seg button { padding:8px 14px; font-size:14px; font-weight:700; color:var(--ink); border-right:1.5px solid var(--ink); }
        .seg button:last-child { border-right:none; }
        .seg button:hover { background:var(--bg); }
        .seg button.on { background:var(--ink); color:#fff; }
        .seg button:focus-visible { outline:2px solid var(--primary); outline-offset:-3px; }
        .switch { margin-left:auto; display:inline-flex; align-items:center; gap:9px; cursor:pointer; user-select:none; }
        .switch input { position:absolute; opacity:0; pointer-events:none; }
        .switch .track { width:40px; height:22px; border-radius:8px; background:var(--line-strong); position:relative; transition:background .15s ease; }
        .switch .knob { position:absolute; top:3px; left:3px; width:16px; height:16px; border-radius:5px; background:#fff; transition:transform .15s ease; }
        .switch input:checked + .track { background:#0B7355; }
        .switch input:checked + .track .knob { transform:translateX(18px); }
        .switch input:focus-visible + .track { outline:2px solid var(--primary); outline-offset:2px; }
        .switch-lbl { font-family:var(--font-display); font-size:15px; font-weight:700; text-transform:uppercase; color:var(--ink); min-width:50px; }
        .inherit { display:flex; align-items:center; gap:13px; padding:13px 16px; margin-bottom:18px; border-radius:5px; background:#E6F8EC; color:#0B7355; border-left:4px solid var(--good); font-size:14px; line-height:1.45; }
        .inherit b { display:block; font-size:15px; }
        .inherit .ic { color:#0B7355; display:grid; place-items:center; flex-shrink:0; }
        .inherit .ic svg { width:18px; height:18px; }
        .inherit .go { width:18px; height:18px; margin-left:auto; flex-shrink:0; }
        .pempty { padding:14px 14px 4px; font-size:14px; color:var(--ink-2); line-height:1.5; }
        .ed-actions { display:flex; gap:8px; padding:14px 20px; border-bottom:2px solid var(--ink); justify-content:flex-end; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:7px; font-size:14px; font-weight:700; border-radius:8px; padding:9px 17px; white-space:nowrap; border:1.5px solid transparent; }
        .btn svg { width:15px; height:15px; }
        .btn:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:15px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); border-color:var(--primary-press); }
        .btn-ghost { border-color:var(--ink); color:var(--ink); background:#fff; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .btn-danger { color:#D81A48; margin-right:auto; text-decoration:underline; text-underline-offset:3px; padding-left:2px; padding-right:2px; }

        /* the sheet: section headers ruled in ink, points ruled in line */
        .sec { padding:18px 20px 7px; font-family:var(--font-display); font-size:19px; font-weight:700; text-transform:uppercase; line-height:1; color:var(--ink); display:flex; gap:10px; align-items:baseline; border-bottom:2px solid var(--ink); }
        .sec span { font-size:15px; font-weight:600; color:var(--ink-3); }
        .pt { display:flex; align-items:center; gap:10px; min-height:42px; padding:5px 12px 5px 20px; font-size:15px; border-bottom:1px solid var(--line-strong); }
        .pt:hover { background:var(--bg); }
        .pt .ck { width:17px; height:17px; color:#0B7355; flex-shrink:0; }
        .pt .lb { flex:1; min-width:0; }
        .pt .tools { display:flex; gap:4px; opacity:0; transition:opacity .12s ease; }
        .pt:hover .tools, .pt:focus-within .tools { opacity:1; }
        .icon-btn { width:30px; height:30px; display:grid; place-items:center; border-radius:8px; border:1.5px solid transparent; color:var(--ink-3); }
        .icon-btn:hover { border-color:var(--ink); color:var(--ink); background:#fff; }
        .icon-btn:focus-visible { outline:2px solid var(--primary); outline-offset:1px; }
        .icon-btn svg { width:15px; height:15px; }
        .empty { margin:16px 20px; padding:16px; border:1.5px dashed var(--line-strong); border-radius:8px; font-size:14px; color:var(--ink-2); }

        .adder { display:grid; grid-template-columns:180px minmax(0, 1fr) auto; gap:8px; padding:16px 20px; background:var(--bg); border-top:2px solid var(--ink); margin-top:-1px; }
        .adder input, .adder textarea { border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font-size:14px; font-family:inherit; color:var(--ink); background:#fff; width:100%; }
        .adder input:focus, .adder textarea:focus { outline:none; border-color:var(--ink); }
        .adder .full { grid-column:1 / -1; }
        .adder .swap { grid-column:1 / -1; justify-self:start; font-size:14px; font-weight:700; color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; }

        @media (max-width:900px) {
            .ip { grid-template-columns:1fr; }
            .fields { grid-template-columns:1fr; }
            .field, .field.w2, .field.w4 { grid-column:1 / -1; }
            .adder { grid-template-columns:1fr; }
            .pt .tools { opacity:1; }
            .switch { margin-left:0; }
        }
    </style>
@endpush

<div>
    @include('partials.brand-header', ['brand' => $brand, 'activeTab' => 'checklist'])

    @if ($statusMessage !== '') <div class="msg">{{ $statusMessage }}</div> @endif

    <div class="ip">
        {{-- Checklists --}}
        <div class="card">
            @if ($programs->isEmpty())
                <div class="pempty">No checklist yet. {{ $brand->name }} cars show no inspection until you add one — type the points in, paste a list, or copy another brand’s to start.</div>
            @endif
            <div class="plist">
                @foreach ($programs as $item)
                    <button type="button" class="pitem {{ $programId === $item->id ? 'on' : '' }} {{ $item->is_active ? '' : 'off' }}" wire:click="select({{ $item->id }})" wire:key="p-{{ $item->id }}">
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
                            <option value="">Another brand’s checklist…</option>
                            @foreach ($this->copySources() as $source)
                                <option value="{{ $source->id }}">{{ $source->make }} · {{ $source->name }} ({{ $source->points_count }})</option>
                            @endforeach
                        </select>
                        @if ($copyFromId)
                            <button type="button" class="btn btn-ghost" style="justify-content:center" wire:click="copyFrom">Copy to {{ $brand->name }}</button>
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
                            <label for="name">Checklist name</label>
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
