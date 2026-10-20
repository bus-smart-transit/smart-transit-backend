<?php

namespace App\Jobs;

use App\Services\RouteGeometryGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class GenerateRouteGeometry implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 75;

    public int $uniqueFor = 900;

    public function __construct(public int $routeId) {}

    public function uniqueId(): string
    {
        return (string) $this->routeId;
    }

    public function handle(RouteGeometryGenerator $generator): void
    {
        $pendingKey = RouteGeometryGenerator::pendingCacheKey($this->routeId);
        $requestToken = Cache::get($pendingKey);
        $result = $generator->generate($this->routeId);
        if ($requestToken !== Cache::get($pendingKey)) {
            $this->release(max(0, (int) config('route_geometry.debounce_s', 5)));

            return;
        }

        if (! $result['ok'] && $result['retryable'] && $this->attempts() < $this->tries) {
            $delay = $this->backoff[$this->attempts() - 1] ?? $this->backoff[array_key_last($this->backoff)];
            $this->release($delay);

            return;
        }

        Cache::forget($pendingKey);
    }

    public function failed(?\Throwable $exception): void
    {
        Cache::forget(RouteGeometryGenerator::pendingCacheKey($this->routeId));
    }
}
