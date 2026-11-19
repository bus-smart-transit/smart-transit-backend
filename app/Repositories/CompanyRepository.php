<?php

namespace App\Repositories;

use App\Models\Company;

/** Batch 46: bus companies. */
class CompanyRepository
{
    public function find(int $companyId): ?Company
    {
        return Company::find($companyId);
    }

    public function findByName(string $name): ?Company
    {
        return Company::whereRaw('LOWER(company_name) = ?', [mb_strtolower(trim($name))])->first();
    }

    public function create(string $name): Company
    {
        return Company::create(['company_name' => trim($name)]);
    }
}
