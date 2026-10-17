<?php

namespace App\Jobs;

use App\Services\RouteGeometryGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class GenerateRouteGeometry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $routeId) {}

    public function handle(RouteGeometryGenerator $generator): void
    {
        try {
            $generator->generate($this->routeId);
        } finally {
            Cache::forget(RouteGeometryGenerator::pendingCacheKey($this->routeId));
        }
    }
}
