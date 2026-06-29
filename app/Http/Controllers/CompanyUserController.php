<?php

namespace App\Http\Controllers;

use App\Services\CompanyUserService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CompanyUserController extends Controller
{
    private CompanyUserService $companyUserService;

    public function __construct(CompanyUserService $companyUserService)
    {
        $this->companyUserService = $companyUserService;
    }

    public function index(Request $request)
    {
        return $this->companyUserService->listCompanyUser($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->companyUserService->createCompanyUser($request->all());
    }

    public function show(string $uuid)
    {
        return $this->companyUserService->getCompanyUser($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->companyUserService->updateCompanyUser($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->companyUserService->deleteCompanyUser($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->companyUserService->restoreCompanyUser($uuid);
    }
}