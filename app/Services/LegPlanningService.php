<?php

namespace App\Services;

use App\Support\LegDistance;
use App\Support\ServiceType;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Duration-based leg planning (smarttransit_prompt.md §4.2 and §5, 2026-10-27). The system SUGGESTS and CHECKS the
 * legs of a shift block; the Operator decides. Nothing here creates or changes a schedule.
 *
 * Travel time of a leg, first match wins (each leg says which one it used):
 *  1. operator_set: an Operator-set peak-hour window covers the departure (the route's stop times x its factor).
 *  2. measured: the median of completed legs of this route, direction, service and hour, when there are enough of them
 *     (leg_planning.report_min_samples; the same numbers as the duration report).
 *  3. estimated: the route's stop times from the routing engine.
 *  None of these: the travel time is unknown, so no later leg is suggested and the plan is flagged for the Operator.
 *
 * How many legs: round trips are added while the next one still ends inside the normal work day (duty_rules), so a
 * short route gets more legs and a long one fewer; there is no fixed number. Duty and driving limits are FLAGS, never
 * silent blocks and never a compliance claim. The one hard rule (assertNoOverlap) is that a leg cannot leave before the
 * previous one is expected to arrive plus the turnaround buffer.
 */
class LegPlanningService
{
    public const SOURCE_OPERATOR_SET = 'operator_set';

    public const SOURCE_MEASURED = 'measured';

    public const SOURCE_ESTIMATED = 'estimated';

    private const SOURCE_LABELS = [
        self::SOURCE_OPERATOR_SET => 'Peak-hour time you set',
        self::SOURCE_MEASURED => 'Measured from past trips',
        self::SOURCE_ESTIMATED => 'Estimate from the route stop times',
    ];

    /** @var array<string, float>|null route|direction|service|hour => measured median minutes */
    private ?array $measured = null;

    public function __construct(private RouteDurationFactorService $factors) {}

    /**
     * Suggested legs from the first departure. $legCount null lets the planner decide.
     *
     * @return array<string, mixed>
     */
    public function plan(int $routeId, ?string $service, string $date, string $firstTime, ?int $legCount = null): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $buffer = self::bufferMinutes();
        $maxLegs = self::maxLegs();
        $dayStart = Carbon::parse($date.' '.$firstTime, $timezone);
        $workLimit = $dayStart->copy()->addMinutes((int) round(config('duty_rules.normal_work_hours') * 60));

        $legs = [];
        $start = $dayStart->copy();
        while (count($legs) < ($legCount ?? $maxLegs)) {
            $index = count($legs);
            $leg = $this->leg($routeId, $service, $index, $start, $date);

            // Planner decides: a new round trip starts only if both its legs still end inside the normal work day.
            if ($legCount === null && $index > 0 && $index % 2 === 0) {
                $returnLeg = $leg['expected_end_at'] ? $this->leg($routeId, $service, $index + 1, $leg['expected_end_at']->copy()->addMinutes($buffer), $date) : null;
                if (! $returnLeg || ! $returnLeg['expected_end_at'] || $returnLeg['expected_end_at']->gt($workLimit)) {
                    break;
                }
            }

            $legs[] = $leg;
            if (! $leg['expected_end_at']) {
                break; // the travel time is unknown: nothing more can be suggested
            }
            $start = $leg['expected_end_at']->copy()->addMinutes($buffer);
        }

        return [
            'buffer_minutes' => $buffer,
            'legs' => array_map(fn (array $leg) => $this->presentLeg($leg), $legs),
            'summary' => $this->summary($legs),
            'flags' => $this->flags($legs, $legCount === null),
            'note' => 'A suggestion only. Check the times and change any of them before you save.',
        ];
    }

    /**
     * The same checks for times the Operator chose (shown when a block is saved). Never refuses anything.
     *
     * @param  list<string>  $legTimes
     * @return array{summary: array<string, mixed>, flags: list<array{code: string, message: string}>}
     */
    public function review(int $routeId, ?string $service, string $date, array $legTimes): array
    {
        $legs = $this->legsAt($routeId, $service, $date, $legTimes);

        return ['summary' => $this->summary($legs), 'flags' => $this->flags($legs, false)];
    }

    /**
     * The one hard rule: each leg leaves no earlier than the previous leg's expected arrival plus the turnaround buffer.
     * A leg whose travel time is unknown is not checked (nothing is invented).
     *
     * @param  list<string>  $legTimes  in leg order
     *
     * @throws ValidationException
     */
    public function assertNoOverlap(int $routeId, ?string $service, string $date, array $legTimes, string $field = 'leg_departure_times'): void
    {
        $legs = $this->legsAt($routeId, $service, $date, $legTimes);
        $buffer = self::bufferMinutes();

        for ($i = 1; $i < count($legs); $i++) {
            $previousEnd = $legs[$i - 1]['expected_end_at'];
            if (! $previousEnd) {
                continue;
            }
            $earliest = $previousEnd->copy()->addMinutes($buffer);
            if ($legs[$i]['start_at']->lt($earliest)) {
                throw ValidationException::withMessages([$field => [sprintf(
                    'Leg %d leaves at %s, but leg %d is expected to arrive at %s and needs a %d minute turnaround. Choose %s or later.',
                    $i + 1, $legs[$i]['start_at']->format('H:i'), $i, $previousEnd->format('H:i'), $buffer, $earliest->format('H:i'),
                )]]);
            }
        }
    }

    public static function bufferMinutes(): int
    {
        return (int) config('shift_blocks.terminal_turnaround_buffer_minutes', 30);
    }

    public static function maxLegs(): int
    {
        return max(1, (int) config('shift_blocks.max_legs_per_block', 8));
    }

    /** @return list<array<string, mixed>> */
    private function legsAt(int $routeId, ?string $service, string $date, array $legTimes): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');

        return array_values(array_map(
            fn ($time, $index) => $this->leg($routeId, $service, $index, Carbon::parse($date.' '.substr((string) $time, 0, 5), $timezone), $date),
            $legTimes,
            array_keys(array_values($legTimes)),
        ));
    }

    /** @return array<string, mixed> one leg with its expected travel time and where that time came from */
    private function leg(int $routeId, ?string $service, int $index, Carbon $start, string $date): array
    {
        $direction = $index % 2 === 0 ? 'outbound' : 'reverse';
        [$minutes, $source, $factor] = $this->travelMinutes($routeId, $direction, $service, $start);

        return [
            'leg_index' => $index + 1,
            'direction' => $direction,
            'start_at' => $start->copy(),
            'expected_duration_minutes' => $minutes,
            'duration_source' => $source,
            'factor' => $factor,
            'expected_end_at' => $minutes === null ? null : $start->copy()->addMinutes($minutes),
            'spills_into_next_day' => ! $start->isSameDay(Carbon::parse($date, config('app.timezone', 'Asia/Manila'))),
        ];
    }

    /** @return array{0: ?int, 1: ?string, 2: float} minutes, source and peak-hour factor; minutes and source null when unknown */
    private function travelMinutes(int $routeId, string $direction, ?string $service, Carbon $start): array
    {
        $base = $this->factors->baseTotalMinutes($routeId, $direction, $service);
        $window = $this->factors->windowFor($routeId, $direction, $start);
        if ($window && $base !== null) {
            return [(int) round($base * (float) $window->factor), self::SOURCE_OPERATOR_SET, (float) $window->factor];
        }

        $measured = $this->measuredMinutes($routeId, $direction, $service, $start->hour);
        if ($measured !== null) {
            return [(int) round($measured), self::SOURCE_MEASURED, 1.0];
        }

        return $base !== null ? [$base, self::SOURCE_ESTIMATED, 1.0] : [null, null, 1.0];
    }

    private function measuredMinutes(int $routeId, string $direction, ?string $service, int $hour): ?float
    {
        if ($this->measured === null) {
            $this->measured = [];
            foreach ($this->factors->durationReport()['rows'] as $row) {
                if ($row['enough_data']) {
                    $this->measured[$row['route_id'].'|'.$row['direction'].'|'.$row['service_type'].'|'.$row['hour']] = (float) $row['median_minutes'];
                }
            }
        }

        $key = $routeId.'|'.LegDistance::direction($direction).'|'.ServiceType::normalize($service).'|'.$hour;

        return $this->measured[$key] ?? null;
    }

    /** @return array<string, mixed> */
    private function summary(array $legs): array
    {
        $known = array_filter($legs, fn (array $leg) => $leg['expected_end_at'] !== null);
        $last = end($legs) ?: null;
        $workMinutes = $legs && $last['expected_end_at'] ? (int) $legs[0]['start_at']->diffInMinutes($last['expected_end_at']) : null;

        return [
            'leg_count' => count($legs),
            'first_departure' => $legs ? $legs[0]['start_at']->format('H:i') : null,
            'expected_finish' => $last && $last['expected_end_at'] ? $last['expected_end_at']->format('H:i') : null,
            'work_minutes' => $workMinutes,
            'driving_minutes' => count($known) === count($legs) ? array_sum(array_column($legs, 'expected_duration_minutes')) : null,
        ];
    }

    /** @return list<array{code: string, message: string}> */
    private function flags(array $legs, bool $plannerDecided): array
    {
        $flags = [];
        $summary = $this->summary($legs);
        $add = function (string $code, string $message) use (&$flags) {
            $flags[] = ['code' => $code, 'message' => $message];
        };

        if (in_array(null, array_column($legs, 'expected_duration_minutes'), true)) {
            $add('travel_time_unknown', 'This route has no travel time yet (no stop times, no measured trips). Set every leg time yourself and check it.');
        }
        if (in_array(true, array_column($legs, 'spills_into_next_day'), true)) {
            $add('after_midnight', 'A leg would leave after midnight. Choose its time yourself.');
        }
        if (in_array(self::SOURCE_ESTIMATED, array_column($legs, 'duration_source'), true)) {
            $add('estimated_time', 'Some travel times are estimates from the route stop times, not measured trips.');
        }

        $normal = (float) config('duty_rules.normal_work_hours') * 60;
        $max = (float) config('duty_rules.max_work_hours_in_24h') * 60;
        if ($summary['work_minutes'] !== null && $summary['work_minutes'] > $max) {
            $add('over_max_work_hours', sprintf('The crew would work about %s, more than %s in a day (DO 118-12 reference).', $this->hours($summary['work_minutes']), $this->hours((int) $max)));
        } elseif ($summary['work_minutes'] !== null && $summary['work_minutes'] > $normal) {
            $add('overtime', sprintf('The crew would work about %s, more than the normal %s. The extra time is overtime.', $this->hours($summary['work_minutes']), $this->hours((int) $normal)));
        }

        $drivingLimit = (float) config('duty_rules.max_continuous_driving_hours') * 60;
        if ($summary['driving_minutes'] !== null && $summary['driving_minutes'] > $drivingLimit) {
            $add('driving_limit', sprintf('About %s of driving in this block, over the %s limit (LTFRB reference). Plan an alternate driver who is not the Chauffeur.', $this->hours($summary['driving_minutes']), $this->hours((int) $drivingLimit)));
        }
        foreach ($legs as $leg) {
            if (($leg['expected_duration_minutes'] ?? 0) > $drivingLimit) {
                $add('long_leg', sprintf('Leg %d alone is longer than the %s driving limit. It needs an alternate driver who is not the Chauffeur.', $leg['leg_index'], $this->hours((int) $drivingLimit)));
            }
        }

        $rest = (int) config('duty_rules.min_rest_minutes_in_12h_shift');
        if (count($legs) > 1 && $summary['work_minutes'] !== null && $summary['work_minutes'] > 6 * 60 && $this->longestGap($legs) < $rest) {
            $add('no_rest_break', sprintf('No break between legs is %d minutes or longer. The turnaround does not count as rest; plan a rest of at least %d minutes.', $rest, $rest));
        }

        if ($this->touchesNight($legs)) {
            $add('night_work', 'Part of this block is between 10:00 PM and 6:00 AM (night work, extra pay).');
        }

        if (count($legs) % 2 === 1) {
            $add('ends_away', 'The last leg ends at the far terminal, not where the block started.');
        }

        if ($plannerDecided && count($legs) === self::maxLegs()) {
            $add('max_legs', 'This is the most legs a block can have.');
        }

        return $flags;
    }

    private function longestGap(array $legs): int
    {
        $gap = 0;
        for ($i = 1; $i < count($legs); $i++) {
            if ($legs[$i - 1]['expected_end_at']) {
                $gap = max($gap, (int) $legs[$i - 1]['expected_end_at']->diffInMinutes($legs[$i]['start_at'], false));
            }
        }

        return $gap;
    }

    private function touchesNight(array $legs): bool
    {
        $nightStart = (string) config('duty_rules.night_work_start');
        $nightEnd = (string) config('duty_rules.night_work_end');
        foreach ($legs as $leg) {
            foreach (array_filter([$leg['start_at'], $leg['expected_end_at']]) as $moment) {
                $time = $moment->format('H:i');
                if ($time >= $nightStart || $time < $nightEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hours(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m === 0 ? "{$h} h" : "{$h} h {$m} min";
    }

    /** @return array<string, mixed> */
    private function presentLeg(array $leg): array
    {
        return [
            'leg_index' => $leg['leg_index'],
            'direction' => $leg['direction'],
            'suggested_departure' => $leg['start_at']->format('H:i'),
            'expected_duration_minutes' => $leg['expected_duration_minutes'],
            'expected_end' => $leg['expected_end_at']?->format('H:i'),
            'duration_source' => $leg['duration_source'],
            'duration_source_label' => $leg['duration_source'] ? self::SOURCE_LABELS[$leg['duration_source']] : 'Not known',
            'spills_into_next_day' => $leg['spills_into_next_day'],
            // Batch 28 shape, kept for older screens: the peak-hour factor and whether a window set it.
            'factor' => $leg['factor'],
            'factor_label' => $leg['duration_source'] === self::SOURCE_OPERATOR_SET ? 'configured' : null,
        ];
    }
}
