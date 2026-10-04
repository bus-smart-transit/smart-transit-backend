<?php

namespace App\Console\Commands;

use App\Services\DropoffRestrictionService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 Stage 3, item 10: create a restricted area (where custom drop-off is NOT available) from a route id and the
 * ids of its two end STOPS. By stop id, never by name. The real urban-corridor area is created only after the adviser
 * confirms the two stops, so it is labelled "pending adviser confirmation" unless a source is given.
 */
class AddDropoffRestriction extends Command
{
    protected $signature = 'dropoff:add-restriction {route_id : Route id} {from_stop_id : Stop id at the start of the area} {to_stop_id : Stop id at the end of the area}
        {--name= : Name shown to passengers and staff (required)}
        {--source=pending adviser confirmation : Where the rule comes from}
        {--note= : Optional note}
        {--inactive : Create it switched off (it has no effect until it is activated)}';

    protected $description = 'Creates a route-segment area where custom drop-off is not available, from a route id and two stop ids.';

    public function handle(DropoffRestrictionService $restrictions): int
    {
        $name = trim((string) $this->option('name'));
        if ($name === '') {
            $this->error('Give the area a name with --name="...".');

            return self::FAILURE;
        }

        try {
            $row = $restrictions->save([
                'route_id' => (int) $this->argument('route_id'),
                'name' => $name,
                'from_stop_id' => (int) $this->argument('from_stop_id'),
                'to_stop_id' => (int) $this->argument('to_stop_id'),
                'source' => (string) $this->option('source'),
                'note' => $this->option('note'),
                'is_active' => ! $this->option('inactive'),
            ]);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }

            return self::FAILURE;
        }

        $this->info("Restricted area #{$row->id} \"{$row->name}\" created on route {$row->route_id}: {$row->from_distance_m} m to {$row->to_distance_m} m from the route start, ".($row->is_active ? 'ACTIVE' : 'inactive').'.');

        return self::SUCCESS;
    }
}
