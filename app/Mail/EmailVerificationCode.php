<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationCode extends Mailable
{
    use Queueable, SerializesModels;

    public string $firstName;

    /**
     * @param  User    $buyer             The account being verified.
     * @param  string  $code              The verification code to display.
     * @param  int     $expiresInMinutes  How long the code stays valid — for the copy.
     */
    public function __construct(
        public User $buyer,
        public string $code,
        public int $expiresInMinutes = 10,
    ) {
        $this->firstName = (string) str($buyer->name)->trim()->before(' ');
    }

    public function envelope(): Envelope
    {
        // Code in the subject so it surfaces in the notification preview.
        return new Envelope(
            subject: $this->code . ' is your TruCars verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.email-verification-code',
        );
    }
}
