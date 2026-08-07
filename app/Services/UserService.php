<?php
namespace App\Services;

use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private UserRepository $userRepository)
    {
    }

    // $payload = ['email', 'password']
    public function loginUser(array $payload): array
    {
        $user = $this->userRepository->findByField('email', $payload['email']);

        if (!$user || !Hash::check($payload['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        // Use same generic message to prevent role/existence enumeration
        if ($user->role !== 'passenger') {
            throw ValidationException::withMessages([
                'email' => ['Invalid email or password credentials provided.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $user->createToken('passenger-session-token')->plainTextToken,
        ];
    }

    public function getPassengerProfile(object $user): ?object
    {
        // Uses user_id explicitly — does not rely on the id accessor
        return $this->userRepository->getPassengerProfile($user->user_id);
    }

    public function logoutUser(object $user): void
    {
        // Returns void — HTTP response is the controller's job, not the service's
        if ($user->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }
    }
}
