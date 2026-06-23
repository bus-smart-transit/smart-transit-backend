<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    private UserRepository $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function createUser(object $payload)
    {
        $user = $this->userRepository->create($payload);

        return new UserResource($user);
    }

    public function loginUser(array $credentials)
    {
        $user = $this->userRepository->findByField('email', $credentials['email']);

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        // Enforce implicit check safeguarding your system dashboard routes 
        if ($user->role !== 'passenger') {
            throw ValidationException::withMessages([
                'email' => ['Access unauthorized via passenger terminal app.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $user->createToken('passenger-session-token')->plainTextToken
        ];
    }

    public function logoutUser(object $user)
    {
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        return response()->json(['message' => 'Logged out successfully'], 200);
    }
}