<?php

namespace App\Services;

use App\Repositories\CompanyUserRepository;
use App\Http\Resources\CompanyUserResource;

class CompanyUserService
{
    private CompanyUserRepository $companyUserRepository;

    public function __construct(CompanyUserRepository $companyUserRepository) 
    {
        $this->companyUserRepository = $companyUserRepository;
    }

    public function listCompanyUser(int $perPage = 15)
    {
        $collection = $this->companyUserRepository->paginate($perPage);
        return CompanyUserResource::collection($collection);
    }

    public function createCompanyUser(array $payload)
    {
        $model = $this->companyUserRepository->create($payload);
        
    }

    public function getCompanyUser(string $uuid)
    {
        $model = $this->companyUserRepository->findByUuid($uuid);
        
    }

    public function getCompanyUserByField(string $field, $value)
    {
        $model = $this->companyUserRepository->findByField($field, $value);
        
    }

    public function updateCompanyUser(string $uuid, array $payload)
    {
        $model = $this->companyUserRepository->update($uuid, $payload);
        
    }

    public function deleteCompanyUser(string $uuid)
    {
        $this->companyUserRepository->delete($uuid);
        return true;
    }

    public function restoreCompanyUser(string $uuid)
    {
        $model = $this->companyUserRepository->restore($uuid);
        
    }
}