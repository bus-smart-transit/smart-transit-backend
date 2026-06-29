<?php

namespace App\Http\Controllers;

use App\Services\FareMatrixService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FareMatrixController extends Controller
{
    private FareMatrixService $fareMatrixService;

    public function __construct(FareMatrixService $fareMatrixService)
    {
        $this->fareMatrixService = $fareMatrixService;
    }

    public function index(Request $request)
    {
        return $this->fareMatrixService->listFareMatrix($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->fareMatrixService->createFareMatrix($request->all());
    }

    public function show(string $uuid)
    {
        return $this->fareMatrixService->getFareMatrix($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->fareMatrixService->updateFareMatrix($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->fareMatrixService->deleteFareMatrix($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->fareMatrixService->restoreFareMatrix($uuid);
    }
}