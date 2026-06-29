<?php

namespace App\Services;

use App\Repositories\TripRepository;
use App\Http\Resources\TripResource;

class TripService
{
    private TripRepository $tripRepository;

    public function __construct(TripRepository $tripRepository) 
    {
        $this->tripRepository = $tripRepository;
    }

    public function listTrip(int $perPage = 15)
    {
        $collection = $this->tripRepository->paginate($perPage);
        return TripResource::collection($collection);
    }

    public function createTrip(array $payload)
    {
        $model = $this->tripRepository->create($payload);
        
    }

    public function getTrip(string $uuid)
    {
        $model = $this->tripRepository->findByUuid($uuid);
        
    }

    public function getTripByField(string $field, $value)
    {
        $model = $this->tripRepository->findByField($field, $value);
        
    }

    public function updateTrip(string $uuid, array $payload)
    {
        $model = $this->tripRepository->update($uuid, $payload);
        
    }

    public function deleteTrip(string $uuid)
    {
        $this->tripRepository->delete($uuid);
        return true;
    }

    public function restoreTrip(string $uuid)
    {
        $model = $this->tripRepository->restore($uuid);
        
    }
}