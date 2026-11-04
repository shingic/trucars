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
 * After that, any day the store is open except Sunday. Saturdays are fine.
 * All in the store's own timezone.
 */
class PickupCalendar
{
    /** Full weekdays the dealership has to prep the car. */
    public const PREP_WEEKDAYS = 2;

    /** The first day the car can be picked up, for an order placed at $orderedAt (store-local). */
    public static function earliest(CarbonInterface $orderedAt): Carbon
    {
        $day = Carbon::instance($orderedAt)->startOfDay();

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
