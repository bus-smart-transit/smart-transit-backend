<?php

namespace App\Http\Controllers;

use App\Services\RouteService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RouteController extends Controller
{
    private RouteService $routeService;

    public function __construct(RouteService $routeService)
    {
        $this->routeService = $routeService;
    }

    public function index(Request $request)
    {
        return $this->routeService->listRoute($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->routeService->createRoute($request->all());
    }

    public function show(string $uuid)
    {
        return $this->routeService->getRoute($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->routeService->updateRoute($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->routeService->deleteRoute($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->routeService->restoreRoute($uuid);
    }
}