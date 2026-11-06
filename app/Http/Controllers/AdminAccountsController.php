<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddCompanyAdminRequest;
use App\Services\AdminCompanyService;
use App\Services\AdminUserService;
use App\Services\AuditLogger;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 48 Phase 3: the Super Admin's Companies and Users pages (Super Admin only, by the route group). The acting
 * account always comes from the sign-in, never from the request.
 */
class AdminAccountsController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AdminCompanyService $companies,
        private AdminUserService $users,
    ) {}

    public function companies(): JsonResponse
    {
        return $this->success($this->companies->list(), 'Companies retrieved');
    }

    public function storeCompany(Request $request): JsonResponse
    {
        $validated = $request->validate(['company_name' => ['required', 'string', 'min:2', 'max:120']]);
        $company = $this->companies->create($validated['company_name']);
        AuditLogger::log('company.create', 'Company', $company['company_id'], ['name' => $company['company_name']]);

        return $this->success($company, 'Company added.');
    }

    public function renameCompany(Request $request, int $companyId): JsonResponse
    {
        $validated = $request->validate(['company_name' => ['required', 'string', 'min:2', 'max:120']]);
        $company = $this->companies->rename($companyId, $validated['company_name']);
        AuditLogger::log('company.rename', 'Company', $companyId, ['from' => $company['old_name'], 'to' => $company['company_name']]);

        return $this->success($company, 'Company renamed.');
    }

    public function addCompanyAdmin(AddCompanyAdminRequest $request, int $companyId): JsonResponse
    {
        $made = $this->companies->addCompanyAdmin($companyId, $request->validated());
        AuditLogger::log('company.admin_added', 'StaffUser', $made['company_user_id'], ['company_id' => $companyId]);

        return $this->success($made, 'Company Admin added. Give them their username and first password.');
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'in:passenger,driver,conductor,operator,admin'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ]);

        return $this->success(
            $this->users->page($validated['search'] ?? null, $validated['role'] ?? null, (int) ($validated['page'] ?? 1), $this->actor($request)),
            'Accounts retrieved',
        );
    }

    public function deactivate(Request $request, int $userId): JsonResponse
    {
        $user = $this->users->deactivate($userId, $this->actor($request));
        AuditLogger::log('user.deactivate', 'User', $userId, ['role' => $user['role']]);

        return $this->success($user, 'Account turned off. They can no longer sign in.');
    }

    public function reactivate(Request $request, int $userId): JsonResponse
    {
        $user = $this->users->reactivate($userId, $this->actor($request));
        AuditLogger::log('user.reactivate', 'User', $userId, ['role' => $user['role']]);

        return $this->success($user, 'Account turned on again. They can sign in.');
    }

    public function setCompanyAdmin(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate(['is_company_admin' => ['required', 'boolean']]);
        $user = $this->users->setCompanyAdmin($userId, (bool) $validated['is_company_admin'], $this->actor($request));
        AuditLogger::log($user['is_company_admin'] ? 'company.admin_on' : 'company.admin_off', 'User', $userId);

        return $this->success($user, $user['is_company_admin'] ? 'They are now a Company Admin.' : 'They are no longer a Company Admin.');
    }

    private function actor(Request $request): int
    {
        return (int) $request->user()->user_id;
    }
}
