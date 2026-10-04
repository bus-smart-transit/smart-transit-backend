<?php

namespace App\Support;

use App\Models\RouteStop;

/**
 * Batch 28 (item 3): service type is a property of the schedule (the trip).
 *
 * On an express trip, a stop that is not marked `served_by_express` is a pass-through for that trip:
 * it is shown as a plain row, can never be chosen to board or get off, and the server rejects it.
 * Regular trips are never affected. Every caller asks this one class, so no screen
 * or service re-implements the rule.
 */
final class ServiceType
{
    public const REGULAR = 'regular';

    public const EXPRESS = 'express';

    public const ALL = [self::REGULAR, self::EXPRESS];

    /** Anything that is not exactly "express" is regular (also covers a trip not yet re-read from the DB). */
    public static function normalize(?string $type): string
    {
        return strtolower((string) $type) === self::EXPRESS ? self::EXPRESS : self::REGULAR;
    }

    public static function isExpress(?string $type): bool
    {
        return self::normalize($type) === self::EXPRESS;
    }

    /** The stop type a trip of this service sees: a non-express stop is a pass-through on express. */
    public static function effectiveStopType(?string $serviceType, RouteStop $routeStop): string
    {
        $type = $routeStop->stop_type ?? 'stop';
        if (self::isExpress($serviceType) && in_array($type, config('timeline.stopping_types'), true) && ! $routeStop->served_by_express) {
            return 'pass_through';
        }

        return $type;
    }

    /** True when a passenger may board or alight at this stop on a trip of this service. */
    public static function isServed(?string $serviceType, RouteStop $routeStop): bool
    {
        return in_array(self::effectiveStopType($serviceType, $routeStop), config('timeline.stopping_types'), true);
    }

    /**
     * Minutes from departure at which the trip reaches the stop. Express never borrows the regular
     * offsets (an express bus is faster); null means "ETA unavailable".
     */
    public static function offsetMinutes(?string $serviceType, RouteStop $routeStop): ?int
    {
        $value = self::isExpress($serviceType) ? $routeStop->express_offset_min : $routeStop->scheduled_offset_min;

        return $value === null ? null : (int) $value;
    }
}
