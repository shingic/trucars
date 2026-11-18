<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

/** To Trumo: stores whose inventory file hasn't changed for a while, so their listings may be out of date. */
class FeedStale extends Mailable
{
    use Queueable;

    /** @param  Collection<int, \App\Models\Rooftop>  $stores */
    public function __construct(public Collection $stores) {}

    public function envelope(): Envelope
    {
        $count = $this->stores->count();

        return new Envelope(subject: $count === 1
            ? 'Inventory feed not updating: ' . $this->stores->first()->dealer?->name
            : "Inventory feeds not updating: {$count} stores");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.feed-stale');
    }
}
