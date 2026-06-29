<?php

namespace App\Repositories;

use App\Models\Stops;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class StopsRepository
{
    public function paginate(int $perPage = 15)
    {
        return Stops::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Stops::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Stops::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return Stops::where($field, $value)->first();
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
        $model = Stops::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}