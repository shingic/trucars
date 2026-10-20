<?php

namespace App\Mail;

use App\Models\DealerInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DealerInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * $acceptUrl carries the one-time plain token — it exists only here and in
     * the recipient's inbox, never in the database.
     */
    public function __construct(public DealerInvitation $invitation, public string $acceptUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You’re invited to the ' . ucwords(strtolower($this->invitation->dealer->name)) . ' portal on TruCars',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.dealer-invitation',
        );
    }
}
