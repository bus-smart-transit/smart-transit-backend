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

    public function update(UpdatePassengerProfileRequest $request, string $uuid): JsonResponse
    {
        $model = $this->passengerService->updatePassenger($uuid, $request->validated());
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
