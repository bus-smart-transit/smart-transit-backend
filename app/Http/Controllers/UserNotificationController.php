<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkNotificationsReadRequest;
use App\Services\FcmService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * S1 (Batch 17): generic in-app notification-bell feed for any
 * authenticated staff user (Driver/Conductor for now — Operator already has
 * its own dedicated decline-focused feed via TripNotificationController).
 * Deliberately role-agnostic: scoped entirely by $request->user()->user_id,
 * so the same controller serves every role's "/{role}/notifications"
 * routes without duplication.
 */
class UserNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private FcmService $fcmService,
    ) {}

    public function index(Request $request)
    {
        $userId = $request->user()->user_id;

        return $this->success([
            'notifications' => $this->fcmService->listRecentForUser($userId),
            'unread_count' => $this->fcmService->countUnreadForUser($userId),
        ], 'Notifications retrieved successfully');
    }

    public function markRead(Request $request, int $id)
    {
        $this->fcmService->markReadForUser($id, $request->user()->user_id);

        return $this->success(null, 'Notification marked as read');
    }

    /** 2026-10-27: one notification group in the bell, marked read at once (only the caller's own notifications). */
    public function markManyRead(MarkNotificationsReadRequest $request)
    {
        $count = $this->fcmService->markManyReadForUser(array_map('intval', $request->validated('ids')), $request->user()->user_id);

        return $this->success(['marked' => $count], 'Notifications marked as read');
    }

    public function markAllRead(Request $request)
    {
        $this->fcmService->markAllReadForUser($request->user()->user_id);

        return $this->success(null, 'All notifications marked as read');
    }
}
