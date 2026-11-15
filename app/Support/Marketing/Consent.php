<?php

declare(strict_types=1);

namespace App\Support\Marketing;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Canada's anti-spam law (CASL) without an opt-in box: marketing email runs on
 * implied consent, which lasts 6 months from the shopper's last inquiry
 * (signing up, saving a car, working on a checkout) or 2 years from a
 * purchase (a reservation). Every new action renews it.
 */
final class Consent
{
    public const INQUIRY_MONTHS = 6;
    public const PURCHASE_MONTHS = 24;

    public static function implied(User $user): bool
    {
        return self::expiresAt($user)?->isFuture() ?? false;
    }

    public static function expiresAt(User $user): ?Carbon
    {
        $inquiry = collect([
            $user->created_at,
            DB::table('favourites')->where('user_id', $user->id)->max('created_at'),
            DB::table('checkout_drafts')->where('user_id', $user->id)->max('last_active_at'),
        ])->filter()->map(fn ($at) => Carbon::parse($at))->max();

        $purchase = DB::table('deals')->where('user_id', $user->id)->max('created_at');

        return collect([
            $inquiry?->copy()->addMonths(self::INQUIRY_MONTHS),
            $purchase ? Carbon::parse($purchase)->addMonths(self::PURCHASE_MONTHS) : null,
        ])->filter()->max();
    }
}
