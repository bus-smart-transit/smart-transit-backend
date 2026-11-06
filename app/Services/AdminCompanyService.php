<?php

namespace App\Services;

use App\Repositories\AdminAccountsRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Batch 48 Phase 3 (user's permission table): only the Super Admin adds and renames companies and adds a company's
 * Company Admins. A Company Admin then adds that company's Operators, Drivers and Chauffeurs in the Operator portal.
 */
class AdminCompanyService
{
    public function __construct(
        private AdminAccountsRepository $accounts,
        private StaffService $staff,
    ) {}

    public function list(): Collection
    {
        return $this->accounts->companiesWithCounts();
    }

    public function create(string $name): array
    {
        $name = $this->clean($name);
        if ($this->accounts->companyNameTaken($name)) {
            throw ValidationException::withMessages(['company_name' => ['A company with this name already exists.']]);
        }
        $company = $this->accounts->createCompany($name);

        return ['company_id' => (int) $company->company_id, 'company_name' => $company->company_name];
    }

    public function rename(int $companyId, string $name): array
    {
        $company = $this->accounts->findCompany($companyId) ?? throw new NotFoundHttpException('Company not found.');
        $name = $this->clean($name);
        if ($this->accounts->companyNameTaken($name, $companyId)) {
            throw ValidationException::withMessages(['company_name' => ['A company with this name already exists.']]);
        }
        $old = $company->company_name;
        $this->accounts->renameCompany($company, $name);

        return ['company_id' => $companyId, 'company_name' => $name, 'old_name' => $old];
    }

    /**
     * A new Operator account in this company with the Company Admin permission. The Super Admin gives them their
     * username and first password, as a Company Admin does for their own staff.
     *
     * @param  array{name: string, email: string, password: string, phone_num: string, address: string, username?: ?string}  $payload
     */
    public function addCompanyAdmin(int $companyId, array $payload): array
    {
        $company = $this->accounts->findCompany($companyId) ?? throw new NotFoundHttpException('Company not found.');
        $made = $this->staff->createStaffAccount([...$payload, 'role' => 'operator', 'company_name' => $company->company_name], 'admin');
        $made['profile']->forceFill(['is_company_admin' => true])->save();

        return ['company_user_id' => (int) $made['profile']->company_user_id, 'user_id' => (int) $made['user']->user_id, 'company_id' => $companyId];
    }

    private function clean(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $name));
    }
}
