<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProvinceRoutesRequest;
use App\Services\PublicRouteService;
use App\Traits\ApiResponse;

/**
 * 2026-11-20 (user): the passengers' route pages, without an account. GET routes?province= lists the routes serving a
 * province; GET routes/{id} is one route; GET routes/{id}/departures is today's departures on it. The route's stops and
 * line come from the existing public routes/{id}/stops and routes/{id}/geometry.
 */
class PublicRouteController extends Controller
{
    use ApiResponse;

    public function __construct(private PublicRouteService $routes) {}

    public function forProvince(ProvinceRoutesRequest $request)
    {
        $data = $this->routes->forProvince((string) $request->validated('province'));

        return $data === null
            ? $this->error('We do not serve that province.', 404)
            : $this->success($data, 'Routes retrieved');
    }

    public function show(int $routeId)
    {
        $route = $this->routes->summary($routeId);

        return $route === null ? $this->error('Route not found.', 404) : $this->success($route, 'Route retrieved');
    }

    public function departures(int $routeId)
    {
        if ($this->routes->summary($routeId) === null) {
            return $this->error('Route not found.', 404);
        }

        return $this->success(['departures' => $this->routes->departures($routeId)], 'Departures retrieved');
    }
}
