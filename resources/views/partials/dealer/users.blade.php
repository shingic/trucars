{{-- Dealer users: staff and invitations. --}}
    <div class="card dl-users" id="users">
        <div class="card-head">
            <div class="ttl">Dealer users</div>
            <div class="sub">Staff who can sign in to this dealer's portal. They see this dealer's data only. New staff join by invitation and set their own password.</div>
        </div>

        @forelse ($this->staffMembers() as $member)
            <div class="u-row" wire:key="staff-{{ $member->id }}">
                <div>
                    <div class="u-name">{{ $member->name }}</div>
                    <div class="u-meta">{{ $member->email }}</div>
                </div>
                @if ($member->isSuspended())
                    <span class="u-pill u-off">Suspended</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="reinstateStaff({{ $member->id }})">Reinstate</button>
                @else
                    <span class="u-pill">Active</span>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="suspendStaff({{ $member->id }})" wire:confirm="Suspend {{ $member->email }}? They won't be able to sign in.">Suspend</button>
                @endif
            </div>
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
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="revokeInvitation({{ $invitation->id }})">Revoke</button>
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
