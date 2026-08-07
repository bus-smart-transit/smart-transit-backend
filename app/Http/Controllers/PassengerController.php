<?php
namespace App\Http\Controllers;

use App\Http\Requests\RegisterPassengerRequest;
use App\Http\Requests\UpdatePassengerProfileRequest;
use App\Http\Resources\PassengerResource;
use App\Services\PassengerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PassengerController extends Controller
{
    use ApiResponse;

    public function __construct(private PassengerService $passengerService)
    {
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
