<?php

namespace Modules\DOTTriage\Services;

/**
 * Business-time arithmetic for escalation windows.
 *
 * Escalation measured in wall-clock minutes punishes agents for weekends: a
 * ticket arriving Friday afternoon with a 1-day SLA would escalate on Saturday
 * afternoon, when nobody was ever going to answer it. Counting only working
 * days means the window reflects time the agent could actually have responded.
 *
 * Deliberately day-granular rather than hour-granular. Modelling working hours
 * as well would need per-agent timezones and shift patterns, which is a lot of
 * complexity for a support desk that mostly cares about "did this sit over a
 * weekend".
 */
class BusinessTime
{
    /** Days config treats as non-working. 6 = Saturday, 7 = Sunday (ISO-8601). */
    public static function weekendDays()
    {
        $days = config('triage.weekend_days', [6, 7]);

        return is_array($days) ? $days : array_map('intval', explode(',', (string) $days));
    }

    public static function isWorkingDay(\DateTimeInterface $date)
    {
        return !in_array((int) $date->format('N'), self::weekendDays(), true);
    }

    /**
     * Minutes of working time between two instants.
     *
     * Whole weekend days are excluded. Partial days at either end count in
     * full if they fall on a working day.
     */
    public static function minutesBetween(\DateTimeInterface $from, \DateTimeInterface $to)
    {
        if ($to <= $from) {
            return 0;
        }

        $start = \DateTimeImmutable::createFromFormat('U', (string) $from->getTimestamp());
        $end   = \DateTimeImmutable::createFromFormat('U', (string) $to->getTimestamp());

        // Same calendar day: either it counts or it does not.
        if ($start->format('Y-m-d') === $end->format('Y-m-d')) {
            return self::isWorkingDay($start)
                ? (int) round(($end->getTimestamp() - $start->getTimestamp()) / 60)
                : 0;
        }

        $minutes = 0;

        // Remainder of the first day.
        if (self::isWorkingDay($start)) {
            $midnight = $start->modify('tomorrow midnight');
            $minutes += (int) round(($midnight->getTimestamp() - $start->getTimestamp()) / 60);
        }

        // Whole days in between.
        $cursor = $start->modify('tomorrow midnight');
        $lastMidnight = $end->modify('today midnight');
        while ($cursor < $lastMidnight) {
            if (self::isWorkingDay($cursor)) {
                $minutes += 1440;
            }
            $cursor = $cursor->modify('+1 day');
        }

        // Elapsed part of the final day.
        if (self::isWorkingDay($end)) {
            $midnight = $end->modify('today midnight');
            $minutes += (int) round(($end->getTimestamp() - $midnight->getTimestamp()) / 60);
        }

        return $minutes;
    }

    /**
     * The instant that is $minutes of working time after $from.
     * Used to show operators when a ticket will actually escalate.
     */
    public static function addMinutes(\DateTimeInterface $from, $minutes)
    {
        $cursor    = \DateTimeImmutable::createFromFormat('U', (string) $from->getTimestamp());
        $remaining = (int) $minutes;
        $guard     = 0;

        while ($remaining > 0 && $guard < 400) {
            $guard++;

            if (!self::isWorkingDay($cursor)) {
                $cursor = $cursor->modify('tomorrow midnight');
                continue;
            }

            $midnight  = $cursor->modify('tomorrow midnight');
            $available = (int) round(($midnight->getTimestamp() - $cursor->getTimestamp()) / 60);

            if ($remaining <= $available) {
                return $cursor->modify('+'.$remaining.' minutes');
            }

            $remaining -= $available;
            $cursor = $midnight;
        }

        return $cursor;
    }

    /** Human label for a minute count, e.g. "1 working day". */
    public static function describe($minutes)
    {
        $minutes = (int) $minutes;

        if ($minutes % 1440 === 0) {
            $days = $minutes / 1440;
            return $days.' working '.($days === 1 ? 'day' : 'days');
        }

        if ($minutes % 60 === 0) {
            $hours = $minutes / 60;
            return $hours.' '.($hours === 1 ? 'hour' : 'hours');
        }

        return $minutes.' minutes';
    }
}
