<?php

namespace App\Console\Commands;

use App\Services\TicketExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpirePassedTickets extends Command
{
    protected $signature = 'tickets:expire-passed';

    protected $description = 'Batch 60: marks unscanned tickets expired once the running bus has reached or passed their destination.';

    public function __construct(private TicketExpiryService $expiry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $expired = $this->expiry->expirePassedOnRunningTrips();

        if ($expired > 0) {
            Log::info('Expired tickets whose destination the bus has passed.', ['ticket_count' => $expired]);
        }

        $this->info("Expired {$expired} ticket(s).");

        return self::SUCCESS;
    }
}
