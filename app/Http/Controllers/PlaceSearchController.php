<?php

namespace App\Http\Controllers;

use App\Http\Requests\PlaceSearchSearchRequest;
use App\Services\PlaceSearchService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/** 2026-11-06 (Batch 47 part A): GET operator/places?q=...&mode=suggest|search, for "Add stop". Staff only. */
class PlaceSearchController extends Controller
{
    use ApiResponse;

    public function __construct(private PlaceSearchService $places) {}

    public function search(PlaceSearchSearchRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $found = $this->places->search($validated['q'], $validated['mode'] ?? 'suggest');

        return $this->success($found, $found['available'] ? 'Places found' : 'The place search is not available right now. Find the place on the map instead.');
    }
}
