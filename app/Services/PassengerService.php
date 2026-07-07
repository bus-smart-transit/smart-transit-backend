<?php
namespace App\Services;

use App\Repositories\PassengerRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\DB;

class PassengerService
{
    public function __construct(
        private PassengerRepository $passengerRepository,
        private UserRepository $userRepository,
    ) {
    }

    public function listPassenger(int $perPage = 15)
    {
        return $this->passengerRepository->paginate($perPage);
    }

    // $payload = ['name','email','password','phone_num','address','birthdate']
    public function createPassenger(array $payload): object
    {
        return DB::transaction(function () use ($payload) {
            $user = $this->userRepository->create($payload);

            return $this->passengerRepository->create([
                'user_id' => $user->user_id,
                'name' => $payload['name'],
                'phone_num' => $payload['phone_num'],
                'address' => $payload['address'] ?? '',
                'birthdate' => $payload['birthdate'],
            ]);
        });
    }

    public function getPassenger(string $uuid): ?object
    {
        return $this->passengerRepository->findByUuid($uuid);
    }

    public function getPassengerByField(string $field, mixed $value): ?object
    {
        return $this->passengerRepository->findByField($field, $value);
    }

    // $payload = validated fields only — never $request->all()
    public function updatePassenger(string $uuid, array $payload): object
    {
        return $this->passengerRepository->update($uuid, $payload);
    }

    public function deletePassenger(string $uuid): bool
    {
        return $this->passengerRepository->delete($uuid);
    }

    public function restorePassenger(string $uuid): object
    {
        return $this->passengerRepository->restore($uuid);
    }
}
