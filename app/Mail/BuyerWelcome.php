<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BuyerWelcome extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The buyer's first name, pulled off their full name in the constructor so
     * the greeting stays warm without any logic living in the Blade view.
     */
    public string $firstName;

    /**
     * The freshly-created buyer. Public so the view can read it as $buyer.
     */
    public function __construct(public User $buyer)
    {
        $this->firstName = (string) str($buyer->name)->trim()->before(' ');
    }

    public function envelope(): Envelope
    {
        // From-address and from-name come from config/mail.php
        // (MAIL_FROM_ADDRESS / MAIL_FROM_NAME), matching ReservationConfirmed —
        // the verified Postmark sender stays in .env, never hard-coded here.
        return new Envelope(
            subject: 'Welcome to Trueleads',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.buyer-welcome',
        );
    }
}
