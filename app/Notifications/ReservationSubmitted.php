<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Deal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Fired once a reservation is placed. Goes to two audiences with different
 * copy: the buyer (confirmation + what's next) and the dealer (new-lead alert).
 * Instantiate once per audience:
 *
 *   Notification::route('mail', $deal->email)
 *       ->notify(new ReservationSubmitted($deal, ReservationSubmitted::BUYER));
 *   Notification::route('mail', $deal->dealer->email)
 *       ->notify(new ReservationSubmitted($deal, ReservationSubmitted::DEALER));
 *
 * Queue-ready: implements ShouldQueue, so it runs inline on the sync driver and
 * off-thread the moment a real queue connection + worker are configured.
 * SMS-ready: add the chosen channel to via() and a matching toVonage()/toSms()
 * method once the vendor (Twilio / MessageBird / Telnyx) is picked.
 */
class ReservationSubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public const BUYER  = 'buyer';
    public const DEALER = 'dealer';

    public function __construct(
        public Deal $deal,
        public string $audience = self::BUYER,
    ) {}

    /**
     * Mail only for now. When SMS lands, append the channel here (e.g. 'vonage')
     * and add the corresponding toVonage() method below.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->audience === self::DEALER
            ? $this->dealerMail()
            : $this->buyerMail();
    }

    private function buyerMail(): MailMessage
    {
        $deal      = $this->deal;
        $vehicle   = $deal->vehicle;
        $vehicleLabel = trim("{$vehicle->year} {$vehicle->make} {$vehicle->model}");
        $deposit   = $this->formattedDeposit();

        return (new MailMessage)
            ->subject("Reservation confirmed — {$deal->reference}")
            ->greeting("You're reserved, {$deal->first_name}.")
            ->line("Your {$vehicleLabel} is held under reference {$deal->reference}.")
            ->line("We've placed your {$deposit} reservation hold. It's fully refundable and credited toward your purchase — it isn't a payment for the vehicle.")
            ->line('Next, finish your documents in My Garage so the dealer can finalize your financing. Any payment figures you saw are estimates until the dealer confirms your terms.')
            ->action('Open My Garage', url('/garage'))
            ->line('The dealership will reach out shortly to arrange the next steps.');
    }

    private function dealerMail(): MailMessage
    {
        $deal         = $this->deal;
        $vehicle      = $deal->vehicle;
        $vehicleLabel = trim("{$vehicle->year} {$vehicle->make} {$vehicle->model}");
        $deposit      = $this->formattedDeposit();

        return (new MailMessage)
            ->subject("New reservation — {$vehicleLabel} ({$deal->reference})")
            ->greeting('New reservation in your inbox.')
            ->line("{$deal->customer_full_name} reserved a {$vehicleLabel}.")
            ->line("Reference: {$deal->reference}")
            ->line("Plan: {$deal->purchase_type} · Deposit {$deposit} ({$deal->deposit_status})")
            ->line("Contact: {$deal->email} · {$deal->phone}")
            ->action('Open the reservation', route('dealer.reservations'))
            ->line('Reach out to the buyer to confirm details and move the deal forward.');
    }

    private function formattedDeposit(): string
    {
        return '$' . number_format($this->deal->deposit_in_cents / 100, 0);
    }
}
