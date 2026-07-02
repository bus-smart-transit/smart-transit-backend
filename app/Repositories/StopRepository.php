<?php

namespace App\Repositories;

use App\Models\Stop;
use Illuminate\Support\Collection;

class StopRepository
{
    public function create(array $payload): Stop
    {
        return Stop::create($payload);
    }

    public function findById(int $stopId): ?Stop
    {
        return Stop::find($stopId);
    }

    public function all(): Collection
    {
        return Stop::orderBy('stop_name')->get();
    }

    public function update(int $stopId, array $payload): bool
    {
        return Stop::where('stop_id', $stopId)->update($payload) > 0;
    }

    public function delete(int $stopId): bool
    {
        return Stop::where('stop_id', $stopId)->delete() > 0;
    }
}
