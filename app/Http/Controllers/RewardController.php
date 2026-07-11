<?php
namespace App\Http\Controllers;

use App\Services\RewardService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    use ApiResponse;

    public function __construct(private RewardService $rewardService)
    {
    }

    public function history(Request $request)
    {
        $passenger = $request->user()?->passengerProfile;

        if (!$passenger) {
            return $this->error("Account is invalid. Please log out and try again.");
        }

        $history = $this->rewardService->getHistory($passenger->passenger_id);
        return $this->success($history, 'Reward history retrieved');
    }
}