<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarkNotificationsReadRequest;
use App\Repositories\TripNotificationRepository;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

/**
 * S2 (Batch 12): lightweight Operator-facing notification feed — currently
 * only populated by trip decline requests and their approvals (TripAssignmentRequestService). Any
 * operator/admin can view/dismiss; notifications are not addressed to a
 * specific user.
 */
class TripNotificationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripNotificationRepository $notificationRepository,
    ) {}

    public function index(Request $request)
    {
        return $this->success([
            'notifications' => $this->notificationRepository->listRecent(),
            'unread_count' => $this->notificationRepository->countUnread(),
        ], 'Notifications retrieved successfully');
    }

    public function markRead(int $id)
    {
        $this->notificationRepository->markRead($id);

        return $this->success(null, 'Notification marked as read');
    }

    /** 2026-10-27: one notification group in the bell, marked read at once. */
    public function markManyRead(MarkNotificationsReadRequest $request)
    {
        $count = $this->notificationRepository->markManyRead(array_map('intval', $request->validated('ids')));

        return $this->success(['marked' => $count], 'Notifications marked as read');
    }

    public function markAllRead()
    {
        $this->notificationRepository->markAllRead();

        return $this->success(null, 'All notifications marked as read');
    }
}
