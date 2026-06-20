<?php

namespace App\Repositories;

use App\Models\PassengerUser;

class PassengerRepository
{
    public function paginate(int $perPage = 15)
    {
        return PassengerUser::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return PassengerUser::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return PassengerUser::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return PassengerUser::where($field, $value)->first();
    }

    public function update(string $uuid, array $payload)
    {
        $model = $this->findByUuid($uuid);
        $model->update($payload);

        return $model;
    }

    public function delete(string $uuid)
    {
        $model = $this->findByUuid($uuid);

        return $model->delete();
    }

    public function restore(string $uuid)
    {
        $model = PassengerUser::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();

        return $model;
    }
}
