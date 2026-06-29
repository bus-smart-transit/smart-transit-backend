<?php

namespace App\Repositories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PaymentRepository
{
    public function paginate(int $perPage = 15)
    {
        return Payment::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Payment::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Payment::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return Payment::where($field, $value)->first();
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
        $model = Payment::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}