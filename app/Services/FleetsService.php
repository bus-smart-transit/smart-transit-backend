<?php

namespace App\Services;

use App\Repositories\FleetsRepository;
use App\Http\Resources\FleetsResource;

class FleetsService
{
    private FleetsRepository $fleetsRepository;

    public function __construct(FleetsRepository $fleetsRepository) 
    {
        $this->fleetsRepository = $fleetsRepository;
    }

    public function listFleets(int $perPage = 15)
    {
        $collection = $this->fleetsRepository->paginate($perPage);
        return FleetsResource::collection($collection);
    }

    public function createFleets(array $payload)
    {
        $model = $this->fleetsRepository->create($payload);
        
    }

    public function getFleets(string $uuid)
    {
        $model = $this->fleetsRepository->findByUuid($uuid);
        
    }

    public function getFleetsByField(string $field, $value)
    {
        $model = $this->fleetsRepository->findByField($field, $value);
        
    }

    public function updateFleets(string $uuid, array $payload)
    {
        $model = $this->fleetsRepository->update($uuid, $payload);
        
    }

    public function deleteFleets(string $uuid)
    {
        $this->fleetsRepository->delete($uuid);
        return true;
    }

    public function restoreFleets(string $uuid)
    {
        $model = $this->fleetsRepository->restore($uuid);
        
    }
}