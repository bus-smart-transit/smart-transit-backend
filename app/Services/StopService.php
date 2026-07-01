<?php

namespace App\Services;

use App\Repositories\StopRepository;
use App\Http\Resources\StopResource;

class StopService
{
    private StopRepository $stopRepository;

    public function __construct(StopRepository $stopRepository)
    {
        $this->stopRepository = $stopRepository;
    }

    public function listStops(int $perPage = 15)
    {
        $collection = $this->stopRepository->paginate($perPage);
        return StopResource::collection($collection);
    }

    public function createStops(array $payload)
    {
        $model = $this->stopRepository->create($payload);

    }

    public function getStops(string $uuid)
    {
        $model = $this->stopRepository->findByUuid($uuid);

    }

    public function getStopsByField(string $field, $value)
    {
        $model = $this->stopRepository->findByField($field, $value);

    }

    public function updateStops(string $uuid, array $payload)
    {
        $model = $this->stopRepository->update($uuid, $payload);

    }

    public function deleteStops(string $uuid)
    {
        $this->stopRepository->delete($uuid);
        return true;
    }

    public function restoreStops(string $uuid)
    {
        $model = $this->stopRepository->restore($uuid);

    }
}