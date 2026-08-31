<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Lightweight audit logger.
 *
 * Usage:
 *   AuditLogger::log('trip.depart', 'Trip', $trip->trip_id, ['fleet_id' => $fleetId]);
 *   AuditLogger::logAs('staff', $user->id, 'fare.update', 'FareRule', $fareRuleId);
 */
class AuditLogger
{
    /**
     * Log an action on behalf of the currently authenticated user (any guard).
     */
    public static function log(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = []
    ): void {
        $user      = null;
        $actorType = null;
        $actorId   = null;

        foreach (['sanctum', 'web'] as $guard) {
            $user = auth($guard)->user();
            if ($user) break;
        }

        if ($user) {
            // Distinguish passenger users from staff (company_users)
            $actorType = method_exists($user, 'companyProfile') ? 'staff' : 'passenger';
            $actorId   = $user->getKey();
        }

        static::insert($actorType, $actorId, $action, $subjectType, $subjectId, $context);
    }

    /**
     * Log with an explicit actor (for system-generated events).
     */
    public static function logAs(
        string $actorType,
        ?int $actorId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $context = []
    ): void {
        static::insert($actorType, $actorId, $action, $subjectType, $subjectId, $context);
    }

    private static function insert(
        ?string $actorType,
        ?int $actorId,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        array $context
    ): void {
        try {
            DB::table('audit_logs')->insert([
                'actor_type'   => $actorType,
                'actor_id'     => $actorId,
                'action'       => $action,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'context'      => empty($context) ? null : json_encode($context),
                'ip_address'   => Request::ip(),
                'created_at'   => now(),
            ]);
        } catch (\Throwable) {
            // Audit failure must never crash the main request.
        }
    }
}
