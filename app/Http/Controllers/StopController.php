<?php

namespace App\Http\Controllers;

use App\Services\StopsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StopsController extends Controller
{
    private StopsService $stopsService;

    public function __construct(StopsService $stopsService)
    {
        $this->stopsService = $stopsService;
    }

    public function index(Request $request)
    {
        return $this->stopsService->listStops($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->stopsService->createStops($request->all());
    }

    public function show(string $uuid)
    {
        return $this->stopsService->getStops($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->stopsService->updateStops($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->stopsService->deleteStops($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->stopsService->restoreStops($uuid);
    }
}