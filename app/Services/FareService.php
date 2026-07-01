<?php

namespace App\Services;

use App\Repositories\FareRepository;
use App\Http\Resources\FareRuleResource;

class FareService
{
    private FareRepository $fareRepository;

    public function __construct(FareRepository $fareRepository)
    {
        $this->fareRepository = $fareRepository;
    }

    public function listFareRule(int $perPage = 15)
    {
        $collection = $this->fareRepository->paginate($perPage);
        return FareRuleResource::collection($collection);
    }

    public function createFareRule(array $payload)
    {
        $model = $this->fareRepository->create($payload);

    }

    public function getFareRule(string $uuid)
    {
        $model = $this->fareRepository->findByUuid($uuid);

    }

    public function getFareRuleByField(string $field, $value)
    {
        $model = $this->fareRepository->findByField($field, $value);

    }

    public function updateFareRule(string $uuid, array $payload)
    {
        $model = $this->fareRepository->update($uuid, $payload);

    }

    public function deleteFareRule(string $uuid)
    {
        $this->fareRepository->delete($uuid);
        return true;
    }

    public function restoreFareRule(string $uuid)
    {
        $model = $this->fareRepository->restore($uuid);

    }
}