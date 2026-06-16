<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReservationConfirmed extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The reservation the buyer just placed. Exposed publicly so the email
     * view can read it directly as $deal.
     */
    public function __construct(public Deal $deal)
    {
    }

    public function envelope(): Envelope
    {
        $vehicle = $this->deal->vehicle;

        $vehicleLabel = trim("{$vehicle->model_year} {$vehicle->make} {$vehicle->model}");

        // The from-address and from-name come from config/mail.php
        // (MAIL_FROM_ADDRESS / MAIL_FROM_NAME), so the verified Postmark
        // sender lives in .env rather than being hard-coded here.
        return new Envelope(
            subject: 'Your reservation is confirmed — ' . $vehicleLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reservation-confirmed',
        );
    }
}
