<?php

namespace App\Mail;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The lender approved the buyer; the terms as the dealership entered them. */
class FinancingApproved extends Mailable
{
    use Queueable, SerializesModels;

    /** $updated is true when the dealership changed terms it had already sent. */
    public function __construct(public Deal $deal, public bool $updated = false)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->updated ? 'Your financing terms were updated' : "You're approved for your " . $this->deal->vehicle_label);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.financing-approved');
    }
}
