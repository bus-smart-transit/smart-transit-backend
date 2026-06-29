<?php

namespace App\Services;

use App\Repositories\FareMatrixRepository;
use App\Http\Resources\FareMatrixResource;

class FareMatrixService
{
    private FareMatrixRepository $fareMatrixRepository;

    public function __construct(FareMatrixRepository $fareMatrixRepository) 
    {
        $this->fareMatrixRepository = $fareMatrixRepository;
    }

    public function listFareMatrix(int $perPage = 15)
    {
        $collection = $this->fareMatrixRepository->paginate($perPage);
        return FareMatrixResource::collection($collection);
    }

    public function createFareMatrix(array $payload)
    {
        $model = $this->fareMatrixRepository->create($payload);
        
    }

    public function getFareMatrix(string $uuid)
    {
        $model = $this->fareMatrixRepository->findByUuid($uuid);
        
    }

    public function getFareMatrixByField(string $field, $value)
    {
        $model = $this->fareMatrixRepository->findByField($field, $value);
        
    }

    public function updateFareMatrix(string $uuid, array $payload)
    {
        $model = $this->fareMatrixRepository->update($uuid, $payload);
        
    }

    public function deleteFareMatrix(string $uuid)
    {
        $this->fareMatrixRepository->delete($uuid);
        return true;
    }

    public function restoreFareMatrix(string $uuid)
    {
        $model = $this->fareMatrixRepository->restore($uuid);
        
    }
}