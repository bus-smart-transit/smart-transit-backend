<?php

namespace App\Http\Controllers;

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
}
