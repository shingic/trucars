<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/** The car is prepped. Lists anything still outstanding so the handover isn't held up. */
class ReadyForHandover extends Mailable
{
    use Queueable, SerializesModels;

    public Collection $documents;

    public function __construct(public Deal $deal)
    {
        $this->documents = $deal->documents()->outstanding()->get();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your ' . $this->deal->vehicle_label . ' is ready');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ready-for-handover');
    }
}
