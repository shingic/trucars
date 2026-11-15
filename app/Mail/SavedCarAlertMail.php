<?php

namespace App\Mail;

use App\Models\User;
use App\Models\Vehicle;
use App\Support\Marketing\EmailTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * News about the cars a shopper saved — see App\Support\Marketing\SavedCarAlerts.
 * Marketing email: business name, mailing address, unsubscribe by type or
 * all. Never names the dealership.
 *
 * @property Collection<int, array{car: Vehicle, was: int}> $drops
 */
class SavedCarAlertMail extends Mailable
{
    use Queueable, SerializesModels;
    use \App\Mail\Concerns\SendsAsMarketing;

    public string $firstName;

    /**
     * @param  Collection<int, array{car: Vehicle, was: int}>  $drops
     * @param  Collection<int, Vehicle>  $gone
     * @param  Collection<int, Vehicle>  $still
     * @param  Collection<int, Vehicle>  $suggestions
     */
    public function __construct(
        public User $user,
        public string $kind,
        public Collection $drops,
        public Collection $gone,
        public Collection $still,
        public Collection $suggestions,
    ) {
        $this->sendAsMarketing($kind);
        $this->firstName = (string) str((string) $user->name)->trim()->before(' ') ?: 'there';
    }

    public function envelope(): Envelope
    {
        $name = fn (Vehicle $car) => trim("{$car->model_year} {$car->make} {$car->model}");

        return new Envelope(from: self::marketingFrom(), subject: match ($this->kind) {
            'saved_price_drop' => $this->drops->count() === 1
                ? 'Price drop: ' . $name($this->drops->first()['car']) . ' is now ' . $this->drops->first()['car']->display_price
                : 'Price drops on ' . $this->drops->count() . ' cars you saved',
            'saved_gone'       => 'The ' . $name($this->gone->first()) . ' you saved is gone, but these are close',
            default            => 'Still thinking about the ' . $name($this->still->first()) . '?',
        });
    }

    public function headers(): Headers
    {
        $unsubscribe = URL::signedRoute('marketing.unsubscribe.type', ['user' => $this->user->id, 'type' => EmailTypes::forKind($this->kind)]);

        return new Headers(text: [
            'List-Unsubscribe'      => '<' . $unsubscribe . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.saved-car-alert');
    }
}
