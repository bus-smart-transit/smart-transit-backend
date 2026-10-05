<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Batch 29, issue 1: the ONE place a block's (or a standalone trip's) lifecycle status and its section are decided.
 *
 * The Driver, the Chauffeur and the Operator all read their card status from here, computed from the LEG statuses
 * (trips.status). The stored shift_blocks.status is the hand-off workflow state (scheduled, in_progress after a
 * takeover, completed after a hand-off, cancelled) and is NOT used for what a card says.
 *
 *  - A block is "completed" only when every leg is completed or cancelled (and at least one completed);
 *    "cancelled" when every leg is cancelled.
 *  - Otherwise it is as far along as its most advanced unfinished leg: in-progress or departed (a leg is under way,
 *    or a leg has finished and another is waiting), then boarding, then delayed, then scheduled.
 *  - An assignment status (accepted, awaiting your response, for approval) is a different thing and stays separate.
 *
 * Section, by the Manila business day: a date after today is "upcoming"; today is "today"; a date before today is
 * "past" when everything is finished, and "overdue" when anything is not (an exception, never "scheduled").
 */
final class ScheduleStatus
{
    public const TERMINAL = ['completed', 'cancelled'];

    public const SECTION_TODAY = 'today';

    public const SECTION_UPCOMING = 'upcoming';

    public const SECTION_PAST = 'past';

    public const SECTION_OVERDUE = 'overdue';

    /** Shown when someone tries to answer, or is told about, an assignment whose date has passed. */
    public const PAST_DATE_MESSAGE = 'This date has passed. Contact your operator.';

    /**
     * @param  iterable<object>  $legs  anything with a `status` (Trip models)
     */
    public static function forLegs(iterable $legs, ?string $fallback = 'scheduled'): string
    {
        $statuses = collect($legs)->map(fn ($leg) => strtolower((string) (is_array($leg) ? ($leg['status'] ?? '') : ($leg->status ?? ''))))->filter()->values();
        if ($statuses->isEmpty()) {
            return $fallback ?? 'scheduled';
        }

        $live = $statuses->reject(fn ($s) => $s === 'cancelled')->values();
        if ($live->isEmpty()) {
            return 'cancelled';
        }
        if ($live->every(fn ($s) => $s === 'completed')) {
            return 'completed';
        }

        $unfinished = $live->reject(fn ($s) => $s === 'completed')->values();
        foreach (['in-progress', 'departed'] as $running) {
            if ($unfinished->contains($running)) {
                return $running;
            }
        }
        if ($unfinished->contains('boarding')) {
            return 'boarding';
        }
        if ($live->contains('completed')) {
            return 'in-progress'; // a leg is done and the next one has not started yet: the block is running
        }
        if ($unfinished->contains('delayed')) {
            return 'delayed';
        }

        return 'scheduled';
    }

    public static function isTerminal(?string $status): bool
    {
        return in_array(strtolower((string) $status), self::TERMINAL, true);
    }

    /** The Manila business date (YYYY-MM-DD). */
    public static function today(?Carbon $now = null): string
    {
        return ($now ?? Carbon::now(config('app.timezone', 'Asia/Manila')))->copy()->setTimezone(config('app.timezone', 'Asia/Manila'))->toDateString();
    }

    public static function isPastDate(?string $date, ?string $today = null): bool
    {
        return $date !== null && $date !== '' && substr($date, 0, 10) < ($today ?? self::today());
    }

    /**
     * @return 'today'|'upcoming'|'past'|'overdue'
     */
    public static function section(?string $date, string $status, ?string $today = null): string
    {
        $today ??= self::today();
        $day = $date ? substr($date, 0, 10) : $today;

        if ($day > $today) {
            return self::SECTION_UPCOMING;
        }
        if ($day === $today) {
            return self::SECTION_TODAY;
        }

        return self::isTerminal($status) ? self::SECTION_PAST : self::SECTION_OVERDUE;
    }

    /** @return Collection<int, string> */
    public static function legStatuses(iterable $legs): Collection
    {
        return collect($legs)->map(fn ($leg) => strtolower((string) $leg->status));
    }
}
