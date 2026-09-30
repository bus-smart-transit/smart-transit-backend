<?php

namespace App\Services;

use App\Models\Stop;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Batch 25, Issue 3: turns the untrusted parts of a booking link
 * (?from=<stop-code>&to=<stop-code>&date=YYYY-MM-DD) into checked values.
 * Nothing in the link is trusted: stop codes are looked up, the date is parsed
 * strictly and judged against the Manila calendar. Failures are friendly
 * messages the page can show with a way back to search.
 */
class BookingLinkService
{
    /**
     * @param  array{from?: mixed, to?: mixed, date?: mixed}  $input
     * @return array{from: array<string, mixed>, to: array<string, mixed>, date: string}
     *
     * @throws ValidationException
     */
    public function resolve(array $input): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $today = Carbon::now($timezone)->startOfDay();

        $from = $this->stopFor($input['from'] ?? null, 'from', 'where you board');
        $to = $this->stopFor($input['to'] ?? null, 'to', 'where you get off');

        if ($from->stop_id === $to->stop_id) {
            throw ValidationException::withMessages(['to' => ['Where you board and where you get off cannot be the same stop. Choose a different stop.']]);
        }

        return [
            'from' => $this->present($from),
            'to' => $this->present($to),
            'date' => $this->dateFor($input['date'] ?? null, $today)->toDateString(),
        ];
    }

    private function stopFor(mixed $code, string $field, string $label): Stop
    {
        $code = is_string($code) ? strtolower(trim($code)) : '';
        if ($code === '') {
            throw ValidationException::withMessages([$field => ["Choose $label to continue."]]);
        }

        $stop = strlen($code) <= 64 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $code)
            ? Stop::where('stop_code', $code)->first()
            : null;

        if (! $stop) {
            throw ValidationException::withMessages([$field => ['We could not find the stop in this link. It may be out of date. Choose your stops again.']]);
        }

        return $stop;
    }

    private function dateFor(mixed $date, Carbon $today): Carbon
    {
        if ($date === null || $date === '') {
            return $today->copy();
        }

        $parsed = false;
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            try {
                $parsed = Carbon::createFromFormat('!Y-m-d', $date, $today->getTimezone());
            } catch (\Throwable) {
                $parsed = false;
            }
        }
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            throw ValidationException::withMessages(['date' => ['The date in this link is not valid. Pick a travel date.']]);
        }

        if ($parsed->lt($today)) {
            throw ValidationException::withMessages(['date' => ['That date has already passed. Pick today or a later date.']]);
        }

        $maxDays = (int) config('booking.max_advance_days');
        if ($maxDays > 0 && $parsed->gt($today->copy()->addDays($maxDays))) {
            throw ValidationException::withMessages(['date' => ["Tickets can be booked up to $maxDays days ahead. Pick an earlier date."]]);
        }

        return $parsed;
    }

    /** @return array<string, mixed> */
    private function present(Stop $stop): array
    {
        return [
            'stop_id' => (int) $stop->stop_id,
            'stop_code' => $stop->stop_code,
            'name' => $stop->stop_name,
            'municipality' => $stop->municipality,
        ];
    }
}
