<?php

namespace App\Http\Controllers;

use App\Services\PaymentService;
use App\Services\RewardService;
use App\Services\TicketService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class PassengerDashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TicketService $ticketService,
        private RewardService $rewardService,
        private PaymentService $paymentService,
    ) {
    }

    public function summary(Request $request)
    {
        $passenger = $request->user()?->passengerProfile;

        if (!$passenger) {
            return $this->error('Passenger profile not found.', 404);
        }

        $tickets = $this->ticketService->getPassengerTickets($passenger->passenger_id);
        $rewards = $this->rewardService->getHistory($passenger->passenger_id);
        $payments = $this->paymentService->getPassengerHistoryFromPayments($passenger->passenger_id);

        return $this->success([
            'profile' => $passenger,
            'tickets' => $tickets,
            'rewards' => $rewards,
            'payments' => $payments,
            'meta' => [
                'tickets_count' => $tickets->count(),
                'rewards_count' => $rewards->count(),
                'payments_count' => $payments->count(),
                'synced_at' => now()->toISOString(),
            ],
        ], 'Dashboard summary retrieved successfully');
    }
}
