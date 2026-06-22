<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\UserService;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;

class AuthController extends Controller
{
    use ApiResponse;
    private $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function login(LoginRequest $request)
    {
        $response = $this->userService->loginUser($request->validated());

        // Matches the updated trait signature
        return $this->success($response, 'Logged in successfully');
    }

    public function logout(Request $request)
    {
        $this->userService->logoutUser($request->user());

        return $this->success(null, 'Logged out successfully');
    }
}