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

    /**
     * Batch 49 item 5: the short code a passenger types at "Track my bus". A wrong code, and a real one that can no
     * longer be tracked, get the same answer. The reply is the ticket's tracking link token, which opens the same
     * tracking page (one tracking flow); it shows only that ticket's bus.
     */
    public function byCode(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:20']);
        $missKey = 'tracking-miss:'.$request->ip();
        if (RateLimiter::tooManyAttempts($missKey, (int) config('tracking.miss_limit'))) {
            return $this->error('Too many attempts. Please wait a few minutes.', 429);
        }

        $ticket = $this->trackingService->findTrackableByCode($data['code']);
        $token = $ticket ? $this->trackingService->tokenFor($ticket) : null;
        if (! $token) {
            RateLimiter::hit($missKey, (int) config('tracking.miss_window_minutes') * 60);

            return $this->error('We could not find a bus for that code. Check the code and try again.', 404);
        }

        return $this->success(['tracking_token' => $token], 'Tracking code found')
            ->withHeaders(['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer']);
    }

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
