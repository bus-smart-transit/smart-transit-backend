<?php

namespace App\Repositories;

use App\Models\Trip;
use App\Models\Ticket;
use App\Models\Payment;
use App\Models\RewardTransaction;
use App\Services\RewardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportingRepository
{
    private function isRevenueStatus(?string $status): bool
    {
        if (!$status) {
            return false;
        }

        return in_array(strtolower($status), ['paid', 'completed', 'success', 'succeeded'], true);
    }

    /**
     * Get financial summary for a fleet within a date range
     */
    public function getFleetFinancialSummary(int $fleetId, string $startDate, string $endDate): array
    {
        // Pure Eloquent aggregation — works on both SQLite (tests) and PostgreSQL (production).
        // We scope to payments on trips that belong to this fleet within the date window.
        $fleetTripIds = Trip::whereHas('fleetRoute', fn ($q) => $q->where('fleet_id', $fleetId))
            ->whereBetween('trip_date', [$startDate, $endDate])
            ->pluck('trip_id');

        $payments = \App\Models\Payment::whereIn('payment_id', function ($sub) use ($fleetTripIds) {
            $sub->select('payment_id')
                ->from('tickets')
                ->whereIn('trip_id', $fleetTripIds);
        })->whereIn('status', ['paid', 'completed', 'success', 'succeeded'])->get();

        $totalRevenue  = $payments->sum('amount');
        $onlineRevenue = $payments->where('payment_method', 'online')->sum('amount');
        $onsiteRevenue = $payments->where('payment_method', 'cash')->sum('amount');

        $totalTickets = \App\Models\Ticket::whereIn('trip_id', $fleetTripIds)
            ->whereIn('payment_id', $payments->pluck('payment_id'))
            ->distinct('ticket_id')
            ->count('ticket_id');

        $completedTrips = Trip::whereIn('trip_id', $fleetTripIds)
            ->where('status', 'completed')
            ->count();

        return [
            'fleet_id' => $fleetId,
            'period'   => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
            'revenue' => [
                'total'       => round((float) $totalRevenue, 2),
                'online'      => round((float) $onlineRevenue, 2),
                'onsite_cash' => round((float) $onsiteRevenue, 2),
                'currency'    => 'PHP',
            ],
            'tickets' => [
                'total'              => $totalTickets,
                'average_per_trip'   => $completedTrips > 0 ? round($totalTickets / $completedTrips, 2) : 0,
            ],
            'trips' => [
                'completed'                => $completedTrips,
                'average_revenue_per_trip' => $completedTrips > 0 ? round($totalRevenue / $completedTrips, 2) : 0,
            ],
        ];
    }

    /**
     * Get revenue breakdown by route
     */
    public function getRevenueByRoute(int $fleetId, string $startDate, string $endDate): Collection
    {
        $trips = Trip::with('fleetRoute.route', 'tickets.payment')
            ->whereHas('fleetRoute', fn($q) => $q->where('fleet_id', $fleetId))
            ->whereBetween('trip_date', [$startDate, $endDate])
            ->get();

        $routeData = [];

        foreach ($trips as $trip) {
            $routeId = $trip->fleetRoute->route_id;
            $routeName = $trip->fleetRoute->route->route_name ?? "Route {$routeId}";

            if (!isset($routeData[$routeId])) {
                $routeData[$routeId] = [
                    'route_id' => $routeId,
                    'route_name' => $routeName,
                    'total_revenue' => 0,
                    'online_revenue' => 0,
                    'onsite_revenue' => 0,
                    'total_tickets' => 0,
                    'total_trips' => 0,
                ];
            }

            $routeData[$routeId]['total_trips']++;

            $paymentMap = [];
            foreach ($trip->tickets as $ticket) {
                if (!$ticket->payment) {
                    continue;
                }

                $payment = $ticket->payment;
                if (!$this->isRevenueStatus($payment->status)) {
                    continue;
                }

                $paymentMap[$payment->payment_id] = $payment;
                $routeData[$routeId]['total_tickets']++;
            }

            foreach ($paymentMap as $payment) {
                $amount = $payment->amount;
                $routeData[$routeId]['total_revenue'] += $amount;

                if ($payment->payment_method === 'online') {
                    $routeData[$routeId]['online_revenue'] += $amount;
                } elseif ($payment->payment_method === 'cash') {
                    $routeData[$routeId]['onsite_revenue'] += $amount;
                }
            }

            if ($routeData[$routeId]['total_trips'] > 0) {
                $routeData[$routeId]['avg_revenue_per_trip'] = round($routeData[$routeId]['total_revenue'] / $routeData[$routeId]['total_trips'], 2);
            }
        }

        return collect($routeData)->sortByDesc('total_revenue');
    }

    /**
     * Get route adherence (on-time performance)
     */
    public function getRouteAdherence(int $fleetId, string $startDate, string $endDate): array
    {
        $trips = Trip::with('fleetRoute.route.routeStops.stop')
            ->whereHas('fleetRoute', fn($q) => $q->where('fleet_id', $fleetId))
            ->whereBetween('trip_date', [$startDate, $endDate])
            ->whereIn('status', ['departed', 'completed'])
            ->get();

        $totalTrips       = count($trips);
        $driver           = DB::getDriverName();
        $tripAdherences   = [];
        $stopCoverageSum  = 0;
        $deviatedTrips    = 0;
        $PROXIMITY_METERS = 300; // metres — bus considered "at" a stop if within 300 m

        foreach ($trips as $trip) {
            // Route::routeStops is already ordered by stop_order; a route stop whose stop row is gone is skipped.
            $stops = ($trip->fleetRoute?->route?->routeStops ?? collect())->pluck('stop')->filter()->values();
            $stopCount = $stops->count();

            if ($stopCount === 0) {
                continue; // no route geometry — skip adherence calc
            }

            // Fetch GPS trail for this trip (chronological)
            $gpsPoints = $this->getTripGpsPoints($trip->trip_id, $driver);

            if ($gpsPoints->isEmpty()) {
                // No GPS data — fall back to completion-based proxy
                $tripAdherences[] = [
                    'trip_id'           => $trip->trip_id,
                    'trip_date'         => $trip->trip_date,
                    'status'            => $trip->status,
                    'stop_coverage_pct' => $trip->status === 'completed' ? 100.0 : null,
                    'gps_points'        => 0,
                    'data_source'       => 'proxy',
                ];
                continue;
            }

            // For each scheduled stop, find whether a GPS point came within PROXIMITY_METERS
            $stopsVisited = 0;
            foreach ($stops as $stop) {
                $stopLat = (float) $stop->latitude;
                $stopLng = (float) $stop->longitude;

                $nearestDist = $gpsPoints->min(function ($pt) use ($stopLat, $stopLng) {
                    return $this->haversineMeters($pt->latitude, $pt->longitude, $stopLat, $stopLng);
                });

                if ($nearestDist !== null && $nearestDist <= $PROXIMITY_METERS) {
                    $stopsVisited++;
                }
            }

            $coveragePct = $stopCount > 0 ? round(($stopsVisited / $stopCount) * 100, 1) : 0;
            $stopCoverageSum += $coveragePct;

            if ($coveragePct < 80) {
                $deviatedTrips++;
            }

            $tripAdherences[] = [
                'trip_id'           => $trip->trip_id,
                'trip_date'         => $trip->trip_date,
                'status'            => $trip->status,
                'stops_scheduled'   => $stopCount,
                'stops_visited'     => $stopsVisited,
                'stop_coverage_pct' => $coveragePct,
                'gps_points'        => $gpsPoints->count(),
                'data_source'       => 'gps',
            ];
        }

        $tripsWithData  = count($tripAdherences);
        $avgCoverage    = $tripsWithData > 0 ? round($stopCoverageSum / $tripsWithData, 1) : null;

        return [
            'fleet_id' => $fleetId,
            'period'   => ['start_date' => $startDate, 'end_date' => $endDate],
            'summary'  => [
                'total_trips'             => $totalTrips,
                'trips_with_gps_data'     => collect($tripAdherences)->where('data_source', 'gps')->count(),
                'avg_stop_coverage_pct'   => $avgCoverage,
                'deviated_trips'          => $deviatedTrips,
                'adherence_rate_pct'      => $tripsWithData > 0
                    ? round((($tripsWithData - $deviatedTrips) / $tripsWithData) * 100, 1)
                    : null,
            ],
            'trips' => $tripAdherences,
        ];
    }

    private function getTripGpsPoints(int $tripId, string $driver): Collection
    {
        if ($driver === 'sqlite') {
            return collect(DB::select("
                SELECT
                    CAST(SUBSTR(location, 1, INSTR(location,',')-1) AS REAL) AS latitude,
                    CAST(SUBSTR(location, INSTR(location,',')+1) AS REAL)    AS longitude,
                    recorded_at
                FROM fleet_location_history
                WHERE trip_id = ?
                ORDER BY recorded_at ASC
            ", [$tripId]));
        }

        return collect(DB::select("
            SELECT
                ST_Y(location::geometry) AS latitude,
                ST_X(location::geometry) AS longitude,
                recorded_at
            FROM fleet_location_history
            WHERE trip_id = ?
            ORDER BY recorded_at ASC
        ", [$tripId]));
    }

    /**
     * Haversine great-circle distance in metres.
     */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R    = 6371000; // Earth radius in metres
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $R * 2 * asin(sqrt($a));
    }

    /**
     * Get occupancy trends (peak hours, average load)
     */
    public function getOccupancyTrends(int $fleetId, string $startDate, string $endDate): array
    {
        $trips = Trip::with('fleetRoute.fleet')
            ->whereHas('fleetRoute', fn($q) => $q->where('fleet_id', $fleetId))
            ->whereBetween('trip_date', [$startDate, $endDate])
            ->whereIn('status', ['boarding', 'departed', 'completed'])
            ->get();

        $fleet = $trips->first()?->fleetRoute->fleet;
        $totalCapacity = $fleet->capacity ?? 0;

        $totalOccupancy = 0;
        $peakOccupancy = 0;
        $minOccupancy = PHP_INT_MAX;
        $occupancyByHour = [];

        foreach ($trips as $trip) {
            $occupancy = $trip->total_occupancy;
            if ($occupancy > 0) {  // Only count trips with passengers
                $totalOccupancy += $occupancy;
                $peakOccupancy = max($peakOccupancy, $occupancy);
                $minOccupancy = min($minOccupancy, $occupancy);

                // Group by hour (from created_at or trip_date)
                $hour = $trip->created_at->hour;
                $occupancyByHour[$hour][] = $occupancy;
            }
        }

        $tripsWithPassengers = count(array_filter($trips->pluck('total_occupancy')->toArray(), fn($x) => $x > 0));
        $averageOccupancy = $tripsWithPassengers > 0 ? $totalOccupancy / $tripsWithPassengers : 0;

        // Calculate average per hour
        $averageOccupancyByHour = [];
        foreach ($occupancyByHour as $hour => $occupancies) {
            $averageOccupancyByHour[$hour] = round(array_sum($occupancies) / count($occupancies), 2);
        }

        ksort($averageOccupancyByHour);

        return [
            'fleet_id' => $fleetId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'capacity' => $totalCapacity,
            'occupancy' => [
                'average' => round($averageOccupancy, 2),
                'peak' => $peakOccupancy,
                'minimum' => $minOccupancy !== PHP_INT_MAX ? $minOccupancy : 0,
                'average_utilization_percentage' => $totalCapacity > 0 ? round(($averageOccupancy / $totalCapacity) * 100, 2) : 0,
                'peak_utilization_percentage' => $totalCapacity > 0 ? round(($peakOccupancy / $totalCapacity) * 100, 2) : 0,
            ],
            'peak_hours' => $averageOccupancyByHour,
            'trips_completed' => count($trips),
        ];
    }

    /**
     * Get daily summary for a fleet
     */
    public function getDailySummary(int $fleetId, string $date): array
    {
        $trips = Trip::with('fleetRoute.route', 'tickets.payment')
            ->whereHas('fleetRoute', fn($q) => $q->where('fleet_id', $fleetId))
            ->where('trip_date', $date)
            ->get();

        $totalRevenue = 0;
        $totalTickets = 0;
        $totalOccupancy = 0;
        $completedTrips = 0;
        $activeTrips = 0;

        foreach ($trips as $trip) {
            if ($trip->status === 'completed') {
                $completedTrips++;
                $totalOccupancy += $trip->total_occupancy;
            } elseif (in_array($trip->status, ['boarding', 'departed'])) {
                $activeTrips++;
            }

            $paymentMap = [];
            foreach ($trip->tickets as $ticket) {
                if (!$ticket->payment) {
                    continue;
                }

                $payment = $ticket->payment;
                if (!$this->isRevenueStatus($payment->status)) {
                    continue;
                }

                $paymentMap[$payment->payment_id] = $payment;
                $totalTickets++;
            }

            foreach ($paymentMap as $payment) {
                $totalRevenue += $payment->amount;
            }
        }

        return [
            'fleet_id' => $fleetId,
            'date' => $date,
            'summary' => [
                'total_trips' => count($trips),
                'completed_trips' => $completedTrips,
                'active_trips' => $activeTrips,
                'total_revenue' => round($totalRevenue, 2),
                'total_tickets' => $totalTickets,
                'average_occupancy' => $completedTrips > 0 ? round($totalOccupancy / $completedTrips, 2) : 0,
            ],
        ];
    }

    /**
     * Get multi-channel payment breakdown
     */
    public function getPaymentChannelBreakdown(int $fleetId, string $startDate, string $endDate): array
    {
        $trips = Trip::with('tickets.payment')
            ->whereHas('fleetRoute', fn($q) => $q->where('fleet_id', $fleetId))
            ->whereBetween('trip_date', [$startDate, $endDate])
            ->get();

        $channelData = [];
        $inScopePaymentIds = [];

        foreach ($trips as $trip) {
            $paymentMap = [];
            foreach ($trip->tickets as $ticket) {
                if (!$ticket->payment) {
                    continue;
                }

                $payment = $ticket->payment;
                if (!$this->isRevenueStatus($payment->status)) {
                    continue;
                }

                $paymentMap[$payment->payment_id] = $payment;
            }

            foreach ($paymentMap as $payment) {
                $channel = $payment->payment_channel ?? 'unknown';
                if (!isset($channelData[$channel])) {
                    $channelData[$channel] = [
                        'channel' => $channel,
                        'total_amount' => 0,
                        'transaction_count' => 0,
                        'average_transaction' => 0,
                    ];
                }

                $channelData[$channel]['total_amount'] += $payment->amount;
                $channelData[$channel]['transaction_count']++;
                $inScopePaymentIds[$payment->payment_id] = true;
            }
        }

        // Calculate averages
        foreach ($channelData as &$data) {
            if ($data['transaction_count'] > 0) {
                $data['average_transaction'] = round($data['total_amount'] / $data['transaction_count'], 2);
            }
        }

        // Batch C / THESIS_OBJECTIVES_AUDIT.md item #8: reward-point
        // redemptions were previously invisible to "multi-channel" financial
        // reporting — a passenger applying points as a discount during
        // checkout (PaymentService::checkoutOnline() → RewardService::
        // redeemPoints($paymentId, ...)) reduces the effective cash collected
        // but that value never showed up anywhere in this report. Reported
        // as a separate reconciliation line (not injected as a fake
        // "channel") since it's a discount/adjustment against face value,
        // not incoming revenue.
        $redemptions = RewardTransaction::where('type', 'redeemed')
            ->whereIn('payment_id', array_keys($inScopePaymentIds))
            ->get();

        // Net per payment: points taken at checkout minus points given back when the payment did not go through.
        $netByPayment = $redemptions->groupBy('payment_id')->map(fn ($rows) => max(0, -1 * (int) $rows->sum('points')));
        $totalPointsRedeemed = (int) $netByPayment->sum();

        return [
            'fleet_id' => $fleetId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'channels' => array_values($channelData),
            'reward_redemptions' => [
                'redemption_count' => $netByPayment->filter(fn ($points) => $points > 0)->count(),
                'total_points_redeemed' => $totalPointsRedeemed,
                // What the passenger actually saved: a point is worth the checkout discount, not the earning rate.
                'total_value_php' => round($totalPointsRedeemed * RewardService::REDEMPTION_PESOS_PER_POINT, 2),
            ],
        ];
    }

    /**
     * Query the audit_logs table (no Eloquent model — raw log table) with
     * optional filters. Moved here from a route closure in routes/api.php
     * to keep data access out of the routing layer.
     */
    public function getAuditLogs(array $filters, int $limit): Collection
    {
        $query = DB::table('audit_logs')
            ->orderByDesc('created_at')
            ->limit($limit);

        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['actor_type'])) {
            $query->where('actor_type', $filters['actor_type']);
        }
        if (!empty($filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }
        if (!empty($filters['subject_type'])) {
            $query->where('subject_type', $filters['subject_type']);
        }
        if (!empty($filters['subject_id'])) {
            $query->where('subject_id', (int) $filters['subject_id']);
        }

        return $query->get();
    }

    /**
     * Query the scheduled_reports table (no Eloquent model — raw table) with
     * optional filters. Moved here from a route closure in routes/api.php
     * to keep data access out of the routing layer.
     */
    public function getStoredReports(array $filters, int $limit): Collection
    {
        $query = DB::table('scheduled_reports')
            ->orderByDesc('report_date')
            ->orderByDesc('generated_at')
            ->limit($limit);

        if (!empty($filters['fleet_id'])) {
            $query->where('fleet_id', (int) $filters['fleet_id']);
        }
        if (!empty($filters['report_type'])) {
            $query->where('report_type', $filters['report_type']);
        }
        if (!empty($filters['date'])) {
            $query->where('report_date', $filters['date']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->get()->map(function ($row) {
            $row->payload = json_decode($row->payload, true);

            return $row;
        });
    }
}
