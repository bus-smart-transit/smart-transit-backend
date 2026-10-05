<?php

namespace App\Services;

use App\Support\BusLabel;
use App\Support\FinancialClock;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 29, Stage 2: the Operator's (and Admin's) money figures, from stored amounts only.
 *
 *  - Revenue = tickets whose payment is PAID and valid (an online payment the signed webhook confirmed, or a cash ticket
 *    that was issued). Pending, failed and expired payments never count. A rebooked ticket is counted once, through its
 *    replacement. Every amount is the stored ticket amount; nothing is priced here.
 *  - Refunds = ticket_resolutions of kind "refund" that SUCCEEDED, at the stored amount, shown separately and
 *    subtracted to get net. A refund counts on the Manila day it was completed (cash-flow view), so it can belong to a
 *    ticket sold before the chosen range. A pending or under-review refund is not subtracted yet.
 *  - Time: App\Support\FinancialClock is the only place the mixed timestamp conventions are handled.
 *  - Scope: an Operator sees only the fleets (and so routes, trips and tickets) they own; an Admin sees everything.
 *
 * The screen, the paginated log and the CSV export all read the same rows from here.
 */
class FinancialReportService
{
    public const SORTS = ['paid_at', 'amount', 'route', 'bus', 'status', 'method', 'trip_date'];

    // ------------------------------------------------------------------ filters

    /** @return array{from: string, to: string, route_id: ?int, fleet_id: ?int, trip_id: ?int, payment_method: ?string, channel: ?string, sort: string, dir: string, page: int, per_page: int} */
    public function normalize(array $input): array
    {
        $today = FinancialClock::today();
        $from = $input['from'] ?? $today;
        $to = $input['to'] ?? $from;
        if ($to < $from) {
            throw ValidationException::withMessages(['to' => ['The end date cannot be before the start date.']]);
        }
        $max = (int) config('financial.max_range_days');
        if ($max > 0 && FinancialClock::startOfDay($from)->diffInDays(FinancialClock::startOfDay($to)) + 1 > $max) {
            throw ValidationException::withMessages(['to' => ["Choose a range of at most {$max} days."]]);
        }

        $perPage = (int) ($input['per_page'] ?? config('financial.default_per_page'));

        return [
            'from' => $from,
            'to' => $to,
            'route_id' => isset($input['route_id']) ? (int) $input['route_id'] : null,
            'fleet_id' => isset($input['fleet_id']) ? (int) $input['fleet_id'] : null,
            'trip_id' => isset($input['trip_id']) ? (int) $input['trip_id'] : null,
            'payment_method' => $input['payment_method'] ?? null,
            'channel' => $input['channel'] ?? null,
            // How refunds are dated: by the day the refund COMPLETED (daily cash reconciliation: when the money actually moved,
            // so a closed day is never rewritten) or by the day of the original SALE (profitability by sale period).
            'refund_basis' => ($input['refund_basis'] ?? 'completed') === 'sale' ? 'sale' : 'completed',
            'sort' => in_array($input['sort'] ?? 'paid_at', self::SORTS, true) ? ($input['sort'] ?? 'paid_at') : 'paid_at',
            'dir' => ($input['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) ($input['page'] ?? 1)),
            'per_page' => max(1, min((int) config('financial.max_per_page'), $perPage)),
        ];
    }

    // ------------------------------------------------------------------ the screen

    /** @return array<string, mixed> */
    public function report(?int $ownerCompanyUserId, array $filters): array
    {
        [$allSales, $allRefunds] = $this->load($ownerCompanyUserId, $filters);
        $sales = $this->applyFilters($allSales, $filters);
        $refunds = $this->applyFilters($allRefunds, $filters);

        $gross = $sales->sum('cents');
        $refunded = $refunds->sum('cents');

        return [
            'filters' => \Illuminate\Support\Arr::only($filters, ['from', 'to', 'route_id', 'fleet_id', 'trip_id', 'payment_method', 'channel', 'refund_basis']),
            'currency' => 'PHP',
            'kpis' => [
                'gross' => $this->money($gross),
                'refunds' => $this->money($refunded),
                'net' => $this->money($gross - $refunded),
                'tickets' => $sales->count(),
                'average_fare' => $sales->count() > 0 ? $this->money((int) round($gross / $sales->count())) : 0.0,
                // "Boarded" = the ticket has a boarded_at time (set when the ticket is scanned on board).
                'boarded' => $sales->filter(fn ($s) => $s['boarded'])->count(),
                'cash' => $this->split($sales, $refunds, 'cash', $gross),
                'online' => $this->split($sales, $refunds, 'online', $gross),
            ],
            'channels' => $this->channels($sales, $refunds, $gross),
            'by_route' => $this->groupBy($sales, $refunds, fn ($r) => (string) $r['route_id'], fn ($r) => ['route_id' => $r['route_id'], 'route_name' => $r['route_name']]),
            'by_bus' => $this->groupBy($sales, $refunds, fn ($r) => (string) $r['fleet_id'], fn ($r) => ['fleet_id' => $r['fleet_id'], 'plate_number' => $r['plate_number'], 'bus_label' => $r['bus_label']]),
            'by_trip' => $this->groupBy($sales, $refunds, fn ($r) => (string) $r['trip_id'], fn ($r) => [
                'trip_id' => $r['trip_id'], 'trip_date' => $r['trip_date'], 'departure_time' => $r['departure_time'], 'route_name' => $r['route_name'],
                'plate_number' => $r['plate_number'], 'shift_block_id' => $r['shift_block_id'], 'direction' => $r['direction'],
            ]),
            'by_hour' => $this->byHour($sales),
            'daily' => $this->daily($sales, $refunds),
            'options' => $this->options($allSales, $ownerCompanyUserId),
        ];
    }

    /** One page of the transaction log. @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>} */
    public function transactions(?int $ownerCompanyUserId, array $filters): array
    {
        $rows = $this->logRows($ownerCompanyUserId, $filters);
        $total = $rows->count();
        $page = $filters['page'];
        $perPage = $filters['per_page'];

        return [
            'data' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->map(fn ($r) => $this->presentTransaction($r))->all(),
            'meta' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'sort' => $filters['sort'], 'dir' => $filters['dir'],
            ],
        ];
    }

    // ------------------------------------------------------------------ CSV

    /** The filtered log as CSV text (same rows as the screen, all pages). */
    public function transactionsCsv(?int $ownerCompanyUserId, array $filters): string
    {
        $rows = $this->logRows($ownerCompanyUserId, $filters)->map(fn ($r) => $this->presentTransaction($r));

        return $this->csv(
            ['Transaction ID', 'Ticket ID', 'Paid at (Manila)', 'Bus (plate)', 'Bus label', 'Route', 'Direction', 'Trip ID', 'Trip date', 'Payment method', 'Channel', 'Service type', 'Seat type', 'Fare (PHP)', 'Refunded (PHP)', 'Status', 'Refund completed (Manila)'],
            $rows->map(fn ($t) => [
                $t['transaction_id'], $t['ticket_id'], $t['paid_at'], $t['plate_number'], $t['bus_label'], $t['route_name'], $t['direction_label'],
                $t['trip_id'], $t['trip_date'], $t['method_label'], $t['channel_label'], $t['service_type'], $t['seat_type'],
                number_format($t['amount'], 2, '.', ''), number_format($t['refunded'], 2, '.', ''), $t['status_label'], $t['refund_completed_at'] ?? '',
            ])->all(),
        );
    }

    /**
     * The refund lines of the range, each linked to its original sale (transaction and ticket id, sale time). The range
     * follows the chosen basis: the day the refund completed (cash reconciliation) or the day of the original sale.
     */
    public function refundsCsv(?int $ownerCompanyUserId, array $filters): string
    {
        [, $allRefunds] = $this->load($ownerCompanyUserId, $filters);
        $refunds = $this->applyFilters($allRefunds, $filters)->sortBy(fn ($r) => $r['refunded_at']->getTimestamp())->values();

        return $this->csv(
            ['Refund completed (Manila)', 'Refund (PHP)', 'Refund method', 'Original transaction ID', 'Original ticket ID', 'Original sale (Manila)', 'Original payment', 'Bus (plate)', 'Route', 'Trip ID', 'Trip date'],
            $refunds->map(fn ($r) => [
                $r['refunded_at']->format('Y-m-d H:i:s'), number_format($r['cents'] / 100, 2, '.', ''), $r['refund_method'] === 'cash' ? 'Cash' : 'Back to the original payment',
                $r['transaction_id'], $r['ticket_id'], $r['sold_at']?->format('Y-m-d H:i:s') ?? '', $this->channelLabel($r['channel'], $r['method']),
                $r['plate_number'], $r['route_name'], $r['trip_id'], $r['trip_date'],
            ])->all(),
        );
    }

    /** The daily summary as CSV text. */
    public function dailyCsv(?int $ownerCompanyUserId, array $filters): string
    {
        $report = $this->report($ownerCompanyUserId, $filters);

        return $this->csv(
            ['Date (Manila)', 'Gross (PHP)', 'Refunds (PHP)', 'Net (PHP)', 'Paid tickets', 'Cash (PHP)', 'Online (PHP)'],
            collect($report['daily'])->map(fn ($d) => [
                $d['date'], number_format($d['gross'], 2, '.', ''), number_format($d['refunds'], 2, '.', ''), number_format($d['net'], 2, '.', ''),
                $d['tickets'], number_format($d['cash'], 2, '.', ''), number_format($d['online'], 2, '.', ''),
            ])->all(),
        );
    }

    /** @param array<int, string> $header @param array<int, array<int, mixed>> $rows */
    private function csv(array $header, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $header);
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'safeCell'], $row));
        }
        rewind($out);
        $text = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$text; // a BOM so Excel reads UTF-8
    }

    /** A cell that starts like a formula is neutralised (CSV injection). */
    private function safeCell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }

    // ------------------------------------------------------------------ rows

    /** Sale rows of the range (after the filters), sorted for the log. */
    private function logRows(?int $owner, array $filters): Collection
    {
        [$allSales] = $this->load($owner, $filters);
        $sales = $this->applyFilters($allSales, $filters);

        $keyed = [
            'paid_at' => fn ($r) => $r['paid_at']->getTimestamp(),
            'amount' => fn ($r) => $r['cents'],
            'route' => fn ($r) => Str::lower((string) $r['route_name']),
            'bus' => fn ($r) => Str::lower((string) $r['plate_number']),
            'status' => fn ($r) => $r['status'],
            'method' => fn ($r) => $r['method'].'/'.$r['channel'],
            'trip_date' => fn ($r) => $r['trip_date'].' '.$r['departure_time'],
        ][$filters['sort']];

        $direction = $filters['dir'] === 'asc' ? 1 : -1;

        return $sales->sort(function (array $a, array $b) use ($keyed, $direction) {
            $compare = $keyed($a) <=> $keyed($b);

            return $compare !== 0 ? $compare * $direction : $b['ticket_id'] <=> $a['ticket_id'];
        })->values();
    }

    /** @return array{0: Collection, 1: Collection} sale rows and refund rows inside the Manila range (ownership applied, no other filter) */
    private function load(?int $owner, array $filters): array
    {
        $start = FinancialClock::startOfDay($filters['from']);
        $end = FinancialClock::endOfDay($filters['to']);

        $sales = $this->base($owner)
            ->leftJoin('onsite_payment', 'onsite_payment.payment_id', '=', 'payments.payment_id')
            ->leftJoinSub($this->firstTicketPerPayment(), 'first_t', 'first_t.payment_id', '=', 'payments.payment_id')
            ->leftJoin('ticket_resolutions as res', 'res.ticket_id', '=', 'tickets.ticket_id')
            ->where('tickets.status', '!=', 'rebooked')
            // Wide enough for either clock; the exact Manila range is applied in PHP below.
            ->whereBetween('payments.payment_created', [FinancialClock::wallString($start->copy()->subDay()), FinancialClock::wallString($end->copy()->addDay())])
            ->select($this->columns())
            ->addSelect('onsite_payment.issued_at as onsite_issued_at', 'first_t.first_ticket_at', 'res.resolution', 'res.status as res_status', 'res.amount as res_amount', 'res.completed_at as res_completed_at')
            ->get()
            ->unique('ticket_id')
            ->map(fn ($r) => $this->mapSale($r))
            ->filter(fn ($r) => $r['paid_at'] && $r['paid_at']->between($start, $end, true))
            ->values();

        $byCompletion = $filters['refund_basis'] !== 'sale';
        $refundQuery = $this->base($owner)
            ->join('ticket_resolutions as res', 'res.ticket_id', '=', 'tickets.ticket_id')
            ->leftJoin('onsite_payment', 'onsite_payment.payment_id', '=', 'payments.payment_id')
            ->leftJoinSub($this->firstTicketPerPayment(), 'first_t', 'first_t.payment_id', '=', 'payments.payment_id')
            ->where('res.resolution', 'refund')
            ->where('res.status', 'succeeded')
            ->whereNotNull('res.amount');
        if ($byCompletion) {
            $refundQuery->whereBetween('res.completed_at', [FinancialClock::utcString($start->copy()->subDay()), FinancialClock::utcString($end->copy()->addDay())]);
        } else {
            // By sale: the refund follows its original sale, whenever it completed.
            $refundQuery->whereBetween('payments.payment_created', [FinancialClock::wallString($start->copy()->subDay()), FinancialClock::wallString($end->copy()->addDay())]);
        }
        $refunds = $refundQuery
            ->select($this->columns())
            ->addSelect('res.amount as res_amount', 'res.completed_at as res_completed_at', 'res.method as res_method', 'onsite_payment.issued_at as onsite_issued_at', 'first_t.first_ticket_at')
            ->get()
            ->map(fn ($r) => $this->mapRefund($r, $byCompletion))
            ->filter(fn ($r) => $r['at'] && $r['at']->between($start, $end, true))
            ->values();

        return [$sales, $refunds];
    }

    /** The earliest ticket of each payment (when its tickets were minted = when an online payment was confirmed). */
    private function firstTicketPerPayment(): Builder
    {
        return DB::table('tickets')->select('payment_id', DB::raw('MIN(created_at) as first_ticket_at'))->groupBy('payment_id');
    }

    /** Tickets of paid, valid payments on the owner's fleets, with the dimensions every figure groups by. */
    private function base(?int $owner): Builder
    {
        $q = DB::table('tickets')
            ->join('payments', 'payments.payment_id', '=', 'tickets.payment_id')
            ->join('trips', 'trips.trip_id', '=', 'tickets.trip_id')
            ->join('fleets_routes', 'fleets_routes.fleet_route_id', '=', 'trips.fleet_route_id')
            ->join('fleets', 'fleets.fleet_id', '=', 'fleets_routes.fleet_id')
            ->join('routes', 'routes.route_id', '=', 'fleets_routes.route_id')
            ->where('payments.status', 'paid')
            ->where('payments.is_valid', true);

        if ($owner !== null) {
            $q->where('fleets.company_user_id', $owner);
        }

        return $q;
    }

    /** @return array<int, string> */
    private function columns(): array
    {
        return [
            'tickets.ticket_id', 'tickets.ticket_uuid', 'tickets.amount', 'tickets.status as ticket_status', 'tickets.seat_type', 'tickets.boarded_at',
            'trips.trip_id', 'trips.trip_date', 'trips.departure_time', 'trips.leg_direction', 'trips.service_type', 'trips.shift_block_id',
            'fleets.fleet_id', 'fleets.plate_number', 'fleets.bus_company_name', 'fleets.body_no',
            'routes.route_id', 'routes.route_name', 'routes.origin', 'routes.destination',
            'payments.payment_id', 'payments.transaction_reference', 'payments.payment_method', 'payments.payment_channel', 'payments.payment_created',
        ];
    }

    private function dims(object $r): array
    {
        $reverse = $r->leg_direction === 'reverse';
        $method = $r->payment_method === 'cash' ? 'cash' : 'online';

        return [
            'ticket_id' => (int) $r->ticket_id,
            'trip_id' => (int) $r->trip_id,
            'trip_date' => substr((string) $r->trip_date, 0, 10),
            'departure_time' => $r->departure_time ? substr((string) $r->departure_time, 0, 5) : null,
            'direction' => $reverse ? 'reverse' : 'outbound',
            'direction_label' => $reverse ? "{$r->destination} to {$r->origin}" : "{$r->origin} to {$r->destination}",
            'service_type' => $r->service_type,
            'shift_block_id' => $r->shift_block_id ? (int) $r->shift_block_id : null,
            'fleet_id' => (int) $r->fleet_id,
            'plate_number' => $r->plate_number,
            'bus_label' => BusLabel::compose($r->bus_company_name, $r->body_no),
            'route_id' => (int) $r->route_id,
            'route_name' => $r->route_name,
            'method' => $method,
            'channel' => (string) $r->payment_channel,
        ];
    }

    private function mapSale(object $r): array
    {
        $paidAt = FinancialClock::paidAt((string) $r->payment_method, $r->onsite_issued_at, $r->first_ticket_at, $r->payment_created);
        $cents = (int) round(((float) $r->amount) * 100);

        $refundCents = ($r->resolution === 'refund' && $r->res_status === 'succeeded' && $r->res_amount !== null) ? (int) round(((float) $r->res_amount) * 100) : 0;
        $status = 'paid';
        if ($refundCents > 0) {
            $status = $refundCents >= $cents ? 'refunded' : 'partially_refunded';
        } elseif ($r->resolution === 'refund' && in_array($r->res_status, ['pending', 'needs_review'], true)) {
            $status = 'refund_pending';
        }

        return $this->dims($r) + [
            'transaction_id' => (string) $r->transaction_reference,
            'payment_id' => (int) $r->payment_id,
            'cents' => $cents,
            'refund_cents' => $refundCents,
            'seat_type' => $r->seat_type,
            'boarded' => $r->boarded_at !== null,
            'paid_at' => $paidAt,
            'refund_completed_at' => $refundCents > 0 ? FinancialClock::fromUtc($r->res_completed_at) : null,
            'status' => $status,
        ];
    }

    /** A refund line: dated by its completion or by its original sale, and always linked back to that sale. */
    private function mapRefund(object $r, bool $byCompletion): array
    {
        $refundedAt = FinancialClock::fromUtc($r->res_completed_at);
        $soldAt = FinancialClock::paidAt((string) $r->payment_method, $r->onsite_issued_at, $r->first_ticket_at, $r->payment_created);

        return $this->dims($r) + [
            'cents' => (int) round(((float) $r->res_amount) * 100),
            'refunded_at' => $refundedAt,
            'sold_at' => $soldAt,
            'at' => $byCompletion ? $refundedAt : $soldAt,
            'transaction_id' => (string) $r->transaction_reference,
            'payment_id' => (int) $r->payment_id,
            'refund_method' => (string) $r->res_method,
        ];
    }

    /** The route / bus / trip / method / channel filters. Ownership is already in the query, so a foreign id matches nothing. */
    private function applyFilters(Collection $rows, array $f): Collection
    {
        return $rows->filter(function ($r) use ($f) {
            return ($f['route_id'] === null || $r['route_id'] === $f['route_id'])
                && ($f['fleet_id'] === null || $r['fleet_id'] === $f['fleet_id'])
                && ($f['trip_id'] === null || $r['trip_id'] === $f['trip_id'])
                && ($f['payment_method'] === null || $r['method'] === $f['payment_method'])
                && ($f['channel'] === null || $r['channel'] === $f['channel']);
        })->values();
    }

    // ------------------------------------------------------------------ figures

    private function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    private function channelLabel(string $channel, string $method): string
    {
        $labels = (array) config('financial.channel_labels');

        return $labels[$channel] ?? ($method === 'cash' ? 'Cash' : Str::headline($channel));
    }

    private function split(Collection $sales, Collection $refunds, string $method, int $gross): array
    {
        $g = $sales->where('method', $method)->sum('cents');
        $r = $refunds->where('method', $method)->sum('cents');

        return [
            'gross' => $this->money($g),
            'refunds' => $this->money($r),
            'net' => $this->money($g - $r),
            'tickets' => $sales->where('method', $method)->count(),
            'percent' => $gross > 0 ? round($g / $gross * 100, 1) : 0.0,
        ];
    }

    private function channels(Collection $sales, Collection $refunds, int $gross): array
    {
        $keys = $sales->map(fn ($r) => $r['method'].'|'.$r['channel'])->merge($refunds->map(fn ($r) => $r['method'].'|'.$r['channel']))->unique()->values();

        return $keys->map(function (string $key) use ($sales, $refunds, $gross) {
            [$method, $channel] = explode('|', $key, 2);
            $s = $sales->filter(fn ($r) => $r['method'] === $method && $r['channel'] === $channel);
            $rf = $refunds->filter(fn ($r) => $r['method'] === $method && $r['channel'] === $channel);

            return [
                'method' => $method,
                'channel' => $channel,
                'label' => $this->channelLabel($channel, $method),
                'tickets' => $s->count(),
                'gross' => $this->money($s->sum('cents')),
                'refunds' => $this->money($rf->sum('cents')),
                'net' => $this->money($s->sum('cents') - $rf->sum('cents')),
                'percent' => $gross > 0 ? round($s->sum('cents') / $gross * 100, 1) : 0.0,
            ];
        })->sortByDesc('gross')->values()->all();
    }

    /** @param callable(array): string $key @param callable(array): array $meta */
    private function groupBy(Collection $sales, Collection $refunds, callable $key, callable $meta): array
    {
        $out = [];
        foreach ($sales as $r) {
            $k = $key($r);
            $out[$k] ??= $meta($r) + ['tickets' => 0, 'gross_c' => 0, 'refunds_c' => 0];
            $out[$k]['tickets']++;
            $out[$k]['gross_c'] += $r['cents'];
        }
        foreach ($refunds as $r) {
            $k = $key($r);
            $out[$k] ??= $meta($r) + ['tickets' => 0, 'gross_c' => 0, 'refunds_c' => 0];
            $out[$k]['refunds_c'] += $r['cents'];
        }

        return collect($out)->map(function (array $row) {
            $gross = $row['gross_c'];
            $refunds = $row['refunds_c'];
            unset($row['gross_c'], $row['refunds_c']);

            return $row + ['gross' => $this->money($gross), 'refunds' => $this->money($refunds), 'net' => $this->money($gross - $refunds)];
        })->sortByDesc('gross')->values()->all();
    }

    /** Revenue (gross) by Manila hour of the sale, all 24 hours so a chart has a stable axis. */
    private function byHour(Collection $sales): array
    {
        $hours = array_fill(0, 24, ['tickets' => 0, 'cents' => 0]);
        foreach ($sales as $r) {
            $h = (int) $r['paid_at']->format('G');
            $hours[$h]['tickets']++;
            $hours[$h]['cents'] += $r['cents'];
        }

        return collect($hours)->map(fn ($v, $h) => ['hour' => $h, 'label' => sprintf('%02d:00', $h), 'tickets' => $v['tickets'], 'gross' => $this->money($v['cents'])])->values()->all();
    }

    /** Per Manila day: sales on the day they were paid, refunds on the day they were completed. */
    private function daily(Collection $sales, Collection $refunds): array
    {
        $days = [];
        $blank = fn () => ['gross_c' => 0, 'refunds_c' => 0, 'tickets' => 0, 'cash_c' => 0, 'online_c' => 0];
        foreach ($sales as $r) {
            $d = $r['paid_at']->toDateString();
            $days[$d] ??= $blank();
            $days[$d]['gross_c'] += $r['cents'];
            $days[$d]['tickets']++;
            $days[$d][$r['method'] === 'cash' ? 'cash_c' : 'online_c'] += $r['cents'];
        }
        foreach ($refunds as $r) {
            $d = $r['at']->toDateString();
            $days[$d] ??= $blank();
            $days[$d]['refunds_c'] += $r['cents'];
        }
        ksort($days);

        return collect($days)->map(fn ($v, $d) => [
            'date' => $d,
            'gross' => $this->money($v['gross_c']),
            'refunds' => $this->money($v['refunds_c']),
            'net' => $this->money($v['gross_c'] - $v['refunds_c']),
            'tickets' => $v['tickets'],
            'cash' => $this->money($v['cash_c']),
            'online' => $this->money($v['online_c']),
        ])->values()->all();
    }

    /** What the filter controls offer: only things that exist in the owner's data for the chosen dates. */
    private function options(Collection $allSales, ?int $owner): array
    {
        $routes = DB::table('fleets_routes')
            ->join('routes', 'routes.route_id', '=', 'fleets_routes.route_id')
            ->join('fleets', 'fleets.fleet_id', '=', 'fleets_routes.fleet_id')
            ->when($owner !== null, fn ($q) => $q->where('fleets.company_user_id', $owner))
            ->select('routes.route_id', 'routes.route_name')->distinct()->orderBy('routes.route_name')->get()
            ->map(fn ($r) => ['route_id' => (int) $r->route_id, 'route_name' => $r->route_name])->all();

        $fleets = DB::table('fleets')
            ->when($owner !== null, fn ($q) => $q->where('company_user_id', $owner))
            ->orderBy('plate_number')->get(['fleet_id', 'plate_number', 'bus_company_name', 'body_no'])
            ->map(fn ($f) => ['fleet_id' => (int) $f->fleet_id, 'plate_number' => $f->plate_number, 'bus_label' => BusLabel::compose($f->bus_company_name, $f->body_no)])->all();

        $trips = $allSales->unique('trip_id')->sortByDesc(fn ($r) => $r['trip_date'].' '.$r['departure_time'])->map(fn ($r) => [
            'trip_id' => $r['trip_id'], 'label' => trim($r['trip_date'].' '.$r['departure_time'].' '.$r['route_name'].' '.$r['plate_number']),
        ])->values()->all();

        $channels = $allSales->map(fn ($r) => ['method' => $r['method'], 'channel' => $r['channel'], 'label' => $this->channelLabel($r['channel'], $r['method'])])->unique(fn ($c) => $c['method'].'|'.$c['channel'])->values()->all();

        return ['routes' => $routes, 'fleets' => $fleets, 'trips' => $trips, 'channels' => $channels];
    }

    private function presentTransaction(array $r): array
    {
        $labels = ['paid' => 'Paid', 'refunded' => 'Refunded', 'partially_refunded' => 'Partially refunded', 'refund_pending' => 'Refund pending'];

        return [
            'ticket_id' => $r['ticket_id'],
            'transaction_id' => $r['transaction_id'],
            'paid_at' => $r['paid_at']->format('Y-m-d H:i:s'),
            'plate_number' => $r['plate_number'],
            'bus_label' => $r['bus_label'],
            'route_name' => $r['route_name'],
            'direction' => $r['direction'],
            'direction_label' => $r['direction_label'],
            'trip_id' => $r['trip_id'],
            'trip_date' => $r['trip_date'],
            'method' => $r['method'],
            'method_label' => $r['method'] === 'cash' ? 'Cash' : 'Online',
            'channel' => $r['channel'],
            'channel_label' => $this->channelLabel($r['channel'], $r['method']),
            'service_type' => $r['service_type'],
            'seat_type' => $r['seat_type'],
            'amount' => $this->money($r['cents']),
            'refunded' => $this->money($r['refund_cents']),
            'status' => $r['status'],
            'status_label' => $labels[$r['status']] ?? $r['status'],
            'refund_completed_at' => $r['refund_completed_at']?->format('Y-m-d H:i:s'),
        ];
    }
}
