<?php

namespace App\Repositories;

use App\Models\FareRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class FareRuleRepository
{
    public function paginate(int $perPage = 15)
    {
        return FareRule::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return FareRule::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return FareRule::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return FareRule::where($field, $value)->first();
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
        $model = FareRule::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}