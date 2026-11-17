<?php

namespace App\Mail;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A contact-form message, to Trumo. Reply goes straight to the shopper. */
class ContactMessageReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Lead $lead) {}

    public function envelope(): Envelope
    {
        $topic = Lead::TOPICS[$this->lead->topic] ?? 'General';

        return new Envelope(
            subject: "Contact form: {$topic} · {$this->lead->name}",
            replyTo: [new Address($this->lead->email, $this->lead->name)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contact-message');
    }
}
