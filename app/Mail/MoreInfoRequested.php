<?php

namespace App\Mail;

use App\Models\Deal;
use App\Models\DealDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/** The dealership asked for something beyond the standard checklist. */
class MoreInfoRequested extends Mailable
{
    use Queueable, SerializesModels;

    public Collection $documents;

    public function __construct(public Deal $deal, DealDocument $document)
    {
        $this->documents = collect([$document]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->deal->seller_name . ' needs one more thing: ' . strtolower($this->documents->first()->name));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.more-info-requested');
    }
}
