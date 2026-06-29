<?php

namespace App\Repositories;

use App\Models\CompanyUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CompanyUserRepository
{
    public function paginate(int $perPage = 15)
    {
        return CompanyUser::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return CompanyUser::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return CompanyUser::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return CompanyUser::where($field, $value)->first();
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
        $model = CompanyUser::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}