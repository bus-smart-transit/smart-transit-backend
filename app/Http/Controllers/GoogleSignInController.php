<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoogleSignInStoreRequest;
use App\Services\AuditLogger;
use App\Services\GoogleSignInService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

/** 2026-11-07: POST /api/auth/google { id_token }, passengers only (see GoogleSignInService). */
class GoogleSignInController extends Controller
{
    use ApiResponse;

    public function __construct(private GoogleSignInService $google) {}

    public function store(GoogleSignInStoreRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->google->signIn($validated['id_token']);
        AuditLogger::logAs('passenger', $result['user_id'], $result['is_new'] ? 'auth.google_signup' : 'auth.google_login', 'User', $result['user_id']);
        unset($result['user_id']);

        return $this->success($result, $result['deletion_cancelled'] ? 'Logged in. Your account will not be deleted.' : 'Logged in successfully.');
    }
}
