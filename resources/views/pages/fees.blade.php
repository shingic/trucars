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
        abort_unless(auth()->user()?->portalDealer() !== null, 403, 'This area is for dealer accounts.');
    }

    /** The signed-in dealership. Guaranteed non-null past the mount guard. */
    protected function dealer(): Dealer
    {
        return auth()->user()->portalDealer();
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
        .fees-head { display:flex; align-items:flex-end; gap:20px; margin-bottom:22px; }
        .fees-head h1 { font-size:34px; font-weight:700; text-transform:uppercase; line-height:1; letter-spacing:0; margin:0 0 10px; }
        .fees-head p { margin:0; font-size:14.5px; color:var(--ink-2); max-width:620px; }
        .fees-head .spacer { flex:1; }

        .btn { font-size:14px; font-weight:700; border-radius:3px; padding:10px 18px; transition:background .15s ease, color .15s ease; white-space:nowrap; }
        .btn-primary { background:var(--primary); color:#fff; font-family:var(--font-display); font-size:15px; text-transform:uppercase; letter-spacing:.02em; }
        .btn-primary:hover { background:var(--primary-press); }
        .btn-primary:disabled { opacity:.55; }
        .btn-ghost { background:#fff; color:var(--ink); border:1.5px solid var(--ink); padding:8.5px 16.5px; }
        .btn-ghost:hover { background:var(--ink); color:#fff; }

        .fees-status { display:flex; align-items:center; gap:9px; background:#fff; color:#0B7355; border:1px solid var(--line-strong); border-left:3px solid var(--good); border-radius:3px; padding:11px 15px; font-size:14px; font-weight:600; margin-bottom:20px; }

        .fees-grid { display:grid; gap:30px; }

        .fee-card { border-top:2px solid var(--ink); }
        .fee-card-head { display:flex; flex-wrap:wrap; align-items:baseline; gap:6px 12px; padding:12px 0; border-bottom:1px solid var(--line-strong); }
        .fee-card-head .ttl { font-family:var(--font-display); font-size:22px; font-weight:700; text-transform:uppercase; line-height:1.1; }
        .fee-card-head .sub { flex-basis:100%; order:3; font-size:14px; color:var(--ink-2); }
        .fee-card-head .tag { display:inline-block; font-family:var(--font-display); font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; padding:2px 8px; border-radius:2px; }
        .tag-included { background:#E5F8EC; color:#0B7355; }
        .tag-pass { background:#FFF8DD; color:#7A4E00; }

        .fee-row { display:flex; align-items:center; gap:14px; padding:12px 0; border-bottom:1px solid var(--line-strong); }
        .fee-row .lbl { font-size:15px; font-weight:600; }
        .fee-row .amt { margin-left:auto; font-family:var(--font-display); font-size:18px; font-weight:700; color:var(--ink); }
        .fee-row .amt.incl { color:var(--ink-3); font-size:15px; font-weight:600; }
        .fee-row-actions { display:flex; gap:6px; }
        .icon-btn { width:32px; height:32px; border-radius:3px; display:grid; place-items:center; border:1.5px solid var(--line-strong); background:#fff; color:var(--ink-2); transition:all .14s ease; }
        .icon-btn svg { width:15px; height:15px; }
        .icon-btn:hover { border-color:var(--ink); color:var(--ink); }
        .icon-btn.danger:hover { border-color:#D81A48; color:#D81A48; background:#FFEEF3; }
        .icon-btn:focus-visible, .btn:focus-visible, .kind-opt:focus-visible { outline:2px solid var(--primary); outline-offset:2px; }

        .fee-empty { margin-top:12px; padding:14px 16px; font-size:14px; color:var(--ink-2); border:1.5px dashed var(--line-strong); border-radius:3px; }

        .fee-editor { background:#fff; border:1.5px solid var(--ink); border-radius:4px; padding:20px; margin-bottom:28px; }
        .fee-editor h2 { font-size:22px; font-weight:700; text-transform:uppercase; line-height:1; margin:0 0 18px; }
        .fee-fields { display:grid; grid-template-columns:1fr 180px; gap:16px; }
        .field { display:flex; flex-direction:column; gap:6px; }
        .field.full { grid-column:1 / -1; }
        .field label { font-size:13.5px; font-weight:700; color:var(--ink); }
        .field input { border:1.5px solid var(--line-strong); border-radius:3px; padding:9px 12px; font-size:15px; color:var(--ink); background:#fff; transition:border-color .14s ease; width:100%; }
        .field input:focus { outline:none; border-color:var(--ink); box-shadow:0 0 0 2px var(--primary-soft); }
        .field .err { font-size:13px; color:#D81A48; font-weight:600; }

        .amount-wrap { position:relative; }
        .amount-wrap span { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--ink-3); font-size:15px; }
        .amount-wrap input { padding-left:24px; }

        .kind-toggle { display:flex; gap:0; border:1.5px solid var(--ink); border-radius:3px; }
        .kind-opt { flex:1; background:#fff; padding:11px 13px; text-align:left; transition:background .14s ease, color .14s ease; }
        .kind-opt + .kind-opt { border-left:1.5px solid var(--ink); }
        .kind-opt .k { font-size:14px; font-weight:700; color:var(--ink); }
        .kind-opt .d { font-size:13px; color:var(--ink-3); margin-top:2px; }
        .kind-opt.on { background:var(--ink); }
        .kind-opt.on .k { color:#fff; }
        .kind-opt.on .d { color:rgba(255,255,255,.7); }

        .fee-editor-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

        @media (max-width:620px){
            .fees-head { flex-direction:column; align-items:flex-start; }
            .fees-head h1 { font-size:30px; }
            .fee-fields { grid-template-columns:1fr; }
            .kind-toggle { flex-direction:column; }
            .kind-opt + .kind-opt { border-left:none; border-top:1.5px solid var(--ink); }
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
