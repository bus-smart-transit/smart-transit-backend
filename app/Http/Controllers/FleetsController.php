<?php

namespace App\Http\Controllers;

use App\Services\FleetsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FleetsController extends Controller
{
    private FleetsService $fleetsService;

    public function __construct(FleetsService $fleetsService)
    {
        $this->fleetsService = $fleetsService;
    }

    public function index(Request $request)
    {
        return $this->fleetsService->listFleets($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->fleetsService->createFleets($request->all());
    }

    public function show(string $uuid)
    {
        return $this->fleetsService->getFleets($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->fleetsService->updateFleets($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->fleetsService->deleteFleets($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->fleetsService->restoreFleets($uuid);
    }
}