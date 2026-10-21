<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The bell: a car the shopper was waiting on is back on sale. */
class VehicleAvailableAgain extends Mailable
{
    use Queueable, SerializesModels;

    public string $firstName;

    public function __construct(public User $buyer, public Vehicle $vehicle)
    {
        $this->firstName = (string) str($buyer->name)->trim()->before(' ');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "It's available again — {$this->vehicle->model_year} {$this->vehicle->make} {$this->vehicle->model}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.vehicle-available-again',
        );
    }
}
