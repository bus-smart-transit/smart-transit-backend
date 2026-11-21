<?php

namespace App\Support;

/**
 * 2026-11-21 (user): a bus's operating hours, one window per bus (set when the bus is added on the Fleets page; it used
 * to be one window per bus and route). A window whose end is before its start runs past midnight. This is the only
 * place that reads a window: whether a time is inside it, and the one window that covers several (used once, to give
 * each existing bus a window from the bus-and-route hours it had).
 */
final class OperatingWindow
{
    private const DAY = 1440;

    /** Minutes since midnight for "H:i" or "H:i:s". */
    public static function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', array_pad(explode(':', $time), 2, '0'));

        return ($hour * 60 + $minute) % self::DAY;
    }

    public static function clock(int $minutes): string
    {
        $minutes = (($minutes % self::DAY) + self::DAY) % self::DAY;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Is the time inside the window (both ends included; a window past midnight is read that way)? */
    public static function contains(string $start, string $end, string $time): bool
    {
        $from = self::minutes($start);
        $to = self::minutes($end);
        $at = self::minutes($time);

        return $from <= $to ? ($at >= $from && $at <= $to) : ($at >= $from || $at <= $to);
    }

    /**
     * The smallest single window that covers every given window: the day minus its longest uncovered stretch. So a
     * departure allowed by any of the windows is still allowed. Null when no window is given; 00:00-23:59 when the
     * windows cover the whole day.
     *
     * @param  list<array{0: string, 1: string}>  $windows  [start, end] pairs
     * @return array{0: string, 1: string}|null
     */
    public static function cover(array $windows): ?array
    {
        if ($windows === []) {
            return null;
        }
        $covered = array_fill(0, self::DAY, false);
        foreach ($windows as [$start, $end]) {
            $from = self::minutes((string) $start);
            $to = self::minutes((string) $end);
            $length = $from <= $to ? $to - $from : self::DAY - $from + $to;
            for ($i = 0; $i <= $length; $i++) {
                $covered[($from + $i) % self::DAY] = true;
            }
        }
        if (! in_array(false, $covered, true)) {
            return ['00:00', '23:59'];
        }

        // The longest run of uncovered minutes, walking round the clock from a covered minute.
        $origin = (int) array_search(true, $covered, true);
        $bestStart = null;
        $bestLength = 0;
        $runStart = null;
        for ($step = 1; $step <= self::DAY; $step++) {
            $minute = ($origin + $step) % self::DAY;
            if (! $covered[$minute]) {
                $runStart ??= $minute;

                continue;
            }
            if ($runStart !== null) {
                $length = ($minute - $runStart + self::DAY) % self::DAY;
                if ($length > $bestLength) {
                    [$bestStart, $bestLength] = [$runStart, $length];
                }
                $runStart = null;
            }
        }

        // The window starts where the gap ends and ends just before the gap starts.
        return [self::clock($bestStart + $bestLength), self::clock($bestStart - 1)];
    }
}
