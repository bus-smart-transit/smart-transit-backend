<?php

namespace App\Repositories;

use App\Models\Fleets;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FleetsRepository
{
    public function paginate(int $perPage = 15)
    {
        return Fleets::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Fleets::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Fleets::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return Fleets::where($field, $value)->first();
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
        $model = Fleets::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}