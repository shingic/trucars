<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a reserved car can be picked up.
 *
 * The dealership gets two full weekdays (Mon–Fri) to get the car ready. The
 * day of the order doesn't count, and neither do weekends, so the first
 * pickup is the second weekday after the order:
 *
 *   Mon → Wed   Tue → Thu   Wed → Fri   Thu → Mon   Fri, Sat, Sun → Tue
 *
 * An order placed after the day's cutoff (the store's closing time, or
 * 6 pm when its hours aren't set) counts as the next day's order: nobody
 * starts prepping a car at 10 pm.
 *
 * After that, any day the store is open except Sunday. Saturdays are fine.
 * All in the store's own timezone.
 */
class PickupCalendar
{
    /** Full weekdays the dealership has to prep the car. */
    public const PREP_WEEKDAYS = 2;

    /** Orders after this (store-local) count as the next day's, when the store's closing time isn't known. */
    public const DEFAULT_CUTOFF = '18:00';

    /**
     * The first day the car can be picked up, for an order placed at
     * $orderedAt (store-local). $cutoff is that day's closing time, 'HH:MM'.
     */
    public static function earliest(CarbonInterface $orderedAt, ?string $cutoff = null): Carbon
    {
        $orderedAt = Carbon::instance($orderedAt);
        $day = $orderedAt->copy()->startOfDay();

        if ($orderedAt->format('H:i') >= ($cutoff ?? self::DEFAULT_CUTOFF)) {
            $day->addDay(); // after hours: it's tomorrow's order
        }

        for ($prep = 0; $prep < self::PREP_WEEKDAYS;) {
            $day->addDay();
            if ($day->isWeekday()) {
                $prep++;
            }
        }

        return $day;
    }

    /** Never a pickup on a Sunday. Whether the store is open that day is checked separately. */
    public static function allowsDay(CarbonInterface $day): bool
    {
        return ! $day->isSunday();
    }
}
