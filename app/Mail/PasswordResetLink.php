<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetLink extends Mailable
{
    use Queueable, SerializesModels;

    public string $firstName;

    public function __construct(
        public User $buyer,
        public string $resetUrl,
        public int $expiresInMinutes = 60,
    ) {
        $this->firstName = (string) str($buyer->name)->trim()->before(' ');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Trumo password');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset-link');
    }
}
