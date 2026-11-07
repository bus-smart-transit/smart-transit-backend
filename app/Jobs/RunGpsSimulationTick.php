<?php

namespace App\Jobs;

use App\Services\GpsSimulationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Batch 49 item 4: one step of a Super Admin GPS simulation, then the next one after
 * route_geometry.gps.simulator_tick_seconds. The chain id ties it to one start or resume, so a pause and a quick resume
 * never leave two chains moving the same bus. On the `sync` queue (tests) it runs one step and does not chain.
 */
class RunGpsSimulationTick implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public int $simulationId, public string $chain) {}

    public function handle(GpsSimulationService $simulator): void
    {
        if (! $simulator->tick($this->simulationId, $this->chain) || config('queue.default') === 'sync') {
            return;
        }

        self::dispatch($this->simulationId, $this->chain)
            ->delay(now()->addSeconds((int) config('route_geometry.gps.simulator_tick_seconds')));
    }
}
