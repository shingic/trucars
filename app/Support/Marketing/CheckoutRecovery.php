<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Mail\CheckoutRecoveryMail;
use App\Models\CheckoutDraft;
use App\Models\MarketingEmail;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Follow-up emails to shoppers who started a checkout but didn't pay the
 * deposit. Run every 15 minutes (checkouts:send-recovery).
 *
 *   +1 hour   pick up where you left off
 *   +1 day    still available, plus similar cars near you
 *   +3 days   the $150 is fully refundable
 *   +7 days   cars picked for you
 *
 * Timed from their last activity. Stops the moment they pay, unsubscribe or
 * the car is gone. One sequence per shopper (their most recent car), never
 * two emails within 12 hours, nothing overnight, and nothing at all until the
 * business mailing address is set (CASL). Dealers are never named.
 */
class CheckoutRecovery
{
    /** kind => minutes after last activity */
    public const STAGES = [
        'recovery_1h' => 60,
        'recovery_1d' => 60 * 24,
        'recovery_3d' => 60 * 24 * 3,
        'recovery_7d' => 60 * 24 * 7,
    ];

    public const MIN_GAP_HOURS = 12;

    /** No sends between these hours, Toronto time. */
    public const QUIET_FROM = 21;
    public const QUIET_UNTIL = 8;

    public static function blockedReason(): ?string
    {
        if (blank(PlatformSettings::get('business_mailing_address'))) {
            return 'Set the business mailing address in Platform settings. Canada\'s anti-spam law requires it in every marketing email.';
        }

        return null;
    }

    /** Sends what's due. Returns how many emails went out. */
    public function run(): int
    {
        if (self::blockedReason() !== null) {
            Log::warning('Checkout recovery emails are paused: ' . self::blockedReason());

            return 0;
        }

        $hour = (int) now('America/Toronto')->format('G');
        if ($hour >= self::QUIET_FROM || $hour < self::QUIET_UNTIL) {
            return 0;
        }

        $sent = 0;

        // Each shopper's most recent unfinished checkout; their older ones stay quiet.
        CheckoutDraft::query()->active()->with(['user', 'vehicle', 'emails'])
            ->orderByDesc('last_active_at')->get()
            ->unique('user_id')
            ->each(function (CheckoutDraft $draft) use (&$sent) {
                if ($this->sendDue($draft)) {
                    $sent++;
                }
            });

        return $sent;
    }

    private function sendDue(CheckoutDraft $draft): bool
    {
        $user = $draft->user;
        $car = $draft->vehicle;

        if ($user === null || ! $user->acceptsMarketing()) {
            return false;
        }

        // The car's off the market, or someone else reserved it: this sequence is over.
        if ($car === null || ! $car->isListed() || $car->isReserved()) {
            $draft->update(['status' => CheckoutDraft::STATUS_CAR_GONE]);

            return false;
        }

        $minutesIdle = $draft->last_active_at->diffInMinutes(now());
        $sentKinds = $draft->emails->pluck('kind')->all();
        $due = collect(self::STAGES)
            ->filter(fn (int $after, string $kind) => $minutesIdle >= $after && ! in_array($kind, $sentKinds, true))
            ->keys()->last(); // a late run sends only the latest stage due, never a backlog

        if ($due === null) {
            return false;
        }

        $lastEmail = MarketingEmail::where('user_id', $user->id)->max('sent_at');
        if ($lastEmail && Carbon::parse($lastEmail)->gt(now()->subHours(self::MIN_GAP_HOURS))) {
            return false;
        }

        try {
            Mail::to($user)->send(new CheckoutRecoveryMail($draft, $due));
        } catch (\Throwable $e) {
            Log::error('Checkout recovery email failed', ['checkout_draft_id' => $draft->id, 'kind' => $due, 'error' => $e->getMessage()]);

            return false;
        }

        MarketingEmail::create(['user_id' => $user->id, 'checkout_draft_id' => $draft->id, 'kind' => $due, 'sent_at' => now()]);

        return true;
    }
}
