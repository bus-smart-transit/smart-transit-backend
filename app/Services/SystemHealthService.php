<?php

namespace App\Services;

use App\Repositories\AdminOverviewRepository;
use App\Support\HealthBeat;
use Illuminate\Support\Carbon;

/**
 * Batch 48 Phase 6: the Super Admin's System health page. Read only, no secrets. Each line is "ok", "problem" or
 * "unknown" (nothing recorded yet, which is not the same as working) with a plain note.
 */
class SystemHealthService
{
    public function __construct(private AdminOverviewRepository $overview) {}

    /** The scheduler beats every minute; this long without a beat means it has stopped. */
    private const SCHEDULER_LATE_MINUTES = 3;

    /** A job waiting this long means the queue worker is stopped or stuck. */
    private const QUEUE_LATE_MINUTES = 10;

    private const LABELS = [
        'mail' => 'Email',
        'push' => 'Phone notifications',
        'routing' => 'Route drawing (OpenRouteService)',
        'traffic' => 'Traffic (TomTom)',
        'places' => 'Place search (Photon / Nominatim)',
        'payments' => 'Online payments (PayMongo)',
        // Batch 58: live updates over WebSocket (Laravel Reverb); screens fall back to polling when it fails.
        'reverb' => 'Live updates (WebSocket)',
    ];

    public function summary(): array
    {
        return [
            'checked_at' => now()->toIso8601String(),
            'core' => [$this->database(), $this->scheduler(), $this->queue()],
            'services' => collect(self::LABELS)->map(fn ($label, $part) => $this->lastResult($part, $label))->values()->all(),
        ];
    }

    private function database(): array
    {
        return $this->overview->databaseAnswers()
            ? ['key' => 'database', 'label' => 'Database', 'status' => 'ok', 'note' => 'Answering.']
            : ['key' => 'database', 'label' => 'Database', 'status' => 'problem', 'note' => 'Not answering.'];
    }

    private function scheduler(): array
    {
        $beat = HealthBeat::last('scheduler');
        if (! $beat) {
            return ['key' => 'scheduler', 'label' => 'Scheduled tasks', 'status' => 'unknown', 'note' => 'No beat recorded yet.', 'at' => null];
        }
        $late = Carbon::parse($beat['at'])->lt(now()->subMinutes(self::SCHEDULER_LATE_MINUTES));

        return ['key' => 'scheduler', 'label' => 'Scheduled tasks', 'status' => $late ? 'problem' : 'ok', 'note' => $late ? 'No beat for more than '.self::SCHEDULER_LATE_MINUTES.' minutes: the scheduler may have stopped.' : 'Running.', 'at' => $beat['at']];
    }

    private function queue(): array
    {
        $pending = $this->overview->pendingJobs();
        $oldest = $this->overview->oldestPendingJobAt();
        $failed = $this->overview->failedJobs();
        $stuck = $oldest !== null && $oldest < now()->subMinutes(self::QUEUE_LATE_MINUTES)->getTimestamp();
        $last = HealthBeat::last('queue');

        $status = $stuck || $failed > 0 ? 'problem' : ($last || $pending === 0 ? 'ok' : 'unknown');
        $notes = array_filter([
            "{$pending} waiting",
            "{$failed} failed",
            $stuck ? 'a job has waited more than '.self::QUEUE_LATE_MINUTES.' minutes: the worker may have stopped' : null,
        ]);

        return ['key' => 'queue', 'label' => 'Background jobs', 'status' => $status, 'note' => ucfirst(implode(', ', $notes)).'.', 'at' => $last['at'] ?? null];
    }

    private function lastResult(string $part, string $label): array
    {
        $last = HealthBeat::last($part);
        if (! $last) {
            return ['key' => $part, 'label' => $label, 'status' => 'unknown', 'note' => 'Nothing recorded yet.', 'at' => null];
        }

        return [
            'key' => $part,
            'label' => $label,
            'status' => $last['ok'] ? 'ok' : 'problem',
            'note' => $last['ok'] ? 'Last call worked'.($last['detail'] ? " ({$last['detail']})" : '').'.' : 'Last call failed'.($last['detail'] ? " ({$last['detail']})" : '').'.',
            'at' => $last['at'],
        ];
    }
}
