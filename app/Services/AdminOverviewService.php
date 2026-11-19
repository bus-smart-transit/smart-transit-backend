<?php

namespace App\Services;

use App\Repositories\AdminOverviewRepository;
use App\Support\FinancialClock;

/**
 * Batch 48 Phase 2: the Super Admin Dashboard. A few platform counts and a short "needs attention" list; each item says
 * plainly what it is and who acts on it.
 */
class AdminOverviewService
{
    public function __construct(private AdminOverviewRepository $overview) {}

    public function summary(): array
    {
        $today = FinancialClock::today();

        return [
            'date' => $today,
            'counts' => [
                'companies' => $this->overview->companies(),
                'operators' => $this->overview->activeOperators(),
                'buses' => $this->overview->buses(),
                'routes' => $this->overview->routes(),
                'trips_today' => $this->overview->tripsOn($today),
                'tickets_paid_today' => $this->overview->ticketsPaidOn($today),
            ],
            'attention' => [
                ['key' => 'needs_refund', 'label' => 'Payments to refund by hand (paid, but no ticket was made)', 'count' => $this->overview->paymentsNeedingRefund()],
                ['key' => 'failed_jobs', 'label' => 'Background jobs that failed (emails, route drawing, reports)', 'count' => $this->overview->failedJobs()],
                ['key' => 'stops_not_pinned', 'label' => 'Stops not yet pinned on the map (their Company Admin re-pins them)', 'count' => $this->overview->stopsNotPinned()],
            ],
        ];
    }
}
