<?php
namespace App\Http\Controllers;

use App\Http\Requests\RegisterPassengerRequest;
use App\Http\Requests\UpdatePassengerProfileRequest;
use App\Http\Resources\PassengerResource;
use App\Services\PassengerService;
use Illuminate\Http\JsonResponse;

class PassengerController extends Controller
{
    public function __construct(private PassengerService $passengerService)
    {
    }

    public function index(): JsonResponse
    {
        $paginated = $this->passengerService->listPassenger(request()->input('per_page', 15));
        return response()->json(PassengerResource::collection($paginated)->response()->getData(true));
    }

    public function store(RegisterPassengerRequest $request): JsonResponse
    {
        $model = $this->passengerService->createPassenger($request->validated());
        $model->load('user');
        $token = $model->user->createToken('passenger-token')->plainTextToken;

        return response()->json([
            'passenger' => new PassengerResource($model),
            'token' => $token,
        ], 201);
    }

    public function show(string $uuid): JsonResponse
    {
        $model = $this->passengerService->getPassenger($uuid);
        return response()->json(new PassengerResource($model), 200);
    }

    public function update(UpdatePassengerProfileRequest $request, string $uuid): JsonResponse
    {
        // Fixed: was $request->all() — now uses validated data only
        $model = $this->passengerService->updatePassenger($uuid, $request->validated());
        return response()->json(new PassengerResource($model), 200);
    }

    public function destroy(string $uuid): JsonResponse
    {
        $this->passengerService->deletePassenger($uuid);
        return response()->json(['message' => 'Deleted successfully'], 200);
    }

    public function restore(string $uuid): JsonResponse
    {
        $model = $this->passengerService->restorePassenger($uuid);
        return response()->json([
            'message' => 'Restored successfully',
            'data' => new PassengerResource($model),
        ], 200);
    }
}
