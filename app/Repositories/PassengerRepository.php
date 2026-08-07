<?php
namespace App\Repositories;

use App\Models\PassengerUser;
use Illuminate\Support\Str;

class PassengerRepository
{
    public function paginate(int $perPage = 15)
    {
        return PassengerUser::with('user')->latest()->paginate($perPage);
    }

    public function create(array $payload): PassengerUser
    {
        // Generate UUID here — no HasUuids trait on the model
        $payload['passenger_uuid'] = (string) Str::uuid();
        return PassengerUser::create($payload);
    }

    public function findByUuid(string $uuid): ?PassengerUser
    {
        return PassengerUser::with('user')
            ->where('passenger_uuid', $uuid)
            ->first();
    }

    public function findByField(string $field, mixed $value): ?PassengerUser
    {
        return PassengerUser::where($field, $value)->first();
    }

    public function update(string $uuid, array $payload): PassengerUser
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);
        return $model->fresh();
    }

    public function delete(string $uuid): bool
    {
        $model = $this->findByUuid($uuid);
        return $model->delete();
    }

    public function restore(string $uuid): PassengerUser
    {
        // Fixed: was querying wrong column 'uuid' — correct column is 'passenger_uuid'
        $model = PassengerUser::withTrashed()
            ->where('passenger_uuid', $uuid)
            ->firstOrFail();
        $model->restore();
        return $model;
    }
}
