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

        $pagination = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $history = $this->rewardService->getHistory(
            $passenger->passenger_id,
            (int) ($pagination['page'] ?? 1),
            (int) ($pagination['per_page'] ?? 50),
        );
        return $this->success($history, 'Reward history retrieved');
    }
}