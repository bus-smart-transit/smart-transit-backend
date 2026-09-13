<?php

namespace App\Http\Middleware;

use App\Services\TripService;
use App\Support\Database\RlsContext;
use Closure;
use Illuminate\Http\Request;

class ApplyDatabaseRlsContext
{
    public function __construct(
        private TripService $tripService,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('sanctum') ?: $request->user();

        RlsContext::applyForRequest($user);

        if ($user && in_array((string) $user->role, ['driver', 'conductor'], true)) {
            $activeTrip = $this->tripService->getCurrentTripForUser($user);
            RlsContext::setCurrentTripId($activeTrip?->trip_id ? (int) $activeTrip->trip_id : null);
        }

        if ($request->is('api/tickets/lookup')) {
            $paymentId = $request->query('payment_id');
            RlsContext::setGuestTicketLookup(
                $request->query('transaction_reference'),
                $request->query('email'),
                is_numeric($paymentId) ? (int) $paymentId : null,
            );
        }

        try {
            return $next($request);
        } finally {
            RlsContext::reset();
        }
    }
}
