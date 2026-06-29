<?php

namespace App\Repositories;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TicketRepository
{
    public function paginate(int $perPage = 15)
    {
        return Ticket::latest()->paginate($perPage);
    }

    public function create(array $payload)
    {
        return Ticket::create($payload);
    }

    public function findByUuid(string $uuid)
    {
        return Ticket::where('uuid', $uuid)->first();
    }

    public function findByField(string $field, $value)
    {
        return Ticket::where($field, $value)->first();
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
        $model = Ticket::withTrashed()->where('uuid', $uuid)->first();
        $model->restore();
        return $model;
    }
}