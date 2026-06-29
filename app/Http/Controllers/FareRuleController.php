<?php

namespace App\Http\Controllers;

use App\Services\FareRuleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FareRuleController extends Controller
{
    private FareRuleService $fareRuleService;

    public function __construct(FareRuleService $fareRuleService)
    {
        $this->fareRuleService = $fareRuleService;
    }

    public function index(Request $request)
    {
        return $this->fareRuleService->listFareRule($request->input('per_page', 15));
    }

    public function store(Request $request)
    {
        return $this->fareRuleService->createFareRule($request->all());
    }

    public function show(string $uuid)
    {
        return $this->fareRuleService->getFareRule($uuid);
    }

    public function update(Request $request, string $uuid)
    {
        return $this->fareRuleService->updateFareRule($uuid, $request->all());
    }

    public function destroy(string $uuid)
    {
        $this->fareRuleService->deleteFareRule($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }
    
    public function restore(string $uuid)
    {
        return $this->fareRuleService->restoreFareRule($uuid);
    }
}