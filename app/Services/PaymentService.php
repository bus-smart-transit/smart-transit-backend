<?php

namespace App\Services;

use App\Repositories\PaymentRepository;
use App\Repositories\OnlinePaymentRepository;
use App\Repositories\OnsitePaymentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    private PaymentRepository $paymentRepository;
    private OnlinePaymentRepository $onlinePaymentRepository;
    private OnsitePaymentRepository $onsitePaymentRepository;
    private TicketService $ticketService;
    public function __construct(
        PaymentRepository $paymentRepository,
        OnlinePaymentRepository $onlinePaymentRepository,
        OnsitePaymentRepository $onsitePaymentRepository,
        TicketService $ticketService,
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->onlinePaymentRepository = $onlinePaymentRepository;
        $this->onsitePaymentRepository = $onsitePaymentRepository;
        $this->ticketService = $ticketService;
    }

    /**
     * Checks out a cart of one or more tickets under a single transaction.
     * $cartItems: array of ['trip_id', 'seat_type', 'origin_stop_id', 'destination_stop_id']
     */
    public function checkoutOnline(int $passengerId, array $cartItems)
    {
        return DB::transaction(function () use ($passengerId, $cartItems) {
            $payment = $this->paymentRepository->create([
                'amount' => 0, // filled in after tickets are priced
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'online',
                'payment_channel' => 'gcash', // or whatever channel was selected
                'status' => 'pending',
                'is_valid' => true,
            ]);

            $this->onlinePaymentRepository->create([
                'passenger_id' => $passengerId,
                'payment_id' => $payment->payment_id,
            ]);

            $total = 0;
            $tickets = [];

            foreach ($cartItems as $item) {
                $ticket = $this->ticketService->issueTicket(
                    $item['trip_id'],
                    $passengerId,
                    $item['seat_type'],
                    $item['origin_stop_id'],
                    $item['destination_stop_id'],
                    $payment->payment_id,
                );
                $total += $ticket->amount;
                $tickets[] = $ticket;
            }

            // Invariant: sum(tickets.amount) must equal payments.amount — enforced here.
            $payment->amount = $total;
            $payment->save();

            return ['payment' => $payment, 'tickets' => $tickets];
        });
    }

    public function checkoutOnsite(int $conductorId, array $cartItems)
    {
        return DB::transaction(function () use ($conductorId, $cartItems) {
            $payment = $this->paymentRepository->create([
                'amount' => 0,
                'transaction_reference' => 'TXN-' . strtoupper(uniqid()),
                'payment_method' => 'cash',
                'payment_channel' => 'onsite',
                'status' => 'paid', // cash is collected immediately
                'is_valid' => true,
            ]);

            $this->onsitePaymentRepository->create([
                'payment_id' => $payment->payment_id,
                'conductor_id' => $conductorId,
            ]);

            $total = 0;
            $tickets = [];

            foreach ($cartItems as $item) {
                $ticket = $this->ticketService->issueTicket(
                    $item['trip_id'],
                    $item['passenger_id'] ?? null, // walk-up passengers may have no account
                    $item['seat_type'],
                    $item['origin_stop_id'],
                    $item['destination_stop_id'],
                    $payment->payment_id,
                );
                $total += $ticket->amount;
                $tickets[] = $ticket;
            }

            $payment->amount = $total;
            $payment->save();

            return ['payment' => $payment, 'tickets' => $tickets];
        });
    }

    public function confirmOnlinePayment(int $paymentId)
    {
        return $this->paymentRepository->markStatus($paymentId, 'paid', true);
    }
}
