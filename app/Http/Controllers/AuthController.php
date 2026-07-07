<?php
namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Resources\PassengerResource;
use App\Services\UserService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private UserService $userService)
    {
    }

    public function login(LoginRequest $request)
    {
        $response = $this->userService->loginUser($request->validated());
        return $this->success($response, 'Logged in successfully');
    }

    public function profile(Request $request)
    {
        $passengerProfile = $this->userService->getPassengerProfile($request->user());

        if (!$passengerProfile) {
            return $this->error('Passenger profile not found.', 404);
        }

        return $this->success(
            new PassengerResource($passengerProfile),
            'Profile retrieved successfully'
        );
    }

    public function logout(Request $request)
    {
        $this->userService->logoutUser($request->user());
        return $this->success(null, 'Logged out successfully');
    }
}
