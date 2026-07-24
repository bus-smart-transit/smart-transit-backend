<?php
namespace App\Services;
use App\Models\Ticket;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\FareRuleRepository;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class TicketService
{
    public function __construct(
        private TicketRepository $ticketRepository,
        private TripRepository $tripRepository,
        private RouteStopRepository $routeStopRepository,
        private FareRuleRepository $fareRuleRepository,
        private FareRuleService $fareRuleService,
        private FareCalculationService $fareCalculationService,
        private TripService $tripService,
        private RewardService $rewardService,
    ) {
    }

    /**
     * $item is one of two shapes:
     *   stop-based:   ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     *   custom-point: ['trip_id','seat_type','origin_lat','origin_lng','destination_lat','destination_lng']
     */
    public function reserveAndPrice(array $item): array
    {
        $trip = $this->tripRepository->findById($item['trip_id']);

        if (!$trip || !in_array($trip->status, ['scheduled', 'boarding'])) {
            throw ValidationException::withMessages([
                'trip' => ['This trip is not accepting tickets.'],
            ]);
        }

        if (isset($item['origin_stop_id'], $item['destination_stop_id'])) {
            $reserved = $this->priceByStops($trip, $item);
        } elseif (isset($item['origin_lat'], $item['origin_lng'], $item['destination_lat'], $item['destination_lng'])) {
            $reserved = $this->priceByCoordinates($trip, $item);
        } else {
            throw ValidationException::withMessages([
                'item' => ['Provide either origin_stop_id/destination_stop_id or origin_lat/lng + destination_lat/lng.'],
            ]);
        }

        // Reserve seat/standing capacity now — throws if full. Same hold
        // semantics regardless of which pricing path was used.
        $this->tripService->recordBoarding($trip->trip_id, $item['seat_type']);

        return $reserved;
    }

    /**
     * Reserves one more seat/standing slot using a precomputed pricing
     * template from a previous reserveAndPrice() call for identical items.
     */
    public function reserveFromTemplate(array $reservedTemplate, int $quantity = 1): array
    {
        if (!isset($reservedTemplate['trip_id'], $reservedTemplate['seat_type'])) {
            throw new \RuntimeException('Invalid reservation template.');
        }

        $this->tripService->recordBoardingMultiple(
            (int) $reservedTemplate['trip_id'],
            (string) $reservedTemplate['seat_type'],
            max(1, $quantity),
        );

        return $reservedTemplate;
    }

    /**
     * Computed on the fly — no fare_matrix table. The distance between two
     * known stops on a known route is exact (no interpolation needed), so
     * this is just a subtraction plus the shared fare formula.
     */
    private function priceByStops(object $trip, array $item): array
    {
        $originRouteStop = $this->routeStopRepository->findByRouteAndStop(
            $trip->fleetRoute->route_id,
            $item['origin_stop_id'],
        );
        $destinationRouteStop = $this->routeStopRepository->findByRouteAndStop(
            $trip->fleetRoute->route_id,
            $item['destination_stop_id'],
        );

        if (!$originRouteStop || !$destinationRouteStop) {
            throw ValidationException::withMessages([
                'stop' => ['One or both stops are not on this trip\'s route.'],
            ]);
        }

        $distanceKm = abs($destinationRouteStop->distance_from_origin_km - $originRouteStop->distance_from_origin_km);

        $rule = $this->fareRuleRepository->getActiveRule($trip->fleetRoute->fleet_id, $item['seat_type'])
            ?? $this->fareRuleRepository->getActiveRuleForRoute($trip->fleetRoute->route_id, $item['seat_type']);

        if (!$rule) {
            throw new \RuntimeException('No fare configured for this fleet and seat type.');
        }

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'fare_rule_id' => $rule->fare_rule_id,
            'distance_km' => $distanceKm,
            'amount' => $this->fareCalculationService->computeFare($rule->base_fare, $rule->fare_per_km, $distanceKm),
            'origin_stop_id' => $item['origin_stop_id'],
            'destination_stop_id' => $item['destination_stop_id'],
        ];
    }

    /**
     * Re-derives price server-side from the trip's actual fleet + route via
     * GPS interpolation — never trusts a client-supplied amount from an
     * earlier browse-time quote.
     */
    private function priceByCoordinates(object $trip, array $item): array
    {
        $quote = $this->fareRuleService->getQuoteForTripFromCoordinates(
            $trip->fleetRoute->fleet_id,
            $trip->fleetRoute->route_id,
            [
                'origin_lat' => $item['origin_lat'],
                'origin_lng' => $item['origin_lng'],
                'destination_lat' => $item['destination_lat'],
                'destination_lng' => $item['destination_lng'],
                'seat_type' => $item['seat_type'],
            ]
        );

        return [
            'fleet_route_id' => $trip->fleet_route_id,
            'trip_id' => $trip->trip_id,
            'seat_type' => $item['seat_type'],
            'fare_rule_id' => $quote['fare_rule_id'],
            'distance_km' => $quote['distance_km'],
            'amount' => $quote['amount'],
        ];
    }

    /**
     * Creates the actual ticket row for a previously reserved item. Called
     * only once payment is confirmed (online) or immediately (onsite/cash).
     * Does NOT call recordBoarding() again — capacity was already reserved
     * in reserveAndPrice().
     */
    public function finalizeTicket(array $reserved, ?int $passengerId, int $paymentId): Ticket
    {
        $ticket = $this->ticketRepository->create([
            'fleet_route_id' => $reserved['fleet_route_id'],
            'trip_id' => $reserved['trip_id'],
            'fare_rule_id' => $reserved['fare_rule_id'],
            'distance_km' => $reserved['distance_km'],
            'payment_id' => $paymentId,
            'passenger_id' => $passengerId,
            'status' => 'issued',
            'amount' => $reserved['amount'],
            'seat_type' => $reserved['seat_type'],
            'origin_stop_id' => $reserved['origin_stop_id'] ?? null,
            'destination_stop_id' => $reserved['destination_stop_id'] ?? null,
        ]);

        if ($passengerId) {
            $earnableAmount = isset($reserved['reward_earn_amount'])
                ? (float) $reserved['reward_earn_amount']
                : (float) $reserved['amount'];

            $this->rewardService->awardPoints($passengerId, $earnableAmount, $paymentId);
        }

        return $ticket;
    }

    /**
     * Releases previously held capacity for an item that never became a
     * ticket (payment failed, or its hold expired).
     */
    public function releaseTicketHold(array $reserved): void
    {
        $this->tripService->releaseBoarding($reserved['trip_id'], $reserved['seat_type']);
    }

    /**
     * Combines reserveAndPrice() + finalizeTicket() into a single call.
     * Used by the onsite flow, where cash payment is confirmed instantly.
     */
    public function issueTicket(array $item, ?int $passengerId, int $paymentId): Ticket
    {
        return DB::transaction(function () use ($item, $passengerId, $paymentId) {
            $reserved = $this->reserveAndPrice($item);
            return $this->finalizeTicket($reserved, $passengerId, $paymentId);
        });
    }

    // $payload = ['ticket_uuid']
    public function validateScan(array $payload): object
    {
        $ticket = $this->ticketRepository->findByUuid($payload['ticket_uuid']);

        if (!$ticket) {
            throw ValidationException::withMessages(['ticket' => ['Ticket not found.']]);
        }

        if ($ticket->status !== 'issued') {
            throw ValidationException::withMessages([
                'ticket' => ["Ticket is already {$ticket->status}."],
            ]);
        }

        if (!$ticket->payment || $ticket->payment->status !== 'paid' || !$ticket->payment->is_valid) {
            throw ValidationException::withMessages([
                'ticket' => ['Payment for this ticket has not been completed yet.'],
            ]);
        }

        $tripDate = $ticket->trip?->trip_date;
        if ($tripDate && now()->startOfDay()->lt($tripDate->startOfDay())) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket is not yet active. It can only be used on the scheduled trip date.'],
            ]);
        }

        if ($tripDate && now()->gt($tripDate->copy()->endOfDay())) {
            throw ValidationException::withMessages([
                'ticket' => ['This ticket has expired. Tickets are only valid until 11:59 PM of the scheduled trip date.'],
            ]);
        }

        $this->ticketRepository->markBoarded($ticket->ticket_id);
        $boardedTicket = $this->ticketRepository->findByUuid($payload['ticket_uuid']);

        return (object) [
            'ticket_id' => $boardedTicket->ticket_id,
            'ticket_uuid' => $boardedTicket->ticket_uuid,
            'passenger_name' => $boardedTicket->passenger?->user?->name ?? 'Guest',
            'origin' => $boardedTicket->originStop?->stop_name,
            'destination' => $boardedTicket->destinationStop?->stop_name,
            'seat_type' => $boardedTicket->seat_type,
            'amount' => (float) $boardedTicket->amount,
            'status' => $boardedTicket->status,
            'boarded_at' => $boardedTicket->boarded_at,
        ];
    }

    public function getPassengerTickets(int $passengerId): object
    {
        return $this->ticketRepository
            ->findByPassenger($passengerId)
            ->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket));
    }

    public function findByTransactionAndEmail(string $transactionReference, ?string $email = null, ?int $paymentId = null): object
    {
        return $this->ticketRepository
            ->findByTransactionAndEmail($transactionReference, $email, $paymentId)
            ->map(fn (Ticket $ticket) => $this->appendValidityWindow($ticket));
    }

    private function appendValidityWindow(Ticket $ticket): Ticket
    {
        $tripDate = $ticket->trip?->trip_date;
        if ($tripDate) {
            $ticket->setAttribute('valid_from', $tripDate->copy()->startOfDay()->toIso8601String());
            $ticket->setAttribute('expires_at', $tripDate->copy()->endOfDay()->toIso8601String());
        } else {
            $ticket->setAttribute('valid_from', null);
            $ticket->setAttribute('expires_at', null);
        }

        return $ticket;
    }
}