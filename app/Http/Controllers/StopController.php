<?php

namespace App\Http\Controllers;

use App\Services\StopService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class StopController extends Controller
{
    private StopService $stopService;

    public function __construct(StopService $stopService)
    {
        $this->stopService = $stopService;
    }

    public function index(Request $request)
    {
        return $this->stopService->listStops($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->stopService->createStops($request->all());
    }

    public function show(string $uuid)
    {
        return $this->stopService->getStops($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->stopService->updateStops($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->stopService->deleteStops($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function restore(string $uuid)
    {
        return $this->stopService->restoreStops($uuid);
    }
}