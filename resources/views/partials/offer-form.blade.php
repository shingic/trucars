{{--
    The offer form fields, for components using App\Livewire\Concerns\EditsOffer.
    The brand is fixed (the brand being edited); $readOnly disables everything.
--}}
@php
    $readOnly ??= false;
    $typeIcons = [
        'apr'      => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'cash'     => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'warranty' => '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
    ];
@endphp
<style>
    .of-form { border:none; margin:0; padding:0; min-width:0; }
    .of-sec { padding:18px 20px; border-bottom:1px solid var(--line); }
    .of-sec:last-child { border-bottom:none; }
    .of-sec-t { font-size:11px; font-weight:800; letter-spacing:.06em; text-transform:uppercase; color:var(--ink-3); margin-bottom:12px; }
    .types { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; margin-bottom:14px; }
    .type { display:flex; align-items:center; gap:10px; padding:11px 12px; border-radius:12px; border:1.5px solid var(--line-strong); font-size:13px; font-weight:700; color:var(--ink-2); text-align:left; background:var(--card); }
    .type .ic { width:30px; height:30px; border-radius:9px; background:var(--bg); display:grid; place-items:center; flex-shrink:0; }
    .type .ic svg { width:16px; height:16px; }
    .type.on { border-color:var(--primary); background:var(--primary-soft); color:var(--primary-press); }
    .type.on .ic { background:var(--primary); color:#fff; }
    .grid { display:grid; grid-template-columns:repeat(6, minmax(0, 1fr)); gap:12px; }
    .f { display:flex; flex-direction:column; gap:5px; }
    .f.w1 { grid-column:span 1; } .f.w2 { grid-column:span 2; } .f.w4 { grid-column:span 4; } .f.w6 { grid-column:1 / -1; }
    .f label { font-size:12.5px; font-weight:700; color:var(--ink-2); }
    .f .opt { font-weight:500; color:var(--ink-3); }
    .f input, .f textarea { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:9px 12px; font-size:14px; font-family:inherit; background:var(--card); width:100%; }
    .f input:focus, .f textarea:focus { outline:none; border-color:var(--primary); }
    .f input[readonly], .of-form:disabled input, .of-form:disabled textarea { background:var(--bg); color:var(--ink-2); }
    .provs { display:flex; flex-wrap:wrap; gap:5px; }
    .prov { font-size:12px; font-weight:700; padding:5px 9px; border-radius:8px; border:1px solid var(--line-strong); cursor:pointer; color:var(--ink-2); }
    .prov input { display:none; }
    .prov.on { background:var(--ink); border-color:var(--ink); color:#fff; }
    .checks { gap:8px; }
    .check { display:flex; align-items:center; gap:8px; font-size:13.5px; font-weight:600; color:var(--ink); cursor:pointer; }
    .f .check input { width:16px; height:16px; padding:0; flex:none; accent-color:var(--primary); }
    .f .check .opt { font-weight:500; }
    .err { font-size:12px; color:var(--primary-press); font-weight:600; }
    @media (max-width:760px) { .types { grid-template-columns:1fr; } .grid { grid-template-columns:repeat(2, minmax(0, 1fr)); } .f.w4, .f.w6 { grid-column:1 / -1; } .f.w2 { grid-column:span 2; } }
</style>
<fieldset class="of-form" @disabled($readOnly)>
    <div class="of-sec">
        <div class="of-sec-t">Offer type</div>
        <div class="types">
            @foreach (\App\Models\Offer::TYPE_LABELS as $value => $label)
                <button type="button" class="type {{ $type === $value ? 'on' : '' }}" wire:click="setType('{{ $value }}')">
                    <span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">{!! $typeIcons[$value] !!}</svg></span>
                    {{ $label }}
                </button>
            @endforeach
        </div>
        <div class="grid">
            <div class="f w4"><label for="title">Title shown to customers</label><input id="title" type="text" wire:model.live.debounce.300ms="title" maxlength="120">@error('title')<span class="err">{{ $message }}</span>@enderror</div>
            @if ($type === 'apr')
                <div class="f w1"><label for="apr">Rate (APR %)</label><input id="apr" type="number" step="0.01" min="0" wire:model.live.debounce.300ms="apr" placeholder="3.99">@error('apr')<span class="err">{{ $message }}</span>@enderror</div>
                <div class="f w1"><label for="termMonths">Up to (months)</label><input id="termMonths" type="number" min="6" wire:model.live.debounce.300ms="termMonths" placeholder="48">@error('termMonths')<span class="err">{{ $message }}</span>@enderror</div>
                <div class="f w6"><label for="lender">Lender or program <span class="opt">(optional)</span></label><input id="lender" type="text" wire:model.live.debounce.300ms="lender" maxlength="120" placeholder="e.g. Manufacturer’s finance arm"></div>
            @elseif ($type === 'cash')
                <div class="f w2"><label for="cashAmount">Amount off ($)</label><input id="cashAmount" type="number" min="1" wire:model.live.debounce.300ms="cashAmount" placeholder="1500">@error('cashAmount')<span class="err">{{ $message }}</span>@enderror</div>
            @else
                <div class="f w6"><label for="benefit">The benefit, in a few words</label><input id="benefit" type="text" wire:model.live.debounce.300ms="benefit" maxlength="200" placeholder="e.g. 1 extra year of powertrain coverage">@error('benefit')<span class="err">{{ $message }}</span>@enderror</div>
            @endif
        </div>
    </div>

    <div class="of-sec">
        <div class="of-sec-t">Which cars</div>
        <div class="grid">
            <div class="f w6"><label for="models">{{ $make }} models <span class="opt">(comma-separated — empty means all)</span></label><input id="models" type="text" wire:model="models" maxlength="500" placeholder="e.g. 330i, X3, X5"></div>
            <div class="f w1"><label for="yearMin">From year</label><input id="yearMin" type="number" wire:model="yearMin" placeholder="Any">@error('yearMin')<span class="err">{{ $message }}</span>@enderror</div>
            <div class="f w1"><label for="yearMax">To year</label><input id="yearMax" type="number" wire:model="yearMax" placeholder="Any">@error('yearMax')<span class="err">{{ $message }}</span>@enderror</div>
            <div class="f w4">
                <label>Provinces <span class="opt">(none ticked means all)</span></label>
                <div class="provs">
                    @foreach (\App\Models\Offer::PROVINCES as $province)
                        <label class="prov {{ in_array($province, $provinces, true) ? 'on' : '' }}"><input type="checkbox" value="{{ $province }}" wire:model.live="provinces">{{ $province }}</label>
                    @endforeach
                </div>
            </div>
            <div class="f w6 checks">
                <label class="check"><input type="checkbox" wire:model="certifiedOnly"> Certified cars only <span class="opt">— cars showing an inspection checklist</span></label>
                <label class="check"><input type="checkbox" wire:model="combinable"> Can be combined with other offers</label>
            </div>
        </div>
    </div>

    <div class="of-sec">
        <div class="of-sec-t">When &amp; fine print</div>
        <div class="grid">
            <div class="f w2"><label for="startsOn">Starts</label><input id="startsOn" type="date" wire:model.live="startsOn">@error('startsOn')<span class="err">{{ $message }}</span>@enderror</div>
            <div class="f w2"><label for="endsOn">Ends <span class="opt">(last day)</span></label><input id="endsOn" type="date" wire:model.live="endsOn">@error('endsOn')<span class="err">{{ $message }}</span>@enderror</div>
            <div class="f w6"><label for="conditions">Conditions and restrictions</label><textarea id="conditions" rows="3" wire:model.live.debounce.400ms="conditions" maxlength="2000" placeholder="e.g. On approved credit. Eligible certified vehicles only. Participating dealers."></textarea>@error('conditions')<span class="err">{{ $message }}</span>@enderror</div>
        </div>
    </div>
</fieldset>
