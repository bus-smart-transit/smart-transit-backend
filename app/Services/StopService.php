<?php
namespace App\Services;
use App\Repositories\StopRepository;

class StopService
{
    public function __construct(private StopRepository $stopRepository)
    {
    }

    // $payload = ['stop_name','latitude','longitude']
    public function createStop(array $payload): object
    {
        return $this->stopRepository->create($payload);
    }

    public function listStops(): object
    {
        return $this->stopRepository->all();
    }

    // $payload = ['stop_name','latitude','longitude'] (all optional via sometimes)
    public function updateStop(int $stopId, array $payload): ?object
    {
        $this->stopRepository->update($stopId, $payload);
        return $this->stopRepository->findById($stopId);
    }

    public function deleteStop(int $stopId): bool
    {
        return $this->stopRepository->delete($stopId);
    }
}
