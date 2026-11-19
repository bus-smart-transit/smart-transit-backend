<?php

namespace App\Http\Controllers;

use App\Http\Requests\PageRequest;
use App\Services\RewardService;
use App\Traits\ApiResponse;

class RewardController extends Controller
{
    use ApiResponse;

    public function __construct(private RewardService $rewardService) {}

    public function history(PageRequest $request)
    {
        $passenger = $request->user()?->passengerProfile;

        if (! $passenger) {
            return $this->error('Account is invalid. Please log out and try again.');
        }

        $pagination = $request->validated();
        $history = $this->rewardService->getHistory(
            $passenger->passenger_id,
            (int) ($pagination['page'] ?? 1),
            (int) ($pagination['per_page'] ?? 50),
        );

        return $this->success($history, 'Reward history retrieved');
    }
}
