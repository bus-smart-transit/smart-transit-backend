<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\RouteStop;
use App\Models\Ticket;
use App\Models\TicketResolution;
use App\Models\Trip;
use App\Models\TripCancellation;
use App\Repositories\RouteStopRepository;
use App\Repositories\TicketRepository;
use App\Support\CompanyScope;
use App\Support\LegDistance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 Stage 3, item 6: settling a ticket of a trip the OPERATOR cancelled, by rebooking or by refund.
 *
 * A passenger can never cancel or refund a paid ticket (that stays a 422). This service only acts on a ticket the
 * cancellation flow marked `trip_cancelled` ("awaiting choice"), and it acts once per ticket: `ticket_resolutions`
 * has a unique ticket_id, so a double tap, a retry or a replayed webhook finds the existing row and changes nothing.
 *
 *  - Rebook: a later departure of the same route, direction, service type and vehicle class between the same two
 *    stops, with room, at no extra charge (TripResolverService::rebookOptions). The old ticket becomes `rebooked`.
 *  - Refund (server-side amount, the existing fare service only, whole pesos):
 *      cancelled before departure ......... the full fare paid for the ticket;
 *      cancelled mid-route, never boarded ... the full fare;
 *      cancelled mid-route, boarded ........ fare paid minus the fare of the ridden portion, priced by the ticket's own
 *        fare rule from its boarding point (stored boarding distance, so a snapped boarding works) to the last point the
 *        bus reached (TripCancellationService::resolveLastPoint), never beyond the ticket's own destination. If the
 *        last point, the boarding point or the fare rule is unknown, a fixed share of the fare is kept
 *        (config refunds.unpriced_deduction_percent, default 10) and the rest is refunded at once; with that setting
 *        empty the ticket is flagged for operator review instead (2026-11-13, user).
 *    A digital ticket is refunded to its original payment method by a gateway reversal; its final state comes from the
 *    signed refund webhook. A cash ticket is refunded by a recorded entry (who, when, how much); a terminal action never
 *    pays out cash for a digital ticket.
 *
 * Ticket status: trip_cancelled (awaiting choice) -> rebooked | refund_pending -> refunded | refund_review.
 */
class TicketResolutionService
{
    public const REVIEW = 'refund_review';

    public const PENDING = 'refund_pending';

    public const REFUNDED = 'refunded';

    public const REBOOKED = 'rebooked';

    public function __construct(
        private FareCalculationService $fares,
        private TripResolverService $resolver,
        private TripService $tripService,
        private TicketTrackingService $tracking,
        private TicketRepository $tickets,
        private RouteStopRepository $routeStops,
        private FcmService $fcm,
        private ShiftCashService $shiftCash,
    ) {}

    /** What the passenger (or the staff member acting for them) sees for one ticket. @return array<string, mixed> */
    public function view(Ticket $ticket): array
    {
        $ticket->loadMissing(['trip.fleetRoute.route', 'payment', 'fareRule']);
        $cancellation = TripCancellation::where('trip_id', $ticket->trip_id)->first();
        $resolution = TicketResolution::where('ticket_id', $ticket->ticket_id)->first();
        $awaiting = $ticket->status === TripCancellationService::AWAITING_CHOICE && $cancellation !== null;

        $quote = $awaiting ? $this->quote($ticket, $cancellation) : null;
        $options = [];
        $rebookMessage = null;
        if ($awaiting) {
            if ($ticket->boarded_at !== null) {
                $rebookMessage = 'You had already boarded, so this ticket can only be refunded for the part you did not travel.';
            } elseif (! $ticket->origin_stop_id || ! $ticket->destination_stop_id) {
                $rebookMessage = 'This ticket was not bought between two stops, so it can only be refunded.';
            } else {
                $options = $this->resolver->rebookOptions($ticket, (int) config('refunds.rebook.max_options'));
                if ($options === []) {
                    $rebookMessage = 'No later departure with room is available on this route yet. Your refund option stays open.';
                }
            }
        }

        return [
            'ticket_uuid' => $ticket->ticket_uuid,
            'status' => $ticket->status,
            'seat_type' => $ticket->seat_type,
            'journey_leg' => $ticket->journey_leg,
            'trip' => [
                'trip_id' => (int) $ticket->trip_id,
                'trip_date' => $ticket->trip?->trip_date?->toDateString(),
                'departure_time' => $ticket->trip?->departure_time,
                'route_name' => $ticket->trip?->fleetRoute?->route?->route_name,
            ],
            'cancellation' => $cancellation ? [
                'cancelled_at' => $cancellation->cancelled_at?->toIso8601ZuluString(),
                'stage' => $cancellation->stage,
            ] : null,
            'deadline' => $this->deadline($cancellation),
            'awaiting_choice' => $awaiting,
            'refund' => $quote ? [
                'amount' => $quote['amount'],
                'fare_paid' => $quote['fare_paid'],
                'ridden_fare' => $quote['ridden_fare'],
                'needs_review' => $quote['needs_review'],
                'review_reason' => $quote['review_reason'],
                'method' => $this->methodFor($ticket),
            ] : null,
            'rebook' => [
                'available' => $options !== [],
                'options' => $options,
                'message' => $rebookMessage,
            ],
            'resolution' => $resolution ? $this->presentResolution($resolution) : null,
        ];
    }

    /**
     * @param  array{choice: string, trip_id?: int|null, note?: string|null, method?: string|null}  $input
     * @param  array{user: object|null, role: string, on_behalf: bool}  $actor
     *
     * @throws ValidationException
     */
    public function choose(Ticket $ticket, array $input, array $actor): TicketResolution
    {
        $choice = (string) ($input['choice'] ?? '');
        if (! in_array($choice, [TicketResolution::REBOOK, TicketResolution::REFUND], true)) {
            throw ValidationException::withMessages(['choice' => ['Choose rebook or refund.']]);
        }

        $retry = null;
        $resolution = DB::transaction(function () use ($ticket, $choice, $input, $actor, &$retry) {
            $locked = Ticket::whereKey($ticket->ticket_id)->lockForUpdate()->firstOrFail();
            $existing = TicketResolution::where('ticket_id', $locked->ticket_id)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->resolution !== $choice) {
                    throw ValidationException::withMessages(['choice' => ['This ticket has already been settled by '.($existing->resolution === TicketResolution::REBOOK ? 'rebooking' : 'a refund').'.']]);
                }
                if ($choice === TicketResolution::REFUND && $existing->status === TicketResolution::FAILED && $existing->method === TicketResolution::METHOD_GATEWAY) {
                    $existing->update(['status' => TicketResolution::PENDING, 'failure_reason' => null, 'actor_user_id' => $actor['user']?->user_id, 'actor_role' => $actor['role'], 'on_behalf' => $actor['on_behalf']]);
                    $locked->update(['status' => self::PENDING]);
                    $retry = $existing;
                    AuditLogger::log('ticket.refund_retried', 'Ticket', (int) $locked->ticket_id, ['resolution_id' => $existing->id]);
                }

                return $existing; // a double tap: same answer, nothing done twice
            }

            if ($locked->status !== TripCancellationService::AWAITING_CHOICE) {
                throw ValidationException::withMessages(['ticket' => ['This ticket cannot be rebooked or refunded. Only tickets for a trip the operator cancelled can be.']]);
            }
            $cancellation = TripCancellation::where('trip_id', $locked->trip_id)->first();
            if (! $cancellation) {
                throw ValidationException::withMessages(['ticket' => ['This ticket has no trip cancellation on record.']]);
            }
            if ($actor['role'] === 'passenger' && ! $this->deadline($cancellation)['open']) {
                throw ValidationException::withMessages(['ticket' => ['The time to choose has passed. Please contact the operator.']]);
            }

            return $choice === TicketResolution::REBOOK
                ? $this->rebook($locked, $cancellation, $input, $actor)
                : $this->startRefund($locked, $cancellation, $input, $actor);
        });

        $resolution = $resolution->fresh();
        if ($retry !== null || ($resolution->resolution === TicketResolution::REFUND && $resolution->method === TicketResolution::METHOD_GATEWAY
            && $resolution->status === TicketResolution::PENDING && $resolution->gateway_refund_id === null)) {
            $this->submitToGateway($resolution);
            $resolution = $resolution->fresh();
        }

        return $resolution;
    }

    /** Operator or Admin: the cash refund of a cash ticket has been handed over to the passenger; record who, when and how much. */
    public function recordCashRefund(TicketResolution $resolution, object $user, string $role, ?string $note): TicketResolution
    {
        return DB::transaction(function () use ($resolution, $user, $role, $note) {
            $locked = TicketResolution::whereKey($resolution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === TicketResolution::SUCCEEDED) {
                return $locked;
            }
            if ($locked->method !== TicketResolution::METHOD_CASH || $locked->resolution !== TicketResolution::REFUND) {
                throw ValidationException::withMessages(['resolution' => ['Only the refund of a cash ticket is recorded as a cash entry. A digital ticket is refunded to its original payment method.']]);
            }
            if ($locked->amount === null) {
                throw ValidationException::withMessages(['resolution' => ['The refund amount is still under review.']]);
            }

            $locked->update([
                'status' => TicketResolution::SUCCEEDED,
                'actor_user_id' => $user->user_id,
                'actor_role' => $role,
                'note' => $note !== null ? mb_substr($note, 0, 255) : $locked->note,
                'completed_at' => Carbon::now('UTC'),
                // Batch 29: the cash-out belongs to the shift that sold the ticket (set at the request; found now for older rows).
                'conductor_shift_log_id' => $locked->conductor_shift_log_id ?? $this->shiftCash->shiftIdForTicket((int) $locked->ticket_id),
            ]);
            Ticket::whereKey($locked->ticket_id)->update(['status' => self::REFUNDED]);
            AuditLogger::log('ticket.cash_refund_recorded', 'Ticket', (int) $locked->ticket_id, ['resolution_id' => $locked->id, 'amount' => $locked->amount]);

            return $locked->fresh();
        });
    }

    /**
     * Operator or Admin: the ridden portion could not be priced (unknown last point, boarding point or fare rule), so
     * a person sets the refund, within what was paid. The reason is recorded with the actor.
     */
    public function setReviewedAmount(TicketResolution $resolution, float $amount, object $user, string $role, string $note): TicketResolution
    {
        $submit = false;
        $result = DB::transaction(function () use ($resolution, $amount, $user, $role, $note, &$submit) {
            $locked = TicketResolution::whereKey($resolution->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== TicketResolution::NEEDS_REVIEW) {
                throw ValidationException::withMessages(['resolution' => ['This refund is not waiting for review.']]);
            }
            $paid = (float) $locked->fare_paid;
            $amount = min($paid, max(0.0, $this->fares->roundAmount($amount)));

            $ticket = Ticket::with('payment')->findOrFail($locked->ticket_id);
            $locked->update([
                'amount' => $amount,
                'note' => mb_substr($note, 0, 255),
                'actor_user_id' => $user->user_id,
                'actor_role' => $role,
                'on_behalf' => true,
            ]);
            AuditLogger::log('ticket.refund_amount_reviewed', 'Ticket', (int) $locked->ticket_id, ['resolution_id' => $locked->id, 'amount' => $amount, 'fare_paid' => $paid]);

            $submit = $this->proceedWithAmount($locked, $ticket, $amount, true);

            return $locked;
        });

        $result = $result->fresh();
        if ($submit) {
            $this->submitToGateway($result);
            $result = $result->fresh();
        }

        return $result;
    }

    /**
     * Sends a pending gateway refund to PayMongo, unless it has to wait (Maya takes only a full refund on the day of the
     * payment, so a partial one waits for the next Manila midnight). The outcome (success or failure) is recorded when
     * the signed refund webhook arrives; an error here marks the refund failed so staff can retry it.
     */
    public function submitToGateway(TicketResolution $resolution): void
    {
        // One sender at a time per refund: two taps at once must not create two refunds at the provider.
        $lock = Cache::lock('ticket-refund-submit-'.$resolution->id, 60);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->sendToGateway($resolution);
        } finally {
            $lock->release();
        }
    }

    private function sendToGateway(TicketResolution $resolution): void
    {
        $resolution = $resolution->fresh();
        if ($resolution->method !== TicketResolution::METHOD_GATEWAY || $resolution->status !== TicketResolution::PENDING || $resolution->gateway_refund_id !== null) {
            return;
        }
        if ($resolution->not_before && $resolution->not_before->isFuture()) {
            return;
        }

        $ticket = Ticket::with('payment')->findOrFail($resolution->ticket_id);
        $payment = $ticket->payment;
        // The fare part and, on the first refunded ticket of the purchase, the booking fee (Batch 45 Feature A).
        $amountCentavos = (int) round(((float) $resolution->amount + (float) $resolution->fee_amount) * 100);

        try {
            $payMongo = app(PayMongoService::class);
            $payId = $payment->gateway_payment_id ?: ($payment->gateway_reference ? $payMongo->findPaymentIdForCheckoutSession($payment->gateway_reference) : null);
            if (! $payId) {
                throw new \RuntimeException('The payment could not be found at the payment provider.');
            }
            if ($payment->gateway_payment_id !== $payId) {
                $payment->forceFill(['gateway_payment_id' => $payId])->save();
            }

            $refund = $payMongo->createRefund(
                $payId,
                $amountCentavos,
                (string) config('refunds.gateway_reason'),
                'Operator-cancelled trip, ticket '.$ticket->ticket_uuid,
                ['resolution_id' => (string) $resolution->id, 'ticket_uuid' => (string) $ticket->ticket_uuid],
            );

            $resolution->update([
                'gateway_payment_id' => $payId,
                'gateway_refund_id' => $refund['id'] ?? null,
                'gateway_status' => $refund['attributes']['status'] ?? 'pending',
            ]);
            AuditLogger::log('ticket.refund_submitted', 'Ticket', (int) $ticket->ticket_id, ['resolution_id' => $resolution->id, 'amount' => $resolution->amount, 'gateway_refund_id' => $refund['id'] ?? null]);
        } catch (\Throwable $e) {
            Log::warning('Ticket refund could not be submitted', ['resolution_id' => $resolution->id, 'error' => $e->getMessage()]);
            $resolution->update(['status' => TicketResolution::FAILED, 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
            Ticket::whereKey($ticket->ticket_id)->update(['status' => self::REVIEW]);
            AuditLogger::log('ticket.refund_failed', 'Ticket', (int) $ticket->ticket_id, ['resolution_id' => $resolution->id, 'reason' => $e->getMessage()]);
        }
    }

    /** Deferred refunds whose waiting time is over (run by `refunds:release-deferred`). */
    public function releaseDeferred(): int
    {
        $count = 0;
        TicketResolution::where('method', TicketResolution::METHOD_GATEWAY)
            ->where('status', TicketResolution::PENDING)
            ->whereNull('gateway_refund_id')
            ->whereNotNull('not_before')
            ->where('not_before', '<=', Carbon::now('UTC')->format('Y-m-d H:i:s'))
            ->get()
            ->each(function (TicketResolution $resolution) use (&$count) {
                $this->submitToGateway($resolution);
                $count++;
            });

        return $count;
    }

    /**
     * The signed PayMongo refund event (payment.refunded / payment.refund.updated, one refund resource at a time; see
     * PayMongoController), or PayMongo's own answer about the refund (`refunds:reconcile`). The only thing that decides
     * a gateway refund's final state. Idempotent: a replay of the same event changes nothing, and a late "failed"
     * never undoes a success.
     *
     * 2026-11-04 (Batch 45, Todo R): matched by the refund id we stored when the refund was requested. The
     * `resolution_id` in the refund's metadata is a plain number another app on the same PayMongo account could also
     * send, so it is used only when the request's answer was lost (no refund id stored yet) and the refund is for the
     * same PayMongo payment. A refund that becomes successful emails the buyer once (RefundCompletedMailer).
     *
     * @param  array<string, mixed>  $refund  the refund resource
     */
    public function handleGatewayRefund(array $refund): bool
    {
        $refundId = $refund['id'] ?? null;
        $attributes = $refund['attributes'] ?? [];
        $status = strtolower((string) ($attributes['status'] ?? ''));
        $resolutionId = $attributes['metadata']['resolution_id'] ?? null;

        $resolution = $refundId ? TicketResolution::where('gateway_refund_id', $refundId)->first() : null;
        if (! $resolution && $resolutionId) {
            $candidate = TicketResolution::find((int) $resolutionId);
            $refundPaymentId = $attributes['payment_id'] ?? null;
            if ($candidate && $candidate->gateway_refund_id === null && is_string($refundPaymentId) && $refundPaymentId !== ''
                && $candidate->gateway_payment_id === $refundPaymentId) {
                $resolution = $candidate;
            }
        }
        if (! $resolution || $resolution->method !== TicketResolution::METHOD_GATEWAY) {
            Log::warning('PayMongo refund webhook: no matching refund.', ['refund_id' => $refundId]);

            return false;
        }

        $notify = false;
        DB::transaction(function () use ($resolution, $refundId, $status, $attributes, &$notify) {
            $locked = TicketResolution::whereKey($resolution->id)->lockForUpdate()->first();

            if ($locked->status === TicketResolution::SUCCEEDED) {
                return; // replay, or a late event after success: nothing to change
            }

            $fill = ['gateway_status' => $status ?: $locked->gateway_status];
            if ($refundId && $locked->gateway_refund_id === null) {
                $fill['gateway_refund_id'] = $refundId;
            }

            if ($status === 'succeeded') {
                $locked->update($fill + ['status' => TicketResolution::SUCCEEDED, 'failure_reason' => null, 'completed_at' => Carbon::now('UTC')]);
                Ticket::whereKey($locked->ticket_id)->update(['status' => self::REFUNDED]);
                AuditLogger::log('ticket.refund_succeeded', 'Ticket', (int) $locked->ticket_id, ['resolution_id' => $locked->id, 'amount' => $locked->amount, 'gateway_refund_id' => $locked->gateway_refund_id ?? $refundId]);
                $notify = true;
            } elseif ($status === 'failed') {
                $locked->update($fill + ['status' => TicketResolution::FAILED, 'failure_reason' => mb_substr((string) ($attributes['failed_message'] ?? $attributes['status_message'] ?? 'The payment provider could not complete the refund.'), 0, 255)]);
                Ticket::whereKey($locked->ticket_id)->update(['status' => self::REVIEW]);
                AuditLogger::log('ticket.refund_failed', 'Ticket', (int) $locked->ticket_id, ['resolution_id' => $locked->id, 'source' => 'webhook']);
            } else {
                $locked->update($fill);
            }
        });

        if ($notify) {
            $refundedTotal = (float) $resolution->amount + (float) $resolution->fee_amount;
            $this->notifyPassenger($resolution->ticket_id, 'Refund processed', 'Your refund of ₱'.number_format($refundedTotal, 2).' was sent back to your original payment method.', ['type' => 'ticket_refunded']);
            $ticket = Ticket::with('payment')->find($resolution->ticket_id);
            if ($ticket?->payment) {
                app(RefundCompletedMailer::class)->send($ticket->payment, $refundedTotal, $ticket);
            }
        }

        return true;
    }

    /**
     * 2026-11-04 (Batch 45, Todo R), run by `refunds:reconcile`: asks PayMongo about gateway refunds still pending
     * `refunds.reconcile.after_minutes` after they were requested, and settles them through handleGatewayRefund (same
     * rules as the event). One still pending after `refunds.reconcile.give_up_hours` goes to the Operator's review
     * queue. Returns how many were settled and how many were handed to review.
     *
     * @return array{settled: int, review: int}
     */
    public function reconcilePending(): array
    {
        $now = Carbon::now('UTC');
        $after = $now->copy()->subMinutes(max(0, (int) config('refunds.reconcile.after_minutes', 10)));
        $giveUp = $now->copy()->subHours(max(1, (int) config('refunds.reconcile.give_up_hours', 72)));
        $settled = 0;
        $review = 0;

        TicketResolution::where('method', TicketResolution::METHOD_GATEWAY)
            ->where('status', TicketResolution::PENDING)
            ->whereNotNull('gateway_refund_id')
            ->where('requested_at', '<=', $after->format('Y-m-d H:i:s'))
            ->orderBy('id')
            ->get()
            ->each(function (TicketResolution $resolution) use ($giveUp, &$settled, &$review) {
                $refund = app(PayMongoService::class)->retrieveRefund((string) $resolution->gateway_refund_id);
                $status = strtolower((string) ($refund['attributes']['status'] ?? ''));
                if ($refund && in_array($status, ['succeeded', 'failed'], true) && ($refund['id'] ?? null) === $resolution->gateway_refund_id) {
                    $this->handleGatewayRefund($refund);
                    $settled++;

                    return;
                }

                if ($resolution->requested_at && $resolution->requested_at->lte($giveUp)) {
                    $reason = 'The payment provider has not finished this refund after '.(int) config('refunds.reconcile.give_up_hours', 72).' hours. Check it in PayMongo.';
                    DB::transaction(function () use ($resolution, $reason) {
                        $locked = TicketResolution::whereKey($resolution->id)->lockForUpdate()->first();
                        if ($locked->status !== TicketResolution::PENDING) {
                            return;
                        }
                        $locked->update(['status' => TicketResolution::FAILED, 'failure_reason' => $reason]);
                        Ticket::whereKey($locked->ticket_id)->update(['status' => self::REVIEW]);
                        AuditLogger::log('ticket.refund_failed', 'Ticket', (int) $locked->ticket_id, ['resolution_id' => $locked->id, 'source' => 'reconcile_timeout']);
                    });
                    $review++;
                }
            });

        return ['settled' => $settled, 'review' => $review];
    }

    /**
     * The amount of the refund and how it was worked out. Server-side only; the existing fare service does the pricing.
     *
     * @return array{amount: float|null, fare_paid: float, ridden_fare: float|null, needs_review: bool, review_reason: string|null, basis: array<string, mixed>}
     */
    public function quote(Ticket $ticket, TripCancellation $cancellation): array
    {
        $ticket->loadMissing(['trip.fleetRoute', 'payment', 'fareRule']);
        $paid = $this->farePaid($ticket);
        $base = ['fare_paid' => $paid, 'ridden_fare' => null, 'needs_review' => false, 'review_reason' => null];

        $boarded = $ticket->boarded_at !== null;
        // Cancelled before the bus left: nobody has ridden anywhere, boarded (a cash sale on the bus is) or not.
        if (! $boarded || $cancellation->stage === TripCancellation::STAGE_BEFORE_DEPARTURE) {
            return array_merge($base, ['amount' => $paid, 'ridden_fare' => 0.0, 'basis' => ['type' => 'full_fare', 'stage' => $cancellation->stage, 'boarded' => $boarded]]);
        }

        // Boarded on a trip cancelled mid-route: the fare for the part already travelled stays with the operator.
        // 2026-11-13 (user): when the travelled part cannot be priced, a fixed share of the fare
        // (config refunds.unpriced_deduction_percent, default 10) is kept and the rest is refunded at once, so no
        // Operator has to step in. With the setting empty, the ticket goes to Operator review as before.
        $review = function (string $reason) use ($base, $paid, $cancellation): array {
            $percent = self::unpricedDeductionPercent();
            $amount = $this->flatDeductionAmount($paid);
            if ($percent === null || $amount === null) {
                return array_merge($base, ['amount' => null, 'basis' => ['type' => 'partial', 'stage' => $cancellation->stage, 'boarded' => true], 'needs_review' => true, 'review_reason' => $reason]);
            }

            return array_merge($base, [
                'amount' => $amount,
                'ridden_fare' => round($paid - $amount, 2),
                'basis' => ['type' => 'flat_deduction', 'stage' => $cancellation->stage, 'boarded' => true, 'deduction_percent' => $percent, 'reason' => $reason],
            ]);
        };

        if ($cancellation->last_point_distance_m === null) {
            return $review('The point the bus had reached is not known (no stop acknowledged and no usable GPS position), so the travelled part cannot be priced.');
        }
        $boardingM = $ticket->boarding_distance_m;
        if ($boardingM === null && $ticket->origin_stop_id) {
            $routeStop = $this->routeStops->findByRouteAndStop((int) $ticket->trip->fleetRoute->route_id, (int) $ticket->origin_stop_id);
            $boardingM = $routeStop ? $this->meters($routeStop) : null;
        }
        if ($boardingM === null) {
            return $review('The ticket has no recorded boarding point.');
        }
        $rule = $ticket->fareRule;
        if (! $rule) {
            return $review('The ticket has no fare rule on record, so the travelled part cannot be priced.');
        }

        $direction = LegDistance::direction($ticket->trip->leg_direction);
        $reachedM = (int) $cancellation->last_point_distance_m;
        $alightingM = $ticket->alighting_distance_m;
        if ($alightingM === null && $ticket->destination_stop_id) {
            $destination = $this->routeStops->findByRouteAndStop((int) $ticket->trip->fleetRoute->route_id, (int) $ticket->destination_stop_id);
            $alightingM = $destination ? $this->meters($destination) : null;
        }
        if ($alightingM !== null) { // never more than the ticket's own journey
            $reachedM = $direction === 'reverse' ? max($reachedM, (int) $alightingM) : min($reachedM, (int) $alightingM);
        }

        $riddenKm = max(0.0, LegDistance::alongLegKm($boardingM / 1000, $reachedM / 1000, $direction));
        $ridden = $riddenKm > 0 ? $this->fares->priceWithRule($rule, $riddenKm) : 0.0;
        $amount = min($paid, max(0.0, $this->fares->roundAmount($paid - $ridden)));

        return array_merge($base, [
            'amount' => $amount,
            'ridden_fare' => $ridden,
            'basis' => [
                'type' => 'partial',
                'stage' => $cancellation->stage,
                'boarded' => true,
                'boarding_distance_m' => (int) $boardingM,
                'last_point_distance_m' => (int) $cancellation->last_point_distance_m,
                'last_point_source' => $cancellation->last_point_source,
                'ridden_km' => round($riddenKm, 3),
                'fare_rule_id' => (int) $rule->fare_rule_id,
            ],
        ]);
    }

    /**
     * The refund when the travelled part cannot be priced: the fare paid less config refunds.unpriced_deduction_percent,
     * whole pesos, or null when that setting is empty. Used by quote() and as the suggested amount on a review.
     */
    public function flatDeductionAmount(float $paid): ?float
    {
        $percent = self::unpricedDeductionPercent();
        if ($percent === null) {
            return null;
        }

        return min($paid, max(0.0, $this->fares->roundAmount($paid * (100 - $percent) / 100)));
    }

    /** config refunds.unpriced_deduction_percent: the share kept when the travelled part cannot be priced; null = Operator review. */
    public static function unpricedDeductionPercent(): ?float
    {
        $value = config('refunds.unpriced_deduction_percent');
        if ($value === null || $value === '') {
            return null;
        }

        return max(0.0, min(100.0, (float) $value));
    }

    /** The share of the payment this ticket accounts for: its fare, scaled down if reward points lowered the payment. */
    public function farePaid(Ticket $ticket): float
    {
        $payment = $ticket->payment;
        $fare = (float) $ticket->amount;
        if (! $payment) {
            return $fare;
        }

        // A rebooked ticket's replacement shares the payment: count each journey once.
        $sum = (float) Ticket::where('payment_id', $payment->payment_id)->where('status', '!=', self::REBOOKED)->sum('amount');
        if ($sum > 0 && (float) $payment->amount < $sum - 0.005) {
            return round($fare * (float) $payment->amount / $sum, 2);
        }

        return $fare;
    }

    /** @return array{configured: bool, open: bool, closes_at: string|null} */
    public function deadline(?TripCancellation $cancellation): array
    {
        $days = config('refunds.choice_deadline_days');
        if ($days === null || ! $cancellation?->cancelled_at) {
            return ['configured' => false, 'open' => true, 'closes_at' => null];
        }
        $closes = $cancellation->cancelled_at->copy()->addDays((int) $days);

        return ['configured' => true, 'open' => Carbon::now('UTC')->lt($closes), 'closes_at' => $closes->toIso8601ZuluString()];
    }

    public function methodFor(Ticket $ticket): string
    {
        $ticket->loadMissing('payment');

        return $ticket->payment?->payment_method === 'online' ? TicketResolution::METHOD_GATEWAY : TicketResolution::METHOD_CASH;
    }

    /** @return array<string, mixed> */
    /**
     * 2026-11-08 (user): every refund of a ticket on a cancelled trip, newest first, for the Refunds tab ("Cancelled
     * trips"): in progress, done, failed or waiting for review. Read only. $companyUserId null = every company (Admin).
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function refundHistory(?int $companyUserId, int $page, int $perPage = 20): array
    {
        $paginator = $this->refundQuery($companyUserId)->paginate($perPage, ['*'], 'page', max(1, $page));

        return [
            'data' => collect($paginator->items())->map(fn (TicketResolution $resolution) => $this->presentRefund($resolution))->values()->all(),
            'meta' => ['page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        ];
    }

    /** 2026-11-08: every cancelled-trip ticket refund, unpaged, for the Refunds tab's one table (RefundListService). */
    public function refundRows(?int $companyUserId): array
    {
        return $this->refundQuery($companyUserId)->get()->map(fn (TicketResolution $resolution) => $this->presentRefund($resolution))->values()->all();
    }

    private function refundQuery(?int $companyUserId)
    {
        return TicketResolution::with(['ticket.trip.fleetRoute.route', 'ticket.passenger', 'ticket.payment'])
            ->where('resolution', TicketResolution::REFUND)
            ->when($companyUserId !== null, fn ($q) => $q->whereHas('ticket.trip', fn ($t) => CompanyScope::whereMadeByCompany($t, 'trips.company_user_id', $companyUserId)))
            ->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    private function presentRefund(TicketResolution $resolution): array
    {
        $ticket = $resolution->ticket;

        return [
            'id' => (int) $resolution->id,
            'ticket_uuid' => $ticket?->ticket_uuid,
            'route_name' => $ticket?->trip?->fleetRoute?->route?->route_name,
            'trip_date' => $ticket?->trip?->trip_date?->toDateString(),
            'departure_time' => $ticket?->trip?->departure_time ? substr((string) $ticket->trip->departure_time, 0, 5) : null,
            'passenger_name' => $ticket?->passenger?->name,
            'guest_email' => $ticket?->payment?->guest_email,
            'transaction_reference' => $ticket?->payment?->transaction_reference,
            'gateway_refund_id' => $resolution->gateway_refund_id,
        ] + $this->presentResolution($resolution);
    }

    public function presentResolution(TicketResolution $resolution): array
    {
        return [
            'id' => (int) $resolution->id,
            'resolution' => $resolution->resolution,
            'status' => $resolution->status,
            'method' => $resolution->method,
            'amount' => $resolution->amount,
            // 2026-11-14 (user): the fare paid, and for a refund waiting for review the amount the 90 percent rule gives,
            // so the Operator's Set amount form opens filled in (they can still change it).
            'fare_paid' => $resolution->fare_paid !== null ? (float) $resolution->fare_paid : null,
            'suggested_amount' => $resolution->status === TicketResolution::NEEDS_REVIEW && $resolution->fare_paid !== null
                ? $this->flatDeductionAmount((float) $resolution->fare_paid)
                : null,
            // Batch 45 Feature A: the booking fee given back with this refund (0 when this ticket did not carry it).
            'fee_amount' => (float) $resolution->fee_amount,
            'on_behalf' => (bool) $resolution->on_behalf,
            'actor_role' => $resolution->actor_role,
            'rebooked_ticket_uuid' => $resolution->rebooked_ticket_id ? Ticket::whereKey($resolution->rebooked_ticket_id)->value('ticket_uuid') : null,
            'failure_reason' => $resolution->failure_reason,
            'waiting_until' => $resolution->not_before?->toIso8601ZuluString(),
            'requested_at' => $resolution->requested_at?->toIso8601ZuluString(),
            'completed_at' => $resolution->completed_at?->toIso8601ZuluString(),
        ];
    }

    // ----------------------------------------------------------------------------------------------------------------

    private function rebook(Ticket $ticket, TripCancellation $cancellation, array $input, array $actor): TicketResolution
    {
        if ($ticket->boarded_at !== null) {
            throw ValidationException::withMessages(['choice' => ['A ticket already used on the trip can only be refunded for the part not travelled.']]);
        }

        $options = $this->resolver->rebookOptions($ticket, 100);
        if ($options === []) {
            throw ValidationException::withMessages(['trip_id' => ['No later departure with room is available on this route. You can still ask for a refund.']]);
        }
        $pick = isset($input['trip_id'])
            ? collect($options)->firstWhere('trip_id', (int) $input['trip_id'])
            : $options[0];
        if (! $pick) {
            throw ValidationException::withMessages(['trip_id' => ['That departure is not available for this ticket.']]);
        }

        $target = Trip::with('fleetRoute.fleet')->findOrFail($pick['trip_id']);
        $this->tripService->recordBoarding((int) $target->trip_id, (string) $ticket->seat_type); // takes one place: "This trip is full" if none is left

        $credentials = $this->tracking->newCredentials();
        $replacement = $this->tickets->create([
            'tracking_token_hash' => $credentials['tracking_token_hash'],
            'tracking_token_enc' => $credentials['tracking_token_enc'],
            'fleet_route_id' => $target->fleet_route_id,
            'trip_id' => $target->trip_id,
            'fare_rule_id' => $ticket->fare_rule_id,
            'rounding_mode' => $ticket->rounding_mode,
            'journey_leg' => $ticket->journey_leg,
            'distance_km' => $ticket->distance_km,
            'payment_id' => $ticket->payment_id,
            'passenger_id' => $ticket->passenger_id,
            'status' => 'valid',
            'amount' => $ticket->amount, // no extra charge, whatever the new trip's fare would be
            'seat_type' => $ticket->seat_type,
            'origin_stop_id' => $ticket->origin_stop_id,
            'destination_stop_id' => $ticket->destination_stop_id,
            'boarding_distance_m' => $ticket->boarding_distance_m,
            'alighting_distance_m' => $ticket->alighting_distance_m,
        ]);

        $ticket->update(['status' => self::REBOOKED]);

        $resolution = TicketResolution::create([
            'ticket_id' => $ticket->ticket_id,
            'trip_cancellation_id' => $cancellation->id,
            'resolution' => TicketResolution::REBOOK,
            'status' => TicketResolution::SUCCEEDED,
            'actor_user_id' => $actor['user']?->user_id,
            'actor_role' => $actor['role'],
            'on_behalf' => $actor['on_behalf'],
            'note' => isset($input['note']) ? mb_substr((string) $input['note'], 0, 255) : null,
            'rebooked_ticket_id' => $replacement->ticket_id,
            'requested_at' => Carbon::now('UTC'),
            'completed_at' => Carbon::now('UTC'),
            'basis' => ['type' => 'rebook', 'from_trip_id' => (int) $ticket->trip_id, 'to_trip_id' => (int) $target->trip_id],
        ]);

        AuditLogger::log('ticket.rebooked', 'Ticket', (int) $ticket->ticket_id, [
            'resolution_id' => $resolution->id,
            'to_ticket_id' => (int) $replacement->ticket_id,
            'to_trip_id' => (int) $target->trip_id,
            'actor_role' => $actor['role'],
            'on_behalf' => $actor['on_behalf'],
        ]);

        $this->notifyPassenger(
            $ticket->ticket_id,
            'Ticket rebooked',
            "Your ticket was moved to {$pick['route_name']} on {$pick['trip_date']} at {$pick['boarding_time']}. No extra charge.",
            ['type' => 'ticket_rebooked', 'ticket_uuid' => (string) $replacement->ticket_uuid],
        );

        return $resolution;
    }

    /**
     * Batch 45 Feature A: the Operator cancelled the trip, so the booking fee of the purchase is given back in full,
     * once: with the first refunded ticket of that payment. The payment row is locked so two tickets of one purchase
     * refunded at the same moment cannot both carry it.
     */
    private function bookingFeeToRefund(Ticket $ticket): float
    {
        $payment = Payment::whereKey($ticket->payment_id)->lockForUpdate()->first();
        $fee = (float) ($payment->fee_amount ?? 0);
        if ($fee <= 0) {
            return 0.0;
        }

        $alreadyGiven = TicketResolution::whereIn('ticket_id', Ticket::where('payment_id', $payment->payment_id)->select('ticket_id'))
            ->where('fee_amount', '>', 0)
            ->exists();

        return $alreadyGiven ? 0.0 : $fee;
    }

    private function startRefund(Ticket $ticket, TripCancellation $cancellation, array $input, array $actor): TicketResolution
    {
        $ticket->loadMissing('payment');
        $method = $this->methodFor($ticket);

        // A terminal (staff) action never pays out cash for a digital ticket.
        if (($input['method'] ?? null) === TicketResolution::METHOD_CASH && $method === TicketResolution::METHOD_GATEWAY) {
            throw ValidationException::withMessages(['method' => ['A digital ticket can only be refunded to its original payment method, never in cash.']]);
        }

        $quote = $this->quote($ticket, $cancellation);
        $resolution = TicketResolution::create([
            'ticket_id' => $ticket->ticket_id,
            'trip_cancellation_id' => $cancellation->id,
            'resolution' => TicketResolution::REFUND,
            'status' => $quote['needs_review'] ? TicketResolution::NEEDS_REVIEW : TicketResolution::PENDING,
            'method' => $method,
            // Batch 29: a cash refund reduces the cash of the Conductor shift that SOLD the ticket, however late it is paid out.
            'conductor_shift_log_id' => $method === TicketResolution::METHOD_CASH ? $this->shiftCash->shiftIdForTicket((int) $ticket->ticket_id) : null,
            'amount' => $quote['amount'],
            'fee_amount' => $method === TicketResolution::METHOD_GATEWAY ? $this->bookingFeeToRefund($ticket) : 0,
            'fare_paid' => $quote['fare_paid'],
            'ridden_fare' => $quote['ridden_fare'],
            'basis' => $quote['basis'] + ($quote['needs_review'] ? ['review_reason' => $quote['review_reason']] : []),
            'actor_user_id' => $actor['user']?->user_id,
            'actor_role' => $actor['role'],
            'on_behalf' => $actor['on_behalf'],
            'note' => isset($input['note']) ? mb_substr((string) $input['note'], 0, 255) : null,
            'requested_at' => Carbon::now('UTC'),
        ]);

        AuditLogger::log('ticket.refund_requested', 'Ticket', (int) $ticket->ticket_id, [
            'resolution_id' => $resolution->id,
            'method' => $method,
            'amount' => $quote['amount'],
            'needs_review' => $quote['needs_review'],
            'actor_role' => $actor['role'],
            'on_behalf' => $actor['on_behalf'],
        ]);

        if ($quote['needs_review']) {
            $ticket->update(['status' => self::REVIEW]);

            return $resolution;
        }

        $this->proceedWithAmount($resolution, $ticket, (float) $quote['amount'], $actor['on_behalf']);

        return $resolution;
    }

    /**
     * Moves a refund with a known amount forward. Returns true when a gateway call should follow once the
     * surrounding transaction has committed.
     */
    private function proceedWithAmount(TicketResolution $resolution, Ticket $ticket, float $amount, bool $staffActed): bool
    {
        if ($amount <= 0) {
            $resolution->update(['status' => TicketResolution::SUCCEEDED, 'completed_at' => Carbon::now('UTC'), 'note' => $resolution->note ?: 'Nothing to refund: the travelled part covers the whole fare.']);
            $ticket->update(['status' => self::REFUNDED]);

            return false;
        }

        if ($resolution->method === TicketResolution::METHOD_CASH) {
            if ($staffActed) {
                // Staff acting at the counter record the payout in the same step (who, when, how much).
                $resolution->update(['status' => TicketResolution::SUCCEEDED, 'completed_at' => Carbon::now('UTC')]);
                $ticket->update(['status' => self::REFUNDED]);
                AuditLogger::log('ticket.cash_refund_recorded', 'Ticket', (int) $ticket->ticket_id, ['resolution_id' => $resolution->id, 'amount' => $amount]);
            } else {
                $resolution->update(['status' => TicketResolution::PENDING]);
                $ticket->update(['status' => self::PENDING]);
            }

            return false;
        }

        $resolution->update(['status' => TicketResolution::PENDING, 'not_before' => $this->waitUntil($ticket->payment, $amount, $resolution)]);
        $ticket->update(['status' => self::PENDING]);

        return true;
    }

    /** Maya accepts only a FULL refund on the day of the payment; a partial one waits for the next Manila midnight. */
    private function waitUntil(?Payment $payment, float $amount, TicketResolution $resolution): ?Carbon
    {
        if (! $payment || strtolower((string) $payment->payment_channel) !== 'maya') {
            return null;
        }

        $tz = config('app.timezone', 'Asia/Manila');
        $paidOn = Carbon::parse($payment->payment_created ?? $payment->created_at, $tz);
        if (! $paidOn->isSameDay(Carbon::now($tz))) {
            return null;
        }

        $alreadyRefunded = (float) TicketResolution::where('method', TicketResolution::METHOD_GATEWAY)
            ->where('id', '!=', $resolution->id)
            ->whereIn('status', [TicketResolution::PENDING, TicketResolution::SUCCEEDED])
            ->whereHas('ticket', fn ($q) => $q->where('payment_id', $payment->payment_id))
            ->sum('amount');
        $isFull = $amount >= (float) $payment->amount - $alreadyRefunded - 0.005 && $alreadyRefunded < 0.005;

        return $isFull ? null : Carbon::now($tz)->addDay()->startOfDay()->utc();
    }

    private function meters(RouteStop $routeStop): int
    {
        return $routeStop->distance_from_origin_m !== null
            ? (int) $routeStop->distance_from_origin_m
            : LegDistance::toMeters((float) $routeStop->distance_from_origin_km);
    }

    private function notifyPassenger(int $ticketId, string $title, string $body, array $data): void
    {
        $user = Ticket::with('passenger.user')->find($ticketId)?->passenger?->user;
        if ($user) {
            $this->fcm->sendToUser($user, $title, $body, $data);
        }
    }
}
