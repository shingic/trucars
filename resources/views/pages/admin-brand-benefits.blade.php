<?php

use App\Models\AuditLog;
use App\Models\Brand;
use App\Support\CertifiedBenefits;
use App\Support\Permission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| Settings › Brands › Certified benefits (settings.manage). What the brand's
| certified pre-owned program gives the buyer — coverage, exchange privilege,
| etc. — typed in from the brand's own Canadian CPO page. Shown on the car
| page only for cars the feed flags manufacturer-certified, and only once on.
*/
new #[Layout('layouts.dealer')] #[Title('Certified benefits')] class extends Component {
    #[Locked]
    public int $brandId;

    public string $programName = '';

    /** @var array<int, array{icon: string, title: string, detail: string}> */
    public array $rows = [];

    public string $finePrint = '';
    public string $sourceUrl = '';
    public string $checkedOn = '';

    public string $statusMessage = '';

    public function mount(Brand $brand): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->brandId = $brand->id;
        $this->programName = (string) $brand->cpo_program_name;
        $this->rows = $brand->certifiedBenefitRows();
        $this->finePrint = (string) $brand->cpo_fine_print;
        $this->sourceUrl = (string) $brand->cpo_source_url;
        $this->checkedOn = (string) $brand->cpo_checked_on?->toDateString();

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function brand(): Brand
    {
        return Brand::findOrFail($this->brandId);
    }

    public function addRow(): void
    {
        $this->rows[] = ['icon' => 'check', 'title' => '', 'detail' => ''];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if ($this->rows === []) {
            $this->addRow();
        }
    }

    public function moveRow(int $index, int $direction): void
    {
        $target = $index + ($direction < 0 ? -1 : 1);

        if (! isset($this->rows[$index], $this->rows[$target])) {
            return;
        }

        [$this->rows[$index], $this->rows[$target]] = [$this->rows[$target], $this->rows[$index]];
    }

    public function save(): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $brand = $this->brand();
        $this->validate($this->rules($brand->cpo_benefits_live), attributes: $this->attributeNames());
        $this->persist($brand, $brand->cpo_benefits_live);
        $this->statusMessage = $brand->cpo_benefits_live ? 'Saved — the car pages show the change now.' : 'Saved. Turn it on when it’s ready.';
    }

    /** Going live needs at least one benefit, the brand's page and the date it was checked. */
    public function setLive(bool $live): void
    {
        Gate::authorize(Permission::SETTINGS_MANAGE);
        $this->validate($this->rules($live), attributes: $this->attributeNames());
        $this->persist($this->brand(), $live);
        $this->statusMessage = $live ? 'On — showing on certified ' . $this->brand()->name . ' cars.' : 'Off — hidden from car pages.';
    }

    private function rules(bool $live): array
    {
        return [
            'programName'   => ['nullable', 'string', 'max:120'],
            'rows'          => ['array', 'max:20'],
            'rows.*.icon'   => ['required', Rule::in(array_keys(CertifiedBenefits::ICONS))],
            'rows.*.title'  => [$live ? 'required' : 'nullable', 'string', 'max:120'],
            'rows.*.detail' => ['nullable', 'string', 'max:200'],
            'finePrint'     => ['nullable', 'string', 'max:3000'],
            'sourceUrl'     => [$live ? 'required' : 'nullable', 'url:https', 'max:500'],
            'checkedOn'     => [$live ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
        ];
    }

    private function attributeNames(): array
    {
        return ['rows.*.title' => 'benefit', 'rows.*.detail' => 'detail', 'sourceUrl' => 'brand page link', 'checkedOn' => 'date checked', 'finePrint' => 'fine print'];
    }

    private function persist(Brand $brand, bool $live): void
    {
        $before = $brand->only(['cpo_benefits_live']) + ['benefits' => count($brand->certifiedBenefitRows())];

        $brand->update([
            'cpo_program_name'  => trim($this->programName) ?: null,
            'cpo_benefits'      => $this->cleanRows(),
            'cpo_fine_print'    => trim($this->finePrint) ?: null,
            'cpo_source_url'    => trim($this->sourceUrl) ?: null,
            'cpo_checked_on'    => $this->checkedOn ?: null,
            'cpo_benefits_live' => $live,
        ]);

        $brand->refresh();
        $this->rows = $brand->certifiedBenefitRows() ?: [['icon' => 'check', 'title' => '', 'detail' => '']];

        AuditLog::record('brand.benefits_updated', $brand, $before, $brand->only(['cpo_benefits_live']) + ['benefits' => count($brand->certifiedBenefitRows())]);
    }

    private function cleanRows(): array
    {
        return collect($this->rows)
            ->map(fn ($row) => ['icon' => (string) ($row['icon'] ?? 'check'), 'title' => trim((string) ($row['title'] ?? '')), 'detail' => trim((string) ($row['detail'] ?? ''))])
            ->filter(fn ($row) => $row['title'] !== '')
            ->values()
            ->all();
    }

    public function with(): array
    {
        $brand = $this->brand();

        return [
            'brand'     => $brand,
            'live'      => $brand->cpo_benefits_live,
            'certified' => \App\Models\Vehicle::whereIn('dealer_id', $brand->dealers()->select('id'))
                ->where('is_published', true)->where('is_manufacturer_certified', true)
                ->whereRaw('lower(make) = ?', [mb_strtolower($brand->name)])->count(),
            'preview'   => array_values(array_filter($this->rows, fn ($row) => trim((string) ($row['title'] ?? '')) !== '')),
        ];
    }
}; ?>

@push('crumb')
    <a href="{{ route('admin.settings') }}" wire:navigate>Platform settings</a>
    <span>›</span>
    <a href="{{ route('admin.brands') }}" wire:navigate>Brands</a>
    <span>›</span>
    <a href="{{ route('admin.brands.show', $brand) }}" wire:navigate>{{ $brand->name }}</a>
    <span>›</span>
    <span class="cur">Certified benefits</span>
@endpush

@push('styles')
    <style>
        .cb { display:grid; grid-template-columns:minmax(0, 1fr) 380px; gap:24px; align-items:start; }
        .cb .card { background:#fff; border:1px solid var(--line-strong); border-top:2px solid var(--ink); border-radius:8px; }
        .cb-sec { padding:18px 20px; border-bottom:1px solid var(--line-strong); }
        .cb-sec:last-child { border-bottom:none; }
        .cb-sec-t { font-family:var(--font-display); font-size:20px; font-weight:700; text-transform:uppercase; line-height:1.1; color:var(--ink); }
        .cb-sec-s { font-size:13.5px; color:var(--ink-3); margin:3px 0 14px; line-height:1.45; }
        .f { display:flex; flex-direction:column; gap:6px; }
        .f label { font-size:13.5px; font-weight:700; color:var(--ink); }
        .f .opt { font-weight:500; color:var(--ink-3); }
        .cb input, .cb textarea, .cb select { border:1.5px solid var(--line-strong); border-radius:8px; padding:9px 12px; font-size:15px; font-family:inherit; color:var(--ink); background:#fff; width:100%; }
        .cb input:focus, .cb textarea:focus, .cb select:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 2px var(--primary-soft); }
        .cb textarea { min-height:110px; line-height:1.5; font-size:14px; }
        .pair { display:grid; grid-template-columns:minmax(0, 1fr) 190px; gap:12px; }
        .err { font-size:13px; color:#D81A48; font-weight:600; }

        .ben { display:grid; grid-template-columns:150px minmax(0, 1fr) auto; gap:10px; padding:12px 0; border-top:1px dashed var(--line-strong); align-items:start; }
        .ben:first-of-type { border-top:none; padding-top:0; }
        .ben .txt { display:flex; flex-direction:column; gap:6px; min-width:0; }
        .ben .txt input:last-of-type { font-size:14px; padding:7px 12px; color:var(--ink-2); }
        .ben .tools { display:flex; gap:2px; }
        .ben .tools button { width:32px; height:36px; display:grid; place-items:center; border-radius:6px; color:var(--ink-3); }
        .ben .tools button:hover:not(:disabled) { background:var(--bg-2); color:var(--ink); }
        .ben .tools button:disabled { opacity:.3; }
        .ben .tools .rm:hover { color:#D81A48; }
        .add { display:inline-flex; align-items:center; gap:6px; margin-top:12px; font-size:14px; font-weight:700; color:var(--primary-press); text-decoration:underline; text-underline-offset:3px; }
        .hint { font-size:13px; color:var(--ink-3); margin-top:6px; line-height:1.45; }

        .actions { display:flex; gap:8px; padding:16px 20px; align-items:center; border-top:2px solid var(--ink); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; font-size:14px; font-weight:700; border-radius:8px; padding:9px 17px; white-space:nowrap; border:1.5px solid transparent; }
        .btn-primary { background:var(--primary); border-color:var(--primary); color:#fff; font-family:var(--font-display); font-size:15px; text-transform:uppercase; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { border-color:var(--ink); color:var(--ink); background:#fff; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }
        .grow { flex:1; }
        .msg { background:#E6F8EC; color:#0B7355; border-left:4px solid var(--good); border-radius:5px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:16px; }

        .side { position:sticky; top:84px; display:flex; flex-direction:column; gap:16px; }
        .status { padding:16px 18px; }
        .status-row { display:flex; align-items:center; gap:10px; }
        .state { font-family:var(--font-display); font-size:14px; font-weight:600; text-transform:uppercase; padding:3px 9px; border-radius:5px; background:var(--bg-2); color:var(--ink-2); }
        .state.live { background:#E6F8EC; color:#0B7355; }
        .status p { font-size:13.5px; color:var(--ink-2); margin:10px 0 0; line-height:1.5; }
        .status .btn { margin-top:12px; width:100%; }

        .pv { padding:16px 18px; }
        .pv-label { font-size:12px; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); margin-bottom:10px; }
        .pv h3 { font-family:var(--font-display); font-size:20px; font-weight:700; text-transform:uppercase; line-height:1.05; margin:0 0 10px; color:var(--ink); }
        .pv-row { display:flex; gap:10px; padding:9px 0; border-top:1px solid var(--line-strong); }
        .pv-row .ic { width:30px; height:30px; border-radius:7px; background:var(--primary-soft); color:var(--primary-press); display:grid; place-items:center; flex-shrink:0; }
        .pv-row .t { font-size:13.5px; font-weight:700; color:var(--ink); line-height:1.3; }
        .pv-row .s { font-size:12.5px; color:var(--ink-3); margin-top:1px; }
        .pv-fine { font-size:11.5px; color:var(--ink-3); line-height:1.45; margin-top:10px; white-space:pre-line; }
        .pv-empty { font-size:13.5px; color:var(--ink-3); }

        @media (max-width:1100px) { .cb { grid-template-columns:minmax(0, 1fr); } .side { position:static; } }
        @media (max-width:640px) { .ben { grid-template-columns:minmax(0, 1fr) auto; } .ben select { grid-column:1 / -1; } .pair { grid-template-columns:minmax(0, 1fr); } }
    </style>
@endpush

<div>
    @include('partials.brand-header', ['brand' => $brand, 'activeTab' => 'benefits'])

    @if ($statusMessage !== '') <div class="msg">{{ $statusMessage }}</div> @endif

    <div class="cb">
        <div class="card">
            <div class="cb-sec">
                <div class="cb-sec-t">Program</div>
                <div class="cb-sec-s">The name of {{ $brand->name }}’s certified program, as {{ $brand->name }} writes it.</div>
                <div class="f">
                    <label for="programName">Program name <span class="opt">— optional</span></label>
                    <input id="programName" type="text" wire:model.live.debounce.400ms="programName" maxlength="120" placeholder="{{ $brand->name }} Certified Pre-Owned">
                    @error('programName')<span class="err">{{ $message }}</span>@enderror
                </div>
            </div>

            <div class="cb-sec">
                <div class="cb-sec-t">Benefits</div>
                <div class="cb-sec-s">One per row, in the order you want them shown. Keep the brand’s footnote marks (*, **, †) so they match the fine print.</div>
                @foreach ($rows as $i => $row)
                    <div class="ben" wire:key="ben-{{ $i }}">
                        <select wire:model.live="rows.{{ $i }}.icon" aria-label="Icon">
                            @foreach (CertifiedBenefits::ICONS as $key => [$label])
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="txt">
                            <input type="text" wire:model.live.debounce.400ms="rows.{{ $i }}.title" maxlength="120" placeholder="Benefit, e.g. Exchange privilege*" aria-label="Benefit">
                            <input type="text" wire:model.live.debounce.400ms="rows.{{ $i }}.detail" maxlength="200" placeholder="Detail line — optional" aria-label="Detail">
                            @error("rows.$i.title")<span class="err">{{ $message }}</span>@enderror
                        </div>
                        <div class="tools">
                            <button type="button" wire:click="moveRow({{ $i }}, -1)" @disabled($i === 0) aria-label="Move up"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m18 15-6-6-6 6"/></svg></button>
                            <button type="button" wire:click="moveRow({{ $i }}, 1)" @disabled($i === count($rows) - 1) aria-label="Move down"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m6 9 6 6 6-6"/></svg></button>
                            <button type="button" class="rm" wire:click="removeRow({{ $i }})" aria-label="Remove"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                        </div>
                    </div>
                @endforeach
                @if (count($rows) < 20)
                    <button type="button" class="add" wire:click="addRow">+ Add a benefit</button>
                @endif
            </div>

            <div class="cb-sec">
                <div class="cb-sec-t">Fine print &amp; source</div>
                <div class="cb-sec-s">Copy the footnotes word for word. Shoppers see the link to {{ $brand->name }}’s page so they can check it themselves.</div>
                <div class="f">
                    <label for="finePrint">Fine print <span class="opt">— the *, ** and † notes</span></label>
                    <textarea id="finePrint" wire:model.live.debounce.400ms="finePrint" maxlength="3000" placeholder="*Exchange privilege … **First oil change … †Connected Services …"></textarea>
                    @error('finePrint')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="pair" style="margin-top:14px">
                    <div class="f">
                        <label for="sourceUrl">{{ $brand->name }}’s CPO page</label>
                        <input id="sourceUrl" type="url" wire:model.blur="sourceUrl" maxlength="500" placeholder="https://www.{{ \Illuminate\Support\Str::slug($brand->name, '') }}.ca/…">
                        @error('sourceUrl')<span class="err">{{ $message }}</span>@enderror
                    </div>
                    <div class="f">
                        <label for="checkedOn">Checked on</label>
                        <input id="checkedOn" type="date" wire:model.blur="checkedOn" max="{{ now()->toDateString() }}">
                        @error('checkedOn')<span class="err">{{ $message }}</span>@enderror
                    </div>
                </div>
                <div class="hint">Brands change their programs. Re-check the page now and then and update the date.</div>
            </div>

            <div class="actions">
                <span class="grow"></span>
                <button type="button" class="btn btn-primary" wire:click="save">Save</button>
            </div>
        </div>

        <div class="side">
            <div class="card status">
                <div class="status-row">
                    <span class="state {{ $live ? 'live' : '' }}">{{ $live ? 'On' : 'Off' }}</span>
                    <b style="font-size:14px">{{ $live ? 'Showing on car pages' : 'Hidden from car pages' }}</b>
                </div>
                <p>
                    Shows only on {{ $brand->name }} cars that {{ $brand->name }} dealers list as manufacturer-certified —
                    <b>{{ $certified }} live {{ $certified === 1 ? 'car' : 'cars' }}</b> right now. Plain used {{ $brand->name }}s don’t get it.
                </p>
                @if ($live)
                    <button type="button" class="btn btn-ghost" wire:click="setLive(false)">Turn off</button>
                @else
                    <button type="button" class="btn btn-primary" wire:click="setLive(true)">Save &amp; turn on</button>
                @endif
            </div>

            <div class="card pv">
                <div class="pv-label">Preview on the car page</div>
                @if ($preview === [])
                    <div class="pv-empty">Add a benefit to see it here.</div>
                @else
                    <h3>{{ trim($programName) ?: $brand->name . ' Certified Pre-Owned' }}</h3>
                    @foreach ($preview as $row)
                        <div class="pv-row">
                            <span class="ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! CertifiedBenefits::icon($row['icon']) !!}</svg></span>
                            <div><div class="t">{{ $row['title'] }}</div>@if (trim($row['detail']) !== '')<div class="s">{{ $row['detail'] }}</div>@endif</div>
                        </div>
                    @endforeach
                    @if (trim($finePrint) !== '')<div class="pv-fine">{{ $finePrint }}</div>@endif
                @endif
            </div>
        </div>
    </div>
</div>
