<?php

namespace App\Console\Commands;

use App\Models\Route;
use App\Repositories\RouteStopRepository;
use App\Services\TrafficService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only evidence for the live-traffic feature (limitation L-38): asks TomTom, through the same TrafficService the
 * apps use, about every stretch between two consecutive stops of a route, and prints what it said: the live time, the
 * time with no traffic, the delay and the label the apps would show. Run it at a busy hour (about 07:00-09:00 or
 * 17:00-19:00 Manila) and keep the output as the evidence that Moderate and Heavy readings occur.
 *
 * It spends real provider requests (one per stretch, answers are cached for the usual few minutes and counted against
 * the monthly budget), so it names the number it is about to use and `--dry` only counts them. The label thresholds
 * are the apps' own (frontend config/trafficConfig.js): extra time above 25 percent of the free-flow time is
 * Moderate, above 60 percent is Heavy, and under one minute of extra time is noise (Normal flow).
 */
class TrafficCheck extends Command
{
    protected $signature = 'traffic:check
        {route? : route_id (default: the first route that has stops)}
        {--direction=outbound : outbound or reverse}
        {--dry : only count the requests, do not call the provider}';

    protected $description = 'Read-only: show live traffic (TomTom) for each stretch between the stops of a route, with the label the apps would show.';

    private const MODERATE = 0.25;
    private const HEAVY = 0.6;
    private const MIN_DELAY_S = 60;

    public function handle(RouteStopRepository $routeStops, TrafficService $traffic): int
    {
        $route = $this->argument('route')
            ? Route::find((int) $this->argument('route'))
            : Route::whereIn('route_id', \DB::table('route_stop')->select('route_id'))->orderBy('route_id')->first();

        if (! $route) {
            $this->error('No such route, or no route has stops.');

            return self::FAILURE;
        }

        $direction = $this->option('direction') === 'reverse' ? 'reverse' : 'outbound';
        $stops = $routeStops->getOrderedStopsForDirection((int) $route->route_id, $direction)->values()
            ->filter(fn ($routeStop) => $routeStop->stop && $routeStop->stop->latitude !== null && $routeStop->stop->longitude !== null)
            ->values();

        $stretches = max(0, $stops->count() - 1);
        $this->line(sprintf('Route %d "%s", %s, %d stretches. Manila time now: %s.', $route->route_id, $route->route_name, $direction, $stretches, Carbon::now(config('app.timezone', 'Asia/Manila'))->format('Y-m-d H:i (l)')));

        if ($stretches === 0) {
            $this->warn('Fewer than two stops with coordinates: nothing to check.');

            return self::SUCCESS;
        }
        if ($this->option('dry')) {
            $this->info("Dry run: this would use up to {$stretches} provider requests (fewer where answers are cached).");

            return self::SUCCESS;
        }

        $rows = [];
        $counts = ['Normal flow' => 0, 'Moderate' => 0, 'Heavy' => 0, 'Unavailable' => 0];
        $totalDelay = 0;

        for ($i = 0; $i < $stretches; $i++) {
            $from = $stops[$i]->stop;
            $to = $stops[$i + 1]->stop;
            $answer = $traffic->segment((float) $from->latitude, (float) $from->longitude, (float) $to->latitude, (float) $to->longitude);

            if (! ($answer['available'] ?? false)) {
                $counts['Unavailable']++;
                $rows[] = [$from->stop_name.' -> '.$to->stop_name, '-', '-', '-', '-', 'Unavailable ('.($answer['reason'] ?? 'unknown').')'];
                usleep(300000);

                continue;
            }

            $live = (int) $answer['travel_time_s'];
            $free = $answer['no_traffic_s'] !== null ? (int) $answer['no_traffic_s'] : null;
            $delay = $free !== null ? max(0, $live - $free) : null;
            $label = $this->label($delay, $free);
            $counts[$label]++;
            $totalDelay += (int) $delay;

            $rows[] = [
                $from->stop_name.' -> '.$to->stop_name,
                round(((int) $answer['length_m']) / 1000, 1).' km',
                $this->minutes($live),
                $free !== null ? $this->minutes($free) : 'n/a',
                $delay !== null ? $this->minutes($delay) : 'n/a',
                $label.(($answer['cached'] ?? false) ? ' (cached)' : ''),
            ];
            usleep(300000); // the provider also limits requests per second
        }

        $this->table(['Stretch', 'Length', 'Live', 'No traffic', 'Extra', 'Label'], $rows);
        $this->line(sprintf(
            'Normal flow: %d, Moderate: %d, Heavy: %d, Unavailable: %d. Total extra time over the route: %s.',
            $counts['Normal flow'], $counts['Moderate'], $counts['Heavy'], $counts['Unavailable'], $this->minutes($totalDelay),
        ));

        return self::SUCCESS;
    }

    private function label(?int $delay, ?int $free): string
    {
        if ($delay === null || $free === null || $free <= 0 || $delay < self::MIN_DELAY_S) {
            return 'Normal flow';
        }

        $ratio = $delay / $free;

        return $ratio >= self::HEAVY ? 'Heavy' : ($ratio >= self::MODERATE ? 'Moderate' : 'Normal flow');
    }

    private function minutes(int $seconds): string
    {
        return $seconds < 60 ? $seconds.' s' : round($seconds / 60, 1).' min';
    }
}
