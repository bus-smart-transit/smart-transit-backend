<?php

namespace App\Repositories;

use App\Models\FleetRoute;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FleetRouteRepository
{
    public function paginate(int $perPage = 15)
    {
        return FleetRoute::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return FleetRoute::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return FleetRoute::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return FleetRoute::where($field, $value)->first();
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
        $model = FleetRoute::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}