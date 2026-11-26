<?php

namespace App\Services;

use App\Models\BaggageCharge;
use App\Models\Ticket;
use App\Repositories\BaggageChargeRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\TicketRepository;
use App\Support\BaggageFee;
use App\Support\CompanyScope;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * 2026-11-25 (user): ONE owner of the on-board baggage payment. The Chauffeur sees the baggage, picks its size and takes
 * the cash; the price comes from the company's list (App\Support\BaggageFee). A payment belongs to a ticket on the
 * Chauffeur's current trip (a booked passenger, or one just sold for cash) and to the Chauffeur's open shift, so it is
 * part of the cash they hand over (ShiftCashService). Online, a passenger only says they bring baggage (has_baggage).
 * A payment taken by mistake, or handed back, is voided by the same Chauffeur during the same shift.
 */
class BaggageChargeService
{
    /** Ticket statuses that can still have baggage paid on board. */
    private const PAYABLE = ['valid', 'boarded'];

    public function __construct(
        private BaggageChargeRepository $charges,
        private TicketRepository $tickets,
        private CompanyRepository $companies,
        private TripService $trips,
        private ShiftCashService $shiftCash,
    ) {}

    /**
     * The price list for the Chauffeur's current bus.
     *
     * @return array<int, array<string, mixed>>
     */
    public function feesForConductor(int $conductorCompanyUserId): array
    {
        $trip = $this->trips->getCurrentTripForConductor($conductorCompanyUserId);

        return BaggageFee::listFor($trip ? $this->charges->companyIdForTrip((int) $trip->trip_id) : CompanyScope::companyIdOf($conductorCompanyUserId));
    }

    /** The Chauffeur records a baggage payment for a ticket on their current trip. */
    public function recordForTicket(int $conductorCompanyUserId, int $ticketId, string $size, ?string $stubNumber): BaggageCharge
    {
        $trip = $this->trips->getCurrentTripForConductor($conductorCompanyUserId);
        $ticket = $this->tickets->findById($ticketId);
        if (! $trip || ! $ticket || (int) $ticket->trip_id !== (int) $trip->trip_id) {
            throw ValidationException::withMessages(['ticket_id' => ['This ticket is not on your current trip.']]);
        }
        if (! in_array($ticket->status, self::PAYABLE, true)) {
            throw ValidationException::withMessages(['ticket_id' => ['This ticket can no longer be used, so no baggage can be added to it.']]);
        }

        return $this->record($conductorCompanyUserId, $ticket, $size, $stubNumber);
    }

    /** A baggage payment taken with a cash sale (the ticket was just issued on the Chauffeur's trip). */
    public function record(int $conductorCompanyUserId, Ticket $ticket, string $size, ?string $stubNumber): BaggageCharge
    {
        if (! BaggageFee::isSize($size)) {
            throw ValidationException::withMessages(['size' => ['Choose Small, Medium or Large.']]);
        }

        $charge = $this->charges->create([
            'trip_id' => (int) $ticket->trip_id,
            'ticket_id' => (int) $ticket->ticket_id,
            'conductor_company_user_id' => $conductorCompanyUserId,
            'conductor_shift_log_id' => $this->shiftCash->openShiftId($conductorCompanyUserId),
            'size' => $size,
            'amount' => BaggageFee::priceFor($this->charges->companyIdForTrip((int) $ticket->trip_id), $size),
            'stub_number' => $stubNumber !== null && trim($stubNumber) !== '' ? trim($stubNumber) : null,
            'collected_at' => now(),
        ]);

        // 2026-11-26: the ticket shows at least as many pieces as were paid for.
        $paidPieces = $this->charges->activeByTicket([(int) $ticket->ticket_id])->get((int) $ticket->ticket_id)?->count() ?? 1;
        if (! $ticket->has_baggage || (int) $ticket->baggage_count < $paidPieces) {
            $ticket->forceFill(['has_baggage' => true, 'baggage_count' => max((int) $ticket->baggage_count, $paidPieces)])->save();
        }

        return $charge;
    }

    /** The price of a size on a trip's bus (for the cash sale quote). */
    public function priceForTrip(int $tripId, string $size): float
    {
        return BaggageFee::priceFor($this->charges->companyIdForTrip($tripId), $size);
    }

    /** The Chauffeur takes back a payment they recorded in their open shift (a mistake, or the cash was handed back). */
    public function void(int $conductorCompanyUserId, int $chargeId): BaggageCharge
    {
        $charge = $this->charges->find($chargeId);
        if (! $charge || (int) $charge->conductor_company_user_id !== $conductorCompanyUserId) {
            throw ValidationException::withMessages(['baggage' => ['This baggage payment was not found.']]);
        }
        if ($charge->voided_at !== null) {
            return $charge;
        }
        $openShift = $this->shiftCash->openShiftId($conductorCompanyUserId);
        if ($charge->conductor_shift_log_id !== null && (int) $charge->conductor_shift_log_id !== $openShift) {
            throw ValidationException::withMessages(['baggage' => ['This baggage payment is from a shift that has ended. Ask your Operator.']]);
        }

        return $this->charges->markVoided($charge);
    }

    /**
     * The payments still counted on these tickets, ready for the screen, by ticket id.
     *
     * @param  array<int, int>  $ticketIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function presentForTickets(array $ticketIds): array
    {
        return $this->charges->activeByTicket($ticketIds)
            ->map(fn (Collection $rows) => $rows->map(fn (BaggageCharge $c) => $this->present($c))->values()->all())
            ->all();
    }

    /** @return array<string, mixed> */
    public function present(BaggageCharge $charge): array
    {
        return [
            'baggage_charge_id' => (int) $charge->baggage_charge_id,
            'ticket_id' => (int) $charge->ticket_id,
            'size' => $charge->size,
            'size_label' => BaggageFee::label($charge->size),
            'amount' => round((float) $charge->amount, 2),
            'stub_number' => $charge->stub_number,
            'collected_at' => $charge->collected_at?->toIso8601String(),
            'voided' => $charge->voided_at !== null,
        ];
    }

    /**
     * The Operator's price list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function feesForOperator(int $operatorCompanyUserId): array
    {
        return BaggageFee::listFor(CompanyScope::companyIdOf($operatorCompanyUserId));
    }

    /**
     * The Operator sets the company's price of each size.
     *
     * @param  array<string, float|int|string>  $prices  size key => price
     * @return array<int, array<string, mixed>>
     */
    public function updateFees(int $operatorCompanyUserId, array $prices): array
    {
        $companyId = CompanyScope::companyIdOf($operatorCompanyUserId);
        $company = $companyId ? $this->companies->find($companyId) : null;
        if (! $company) {
            throw ValidationException::withMessages(['small' => ['Your account is not linked to a bus company.']]);
        }

        $update = [];
        foreach (BaggageFee::sizeKeys() as $size) {
            if (array_key_exists($size, $prices)) {
                $update['baggage_fee_'.$size] = round((float) $prices[$size], 2);
            }
        }
        $company->forceFill($update)->save();

        return BaggageFee::listFor($companyId);
    }
}
