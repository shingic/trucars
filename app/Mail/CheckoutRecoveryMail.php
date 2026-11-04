<?php

namespace App\Mail;

use App\Models\CheckoutDraft;
use App\Models\Vehicle;
use App\Support\Marketing\Recommendations;
use App\Support\ShopperLocation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * A follow-up to a shopper who started a checkout and didn't pay the deposit.
 * $kind is the stage (see CheckoutRecovery::STAGES). Marketing email: it
 * names the business and its mailing address, and unsubscribes in one click.
 * Never names the dealership.
 */
class CheckoutRecoveryMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $firstName;

    public string $carName;

    public string $unsubscribeUrl;

    /** @var Collection<int, Vehicle> */
    public Collection $suggestions;

    public function __construct(public CheckoutDraft $draft, public string $kind)
    {
        $car = $draft->vehicle;
        $this->firstName = (string) str((string) $draft->user->name)->trim()->before(' ') ?: 'there';
        $this->carName = trim("{$car->model_year} {$car->make} {$car->model}");
        $this->unsubscribeUrl = URL::signedRoute('marketing.unsubscribe', ['user' => $draft->user_id]);
        $this->suggestions = match ($kind) {
            'recovery_1d' => Recommendations::similarTo($car, 3, $draft->shopper_location),
            'recovery_7d' => Recommendations::similarTo($car, 6, $draft->shopper_location),
            default       => collect(),
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: match ($this->kind) {
            'recovery_1h' => "Your {$this->carName} is waiting",
            'recovery_1d' => "Still available: {$this->carName}",
            'recovery_3d' => "Hold the {$this->carName} for \$150, fully refundable",
            default       => 'Cars picked for you',
        });
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe'      => '<' . $this->unsubscribeUrl . '>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.checkout-recovery', with: ['whereLine' => fn (Vehicle $car) => $this->whereLine($car)]);
    }

    /** "85 km from you · Chilliwack, BC" for a car, from where the shopper was shopping. */
    public function whereLine(Vehicle $car): string
    {
        $km = ShopperLocation::distanceKm($this->draft->shopper_location, $car->locatedAt());

        return collect([$km === null ? null : ($km < 10 ? number_format($km, 1) : number_format($km)) . ' km from you', $car->display_location])->filter()->implode(' · ');
    }
}
