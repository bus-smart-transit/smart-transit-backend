<?php
namespace App\Services;
use App\Repositories\PaymentRepository;
use App\Repositories\OnlinePaymentRepository;
use App\Repositories\OnsitePaymentRepository;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private OnlinePaymentRepository $onlinePaymentRepository,
        private OnsitePaymentRepository $onsitePaymentRepository,
        private TicketService $ticketService,
    ) {
    }

    /**
     * $payload = ['items' => [...], 'payment_channel']
     * $payload['items'][*] = ['trip_id','seat_type','origin_stop_id','destination_stop_id']
     * Invariant: SUM(tickets.amount) === payments.amount — enforced after all tickets issued
     */
    public function checkoutOnline(int $passengerId, array $payload): array
    {
        return DB::transaction(function () use ($passengerId, $payload) {
            $payment = $this->paymentRepository->create([
                'amount' => 0,
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'online',
                'payment_channel' => $payload['payment_channel'],
                'status' => 'pending',
                'is_valid' => true,
            ]);

            $this->onlinePaymentRepository->create([
                'passenger_id' => $passengerId,
                'payment_id' => $payment->payment_id,
            ]);

            $total = 0;
            $tickets = [];

            foreach ($payload['items'] as $item) {
                $ticket = $this->ticketService->issueTicket($item, $passengerId, $payment->payment_id);
                $total += $ticket->amount;
                $tickets[] = $ticket;
            }

            $payment->amount = $total;
            $payment->save();

            return ['payment' => $payment, 'tickets' => $tickets];
        });
    }

    /**
     * $payload = ['items' => [...]]
     * $payload['items'][*] = ['trip_id','seat_type','origin_stop_id','destination_stop_id','passenger_id' nullable]
     */
    public function checkoutOnsite(int $conductorCompanyUserId, array $payload): array
    {
        return DB::transaction(function () use ($conductorCompanyUserId, $payload) {
            $payment = $this->paymentRepository->create([
                'amount' => 0,
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'cash',
                'payment_channel' => 'onsite',
                'status' => 'paid',
                'is_valid' => true,
            ]);

            $this->onsitePaymentRepository->create([
                'payment_id' => $payment->payment_id,
                'conductor_id' => $conductorCompanyUserId,
            ]);

            $total = 0;
            $tickets = [];

            foreach ($payload['items'] as $item) {
                $passengerId = $item['passenger_id'] ?? null;
                $ticket = $this->ticketService->issueTicket($item, $passengerId, $payment->payment_id);
                $total += $ticket->amount;
                $tickets[] = $ticket;
            }

            $payment->amount = $total;
            $payment->save();

            return ['payment' => $payment, 'tickets' => $tickets];
        });
    }

    public function confirmOnlinePayment(int $paymentId): bool
    {
        return $this->paymentRepository->markStatus($paymentId, 'paid', true);
    }
}
