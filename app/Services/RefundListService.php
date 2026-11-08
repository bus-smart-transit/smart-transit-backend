<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * 2026-11-08 (user): the Refunds tab is ONE table. It merges the two kinds of refund, each still owned where it was:
 *  - a payment that made no ticket (bus filled up, late payment) or whose checkout outcome is unclear
 *    (UnfulfilledPaymentService; one payment, refunded as a whole);
 *  - a ticket on a cancelled trip (TicketResolutionService; one row per ticket, because tickets of one purchase can end
 *    differently: refunded, partly refunded, rebooked).
 * Each row gets one plain status and says whether someone has to act. Read only: the actions stay on their own routes.
 */
class RefundListService
{
    public const STATUSES = ['all', 'needs_action', 'in_progress', 'refunded', 'failed'];

    public function __construct(
        private UnfulfilledPaymentService $payments,
        private TicketResolutionService $tickets,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function paginate(?int $companyUserId, string $status, int $page, int $perPage = 20): array
    {
        $rows = collect($this->payments->rows($companyUserId))->map(fn (array $row) => $this->fromPayment($row))
            ->concat(collect($this->tickets->refundRows($companyUserId))->map(fn (array $row) => $this->fromTicket($row)))
            ->sortByDesc(fn (array $row) => $row['date'] ?? '')
            ->values();

        $counts = ['all' => $rows->count()];
        foreach (array_slice(self::STATUSES, 1) as $key) {
            $counts[$key] = $rows->filter(fn (array $row) => $this->matches($row, $key))->count();
        }

        $filtered = $status === 'all' ? $rows : $rows->filter(fn (array $row) => $this->matches($row, $status))->values();
        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));

        return [
            'data' => $filtered->forPage($page, $perPage)->values()->all(),
            'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage, 'status' => $status, 'counts' => $counts],
        ];
    }

    private function matches(array $row, string $status): bool
    {
        return $status === 'needs_action' ? $row['needs_action'] : $row['status'] === $status;
    }

    /** @return array<string, mixed> */
    private function fromPayment(array $row): array
    {
        $unknown = $row['reconciliation_status'] === UnfulfilledPaymentService::OUTCOME_UNKNOWN;
        $status = match (true) {
            $unknown => 'check_paymongo',
            $row['status'] === UnfulfilledPaymentService::REFUNDED => 'refunded',
            (bool) ($row['automatic_refund_pending'] ?? false) => 'in_progress',
            default => 'needs_refund',
        };
        $trip = $row['trips'][0] ?? null;

        return [
            'key' => 'payment-'.$row['payment_id'],
            'kind' => 'payment',
            'date' => $this->iso($row['paid_at']),
            'transaction_reference' => $row['transaction_reference'],
            'passenger' => $row['payer']['is_guest'] ? 'Guest' : ($row['payer']['name'] ?: 'Passenger'),
            'email' => $row['payer']['email'],
            'trip' => $trip ? trim(($trip['route_name'] ?? 'Route unavailable').', '.($trip['trip_date'] ?? '').' '.substr((string) ($trip['departure_time'] ?? ''), 0, 5)) : null,
            'reason' => $unknown ? 'Checkout unclear' : 'Paid, but no ticket was made',
            'amount' => (float) $row['total_paid'],
            'status' => $status,
            'needs_action' => in_array($status, ['needs_refund', 'check_paymongo'], true),
            'detail' => $row,
        ];
    }

    /** @return array<string, mixed> */
    private function fromTicket(array $row): array
    {
        $status = match ($row['status']) {
            'succeeded' => 'refunded',
            'failed' => 'failed',
            'needs_review' => 'needs_review',
            default => 'in_progress',
        };

        return [
            'key' => 'ticket-'.$row['id'],
            'kind' => 'ticket',
            'date' => $this->iso($row['requested_at']),
            'transaction_reference' => $row['transaction_reference'],
            'passenger' => $row['passenger_name'] ?: ($row['guest_email'] ? 'Guest' : 'Passenger'),
            'email' => $row['guest_email'],
            'trip' => trim(($row['route_name'] ?? 'Route unavailable').', '.($row['trip_date'] ?? '').' '.($row['departure_time'] ?? '')),
            'reason' => 'Trip cancelled',
            'amount' => round((float) $row['amount'] + (float) $row['fee_amount'], 2),
            'status' => $status,
            'needs_action' => in_array($status, ['failed', 'needs_review'], true),
            'detail' => $row,
        ];
    }

    private function iso(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->utc()->toIso8601ZuluString() : null;
    }
}
