<?php

namespace App\Http\Controllers;

use App\Services\TicketTrackingService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Batch 26: public, read-only bus tracking for one ticket. The token in the address is the only
 * credential. An unknown or malformed token answers 404 with the same message either way, and a
 * client that keeps guessing is slowed down. Responses are never cached or shared, and the token
 * is never written to any log by this code.
 */
class TrackingController extends Controller
{
    use ApiResponse;

    public function __construct(private TicketTrackingService $trackingService) {}

    public function show(Request $request, string $token)
    {
        $missKey = 'tracking-miss:'.$request->ip();
        if (RateLimiter::tooManyAttempts($missKey, (int) config('tracking.miss_limit'))) {
            return $this->error('Too many attempts. Please wait a few minutes.', 429);
        }

        $ticket = $this->trackingService->findByToken($token);
        if (! $ticket) {
            RateLimiter::hit($missKey, (int) config('tracking.miss_window_minutes') * 60);

            return $this->error('Tracking link not found.', 404);
        }

        return $this->success($this->trackingService->viewFor($ticket), 'Tracking retrieved')
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }
}
