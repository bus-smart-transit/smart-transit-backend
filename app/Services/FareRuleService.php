<?php

namespace App\Services;

use App\Repositories\FareRuleRepository;
use App\Http\Resources\FareRuleResource;

class FareRuleService
{
    private FareRuleRepository $fareRuleRepository;

    public function __construct(FareRuleRepository $fareRuleRepository) 
    {
        $this->fareRuleRepository = $fareRuleRepository;
    }

    public function listFareRule(int $perPage = 15)
    {
        $collection = $this->fareRuleRepository->paginate($perPage);
        return FareRuleResource::collection($collection);
    }

    public function createFareRule(array $payload)
    {
        $model = $this->fareRuleRepository->create($payload);
        
    }

    public function getFareRule(string $uuid)
    {
        $model = $this->fareRuleRepository->findByUuid($uuid);
        
    }

    public function getFareRuleByField(string $field, $value)
    {
        $model = $this->fareRuleRepository->findByField($field, $value);
        
    }

    public function updateFareRule(string $uuid, array $payload)
    {
        $model = $this->fareRuleRepository->update($uuid, $payload);
        
    }

    public function deleteFareRule(string $uuid)
    {
        $this->fareRuleRepository->delete($uuid);
        return true;
    }

    public function restoreFareRule(string $uuid)
    {
        $model = $this->fareRuleRepository->restore($uuid);
        
    }
}