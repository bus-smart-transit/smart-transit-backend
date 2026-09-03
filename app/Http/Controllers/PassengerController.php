<?php
namespace App\Http\Controllers;

use App\Http\Requests\RegisterPassengerRequest;
use App\Http\Requests\UpdatePassengerProfileRequest;
use App\Http\Resources\PassengerResource;
use App\Services\PaymentService;
use App\Services\PassengerService;
use App\Services\RewardService;
use App\Services\TicketService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassengerController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PassengerService $passengerService,
        private TicketService $ticketService,
        private RewardService $rewardService,
        private PaymentService $paymentService,
    )
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $passenger = $request->user()?->passengerProfile;

        if (!$passenger) {
            return $this->error('Passenger profile not found.', 404);
        }

        $tickets = $this->ticketService->getPassengerTickets($passenger->passenger_id);
        $rewards = $this->rewardService->getHistory($passenger->passenger_id);
        $payments = $this->paymentService->getPassengerHistoryFromPayments($passenger->passenger_id);

        return $this->success([
            'profile' => [
                'passenger_uuid' => $passenger->passenger_uuid,
                'name' => $passenger->name,
                'phone_num' => $passenger->phone_num,
                'address' => $passenger->address,
                'birth_date' => $passenger->birth_date,
                'reward_points' => $passenger->reward_points,
                'email' => $user?->email,
                'username' => $user?->username,
                'two_factor_enabled' => (bool) ($user?->two_factor_enabled ?? false),
                'user' => [
                    'email' => $user?->email,
                    'username' => $user?->username,
                    'two_factor_enabled' => (bool) ($user?->two_factor_enabled ?? false),
                ],
                'created_at' => $passenger->created_at,
            ],
            'tickets' => $tickets,
            'rewards' => $rewards,
            'payments' => $payments,
            'meta' => [
                'tickets_count' => $tickets->count(),
                'rewards_count' => $rewards->count(),
                'payments_count' => $payments->count(),
                'synced_at' => now()->toISOString(),
            ],
        ], 'Dashboard summary retrieved successfully');
    }

    public function index(): JsonResponse
    {
        $paginated = $this->passengerService->listPassenger(request()->input('per_page', 15));
        return $this->success(PassengerResource::collection($paginated)->response()->getData(true));
    }

    public function store(RegisterPassengerRequest $request): JsonResponse
    {
        ['passenger' => $passenger, 'token' => $token] = $this->passengerService->registerPassenger($request->validated());

        return $this->success(
            ['passenger' => new PassengerResource($passenger), 'token' => $token],
            'Registered successfully',
            201
        );
    }

    public function show(string $uuid): JsonResponse
    {
        return $this->success(new PassengerResource($this->passengerService->getPassenger($uuid)));
    }

    public function update(UpdatePassengerProfileRequest $request): JsonResponse
    {
        // Enforce ownership: always update the authenticated user's own profile
        $passengerProfile = $request->user()?->passengerProfile;
        if (!$passengerProfile) {
            return $this->error('Passenger profile not found.', 404);
        }

        $model = $this->passengerService->updatePassenger(
            $passengerProfile->passenger_uuid,
            $request->validated()
        );

        // Revoke all other tokens when profile is updated (session hygiene)
        $request->user()->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return $this->success(new PassengerResource($model), 'Profile updated successfully');
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->passengerService->deletePassenger($uuid);
        return $this->success(null, 'Deleted successfully');
    }

    public function restore(string $uuid): JsonResponse
    {
        $model = $this->passengerService->restorePassenger($uuid);
        return $this->success(new PassengerResource($model), 'Restored successfully');
    }
}
