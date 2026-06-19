<?php

namespace App\Http\Controllers;

use App\Services\PassengerService;
use App\Http\Resources\PassengerResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class PassengerController extends Controller
{
    private PassengerService $passengerService;

    public function __construct(PassengerService $passengerService)
    {
        $this->passengerService = $passengerService;
    }

    public function index(): JsonResponse
    {
        $records = $this->passengerService->getAll();
        return response()->json(PassengerResource::collection(collect($records)));
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $record = $this->passengerService->getById($uuid);
            return response()->json(new PassengerResource($record));
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Setup explicit manual layout validation properties here
        ]);

        $result = $this->passengerService->create($request->all());
        return response()->json(new PassengerResource($result), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            // Setup explicit manual layout validation properties here
        ]);

        try {
            $result = $this->passengerService->update($uuid, $request->all());
            return response()->json(new PassengerResource($result));
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function destroy(string $uuid): JsonResponse
    {
        try {
            $this->passengerService->delete($uuid);
            return response()->json(['message' => 'Record safely purged successfully']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
}