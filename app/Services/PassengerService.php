<?php

namespace App\Services;

use App\Repositories\PassengerRepository;
use Exception;

class PassengerService
{
    private PassengerRepository $passengerRepository;

    public function __construct(PassengerRepository $passengerRepository) 
    {
        $this->passengerRepository = $passengerRepository;
    }

    public function getAll(): array
    {
        return $this->passengerRepository->all();
    }

    public function getById(string $uuid): array
    {
        $record = $this->passengerRepository->find($uuid);
        if (!$record) {
            throw new Exception("Record with identifier {$uuid} not found.");
        }
        return $record;
    }

    public function create(array $data): array
    {
        return $this->passengerRepository->create($data);
    }

    public function update(string $uuid, array $data): array
    {
        $this->getById($uuid); // Validate lifecycle integrity
        return $this->passengerRepository->update($uuid, $data);
    }

    public function delete(string $uuid): bool
    {
        $this->getById($uuid); // Validate lifecycle integrity
        return $this->passengerRepository->delete($uuid);
    }
}