<?php

namespace App\Repositories;

use App\Models\Trip;
use App\Models\Ticket;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
        // Single aggregation query — avoids hydrating thousands of trip/ticket/payment
        // models into PHP memory for every report request.
        $row = \Illuminate\Support\Facades\DB::selectOne("
            SELECT
                COALESCE(SUM(CASE WHEN p.payment_method = 'online' THEN p.amount ELSE 0 END), 0) AS online_revenue,
                COALESCE(SUM(CASE WHEN p.payment_method = 'cash' THEN p.amount ELSE 0 END), 0)   AS onsite_revenue,
                COALESCE(SUM(p.amount), 0)                                                         AS total_revenue,
                COUNT(DISTINCT t.ticket_id)                                                        AS total_tickets,
                COUNT(DISTINCT CASE WHEN tr.status = 'completed' THEN tr.trip_id ELSE NULL END)   AS completed_trips
            FROM trips tr
            JOIN fleet_routes fr ON fr.fleet_route_id = tr.fleet_route_id
            JOIN tickets t       ON t.trip_id = tr.trip_id
            JOIN payments p      ON p.payment_id = t.payment_id
            WHERE fr.fleet_id = ?
              AND tr.trip_date BETWEEN ? AND ?
              AND p.status IN ('paid','completed','success','succeeded')
        ", [$fleetId, $startDate, $endDate]);

        $completedTrips = (int) ($row->completed_trips ?? 0);
        $totalRevenue   = (float) ($row->total_revenue  ?? 0);
        $totalTickets   = (int) ($row->total_tickets    ?? 0);

        return [
            'fleet_id' => $fleetId,
            'period'   => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
            'revenue' => [
                'total'       => round($totalRevenue, 2),
                'online'      => round((float) ($row->online_revenue  ?? 0), 2),
                'onsite_cash' => round((float) ($row->onsite_revenue  ?? 0), 2),
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
        $trips = Trip::with('fleetRoute.route')
            ->whereHas('fleetRoute', fn($q) => $q->where('fleet_id', $fleetId))
            ->whereBetween('trip_date', [$startDate, $endDate])
            ->whereIn('status', ['departed', 'completed'])
            ->get();

        $totalTrips = count($trips);
        $onTimeTrips = 0; // Would require actual departure vs scheduled time
        $delayedTrips = 0;

        // For now, calculate based on trip completion
        foreach ($trips as $trip) {
            if ($trip->status === 'completed') {
                $onTimeTrips++;
            }
        }

        $delayedTrips = $totalTrips - $onTimeTrips;

        return [
            'fleet_id' => $fleetId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'on_time_performance' => [
                'total_trips' => $totalTrips,
                'on_time_trips' => $onTimeTrips,
                'delayed_trips' => $delayedTrips,
                'on_time_percentage' => $totalTrips > 0 ? ($onTimeTrips / $totalTrips) * 100 : 0,
            ],
        ];
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
            }
        }

        // Calculate averages
        foreach ($channelData as &$data) {
            if ($data['transaction_count'] > 0) {
                $data['average_transaction'] = round($data['total_amount'] / $data['transaction_count'], 2);
            }
        }

        return [
            'fleet_id' => $fleetId,
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'channels' => array_values($channelData),
        ];
    }
}
