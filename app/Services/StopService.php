<?php

namespace App\Services;

use App\Repositories\StopsRepository;
use App\Http\Resources\StopsResource;

class StopsService
{
    private StopsRepository $stopsRepository;

    public function __construct(StopsRepository $stopsRepository) 
    {
        $this->stopsRepository = $stopsRepository;
    }

    public function listStops(int $perPage = 15)
    {
        $collection = $this->stopsRepository->paginate($perPage);
        return StopsResource::collection($collection);
    }

    public function createStops(array $payload)
    {
        $model = $this->stopsRepository->create($payload);
        
    }

    public function getStops(string $uuid)
    {
        $model = $this->stopsRepository->findByUuid($uuid);
        
    }

    public function getStopsByField(string $field, $value)
    {
        $model = $this->stopsRepository->findByField($field, $value);
        
    }

    public function updateStops(string $uuid, array $payload)
    {
        $model = $this->stopsRepository->update($uuid, $payload);
        
    }

    public function deleteStops(string $uuid)
    {
        $this->stopsRepository->delete($uuid);
        return true;
    }

    public function restoreStops(string $uuid)
    {
        $model = $this->stopsRepository->restore($uuid);
        
    }
}