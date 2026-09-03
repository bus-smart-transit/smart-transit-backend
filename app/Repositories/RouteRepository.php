<?php
namespace App\Repositories;
use App\Models\Route;
use Illuminate\Support\Collection;

class RouteRepository
{
    public function create(array $payload): Route
    {
        return Route::create($payload);
    }

    public function findById(int $routeId): ?Route
    {
        return Route::with([
            'routeStops' => function ($q) {
                $q->orderBy('stop_order')->with('stop');
            }
        ])->find($routeId);
    }

    public function findWithStops(int $routeId): ?Route
    {
        return Route::with('routeStops.stop')->find($routeId);
    }

    public function all(): Collection
    {
        return Route::with([
            'routeStops' => function ($q) {
                $q->orderBy('stop_order')->with('stop');
            }
        ])->get();
    }

    public function update(int $routeId, array $payload): bool
    {
        return Route::where('route_id', $routeId)->update($payload) > 0;
    }

    public function delete(int $routeId): bool
    {
        return Route::where('route_id', $routeId)->delete() > 0;
    }

    public function allWithOrderedStops(): Collection
    {
        return Route::with([
            'routeStops' => function ($q) {
                $q->orderBy('stop_order')->with('stop');
            }
        ])->get();
    }
}
