<?php

namespace App\Http\Middleware;

use App\Support\ApiRequestMetrics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiRequestMetricsMiddleware
{
    public function __construct(private ApiRequestMetrics $metrics) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! config('api_instrumentation.enabled', true)) {
            return $next($request);
        }

        $startedAt = hrtime(true);
        $this->metrics->start();

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->write($request, $startedAt, $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500, 0);

            throw $exception;
        }

        $content = $response->getContent();
        $this->write(
            $request,
            $startedAt,
            $response->getStatusCode(),
            strlen(is_string($content) ? $content : ''),
            is_string($content) ? $this->responseRowCount($content) : null,
        );

        return $response;
    }

    private function write(Request $request, int $startedAt, int $status, int $responseBytes, ?int $responseRows = null): void
    {
        $database = $this->metrics->finish();
        $route = $request->route();

        Log::info('api.request', [
            'method' => $request->method(),
            'route' => $route?->uri() ?? 'unmatched',
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'response_bytes' => $responseBytes,
            'response_rows' => $responseRows,
            'db_query_count' => $database['query_count'],
            'db_time_ms' => $database['query_time_ms'],
        ]);
    }

    private function responseRowCount(string $content): ?int
    {
        $payload = json_decode($content, true);
        if (! is_array($payload)) {
            return null;
        }

        $data = $payload['data'] ?? null;
        if (is_array($data) && array_is_list($data)) {
            return count($data);
        }

        $pageRows = is_array($data) ? ($data['data'] ?? null) : null;

        return is_array($pageRows) && array_is_list($pageRows) ? count($pageRows) : null;
    }
}
