<?php

namespace App\Repositories;

use App\Models\Trip;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TripRepository
{
    public function paginate(int $perPage = 15)
    {
        return Trip::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Trip::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Trip::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return Trip::where($field, $value)->first();
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
        $model = Trip::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}