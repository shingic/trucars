<?php

namespace App\Mail;

use App\Models\Deal;
use App\Models\DealDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The dealership sent a document back; tells the buyer which one and why. */
class DocumentReuploadRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Deal $deal, public DealDocument $document, public ?string $reason = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Please re-upload your ' . strtolower($this->document->name));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.document-reupload');
    }
}
