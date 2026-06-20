<?php

namespace App\Services; // Double-check if your folder is named 'Service' or 'Services'

use App\Repositories\PassengerRepository;

class PassengerService
{
    private PassengerRepository $passengerRepository;

    public function __construct(PassengerRepository $passengerRepository)
    {
        $this->passengerRepository = $passengerRepository;
    }

    public function listPassenger(int $perPage = 15)
    {
        return $this->passengerRepository->paginate($perPage);
    }

    public function createPassenger(array $payload)
    {
        // Great place to add business logic/mutations later if needed!
        return $this->passengerRepository->create($payload);
    }

    public function getPassenger(string $uuid)
    {
        return $this->passengerRepository->findByUuid($uuid);
    }

    public function getPassengerByField(string $field, $value)
    {
        return $this->passengerRepository->findByField($field, $value);
    }

    public function updatePassenger(string $uuid, array $payload)
    {
        return $this->passengerRepository->update($uuid, $payload);
    }

    public function deletePassenger(string $uuid): bool
    {
        $this->passengerRepository->delete($uuid);

        return true;
    }

    public function restorePassenger(string $uuid)
    {
        return $this->passengerRepository->restore($uuid);
    }
}
