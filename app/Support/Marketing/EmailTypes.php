<?php

declare(strict_types=1);

namespace App\Support\Marketing;

/**
 * The marketing emails a shopper can switch off one by one, on the email
 * preferences page and with "unsubscribe from this type" in each email.
 * Everyone starts with all of them on.
 *
 * Transactional email (reservation, documents, ID check, verification codes,
 * financing) isn't listed: it can't be switched off.
 */
final class EmailTypes
{
    public const CHECKOUT_REMINDERS = 'checkout_reminders';
    public const PRICE_DROPS = 'price_drops';
    public const SAVED_CAR_UPDATES = 'saved_car_updates';
    public const AVAILABILITY = 'availability';
    public const RECOMMENDATIONS = 'recommendations';

    /** key => [label, what it is]. Display order. */
    public const ALL = [
        self::PRICE_DROPS        => ['Price drop alerts', 'When a car you saved gets cheaper.'],
        self::SAVED_CAR_UPDATES  => ['Saved car updates', 'When a car you saved is still waiting for you, or someone else reserves it.'],
        self::AVAILABILITY       => ['Back-on-sale alerts', 'When a car you tapped the bell on is available again.'],
        self::CHECKOUT_REMINDERS => ['Checkout reminders', 'A nudge to finish a reservation you started.'],
        self::RECOMMENDATIONS    => ['Cars picked for you', 'Cars like the ones you’ve been looking at.'],
    ];

    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::ALL);
    }

    public static function label(string $type): string
    {
        return self::ALL[$type][0] ?? $type;
    }

    /** Which type a MarketingEmail kind belongs to. */
    public static function forKind(string $kind): string
    {
        return match ($kind) {
            'recovery_7d'          => self::RECOMMENDATIONS,
            'saved_price_drop'     => self::PRICE_DROPS,
            'saved_gone', 'saved_still_available' => self::SAVED_CAR_UPDATES,
            default                => self::CHECKOUT_REMINDERS,
        };
    }
}
