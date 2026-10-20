{{-- Changing an existing dealer's brand: its own step, with the impact shown, a reason and a typed confirmation. --}}
@if ($changingBrand)
    @php $impact = $this->brandChangeImpact(); $dealerName = \App\Models\Dealer::find($dealerId)?->name; @endphp
    <div class="bc-scrim" wire:keydown.escape.window="cancelBrandChange">
        <form class="bc" wire:submit="confirmBrandChange" role="dialog" aria-modal="true" aria-labelledby="bc-title">
            <div class="bc-head">
                <span class="warn"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17v.01"/></svg> Affects what shoppers see</span>
                <h2 id="bc-title">{{ $savedBrandId ? 'Change' : 'Set' }} {{ $dealerName }}’s brand</h2>
                <p>The dealer will follow the new brand’s CPO checklist and offers straight away.</p>
            </div>
            <div class="bc-body">
                <div>
                    <label for="newBrandId">New brand</label>
                    <select id="newBrandId" wire:model.live="newBrandId">
                        <option value="">Choose a brand…</option>
                        @foreach ($this->brands() as $brandOption)
                            @continue($brandOption->id === $savedBrandId)
                            <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                        @endforeach
                    </select>
                    @error('newBrandId') <span class="err">{{ $message }}</span> @enderror
                </div>

                @if ($impact)
                    <div class="impact">
                        <div class="impact-row">
                            <span class="k">Brand</span>
                            <span class="from">{{ $impact['old']['brand']->name ?? 'None' }}</span><span>→</span>
                            <span class="to">{{ $impact['new']['brand']->name }}</span>
                        </div>
                        <div class="impact-row">
                            <span class="k">CPO checklist</span>
                            <span class="from">{{ ($impact['old']['checklist'] ?? null) ? $impact['old']['checklist']->points_count . ' points' : 'None' }}</span><span>→</span>
                            <span class="to">{{ $impact['new']['checklist'] ? $impact['new']['checklist']->points_count . ' points' : 'None' }}</span>
                        </div>
                        <div class="impact-row">
                            <span class="k">Offers</span>
                            <span class="from">{{ $impact['old']['offers'] ?? 0 }} current</span><span>→</span>
                            <span class="to">{{ $impact['new']['offers'] }} current</span>
                        </div>
                    </div>
                    @if ($impact['oldBrandCars'] || $impact['liveCampaigns'])
                        <div class="impact-note">
                            @if ($impact['oldBrandCars']) {{ $impact['oldBrandCars'] }} live {{ $impact['old']['brand']->name ?? '' }} {{ $impact['oldBrandCars'] === 1 ? 'car loses' : 'cars lose' }} the {{ $impact['old']['brand']->name ?? 'old' }} checklist and offers. @endif
                            @if ($impact['liveCampaigns']) {{ $impact['liveCampaigns'] }} live {{ $impact['liveCampaigns'] === 1 ? 'campaign' : 'campaigns' }} will show {{ $impact['new']['brand']->name }} certified cars instead. @endif
                        </div>
                    @endif
                @endif

                <div>
                    <label for="brandChangeReason">Reason <span style="font-weight:500;color:var(--ink-3)">(kept in the audit log)</span></label>
                    <textarea id="brandChangeReason" rows="2" wire:model="brandChangeReason" maxlength="255" placeholder="e.g. Dealership re-franchised to Nissan on Nov 1"></textarea>
                    @error('brandChangeReason') <span class="err">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="brandChangeConfirm">Type <b>{{ $dealerName }}</b> to confirm</label>
                    <input id="brandChangeConfirm" type="text" wire:model.live="brandChangeConfirm" autocomplete="off">
                    @error('brandChangeConfirm') <span class="err">{{ $message }}</span> @enderror
                </div>
            </div>
            <div class="bc-foot">
                <button type="button" class="btn-cancel" wire:click="cancelBrandChange">Cancel</button>
                <button type="submit" class="btn-danger" @disabled(! $newBrandId || mb_strtolower(trim($brandChangeConfirm)) !== mb_strtolower(trim((string) $dealerName)))>{{ $savedBrandId ? 'Change brand' : 'Set brand' }}</button>
            </div>
        </form>
    </div>
@endif
