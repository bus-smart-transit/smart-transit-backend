<?php

namespace App\Http\Controllers;

use App\Http\Requests\TrafficSegmentRequest;
use App\Services\TrafficService;
use App\Traits\ApiResponse;

class TrafficController extends Controller
{
    use ApiResponse;

    public function __construct(private TrafficService $trafficService) {}

    // Driver, Chauffeur, Operator and passengers (guests too): live traffic for one segment. Always 200 for a valid
    // question; "available: false" with a reason when the provider cannot answer.
    public function segment(TrafficSegmentRequest $request)
    {
        $answer = $this->trafficService->segment(
            (float) $request->validated('from_lat'),
            (float) $request->validated('from_lng'),
            (float) $request->validated('to_lat'),
            (float) $request->validated('to_lng'),
        );

        return $this->success($answer, $answer['available'] ? 'Traffic retrieved' : 'Traffic unavailable');
    }
}
