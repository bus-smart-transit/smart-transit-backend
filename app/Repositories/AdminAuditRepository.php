<?php

namespace App\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Batch 48 Phase 4: the Super Admin's Audit log page. Newest first, with who did it (name, username, role).
 * audit_logs.created_at is stored in Manila wall-clock time (AuditLogger uses now()), so dates filter directly.
 */
class AdminAuditRepository
{
    /** @param array{action?: ?string, role?: ?string, from?: ?string, to?: ?string} $filters */
    public function page(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return DB::table('audit_logs')
            ->leftJoin('users', 'users.user_id', '=', 'audit_logs.actor_id')
            ->leftJoin('company_users', 'company_users.user_id', '=', 'users.user_id')
            ->leftJoin('passenger_users', 'passenger_users.user_id', '=', 'users.user_id')
            ->when(filled($filters['action'] ?? null), fn ($q) => $q->whereRaw('LOWER(audit_logs.action) LIKE ?', ['%'.mb_strtolower(trim($filters['action'])).'%']))
            ->when(filled($filters['role'] ?? null), fn ($q) => $filters['role'] === 'system'
                ? $q->whereNull('audit_logs.actor_id')
                : $q->where('users.role', $filters['role']))
            ->when(filled($filters['from'] ?? null), fn ($q) => $q->where('audit_logs.created_at', '>=', $filters['from'].' 00:00:00'))
            ->when(filled($filters['to'] ?? null), fn ($q) => $q->where('audit_logs.created_at', '<=', $filters['to'].' 23:59:59'))
            ->orderByDesc('audit_logs.audit_id')
            ->select([
                'audit_logs.audit_id', 'audit_logs.action', 'audit_logs.subject_type', 'audit_logs.subject_id',
                'audit_logs.context', 'audit_logs.created_at', 'audit_logs.actor_type', 'audit_logs.actor_id',
                'users.username', 'users.role',
                DB::raw('COALESCE(company_users.name, passenger_users.name) as actor_name'),
            ])
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
