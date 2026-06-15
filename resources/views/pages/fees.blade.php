<?php

use App\Models\Dealer;
use App\Models\DealerFee;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Illuminate\Validation\Rule;

new #[Layout('layouts.dealer')] #[Title('Fee schedule')] class extends Component {
    // The editor is a single inline form reused for both adding and editing.
    // editingFeeId is null while adding a brand-new fee, or the id being edited.
    public bool $showEditor = false;
    public ?int $editingFeeId = null;

    public string $feeLabel = '';
    public string $feeKind = DealerFee::KIND_INCLUDED;
    public $feeAmount = '';            // entered in dollars; stored in cents

    public string $statusMessage = '';

    public function mount(): void
    {
        // This is a dealer-console page. The 'auth' middleware only proves the
        // visitor is signed in — it doesn't prove they're a dealer. A buyer
        // account (no dealer_id) would otherwise reach dealer() and 500, so
        // stop them here with a clear status instead.
        abort_unless(auth()->user()?->dealer !== null, 403, 'This area is for dealer accounts.');
    }

    /** The signed-in dealership. Guaranteed non-null past the mount guard. */
    protected function dealer(): Dealer
    {
        return auth()->user()->dealer;
    }

    /** This dealer's full schedule, in display order. */
    #[Computed]
    public function fees()
    {
        return $this->dealer()->fees()->get();
    }

    /** The dealer's own costs that sit inside the advertised all-in price. */
    #[Computed]
    public function includedFees()
    {
        return $this->fees->where('kind', DealerFee::KIND_INCLUDED)->values();
    }

    /** At-cost charges collected at delivery — never inside the headline price. */
    #[Computed]
    public function passThroughFees()
    {
        return $this->fees->where('kind', DealerFee::KIND_PASS_THROUGH)->values();
    }

    public function startAdd(): void
    {
        $this->resetEditor();
        $this->editingFeeId = null;
        $this->showEditor = true;
    }

    public function startEdit(int $feeId): void
    {
        $fee = $this->dealer()->fees()->whereKey($feeId)->firstOrFail();

        $this->editingFeeId = $fee->id;
        $this->feeLabel = $fee->label;
        $this->feeKind = $fee->kind;
        $this->feeAmount = number_format($fee->amount_in_cents / 100, 2, '.', '');
        $this->statusMessage = '';
        $this->showEditor = true;
    }

    public function saveFee(): void
    {
        $validated = $this->validate([
            'feeLabel'  => ['required', 'string', 'max:60'],
            'feeKind'   => ['required', Rule::in([DealerFee::KIND_INCLUDED, DealerFee::KIND_PASS_THROUGH])],
            'feeAmount' => ['required', 'numeric', 'min:0', 'max:100000'],
        ], attributes: [
            'feeLabel'  => 'fee name',
            'feeKind'   => 'fee type',
            'feeAmount' => 'amount',
        ]);

        $amountInCents = (int) round(((float) $validated['feeAmount']) * 100);

        if ($this->editingFeeId === null) {
            // Append new fees to the end of the schedule.
            $nextSortOrder = ((int) $this->dealer()->fees()->max('sort_order')) + 1;

            $this->dealer()->fees()->create([
                'label'           => $validated['feeLabel'],
                'kind'            => $validated['feeKind'],
                'amount_in_cents' => $amountInCents,
                'sort_order'      => $nextSortOrder,
            ]);

            $this->statusMessage = "Added “{$validated['feeLabel']}”.";
        } else {
            $fee = $this->dealer()->fees()->whereKey($this->editingFeeId)->firstOrFail();

            $fee->update([
                'label'           => $validated['feeLabel'],
                'kind'            => $validated['feeKind'],
                'amount_in_cents' => $amountInCents,
            ]);

            $this->statusMessage = "Updated “{$validated['feeLabel']}”.";
        }

        $this->closeEditor();
        unset($this->fees);
    }

    public function removeFee(int $feeId): void
    {
        $fee = $this->dealer()->fees()->whereKey($feeId)->firstOrFail();
        $removedLabel = $fee->label;
        $fee->delete();

        // If the row being edited was the one removed, drop the open editor.
        if ($this->editingFeeId === $feeId) {
            $this->closeEditor();
        }

        $this->statusMessage = "Removed “{$removedLabel}”.";
        unset($this->fees);
    }

    public function closeEditor(): void
    {
        $this->showEditor = false;
        $this->resetEditor();
    }

    protected function resetEditor(): void
    {
        $this->reset(['editingFeeId', 'feeLabel', 'feeAmount']);
        $this->feeKind = DealerFee::KIND_INCLUDED;
        $this->resetValidation();
    }

    public function asMoney($amountInDollars): string
    {
        return '$' . number_format((float) $amountInDollars, 2);
    }
}; ?>

@push('crumb')
    <span>Settings</span>
    <span>›</span>
    <span class="cur">Fee schedule</span>
@endpush

@push('styles')
    <style>
        .fees-head { display:flex; align-items:flex-start; gap:20px; margin-bottom:22px; }
        .fees-head h1 { font-size:24px; font-weight:800; letter-spacing:-.02em; margin:0 0 6px; }
        .fees-head p { margin:0; font-size:14px; color:var(--ink-2); max-width:620px; }
        .fees-head .spacer { flex:1; }

        .btn { font-size:14px; font-weight:700; border-radius:var(--radius-pill); padding:10px 18px; transition:all .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; box-shadow:0 8px 20px rgba(245,99,31,.24); }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-ghost { background:transparent; color:var(--ink-2); border:1px solid var(--line-strong); }
        .btn-ghost:hover { border-color:var(--ink-3); color:var(--ink); }

        .fees-status { display:flex; align-items:center; gap:9px; background:var(--good-soft); color:var(--good-ink); border:1px solid rgba(18,184,134,.25); border-radius:var(--radius-sm); padding:11px 15px; font-size:13.5px; font-weight:600; margin-bottom:20px; }

        .fees-grid { display:grid; gap:20px; }

        .fee-card { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .fee-card-head { padding:16px 20px 14px; border-bottom:1px solid var(--line); }
        .fee-card-head .ttl { font-size:15px; font-weight:700; }
        .fee-card-head .sub { font-size:12.5px; color:var(--ink-3); margin-top:2px; }
        .fee-card-head .tag { display:inline-block; font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; padding:3px 9px; border-radius:var(--radius-pill); margin-top:9px; }
        .tag-included { background:var(--good-soft); color:var(--good-ink); }
        .tag-pass { background:var(--amber-soft); color:var(--amber); }

        .fee-row { display:flex; align-items:center; gap:14px; padding:14px 20px; border-bottom:1px solid var(--line); }
        .fee-row:last-child { border-bottom:none; }
        .fee-row .lbl { font-size:14px; font-weight:600; }
        .fee-row .amt { margin-left:auto; font-family:var(--mono); font-size:14px; font-weight:600; }
        .fee-row .amt.incl { color:var(--ink-3); font-weight:500; font-style:italic; font-family:var(--font); }
        .fee-row-actions { display:flex; gap:6px; }
        .icon-btn { width:32px; height:32px; border-radius:9px; display:grid; place-items:center; border:1px solid var(--line); color:var(--ink-2); transition:all .14s ease; }
        .icon-btn svg { width:15px; height:15px; }
        .icon-btn:hover { border-color:var(--ink-3); color:var(--ink); background:var(--bg); }
        .icon-btn.danger:hover { border-color:var(--primary-line); color:var(--primary-press); background:var(--primary-soft); }

        .fee-empty { padding:20px; font-size:13.5px; color:var(--ink-3); }

        .fee-editor { background:var(--card); border:1px solid var(--primary-line); border-radius:var(--radius); box-shadow:var(--shadow-md); padding:20px; margin-bottom:20px; }
        .fee-editor h2 { font-size:15px; font-weight:700; margin:0 0 16px; }
        .fee-fields { display:grid; grid-template-columns:1fr 180px; gap:16px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field.full { grid-column:1 / -1; }
        .field label { font-size:12.5px; font-weight:700; color:var(--ink-2); }
        .field input { border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:10px 13px; font-size:14px; color:var(--ink); transition:border-color .14s ease; width:100%; }
        .field input:focus { outline:none; border-color:var(--primary); }
        .field .err { font-size:12px; color:var(--primary-press); font-weight:600; }

        .amount-wrap { position:relative; }
        .amount-wrap span { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--ink-3); font-size:14px; }
        .amount-wrap input { padding-left:24px; }

        .kind-toggle { display:flex; gap:8px; }
        .kind-opt { flex:1; border:1px solid var(--line-strong); border-radius:var(--radius-sm); padding:11px 12px; text-align:left; transition:all .14s ease; }
        .kind-opt .k { font-size:13px; font-weight:700; color:var(--ink); }
        .kind-opt .d { font-size:11.5px; color:var(--ink-3); margin-top:2px; }
        .kind-opt.on { border-color:var(--primary); background:var(--primary-soft); }
        .kind-opt.on .k { color:var(--primary-press); }

        .fee-editor-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

        @media (max-width:620px){
            .fee-fields { grid-template-columns:1fr; }
        }
    </style>
@endpush

<div>
    <div class="fees-head">
        <div>
            <h1>Fee schedule</h1>
            <p>
                Under OMVIC all-in pricing, your own costs — freight, PDI, admin — live inside the advertised price and
                are shown to buyers as <strong>included</strong>. Only at-cost government charges like licensing and
                registration are added at delivery. Edit either set below; changes apply to new reservations and never
                rewrite a buyer's already-agreed numbers.
            </p>
        </div>
        <div class="spacer"></div>
        <button type="button" class="btn btn-primary" wire:click="startAdd">+ Add a fee</button>
    </div>

    @if ($statusMessage !== '')
        <div class="fees-status">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
            <span>{{ $statusMessage }}</span>
        </div>
    @endif

    @if ($showEditor)
        <div class="fee-editor">
            <h2>{{ $editingFeeId === null ? 'Add a fee' : 'Edit fee' }}</h2>

            <div class="fee-fields">
                <div class="field full">
                    <label for="feeLabel">Fee name</label>
                    <input id="feeLabel" type="text" wire:model="feeLabel" placeholder="e.g. Dealer admin" maxlength="60">
                    @error('feeLabel') <span class="err">{{ $message }}</span> @enderror
                </div>

                <div class="field full">
                    <label>Where it sits</label>
                    <div class="kind-toggle">
                        <button type="button" class="kind-opt {{ $feeKind === \App\Models\DealerFee::KIND_INCLUDED ? 'on' : '' }}" wire:click="$set('feeKind', '{{ \App\Models\DealerFee::KIND_INCLUDED }}')">
                            <div class="k">Inside the all-in price</div>
                            <div class="d">Disclosed as included — adds nothing on top</div>
                        </button>
                        <button type="button" class="kind-opt {{ $feeKind === \App\Models\DealerFee::KIND_PASS_THROUGH ? 'on' : '' }}" wire:click="$set('feeKind', '{{ \App\Models\DealerFee::KIND_PASS_THROUGH }}')">
                            <div class="k">Added at delivery</div>
                            <div class="d">At-cost charge collected at handover</div>
                        </button>
                    </div>
                    @error('feeKind') <span class="err">{{ $message }}</span> @enderror
                </div>

                <div class="field">
                    <label for="feeAmount">Amount</label>
                    <div class="amount-wrap">
                        <span>$</span>
                        <input id="feeAmount" type="number" step="0.01" min="0" wire:model="feeAmount" placeholder="0.00">
                    </div>
                    @error('feeAmount') <span class="err">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="fee-editor-actions">
                <button type="button" class="btn btn-ghost" wire:click="closeEditor">Cancel</button>
                <button type="button" class="btn btn-primary" wire:click="saveFee" wire:loading.attr="disabled" wire:target="saveFee">
                    <span wire:loading.remove wire:target="saveFee">Save fee</span>
                    <span wire:loading wire:target="saveFee">Saving…</span>
                </button>
            </div>
        </div>
    @endif

    <div class="fees-grid">
        <div class="fee-card">
            <div class="fee-card-head">
                <div class="ttl">Inside the all-in price</div>
                <div class="sub">Your own costs, already baked into the advertised price.</div>
                <span class="tag tag-included">Included</span>
            </div>

            @forelse ($this->includedFees as $fee)
                <div class="fee-row" wire:key="fee-{{ $fee->id }}">
                    <span class="lbl">{{ $fee->label }}</span>
                    <span class="amt incl">{{ $this->asMoney($fee->amount_in_cents / 100) }} · included</span>
                    <span class="fee-row-actions">
                        <button type="button" class="icon-btn" wire:click="startEdit({{ $fee->id }})" aria-label="Edit {{ $fee->label }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button type="button" class="icon-btn danger" wire:click="removeFee({{ $fee->id }})" wire:confirm="Remove “{{ $fee->label }}” from your fee schedule?" aria-label="Remove {{ $fee->label }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                        </button>
                    </span>
                </div>
            @empty
                <div class="fee-empty">No included fees yet. Add freight, PDI, or an admin fee to disclose what's inside your price.</div>
            @endforelse
        </div>

        <div class="fee-card">
            <div class="fee-card-head">
                <div class="ttl">Added at delivery — at cost</div>
                <div class="sub">Government charges passed straight through, never in the headline price.</div>
                <span class="tag tag-pass">Pass-through</span>
            </div>

            @forelse ($this->passThroughFees as $fee)
                <div class="fee-row" wire:key="fee-{{ $fee->id }}">
                    <span class="lbl">{{ $fee->label }}</span>
                    <span class="amt">{{ $this->asMoney($fee->amount_in_cents / 100) }}</span>
                    <span class="fee-row-actions">
                        <button type="button" class="icon-btn" wire:click="startEdit({{ $fee->id }})" aria-label="Edit {{ $fee->label }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                        </button>
                        <button type="button" class="icon-btn danger" wire:click="removeFee({{ $fee->id }})" wire:confirm="Remove “{{ $fee->label }}” from your fee schedule?" aria-label="Remove {{ $fee->label }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                        </button>
                    </span>
                </div>
            @empty
                <div class="fee-empty">No pass-through charges yet. Add licensing or registration to collect them at delivery.</div>
            @endforelse
        </div>
    </div>
</div>
