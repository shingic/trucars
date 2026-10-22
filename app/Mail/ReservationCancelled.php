<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The reservation is off, declined by the dealership or cancelled by TruCars, and whether the $150 went back. */
class ReservationCancelled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Deal $deal, public bool $byDealer, public bool $refunded, public ?string $note = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your reservation for the ' . $this->deal->vehicle_label . ' was ' . ($this->byDealer ? 'declined' : 'cancelled'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.reservation-cancelled');
    }
}
