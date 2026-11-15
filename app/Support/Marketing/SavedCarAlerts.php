<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Mail\SavedCarAlertMail;
use App\Models\CheckoutDraft;
use App\Models\MarketingEmail;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails about the cars a shopper saved (saved:send-alerts, every 15 min):
 *
 *   Price drop   — a saved car is at least $250 cheaper than when they saved
 *                  it (or were last told about it).
 *   Gone         — someone else reserved it, or it's off the market; here
 *                  are similar cars.
 *   Still there? — 3 days after saving, still for sale and they haven't
 *                  started reserving it (that's the checkout reminders' job).
 *
 * At most one email per shopper per run, grouping their cars. Same rules as
 * the checkout reminders: needs the business mailing address, nothing
 * overnight, 12 hours between marketing emails, implied consent (Consent),
 * the shopper's email preferences, and dealers are never named.
 */
class SavedCarAlerts
{
    public const PRICE_DROP_MIN_CENTS = 25000;
    public const NUDGE_AFTER_DAYS = 3;

    /** Sends what's due. Returns how many emails went out. */
    public function run(): int
    {
        if (CheckoutRecovery::blockedReason() !== null) {
            return 0;
        }

        $hour = (int) now('America/Toronto')->format('G');
        if ($hour >= CheckoutRecovery::QUIET_FROM || $hour < CheckoutRecovery::QUIET_UNTIL) {
            return 0;
        }

        $sent = 0;
        User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->whereIn('id', DB::table('favourites')->select('user_id'))
            ->each(function (User $user) use (&$sent) {
                if ($this->sendDue($user)) {
                    $sent++;
                }
            });

        return $sent;
    }

    private function sendDue(User $user): bool
    {
        $saved = DB::table('favourites')->where('user_id', $user->id)->orderByDesc('created_at')->get();
        $cars = Vehicle::query()->with(["dealer", "rooftop"])->whereIn('id', $saved->pluck('vehicle_id'))->get()->keyBy('id');

        $drops = [];
        $gone = [];
        $still = [];

        foreach ($saved as $row) {
            $car = $cars->get($row->vehicle_id);
            if ($car === null) {
                continue;
            }

            $available = $car->isListed() && ! $car->isReserved();

            // First look at this save: remember today's price to compare against.
            if ($row->alerted_price_in_cents === null) {
                DB::table('favourites')->where('id', $row->id)->update(['alerted_price_in_cents' => $car->price_in_cents]);
                $row->alerted_price_in_cents = $car->price_in_cents;
            }

            if ($available && $car->price_in_cents <= $row->alerted_price_in_cents - self::PRICE_DROP_MIN_CENTS) {
                $drops[] = ['row' => $row, 'car' => $car, 'was' => (int) $row->alerted_price_in_cents];
            } elseif (! $available && $row->gone_alerted_at === null && ! $car->isReservedBy($user)) {
                $gone[] = ['row' => $row, 'car' => $car];
            } elseif ($available && $row->nudged_at === null
                && Carbon::parse($row->created_at)->lte(now()->subDays(self::NUDGE_AFTER_DAYS))
                && ! CheckoutDraft::where('user_id', $user->id)->where('vehicle_id', $car->id)->exists()) {
                $still[] = ['row' => $row, 'car' => $car];
            }
        }

        $wantsDrops = $user->wantsEmail(EmailTypes::PRICE_DROPS);
        $wantsUpdates = $user->wantsEmail(EmailTypes::SAVED_CAR_UPDATES);
        $paused = fn (string $kind) => \App\Support\Email\Catalogue::paused($kind);
        $drops = $wantsDrops && ! $paused('saved_price_drop') ? $drops : [];
        $gone = $wantsUpdates && ! $paused('saved_gone') ? $gone : [];
        $still = $wantsUpdates && ! $paused('saved_still_available') ? $still : [];

        if ($drops === [] && $gone === [] && $still === []) {
            return false;
        }

        if (! Consent::implied($user)) {
            return false;
        }

        $lastEmail = MarketingEmail::where('user_id', $user->id)->max('sent_at');
        if ($lastEmail && Carbon::parse($lastEmail)->gt(now()->subHours(CheckoutRecovery::MIN_GAP_HOURS))) {
            return false;
        }

        $kind = match (true) {
            $drops !== [] => 'saved_price_drop',
            $gone !== []  => 'saved_gone',
            default       => 'saved_still_available',
        };

        $suggestions = $this->suggestions($user, $gone !== [] ? $gone[0]['car'] : ($still[0]['car'] ?? null), $saved->pluck('vehicle_id'));

        try {
            Mail::to($user)->send(new SavedCarAlertMail(
                $user,
                $kind,
                collect($drops)->map(fn ($item) => ['car' => $item['car'], 'was' => $item['was']]),
                collect($gone)->pluck('car'),
                collect($still)->pluck('car'),
                $suggestions,
            ));
        } catch (\Throwable $e) {
            Log::error('Saved car alert failed', ['user_id' => $user->id, 'kind' => $kind, 'error' => $e->getMessage()]);

            return false;
        }

        foreach ($drops as $item) {
            DB::table('favourites')->where('id', $item['row']->id)->update(['alerted_price_in_cents' => $item['car']->price_in_cents]);
        }
        foreach ($gone as $item) {
            DB::table('favourites')->where('id', $item['row']->id)->update(['gone_alerted_at' => now()]);
        }
        foreach ($still as $item) {
            DB::table('favourites')->where('id', $item['row']->id)->update(['nudged_at' => now()]);
        }

        MarketingEmail::create(['user_id' => $user->id, 'kind' => $kind, 'sent_at' => now()]);

        return true;
    }

    /** @return Collection<int, Vehicle> up to 3 similar cars they haven't saved */
    private function suggestions(User $user, ?Vehicle $like, Collection $savedIds): Collection
    {
        if ($like === null) {
            return collect();
        }

        return Recommendations::similarTo($like, 6)
            ->reject(fn (Vehicle $car) => $savedIds->contains($car->id))
            ->take(3)
            ->values();
    }
}
