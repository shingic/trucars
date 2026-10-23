{{-- Dealer users: staff and invitations. --}}
    <div class="card dl-users" id="users">
        <div class="card-head">
            <div class="ttl">Dealer users</div>
            <div class="sub">Staff who can sign in to this dealer's portal. They see this dealer's stores unless limited to chosen ones; a store at another dealer of the same group needs to be given here. New staff join by invitation and set their own password.</div>
        </div>

        @php $grantable = $this->dealerId ? $this->grantableStores() : collect(); @endphp
        @forelse ($this->staffMembers() as $member)
            <div class="u-row" wire:key="staff-{{ $member->id }}">
                <div>
                    <div class="u-name">{{ $member->name }}</div>
                    <div class="u-meta">{{ $member->email }} ·
                        {{ $member->storeGrants->isEmpty() ? 'All of this dealer’s stores' : $member->storeGrants->map(fn ($store) => ucwords(strtolower($store->display_name)))->implode(', ') }}
                    </div>
                </div>
                @if ($grantable->count() > 1)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="startStoreAccess({{ $member->id }})">Stores</button>
                @endif
                @if ($member->isSuspended())
                    <span class="u-pill u-off">Suspended</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="reinstateStaff({{ $member->id }})">Reinstate</button>
                @else
                    <span class="u-pill">Active</span>
                    <button type="button" class="btn btn-ghost btn-warn btn-sm" wire:click="suspendStaff({{ $member->id }})" wire:confirm="Suspend {{ $member->email }}? They won't be able to sign in.">Suspend</button>
                @endif
            </div>
            @if ($accessUserId === $member->id)
                <div class="u-access" wire:key="access-{{ $member->id }}">
                    <label class="u-check"><input type="radio" wire:model.live="accessAllStores" value="1" @checked($accessAllStores)> Every store of this dealer, including new ones</label>
                    <label class="u-check"><input type="radio" wire:model.live="accessAllStores" value="0" @checked(! $accessAllStores)> Only these stores</label>
                    @unless ($accessAllStores)
                        <div class="u-stores">
                            @foreach ($grantable as $store)
                                <label class="u-check" wire:key="grant-{{ $store->id }}">
                                    <input type="checkbox" wire:model="accessStoreIds" value="{{ $store->id }}">
                                    {{ ucwords(strtolower($store->display_name)) }}
                                    @if ($store->dealer_id !== $dealerId) <span class="u-meta">· {{ ucwords(strtolower($store->dealer->name)) }}, same group</span> @endif
                                </label>
                            @endforeach
                        </div>
                    @endunless
                    @error('accessStoreIds') <span class="err">{{ $message }}</span> @enderror
                    <div class="u-acts">
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelStoreAccess">Cancel</button>
                        <button type="button" class="btn btn-primary btn-sm" wire:click="saveStoreAccess">Save access</button>
                    </div>
                </div>
            @endif
        @empty
            <div class="u-empty">No staff yet.</div>
        @endforelse

        @foreach ($this->openInvitations() as $invitation)
            <div class="u-row" wire:key="invite-{{ $invitation->id }}">
                <div>
                    <div class="u-name">{{ $invitation->name }}</div>
                    <div class="u-meta">{{ $invitation->email }} · invited, expires {{ $invitation->expires_at->diffForHumans() }}</div>
                </div>
                <span class="u-pill u-wait">Invited</span>
                <span class="u-acts">
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="resendInvitation({{ $invitation->id }})">Resend</button>
                    <button type="button" class="btn btn-ghost btn-warn btn-sm" wire:click="revokeInvitation({{ $invitation->id }})">Revoke</button>
                </span>
            </div>
        @endforeach

        <form class="u-invite" wire:submit="inviteStaff">
            <div class="field">
                <label for="inviteName">Name</label>
                <input id="inviteName" type="text" wire:model="inviteName" maxlength="120">
                @error('inviteName') <span class="err">{{ $message }}</span> @enderror
            </div>
            <div class="field">
                <label for="inviteEmail">Work email</label>
                <input id="inviteEmail" type="email" wire:model="inviteEmail" maxlength="160">
                @error('inviteEmail') <span class="err">{{ $message }}</span> @enderror
            </div>
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="inviteStaff">Send invitation</button>
        </form>
    </div>
