<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Batch 79 (panelist's advice, 2026-11-18): every time a person reads in a text, an email or a message is on a 12-hour
 * clock ("1:30 PM"). API fields keep their 24-hour values ("13:30") because screens compare them and time inputs use
 * them; the website formats those itself (src/utils/clock.js).
 */
class ClockTime
{
    /**
     * "13:30", "13:30:00" or a Carbon value (read in Manila time) as "1:30 PM". Empty or unreadable: an empty string.
     */
    public static function label(CarbonInterface|string|null $value): string
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone(config('app.timezone', 'Asia/Manila'))->format('g:i A');
        }
        $text = trim((string) $value);
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $text, $m) || (int) $m[1] > 24 || (int) $m[2] > 59) {
            return '';
        }

        return Carbon::createFromTime((int) $m[1] % 24, (int) $m[2])->format('g:i A');
    }
}
