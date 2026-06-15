<?php

use App\Models\Lead;
use Livewire\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

new #[Layout('layouts.dealer')] class extends Component {
    public Lead $lead;

    public string $newNote = '';

    public array $statusLabels = [
        'reservation' => 'Reservation',
        'new'         => 'New inquiry',
        'contacted'   => 'Contacted',
        'confirmed'   => 'Confirmed',
        'closed'      => 'Closed',
    ];

    public array $nextStage = [
        'reservation' => 'contacted',
        'new'         => 'contacted',
        'contacted'   => 'confirmed',
        'confirmed'   => 'closed',
    ];

    public function advance(): void
    {
        // The route-bound $lead is already fenced to this dealer by DealerScope.
        $nextStatus = $this->nextStage[$this->lead->status] ?? null;

        if ($nextStatus === null) {
            return; // closed is terminal — nothing to advance to
        }

        $this->lead->update(['status' => $nextStatus]);
    }

    public function addNote(): void
    {
        $this->validate(
            ['newNote' => ['required', 'string', 'max:2000']],
            ['newNote.required' => 'Write a note first.'],
        );

        $this->lead->notes()->create([
            'user_id' => auth()->id(),
            'body'    => trim($this->newNote),
        ]);

        $this->newNote = '';

        unset($this->notes); // bust the computed cache so the new note shows immediately
    }

    #[Computed]
    public function notes()
    {
        return $this->lead->notes()->with('author')->latest()->get();
    }

    #[Computed]
    public function ladderSteps(): array
    {
        // There is no status-history table yet, so once a lead moves past its
        // inbound stage we can't know whether it arrived as a reservation or a
        // plain inquiry — the first rung falls back to a neutral "Received".
        $inboundLabel = match ($this->lead->status) {
            'reservation' => 'Reservation',
            'new'         => 'New inquiry',
            default       => 'Received',
        };

        return [$inboundLabel, 'Contacted', 'Confirmed', 'Closed'];
    }

    #[Computed]
    public function ladderPosition(): int
    {
        // closed returns 4 (past the last rung) so every step renders as done.
        return match ($this->lead->status) {
            'contacted' => 1,
            'confirmed' => 2,
            'closed'    => 4,
            default     => 0,
        };
    }
}; ?>

@push('styles')
    <style>
        .lead-wrap { max-width:1180px; }

        .crumb a { color:var(--ink-3); text-decoration:none; transition:color .15s ease; }
        .crumb a:hover { color:var(--ink); }

        .lead-head { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin-bottom:22px; }
        .lead-head h1 { font-size:25px; font-weight:800; letter-spacing:-.025em; margin:0 0 5px; }
        .lead-head-meta { font-size:13px; font-weight:600; color:var(--ink-3); }

        .stage { display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:700; padding:5px 11px; border-radius:var(--radius-pill); white-space:nowrap; }
        .stage .dot { width:7px; height:7px; border-radius:50%; }
        .stage.reservation { background:var(--coral-soft); color:#B5611A; } .stage.reservation .dot { background:var(--coral); }
        .stage.new { background:var(--primary-soft); color:var(--primary-press); } .stage.new .dot { background:var(--primary); }
        .stage.contacted { background:var(--amber-soft); color:var(--amber); } .stage.contacted .dot { background:var(--amber); }
        .stage.confirmed { background:var(--good-soft); color:var(--good-ink); } .stage.confirmed .dot { background:var(--good); }
        .stage.closed { background:rgba(22,24,29,.07); color:var(--ink-2); } .stage.closed .dot { background:var(--ink-3); }

        .lead-grid { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:18px; align-items:start; }

        .panel { background:var(--card); border:1px solid var(--line); border-radius:var(--radius); padding:20px 22px; }
        .panel + .panel { margin-top:18px; }
        .panel h3 { font-size:12.5px; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--ink-3); margin:0 0 14px; }

        .lead-message { font-size:14.5px; line-height:1.65; color:var(--ink); white-space:pre-line; margin:0; }
        .lead-message.empty { color:var(--ink-3); font-style:italic; }

        .note-form textarea { width:100%; min-height:86px; resize:vertical; font:inherit; font-size:14px; line-height:1.55; color:var(--ink); padding:11px 13px; border:1.5px solid var(--line-strong); border-radius:var(--radius-sm); background:#fff; }
        .note-form textarea:focus { outline:none; border-color:var(--primary); }
        .note-form-foot { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:10px; }
        .note-error { font-size:12.5px; font-weight:600; color:#D7263D; }
        .note-submit { margin-left:auto; font-size:13px; font-weight:800; color:#fff; background:var(--btn); padding:9px 18px; border-radius:var(--radius-pill); transition:background .15s ease; }
        .note-submit:hover { background:var(--btn-press); }
        .note-submit[disabled] { opacity:.6; cursor:wait; }

        .note-list { margin-top:18px; }
        .note { display:flex; gap:12px; padding:14px 0; border-top:1px solid var(--line); }
        .note-av { width:32px; height:32px; flex:0 0 32px; border-radius:50%; background:var(--primary-soft); color:var(--primary-press); font-size:11.5px; font-weight:800; display:grid; place-items:center; }
        .note-head { font-size:13px; font-weight:700; color:var(--ink); }
        .note-when { font-size:12px; font-weight:600; color:var(--ink-3); margin-left:7px; }
        .note-body { font-size:14px; line-height:1.6; color:var(--ink-2); margin:3px 0 0; white-space:pre-line; }
        .notes-empty { font-size:13.5px; color:var(--ink-3); margin-top:18px; padding-top:14px; border-top:1px solid var(--line); }

        .fact { padding:10px 0; }
        .fact + .fact { border-top:1px solid var(--line); }
        .fact:first-of-type { padding-top:0; }
        .fact:last-of-type { padding-bottom:0; }
        .fact-label { display:block; font-size:11.5px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--ink-3); margin-bottom:3px; }
        .fact-value { font-size:14.5px; font-weight:600; color:var(--ink); text-decoration:none; }
        a.fact-value:hover { color:var(--primary-press); }
        .fact-value.muted { color:var(--ink-3); }

        .veh-title { font-size:15px; font-weight:800; color:var(--ink); }
        .veh-sub { font-size:13px; font-weight:600; color:var(--ink-2); margin-top:3px; }
        .veh-link { display:inline-flex; align-items:center; gap:6px; margin-top:13px; font-size:13px; font-weight:700; color:var(--primary-press); text-decoration:none; }
        .veh-link:hover { text-decoration:underline; }

        .ladder { display:flex; flex-direction:column; }
        .ladder-step { position:relative; display:flex; align-items:center; gap:12px; padding:9px 0; }
        .ladder-step + .ladder-step::before { content:""; position:absolute; left:5px; top:-9px; height:18px; width:2px; background:var(--line); }
        .ladder-dot { width:12px; height:12px; flex:0 0 12px; border-radius:50%; border:2px solid var(--line-strong); background:#fff; }
        .ladder-step.done .ladder-dot { background:var(--good); border-color:var(--good); }
        .ladder-step.now .ladder-dot { background:var(--primary); border-color:var(--primary); box-shadow:0 0 0 4px var(--primary-soft); }
        .ladder-label { font-size:13.5px; font-weight:600; color:var(--ink-3); }
        .ladder-step.done .ladder-label { color:var(--ink-2); }
        .ladder-step.now .ladder-label { color:var(--ink); font-weight:800; }

        .advance-full { width:100%; margin-top:16px; font-size:13.5px; font-weight:800; color:#fff; background:var(--primary); padding:12px 16px; border-radius:var(--radius-pill); transition:background .15s ease; }
        .advance-full:hover { background:var(--primary-press); }
        .advance-full[disabled] { opacity:.6; cursor:wait; }
        .ladder-closed { margin-top:16px; font-size:13px; font-weight:600; color:var(--ink-3); text-align:center; }

        @media (max-width:980px){
            .lead-grid { grid-template-columns:1fr; }
        }
    </style>
@endpush

@push('crumb')
    Dealer <span>·</span> <a href="{{ route('dealer.reservations') }}">Reservations</a> <span>·</span> <span class="cur">{{ $lead->name }}</span>
@endpush

<div class="lead-wrap">
    <div class="lead-head">
        <div>
            <h1>{{ $lead->name }}</h1>
            <div class="lead-head-meta">Lead #{{ $lead->id }} · arrived {{ $lead->created_at->diffForHumans() }}</div>
        </div>

        <span class="stage {{ $lead->status }}">
            <span class="dot"></span> {{ $statusLabels[$lead->status] ?? ucfirst($lead->status) }}
        </span>
    </div>

    <div class="lead-grid">
        <div>
            <section class="panel">
                <h3>Customer message</h3>

                @if (filled($lead->message))
                    <p class="lead-message">{{ $lead->message }}</p>
                @else
                    <p class="lead-message empty">No message was included with this lead.</p>
                @endif
            </section>

            <section class="panel">
                <h3>Internal notes</h3>

                <form class="note-form" wire:submit="addNote">
                    <textarea wire:model="newNote" placeholder="Add a note for your team — call outcomes, follow-ups, anything the next person should know."></textarea>

                    <div class="note-form-foot">
                        @error('newNote') <span class="note-error">{{ $message }}</span> @enderror

                        <button type="submit" class="note-submit" wire:loading.attr="disabled" wire:target="addNote">
                            <span wire:loading.remove wire:target="addNote">Add note</span>
                            <span wire:loading wire:target="addNote">Saving…</span>
                        </button>
                    </div>
                </form>

                @if ($this->notes->isNotEmpty())
                    <div class="note-list">
                        @foreach ($this->notes as $note)
                            <div class="note" wire:key="note-{{ $note->id }}">
                                <span class="note-av">{{ collect(explode(' ', $note->author->name))->take(2)->map(fn ($namePart) => mb_strtoupper(mb_substr($namePart, 0, 1)))->implode('') }}</span>
                                <div>
                                    <div>
                                        <span class="note-head">{{ $note->author->name }}</span>
                                        <span class="note-when">{{ $note->created_at->diffForHumans() }}</span>
                                    </div>
                                    <p class="note-body">{{ $note->body }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="notes-empty">No notes yet — you'll be the first.</div>
                @endif
            </section>
        </div>

        <aside>
            <section class="panel">
                <h3>Contact</h3>

                <div class="fact">
                    <span class="fact-label">Email</span>
                    <a href="mailto:{{ $lead->email }}" class="fact-value">{{ $lead->email }}</a>
                </div>

                <div class="fact">
                    <span class="fact-label">Phone</span>
                    @if (filled($lead->phone))
                        <a href="tel:{{ $lead->phone }}" class="fact-value">{{ $lead->phone }}</a>
                    @else
                        <span class="fact-value muted">Not provided</span>
                    @endif
                </div>

                <div class="fact">
                    <span class="fact-label">Received</span>
                    <span class="fact-value">{{ $lead->created_at->format('M j, Y · g:i a') }}</span>
                </div>
            </section>

            <section class="panel">
                <h3>Vehicle</h3>

                @if ($lead->vehicle)
                    <div class="veh-title">{{ $lead->vehicle->model_year }} {{ $lead->vehicle->make }} {{ $lead->vehicle->model }}</div>
                    <div class="veh-sub">{{ $lead->vehicle->trim }} · {{ number_format($lead->vehicle->kilometres) }} km · ${{ number_format($lead->vehicle->price_in_cents / 100) }}</div>

                    <a href="/cars/{{ $lead->vehicle->id }}" target="_blank" rel="noopener" class="veh-link">
                        View live listing
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17 17 7"/><path d="M7 7h10v10"/></svg>
                    </a>
                @else
                    <div class="veh-title">General inquiry</div>
                    <div class="veh-sub">No vehicle attached</div>
                @endif
            </section>

            <section class="panel">
                <h3>Progress</h3>

                <div class="ladder">
                    @foreach ($this->ladderSteps as $stepLabel)
                        <div class="ladder-step {{ $loop->index < $this->ladderPosition ? 'done' : '' }} {{ $loop->index === $this->ladderPosition ? 'now' : '' }}">
                            <span class="ladder-dot"></span>
                            <span class="ladder-label">{{ $stepLabel }}</span>
                        </div>
                    @endforeach
                </div>

                @if (isset($nextStage[$lead->status]))
                    <button type="button" class="advance-full" wire:click="advance" wire:loading.attr="disabled" wire:target="advance">
                        <span wire:loading.remove wire:target="advance">Mark {{ $statusLabels[$nextStage[$lead->status]] }}</span>
                        <span wire:loading wire:target="advance">Saving…</span>
                    </button>
                @else
                    <div class="ladder-closed">This lead is closed.</div>
                    @endif
            </section>
        </aside>
    </div>
</div>
