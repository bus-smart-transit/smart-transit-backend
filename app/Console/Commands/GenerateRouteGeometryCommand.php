<?php

namespace App\Console\Commands;

use App\Models\Route;
use App\Services\RouteGeometryGenerator;
use Illuminate\Console\Command;

class GenerateRouteGeometryCommand extends Command
{
    protected $signature = 'routes:generate-geometry {route? : route_id (omit for all routes with 2+ stops)}';

    protected $description = 'Admin/background: (re)generate canonical route geometry through OpenRouteService. Keeps the previous geometry and flags geometry_stale on failure.';

    public function handle(RouteGeometryGenerator $generator): int
    {
        $routes = $this->argument('route')
            ? Route::where('route_id', (int) $this->argument('route'))->get()
            : Route::has('routeStops', '>=', 2)->get();

        $failed = 0;
        foreach ($routes as $route) {
            $result = $generator->generate((int) $route->route_id);
            $this->line(($result['ok'] ? 'ok    ' : 'FAILED') . " route {$route->route_id} ({$route->route_name}): {$result['message']}");
            $failed += $result['ok'] ? 0 : 1;
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
