<?php

namespace App\Http\Controllers;

use App\Services\AddressSearchService;
use App\Services\BookingLinkService;
use App\Services\BookingStopsService;
use App\Services\TripResolverService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Public, read-only passenger booking helpers: destinations computed from data
 * (C3), trip resolution for Book Now / Book Later (C1, C2, D3) and the address
 * search proxy for custom drop-off (D5). Nothing here creates or holds anything.
 */
class BookingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private BookingStopsService $bookingStopsService,
        private TripResolverService $tripResolverService,
        private AddressSearchService $addressSearchService,
        private BookingLinkService $bookingLinkService,
    ) {}

    // Checks the untrusted parts of a shared booking link (stop codes, date).
    public function link(Request $request)
    {
        return $this->success(
            $this->bookingLinkService->resolve($request->only(['from', 'to', 'date'])),
            'Booking link is valid'
        );
    }

    public function stops(Request $request)
    {
        $data = $request->validate(['origin_stop_id' => 'nullable|integer|min:1']);

        $result = $this->bookingStopsService->list(isset($data['origin_stop_id']) ? (int) $data['origin_stop_id'] : null);
        // What the date picker needs: today in Manila and how far ahead a date may be chosen.
        $result['meta'] = [
            'today' => Carbon::now(config('app.timezone', 'Asia/Manila'))->toDateString(),
            'max_advance_days' => (int) config('booking.max_advance_days'),
        ];

        return $this->success($result, 'Booking stops retrieved successfully');
    }

    // Book Later: the departures of one Manila date that fit the journey, to choose from.
    public function departures(Request $request)
    {
        $data = $request->validate([
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|different:origin_stop_id|exists:stops,stop_id',
            'date' => 'required|date_format:Y-m-d',
            'seat_type' => 'nullable|in:seated,standing',
        ]);

        return $this->success($this->tripResolverService->departures($data), 'Departures retrieved');
    }

    public function resolve(Request $request)
    {
        $data = $request->validate([
            'mode' => 'required|in:now,later',
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|different:origin_stop_id|exists:stops,stop_id',
            'date' => 'nullable|date_format:Y-m-d|required_if:mode,later',
            'time' => 'nullable|date_format:H:i|required_if:mode,later',
            'seat_type' => 'nullable|in:seated,standing',
        ]);

        return $this->success($this->tripResolverService->resolve($data), 'Trip resolved');
    }

    public function addressSearch(Request $request)
    {
        $data = $request->validate(['q' => 'required|string|min:'.(int) config('timeline.address_search.min_query_length').'|max:120']);

        $results = $this->addressSearchService->search($data['q']);
        if ($results === null) {
            return $this->error('Address search is unavailable right now. Drop the pin on the map instead.', 503);
        }

        return $this->success(['results' => $results], 'Address search results');
    }
}