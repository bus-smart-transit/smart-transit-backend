<?php

namespace App\Services;

use App\Support\FirebaseCredentials;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * 2026-11-07: checks the short-lived proof the website gets from Google through Firebase Authentication (an ID
 * token): signed by Google, for our Firebase project, not expired, and from Google sign-in. Returns what we use from
 * it. Any failure reads the same plain message; the reason goes to the log only.
 */
class GoogleIdTokenVerifier
{
    /** @return array{uid: string, email: string, email_verified: bool, name: ?string} */
    public function verify(string $idToken): array
    {
        try {
            $claims = FirebaseCredentials::factory()->createAuth()->verifyIdToken($idToken)->claims();
        } catch (\Throwable $e) {
            Log::info('Google sign-in token refused: '.class_basename($e));
            throw ValidationException::withMessages(['id_token' => ['Google sign-in did not work. Please try again.']]);
        }

        $provider = $claims->get('firebase')['sign_in_provider'] ?? null;
        if ($provider !== 'google.com' || ! $claims->get('email')) {
            throw ValidationException::withMessages(['id_token' => ['Google sign-in did not work. Please try again.']]);
        }

        return [
            'uid' => (string) $claims->get('sub'),
            'email' => strtolower(trim((string) $claims->get('email'))),
            'email_verified' => (bool) $claims->get('email_verified'),
            'name' => $claims->get('name') ? trim((string) $claims->get('name')) : null,
        ];
    }
}
