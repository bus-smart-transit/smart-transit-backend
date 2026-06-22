<?php

namespace App\Http\Controllers;

use App\Http\Requests\PassengerStoreRequest;
use App\Http\Resources\PassengerResource;
use App\Services\PassengerService; // Import the Resource here instead!
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PassengerController extends Controller
{
    private PassengerService $passengerService;

    public function __construct(PassengerService $passengerService)
    {
        $this->passengerService = $passengerService;
    }

    public function index(Request $request): JsonResponse
    {
        $paginatedModels = $this->passengerService->listPassenger($request->input('per_page', 15));

        // Transforms the paginated collection and automatically appends metadata
        return response()->json(PassengerResource::collection($paginatedModels)->response()->getData(true));
    }

    public function store(PassengerStoreRequest $request): JsonResponse
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

    public function update(Request $request, string $uuid): JsonResponse
    {
        $model = $this->passengerService->updatePassenger($uuid, $request->all());

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
