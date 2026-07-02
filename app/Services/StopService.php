<?php

namespace App\Services;

use App\Repositories\StopRepository;

class StopService
{
    private StopRepository $stopRepository;
    public function __construct(StopRepository $stopRepository)
    {
        $this->stopRepository = $stopRepository;
    }

    public function createStop(array $payload)
    {
        return $this->stopRepository->create($payload);
    }

    public function listStops()
    {
        return $this->stopRepository->all();
    }

    public function updateStop(int $stopId, array $payload)
    {
        $this->stopRepository->update($stopId, $payload);
        return $this->stopRepository->findById($stopId);
    }

    public function deleteStop(int $stopId)
    {
        return $this->stopRepository->delete($stopId);
    }
}
