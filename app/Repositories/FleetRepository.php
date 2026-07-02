<?php

namespace App\Repositories;

use App\Models\Fleet;
use Illuminate\Support\Collection;

class FleetRepository
{
    public function create(array $payload): Fleet
    {
        return Fleet::create($payload);
    }

    public function findById(int $fleetId): ?Fleet
    {
        return Fleet::find($fleetId);
    }

    public function all(): Collection
    {
        return Fleet::where('status', 'active')->get();
    }

    public function update(int $fleetId, array $payload): bool
    {
        return Fleet::where('fleet_id', $fleetId)->update($payload) > 0;
    }
}
