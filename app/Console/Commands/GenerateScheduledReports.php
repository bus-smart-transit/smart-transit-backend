<?php

namespace App\Console\Commands;

use App\Services\ReportingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GenerateScheduledReports extends Command
{
    protected $signature   = 'reports:generate
                                {--date=        : Date to generate reports for (Y-m-d). Defaults to yesterday.}
                                {--fleet_id=    : Only generate for one fleet. Defaults to all fleets.}
                                {--type=        : Report type: daily_summary|financial|occupancy_trends|revenue_by_route. Defaults to all.}';

    protected $description = 'Generate and store daily fleet reports in the scheduled_reports table.';

    private const TYPES = ['daily_summary', 'financial', 'occupancy_trends', 'revenue_by_route'];

    public function __construct(private ReportingService $reportingService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $date    = $this->option('date')     ?? Carbon::yesterday()->toDateString();
        $fleetId = $this->option('fleet_id') ? (int) $this->option('fleet_id') : null;
        $types   = $this->option('type')
            ? [trim($this->option('type'))]
            : self::TYPES;

        $fleetIds = $fleetId
            ? [$fleetId]
            : DB::table('fleets')->pluck('fleet_id')->toArray();

        if (empty($fleetIds)) {
            $this->warn('No fleets found.');
            return self::SUCCESS;
        }

        $generated = 0;
        $failed    = 0;

        foreach ($fleetIds as $fid) {
            foreach ($types as $type) {
                try {
                    $payload = $this->runReport($type, (int) $fid, $date);

                    DB::table('scheduled_reports')->updateOrInsert(
                        ['fleet_id' => $fid, 'report_type' => $type, 'report_date' => $date],
                        [
                            'payload'      => json_encode($payload),
                            'status'       => 'generated',
                            'error_message' => null,
                            'generated_at' => now(),
                        ]
                    );

                    $generated++;
                    $this->line("  ✓ fleet={$fid} type={$type} date={$date}");
                } catch (\Throwable $e) {
                    DB::table('scheduled_reports')->updateOrInsert(
                        ['fleet_id' => $fid, 'report_type' => $type, 'report_date' => $date],
                        [
                            'payload'       => json_encode([]),
                            'status'        => 'failed',
                            'error_message' => $e->getMessage(),
                            'generated_at'  => now(),
                        ]
                    );

                    $failed++;
                    $this->error("  ✗ fleet={$fid} type={$type}: " . $e->getMessage());
                }
            }
        }

        $this->info("Done. Generated: {$generated}, Failed: {$failed}");
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function runReport(string $type, int $fleetId, string $date): array
    {
        return match ($type) {
            'daily_summary'    => $this->reportingService->getDailySummary($fleetId, $date),
            'financial'        => $this->reportingService->getFinancialAudit($fleetId, $date, $date),
            'occupancy_trends' => $this->reportingService->getOccupancyTrends($fleetId, $date, $date),
            'revenue_by_route' => $this->reportingService->getRevenueByRoute($fleetId, $date, $date),
            default            => throw new \InvalidArgumentException("Unknown report type: {$type}"),
        };
    }
}
