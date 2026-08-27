<?php

namespace Arzcode\FilamentMagicLogin\Support;

use Carbon\CarbonInterval;

/**
 * Says how long a link lasts the way a person would.
 *
 * An hour or less stays in minutes, which is the unit somebody choosing a short-lived
 * link is already thinking in. Anything longer cascades into hours and days, so a
 * three-day link reads as "3 days" rather than "4320 minutes".
 */
final class ExpiryDuration
{
    public const CASCADES_ABOVE_MINUTES = 60;

    public static function describe(int $minutes): string
    {
        $minutes = max($minutes, 0);

        $interval = CarbonInterval::minutes($minutes);

        if ($minutes > self::CASCADES_ABOVE_MINUTES) {
            $interval = $interval->cascade();
        }

        return $interval->locale(app()->getLocale())->forHumans();
    }
}
