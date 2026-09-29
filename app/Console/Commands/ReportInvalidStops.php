<?php

namespace App\Console\Commands;

use App\Repositories\RouteGeometryRepository;
use App\Services\StopCoordinateValidator;
use App\Support\Geo\GeoLine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportInvalidStops extends Command
{
    protected $signature = 'stops:report-invalid {--route= : Only stops on this route_id}';

    protected $description = 'B2: list stops with null/zero/swapped/out-of-area, duplicated or off-route coordinates for manual correction (read-only).';

    public function handle(StopCoordinateValidator $validator, RouteGeometryRepository $geometry): int
    {
        $query = DB::table('stops as s')
            ->leftJoin('route_stop as rs', 'rs.stop_id', '=', 's.stop_id')
            ->leftJoin('routes as r', 'r.route_id', '=', 'rs.route_id')
            ->select('s.stop_id', 's.stop_name', 's.latitude', 's.longitude', 'rs.route_id', 'r.route_name', 'rs.stop_order', 'rs.stop_type')
            ->orderBy('rs.route_id')->orderBy('rs.stop_order');

        if ($this->option('route')) {
            $query->where('rs.route_id', (int) $this->option('route'));
        }

        $rows = $query->get();

        // Coordinate keys used by more than one stop_id => duplicates.
        $byCoord = [];
        foreach ($rows->unique('stop_id') as $row) {
            if ($row->latitude !== null && $row->longitude !== null) {
                $byCoord[round((float) $row->latitude, 5) . ',' . round((float) $row->longitude, 5)][] = $row->stop_id;
            }
        }

        $geometryCache = [];
        $report = [];
        $counts = [];

        foreach ($rows as $row) {
            $lat = $row->latitude !== null ? (float) $row->latitude : null;
            $lng = $row->longitude !== null ? (float) $row->longitude : null;

            $classification = 'valid';
            $detail = '';
            $offRouteM = null;

            $problems = $validator->problems($lat, $lng);
            if ($problems !== []) {
                $swappedOk = $lat !== null && $lng !== null && $validator->problems($lng, $lat) === [];
                $classification = ($lat === null || $lng === null || $lat == 0.0 || $lng == 0.0)
                    ? 'null_or_zero'
                    : ($swappedOk ? 'swapped' : 'out_of_area');
                $detail = $problems[0];
            } else {
                $key = round($lat, 5) . ',' . round($lng, 5);
                if (count($byCoord[$key] ?? []) > 1) {
                    $classification = 'duplicated';
                    $detail = 'Same coordinates as stop_id ' . implode(', ', array_diff($byCoord[$key], [$row->stop_id]));
                }

                if ($row->route_id) {
                    $geometryCache[$row->route_id] ??= $geometry->get((int) $row->route_id);
                    $line = $geometryCache[$row->route_id];
                    if ($line) {
                        $projection = GeoLine::project($line['coordinates'], $lat, $lng);
                        $offRouteM = $projection ? round($projection['distance_to_line_m'], 1) : null;
                        if ($offRouteM !== null && $offRouteM > (float) config('route_geometry.stop_off_route_report_m') && $classification === 'valid') {
                            $classification = 'off_route';
                            $detail = "{$offRouteM} m from the route line";
                        }
                    }
                }
            }

            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            $report[] = [
                $row->stop_id, $row->stop_name, $lat ?? 'null', $lng ?? 'null',
                $row->route_id ?? '-', $row->stop_order ?? '-', $row->stop_type ?? '-',
                $offRouteM ?? '-', $classification, $detail,
            ];
        }

        $this->table(
            ['stop_id', 'name', 'lat', 'lng', 'route_id', 'seq', 'type', 'off_route_m', 'class', 'detail'],
            $report
        );
        $this->info('summary: ' . json_encode($counts));
        $this->line('Stops are never modified by this command; correct them manually.');

        return self::SUCCESS;
    }
}
