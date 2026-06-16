<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ReservationSubmitted extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The reservation that just landed. Public so the dealer-facing view can
     * read it directly as $deal.
     */
    public function __construct(public Deal $deal)
    {
    }

    public function envelope(): Envelope
    {
        $vehicle = $this->deal->vehicle;

        $vehicleLabel = trim("{$vehicle->model_year} {$vehicle->make} {$vehicle->model}");

        // From-address / from-name from config/mail.php, same as the buyer mail.
        return new Envelope(
            subject: 'New reservation — ' . $vehicleLabel,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reservation-submitted',
        );
    }
}
