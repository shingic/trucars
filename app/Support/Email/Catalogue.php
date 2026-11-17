<?php

declare(strict_types=1);

namespace App\Support\Email;

use App\Mail\BuyerWelcome;
use App\Mail\CheckoutRecoveryMail;
use App\Mail\DealerInvitationMail;
use App\Mail\DocumentReuploadRequested;
use App\Mail\DocumentsReminder;
use App\Mail\EmailVerificationCode;
use App\Mail\PasswordResetLink;
use App\Mail\FinancingApproved;
use App\Mail\InsuranceRequested;
use App\Mail\MoreInfoRequested;
use App\Mail\ReadyForHandover;
use App\Mail\ReservationCancelled;
use App\Mail\ReservationConfirmed;
use App\Mail\ReservationSubmitted;
use App\Mail\SavedCarAlertMail;
use App\Mail\StaffInvitationMail;
use App\Mail\VehicleAvailableAgain;
use App\Models\CheckoutDraft;
use App\Models\Deal;
use App\Models\DealerInvitation;
use App\Models\StaffInvitation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Marketing\EmailTypes;
use App\Support\PlatformSettings;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Every email the platform sends, for the Command Centre's Email Centre:
 * what it is, when it goes out, who gets it, whether staff may pause it, and
 * how to build a sample of it.
 *
 * Emails a reservation or a sign-in depends on can't be paused.
 */
final class Catalogue
{
    public const GROUP_MARKETING = 'Marketing';
    public const GROUP_ACCOUNT = 'Account';
    public const GROUP_RESERVATION = 'Reservation';
    public const GROUP_STAFF = 'Staff & dealers';

    /** slug => [name, group, trigger, audience, mailable, kind, marketing type, can pause] */
    public const ALL = [
        // Marketing
        'recovery_1h' => ['name' => 'Checkout reminder · 1 hour', 'group' => self::GROUP_MARKETING, 'trigger' => '1 hour after a shopper stops partway through reserving a car.', 'audience' => 'Shoppers', 'mailable' => CheckoutRecoveryMail::class, 'kind' => 'recovery_1h', 'type' => EmailTypes::CHECKOUT_REMINDERS, 'pausable' => true],
        'recovery_1d' => ['name' => 'Checkout reminder · 1 day', 'group' => self::GROUP_MARKETING, 'trigger' => '1 day after they stopped: still available, plus similar cars.', 'audience' => 'Shoppers', 'mailable' => CheckoutRecoveryMail::class, 'kind' => 'recovery_1d', 'type' => EmailTypes::CHECKOUT_REMINDERS, 'pausable' => true],
        'recovery_3d' => ['name' => 'Checkout reminder · 3 days', 'group' => self::GROUP_MARKETING, 'trigger' => '3 days after they stopped: the $150 is fully refundable.', 'audience' => 'Shoppers', 'mailable' => CheckoutRecoveryMail::class, 'kind' => 'recovery_3d', 'type' => EmailTypes::CHECKOUT_REMINDERS, 'pausable' => true],
        'recovery_7d' => ['name' => 'Cars picked for you · 7 days', 'group' => self::GROUP_MARKETING, 'trigger' => '7 days after they stopped: cars like the one they were reserving.', 'audience' => 'Shoppers', 'mailable' => CheckoutRecoveryMail::class, 'kind' => 'recovery_7d', 'type' => EmailTypes::RECOMMENDATIONS, 'pausable' => true],
        'saved_price_drop' => ['name' => 'Saved car · price drop', 'group' => self::GROUP_MARKETING, 'trigger' => 'A saved car is at least $250 cheaper than when they saved it.', 'audience' => 'Shoppers', 'mailable' => SavedCarAlertMail::class, 'kind' => 'saved_price_drop', 'type' => EmailTypes::PRICE_DROPS, 'pausable' => true],
        'saved_gone' => ['name' => 'Saved car · gone', 'group' => self::GROUP_MARKETING, 'trigger' => 'Someone else reserved a saved car, or it was taken off the market.', 'audience' => 'Shoppers', 'mailable' => SavedCarAlertMail::class, 'kind' => 'saved_gone', 'type' => EmailTypes::SAVED_CAR_UPDATES, 'pausable' => true],
        'saved_still_available' => ['name' => 'Saved car · still thinking?', 'group' => self::GROUP_MARKETING, 'trigger' => '3 days after saving, if it’s still for sale and they haven’t started reserving it.', 'audience' => 'Shoppers', 'mailable' => SavedCarAlertMail::class, 'kind' => 'saved_still_available', 'type' => EmailTypes::SAVED_CAR_UPDATES, 'pausable' => true],
        'back_on_sale' => ['name' => 'Back on sale', 'group' => self::GROUP_MARKETING, 'trigger' => 'A car they tapped the bell on is available again (its reservation was cancelled).', 'audience' => 'Shoppers', 'mailable' => VehicleAvailableAgain::class, 'kind' => null, 'type' => EmailTypes::AVAILABILITY, 'pausable' => true],

        // Account
        'verification_code' => ['name' => 'Verification code', 'group' => self::GROUP_ACCOUNT, 'trigger' => 'When a shopper signs up, or asks for a new code.', 'audience' => 'Shoppers', 'mailable' => EmailVerificationCode::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'password_reset' => ['name' => 'Password reset link', 'group' => self::GROUP_ACCOUNT, 'trigger' => 'When a shopper taps “Forgot password?” and enters their email.', 'audience' => 'Shoppers', 'mailable' => PasswordResetLink::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'welcome' => ['name' => 'Welcome', 'group' => self::GROUP_ACCOUNT, 'trigger' => 'Once a new shopper confirms their email.', 'audience' => 'Shoppers', 'mailable' => BuyerWelcome::class, 'kind' => null, 'type' => null, 'pausable' => true],

        // Reservation
        'reservation_confirmed' => ['name' => 'Reservation confirmed', 'group' => self::GROUP_RESERVATION, 'trigger' => 'The moment the $150 deposit is paid.', 'audience' => 'Buyers', 'mailable' => ReservationConfirmed::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'reservation_submitted' => ['name' => 'New reservation (to dealer)', 'group' => self::GROUP_RESERVATION, 'trigger' => 'The moment a buyer reserves one of the dealer’s cars.', 'audience' => 'Dealer staff', 'mailable' => ReservationSubmitted::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'reservation_cancelled' => ['name' => 'Reservation cancelled / declined', 'group' => self::GROUP_RESERVATION, 'trigger' => 'When a reservation is cancelled or the dealer declines it.', 'audience' => 'Buyers', 'mailable' => ReservationCancelled::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'more_info_requested' => ['name' => 'More info requested', 'group' => self::GROUP_RESERVATION, 'trigger' => 'When the dealer asks the buyer for another document.', 'audience' => 'Buyers', 'mailable' => MoreInfoRequested::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'document_reupload' => ['name' => 'Re-upload a document', 'group' => self::GROUP_RESERVATION, 'trigger' => 'When the dealer rejects a document the buyer sent.', 'audience' => 'Buyers', 'mailable' => DocumentReuploadRequested::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'documents_reminder' => ['name' => 'Documents reminder', 'group' => self::GROUP_RESERVATION, 'trigger' => 'Once, when requested documents are still missing 2 days later.', 'audience' => 'Buyers', 'mailable' => DocumentsReminder::class, 'kind' => null, 'type' => null, 'pausable' => true],
        'financing_approved' => ['name' => 'Financing approved', 'group' => self::GROUP_RESERVATION, 'trigger' => 'When the dealer publishes a lender approval (or updates it).', 'audience' => 'Buyers', 'mailable' => FinancingApproved::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'insurance_requested' => ['name' => 'Proof of insurance', 'group' => self::GROUP_RESERVATION, 'trigger' => 'When the deal moves to Documents (finalized).', 'audience' => 'Buyers', 'mailable' => InsuranceRequested::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'ready_for_handover' => ['name' => 'Ready for pickup', 'group' => self::GROUP_RESERVATION, 'trigger' => 'When the deal moves to Ready for delivery.', 'audience' => 'Buyers', 'mailable' => ReadyForHandover::class, 'kind' => null, 'type' => null, 'pausable' => false],

        // Staff & dealers
        'dealer_invitation' => ['name' => 'Dealer staff invitation', 'group' => self::GROUP_STAFF, 'trigger' => 'When someone is invited to a dealership’s portal.', 'audience' => 'Dealer staff', 'mailable' => DealerInvitationMail::class, 'kind' => null, 'type' => null, 'pausable' => false],
        'staff_invitation' => ['name' => 'Trumo staff invitation', 'group' => self::GROUP_STAFF, 'trigger' => 'When someone is invited to the Command Centre.', 'audience' => 'Trumo staff', 'mailable' => StaffInvitationMail::class, 'kind' => null, 'type' => null, 'pausable' => false],
    ];

    /** The catalogue slug for a sent mailable (and its kind, for multi-stage ones). */
    public static function typeFor(string $mailableClass, ?string $kind = null): string
    {
        if ($kind !== null && isset(self::ALL[$kind]) && self::ALL[$kind]['mailable'] === $mailableClass) {
            return $kind;
        }

        foreach (self::ALL as $slug => $entry) {
            if ($entry['mailable'] === $mailableClass) {
                return $slug;
            }
        }

        return Str::snake(class_basename($mailableClass)) ?: 'other';
    }

    /** @return array<int, string> slugs staff have paused */
    public static function pausedSlugs(): array
    {
        $paused = json_decode(PlatformSettings::get(PlatformSettings::EMAIL_TYPES_PAUSED) ?: '[]', true);

        return is_array($paused) ? array_values(array_filter($paused, 'is_string')) : [];
    }

    public static function paused(string $slug): bool
    {
        return (self::ALL[$slug]['pausable'] ?? false) && in_array($slug, self::pausedSlugs(), true);
    }

    public static function setPaused(string $slug, bool $paused, ?int $byUserId): void
    {
        $slugs = array_values(array_diff(self::pausedSlugs(), [$slug]));
        if ($paused) {
            $slugs[] = $slug;
        }

        PlatformSettings::put(PlatformSettings::EMAIL_TYPES_PAUSED, json_encode($slugs), $byUserId);
    }

    /**
     * A sample of one email, built from a made-up shopper and the newest
     * listed car inside a transaction that's always rolled back — nothing is
     * saved and no real customer appears.
     *
     * @return array{subject: string, html: string}|null
     */
    public static function preview(string $slug): ?array
    {
        if (! isset(self::ALL[$slug])) {
            return null;
        }

        DB::beginTransaction();

        try {
            $mail = self::sample($slug);
            if ($mail === null) {
                return null;
            }

            return ['subject' => (string) $mail->envelope()->subject, 'html' => $mail->render()];
        } catch (\Throwable $e) {
            Log::warning('Email preview failed', ['type' => $slug, 'error' => $e->getMessage()]);

            return null;
        } finally {
            DB::rollBack();
        }
    }

    private static function sample(string $slug): ?Mailable
    {
        $car = Vehicle::query()->listed()->with(['dealer', 'rooftop'])->latest('id')->first();
        if ($car === null) {
            return null;
        }

        $shopper = User::create(['name' => 'Alex Sample', 'email' => 'alex.sample.' . Str::random(8) . '@example.com', 'password' => Str::random(40)]);
        $shopper->forceFill(['email_verified_at' => now()])->save();

        $deal = fn () => Deal::withoutGlobalScopes()->create([
            'dealer_id' => $car->dealer_id, 'rooftop_id' => $car->rooftop_id, 'vehicle_id' => $car->id, 'user_id' => $shopper->id,
            'first_name' => 'Alex', 'last_name' => 'Sample', 'email' => $shopper->email, 'phone' => '6045550100',
            'purchase_type' => 'finance', 'term_months' => 72, 'down_payment_in_cents' => 200000,
            'handover_mode' => 'pickup', 'pickup_location' => $car->display_location, 'pickup_at' => now()->addDays(3)->setTime(10, 0),
            'financing_lender' => 'Sample Bank', 'financing_apr' => 7.49, 'financing_term_months' => 72, 'financing_payment_in_cents' => 21500,
            'financing_frequency' => 'biweekly', 'financing_amount_in_cents' => $car->price_in_cents, 'financing_down_payment_in_cents' => 200000,
            'financing_first_payment_on' => now()->addMonth(), 'financing_approved_at' => now(),
        ]);

        $draft = fn () => CheckoutDraft::create([
            'user_id' => $shopper->id, 'vehicle_id' => $car->id, 'step_key' => 'plan', 'data' => [], 'status' => CheckoutDraft::STATUS_ACTIVE, 'last_active_at' => now(),
        ]);

        $similar = fn () => \App\Support\Marketing\Recommendations::similarTo($car, 3);

        return match ($slug) {
            'recovery_1h', 'recovery_1d', 'recovery_3d', 'recovery_7d' => new CheckoutRecoveryMail($draft()->load(['user', 'vehicle']), $slug),
            'saved_price_drop'      => new SavedCarAlertMail($shopper, $slug, collect([['car' => $car, 'was' => $car->price_in_cents + 150000]]), collect(), collect(), collect()),
            'saved_gone'            => new SavedCarAlertMail($shopper, $slug, collect(), collect([$car]), collect(), $similar()),
            'saved_still_available' => new SavedCarAlertMail($shopper, $slug, collect(), collect(), collect([$car]), $similar()),
            'back_on_sale'          => new VehicleAvailableAgain($shopper, $car),
            'verification_code'     => new EmailVerificationCode($shopper, '482913'),
            'password_reset'        => new PasswordResetLink($shopper, url('/reset-password/sample-token?email=' . urlencode($shopper->email))),
            'welcome'               => new BuyerWelcome($shopper),
            'reservation_confirmed' => new ReservationConfirmed($deal()),
            'reservation_submitted' => new ReservationSubmitted($deal()),
            'reservation_cancelled' => new ReservationCancelled($deal(), false, true, null),
            'more_info_requested'   => (fn ($d) => new MoreInfoRequested($d, $d->documents()->first()))($deal()),
            'document_reupload'     => (fn ($d) => new DocumentReuploadRequested($d, $d->documents()->first(), 'The photo is too blurry to read.'))($deal()),
            'documents_reminder'    => (function (Deal $d) {
                $d->documents()->update(['requested_at' => now()->subDays(2)]);

                return new DocumentsReminder($d);
            })($deal()),
            'financing_approved'    => new FinancingApproved($deal()),
            'insurance_requested'   => new InsuranceRequested($deal()),
            'ready_for_handover'    => new ReadyForHandover($deal()),
            'dealer_invitation'     => new DealerInvitationMail(DealerInvitation::create([
                'dealer_id' => $car->dealer_id, 'invited_by_user_id' => $shopper->id, 'name' => 'Alex Sample', 'email' => $shopper->email,
                'token_hash' => hash('sha256', Str::random(40)), 'expires_at' => now()->addDays(7),
            ]), url('/invitation/sample')),
            'staff_invitation'      => new StaffInvitationMail(StaffInvitation::create([
                'invited_by_user_id' => $shopper->id, 'name' => 'Alex Sample', 'email' => $shopper->email, 'permissions' => [],
                'token_hash' => hash('sha256', Str::random(40)), 'expires_at' => now()->addDays(7),
            ]), url('/invitation/sample')),
            default => null,
        };
    }
}
