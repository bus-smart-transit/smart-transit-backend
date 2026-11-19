<?php

namespace App\Support;

final class ApiRequestMetrics
{
    private bool $active = false;

    private int $queryCount = 0;

    private float $queryTimeMs = 0.0;

    public function start(): void
    {
        $this->active = true;
        $this->queryCount = 0;
        $this->queryTimeMs = 0.0;
    }

    public function recordQuery(float $timeMs): void
    {
        if (! $this->active) {
            return;
        }

        $this->queryCount++;
        $this->queryTimeMs += $timeMs;
    }

    /** @return array{query_count: int, query_time_ms: float} */
    public function finish(): array
    {
        $this->active = false;

        return [
            'query_count' => $this->queryCount,
            'query_time_ms' => round($this->queryTimeMs, 2),
        ];
    }
}
