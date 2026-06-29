<?php

namespace App\Http\Controllers;

use App\Services\TripService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TripController extends Controller
{
    private TripService $tripService;

    public function __construct(TripService $tripService)
    {
        $this->tripService = $tripService;
    }

    public function index(Request $request)
    {
        return $this->tripService->listTrip($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->tripService->createTrip($request->all());
    }

    public function show(string $uuid)
    {
        return $this->tripService->getTrip($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->tripService->updateTrip($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->tripService->deleteTrip($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->tripService->restoreTrip($uuid);
    }
}