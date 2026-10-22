<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The deal is finalized; the last thing the buyer owes is proof of insurance. */
class InsuranceRequested extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Deal $deal)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your deal is finalized — one last thing');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.insurance-requested');
    }
}
