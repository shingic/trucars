<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/** A nudge when documents the buyer was asked for still aren't in. */
class DocumentsReminder extends Mailable
{
    use Queueable, SerializesModels;

    public Collection $documents;

    public function __construct(public Deal $deal)
    {
        $this->documents = $deal->documents()->outstanding()->get();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Still needed for your ' . $this->deal->vehicle_label);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.documents-reminder');
    }
}
