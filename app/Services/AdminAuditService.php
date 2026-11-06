<?php

namespace App\Services;

use App\Repositories\AdminAuditRepository;
use App\Support\FinancialClock;

/**
 * Batch 48 Phase 4: the Audit log page. Who did what, to what, and when; 25 a page. The extra details are shown as
 * they were recorded (entries never hold passwords, and phones are masked when written).
 */
class AdminAuditService
{
    public const PER_PAGE = 25;

    private const ROLE_LABELS = ['passenger' => 'Passenger', 'driver' => 'Driver', 'conductor' => 'Chauffeur', 'operator' => 'Operator', 'admin' => 'Super Admin'];

    public function __construct(private AdminAuditRepository $audit) {}

    /** @param array{action?: ?string, role?: ?string, from?: ?string, to?: ?string} $filters */
    public function page(array $filters, int $page): array
    {
        $paginator = $this->audit->page($filters, max(1, $page), self::PER_PAGE);

        return [
            'entries' => collect($paginator->items())->map(fn ($row) => [
                'audit_id' => (int) $row->audit_id,
                // Stored as Manila wall-clock time; sent as an instant so every device shows the same time.
                'at' => FinancialClock::fromWallClock($row->created_at)?->toIso8601String(),
                'action' => $row->action,
                'who' => $row->actor_id === null
                    ? ['name' => 'System', 'username' => null, 'role_label' => 'System']
                    : ['name' => $row->actor_name, 'username' => $row->username, 'role_label' => self::ROLE_LABELS[$row->role] ?? 'Not provided'],
                'subject' => $row->subject_type ? trim($row->subject_type.' '.($row->subject_id ?? '')) : null,
                'details' => $row->context ? json_decode((string) $row->context, true) : null,
            ])->values()->all(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }
}
