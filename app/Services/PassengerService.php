<?php

namespace App\Services; // Double-check if your folder is named 'Service' or 'Services'

use App\Repositories\PassengerRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

class PassengerService
{
    private PassengerRepository $passengerRepository;
    private UserRepository $userRepository;

    public function __construct(
        PassengerRepository $passengerRepository,
        UserRepository $userRepository
    ) {
        $this->passengerRepository = $passengerRepository;
        $this->userRepository = $userRepository;
    }

    public function listPassenger(int $perPage = 15)
    {
        return $this->passengerRepository->paginate($perPage);
    }

    public function createPassenger(array $payload)
    {
        return DB::transaction(function () use ($payload) {
            // Now passing an array to an array-expecting repository safely
            $user = $this->userRepository->create($payload);

            // FIX: Match your custom primary key field 'user_id'
            $payload['user_id'] = $user->user_id;

            return $this->passengerRepository->create($payload);
        });
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
