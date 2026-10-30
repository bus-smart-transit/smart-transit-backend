<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\FleetRouteRepository;
use App\Repositories\RouteRepository;

/**
 * 2026-10-30: who may manage a route's settings (drop-off rules, duration windows). An Admin manages every route; an
 * Operator only the routes their own buses are assigned to. Was copied in DropoffRulesController and
 * RouteDurationFactorController; both use this one rule now.
 */
class OperatorRouteAccess
{
    public function __construct(
        private FleetRouteRepository $fleetRoutes,
        private RouteRepository $routes,
    ) {}

    /** null = admin (all routes), int = an Operator's company, false = no staff profile. */
    public function scope(User $user): int|false|null
    {
        if (($user->role ?? null) === 'admin') {
            return null;
        }
        $companyUserId = $user->companyProfile?->company_user_id;

        return $companyUserId ? (int) $companyUserId : false;
    }

    /** @return array<int, int> the routes an Operator's buses are assigned to */
    public function routeIds(int $companyUserId): array
    {
        return $this->fleetRoutes->routeIdsForCompany($companyUserId);
    }

    /**
     * Why this user may not manage `$what` on the route, or null when they may.
     *
     * @return array{0: string, 1: int}|null the message and HTTP status
     */
    public function denial(User $user, int $routeId, string $what): ?array
    {
        $scope = $this->scope($user);
        if ($scope === false) {
            return ['Staff profile not found.', 404];
        }
        if ($scope !== null && ! in_array($routeId, $this->routeIds($scope), true)) {
            return ["You can only manage {$what} for routes your buses run.", 403];
        }
        if (! $this->routes->exists($routeId)) {
            return ['Route not found.', 404];
        }

        return null;
    }
}
