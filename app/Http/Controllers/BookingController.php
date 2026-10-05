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
        $result['meta'] = \App\Support\BookingWindow::describe() + [
            'home_preview_limit' => (int) config('booking.home_preview_limit'),
            'trips_per_page' => (int) config('booking.trips_per_page'),
        ];

        return $this->success($result, 'Booking stops retrieved successfully');
    }

    /**
     * Batch 29, issue 4: the booking window alone (today, how many days ahead, the last bookable date), the same values
     * the passenger date pickers get. The Operator's schedule form uses it to warn about a date passengers cannot book yet.
     */
    public function window()
    {
        return $this->success(\App\Support\BookingWindow::describe(), 'Booking window retrieved successfully');
    }

    // Available trips for a Manila date (default today), optionally for one journey, paginated.
    // The journey is given as readable stop codes (never ids) and is optional so the home preview
    // can list today's buses before anything is chosen. Bad codes or dates answer a friendly 422.
    public function trips(Request $request)
    {
        $data = $request->validate([
            'from' => 'nullable|string|max:64',
            'to' => 'nullable|string|max:64',
            'date' => 'nullable|string|max:10',
            'seat_type' => 'nullable|in:seated,standing',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.(int) config('booking.trips_max_per_page'),
        ]);

        $journey = $this->bookingLinkService->resolveJourney($data);
        $result = $this->tripResolverService->available([
            'origin_stop_id' => $journey['from']['stop_id'] ?? null,
            'destination_stop_id' => $journey['to']['stop_id'] ?? null,
            'date' => $journey['date'],
            'seat_type' => $data['seat_type'] ?? null,
        ], (int) ($data['page'] ?? 1), isset($data['per_page']) ? (int) $data['per_page'] : null);

        return $this->success($result + ['from' => $journey['from'], 'to' => $journey['to']], 'Trips retrieved');
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

    // Roundtrip (Batch 28, item 4): both legs resolved, with fares and bus labels, before anything is paid.
    public function roundtrip(Request $request)
    {
        $data = $request->validate([
            'origin_stop_id' => 'required|integer|exists:stops,stop_id',
            'destination_stop_id' => 'required|integer|different:origin_stop_id|exists:stops,stop_id',
            'outbound_mode' => 'required|in:now,later',
            'outbound_date' => 'nullable|date_format:Y-m-d|required_if:outbound_mode,later',
            'outbound_time' => 'nullable|date_format:H:i|required_if:outbound_mode,later',
            'return_date' => 'required|date_format:Y-m-d',
            'return_time' => 'required|date_format:H:i',
            'seat_type' => 'nullable|in:seated,standing',
        ]);

        return $this->success($this->tripResolverService->resolveRoundtrip($data), 'Roundtrip resolved');
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