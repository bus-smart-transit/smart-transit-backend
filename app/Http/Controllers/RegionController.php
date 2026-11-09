<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegionPlaceRequest;
use App\Services\RegionPlaceService;
use App\Services\RegionService;
use App\Traits\ApiResponse;

/**
 * Public, read-only region configuration (name, centre, bounding box,
 * provinces and municipalities). No personal data.
 */
class RegionController extends Controller
{
    use ApiResponse;

    public function __construct(private RegionService $regionService) {}

    public function show()
    {
        return $this->success($this->regionService->toArray(), 'Region retrieved successfully');
    }

    /** Batch 51 item 3: the region town a point is in, for "Are you in {place}?"; null when unknown or outside. */
    public function place(RegionPlaceRequest $request, RegionPlaceService $places)
    {
        return $this->success($places->placeAt((float) $request->validated('lat'), (float) $request->validated('lng')), 'Place retrieved');
    }
}
