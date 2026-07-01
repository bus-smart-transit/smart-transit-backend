<?php

namespace App\Repositories;

use App\Models\Route;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RouteRepository
{
    public function paginate(int $perPage = 15)
    {
        return Route::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Route::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Route::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return Route::where($field, $value)->first();
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
        $model = Route::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}