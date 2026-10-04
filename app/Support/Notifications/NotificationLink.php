<?php

namespace App\Support\Notifications;

/**
 * Where a notification should take the person who clicks it (Batch 27, Task 1).
 *
 * The link is built on the server, once, and travels inside the notification's
 * data (the FCM push payload and the stored in-app notification), so the push
 * tap, the in-app list click and old clients all open the same target. It is
 * always a relative path on our own site, never an external URL.
 *
 * Only the "new trip assigned" notification uses it for now; other types stay
 * as they were (see the Batch 27 report for the list).
 */
final class NotificationLink
{
    /** Staff portal tab that shows the person's assigned trips, per role. */
    private const TRIP_TAB = [
        'driver' => 'dashboard',
        'conductor' => 'dashboard',
        'operator' => 'trips',
    ];

    public static function tripAssignment(string $role, int $tripId): string
    {
        $tab = self::TRIP_TAB[$role] ?? 'dashboard';

        return "/employee/{$role}/dashboard?tab={$tab}&trip={$tripId}";
    }

    /**
     * Data for a "trip assigned" notification. All values are scalars so the
     * FCM data map (strings only) can carry them unchanged.
     *
     * @return array<string, string|int>
     */
    public static function tripAssignedData(string $role, int $tripId): array
    {
        return [
            'type' => 'trip_assigned',
            'trip_id' => $tripId,
            'role' => $role,
            'link' => self::tripAssignment($role, $tripId),
        ];
    }
}
