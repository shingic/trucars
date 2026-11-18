<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A careers-page application, to Trumo. Sent straight away (not queued) so
 * the CV can be attached from the upload and never stored on our server.
 */
class JobApplicationReceived extends Mailable
{
    use Queueable;

    /**
     * @param  array{name: string, email: string, phone: ?string, role: string, link: ?string, message: string}  $application
     */
    public function __construct(
        public array $application,
        public ?string $resumePath = null,
        public ?string $resumeName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Application: {$this->application['role']} · {$this->application['name']}",
            replyTo: [new Address($this->application['email'], $this->application['name'])],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.job-application');
    }

    public function attachments(): array
    {
        if (! $this->resumePath || ! is_file($this->resumePath)) {
            return [];
        }

        return [Attachment::fromPath($this->resumePath)->as($this->resumeName ?: 'cv.pdf')];
    }
}
