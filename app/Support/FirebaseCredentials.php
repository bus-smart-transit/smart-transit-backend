<?php

namespace App\Support;

use Kreait\Firebase\Factory;

/**
 * The one place that builds the Firebase connection from the service account (push notifications and, since
 * 2026-11-07, Google sign-in). Two sources, checked in order: FIREBASE_CREDENTIALS_JSON (the JSON on one line, used
 * on the server) or the file at services.firebase.credentials (local development).
 */
class FirebaseCredentials
{
    public static function factory(): Factory
    {
        $factory = new Factory;

        $json = config('services.firebase.credentials_json');
        if (! empty($json)) {
            $decoded = json_decode((string) $json, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('FIREBASE_CREDENTIALS_JSON is not valid JSON.');
            }

            return $factory->withServiceAccount($decoded);
        }

        $path = config('services.firebase.credentials');
        if (empty($path) || ! file_exists($path)) {
            throw new \RuntimeException('Firebase service-account credentials not found. Set FIREBASE_CREDENTIALS_JSON or place the JSON file at the configured path.');
        }

        return $factory->withServiceAccount($path);
    }
}
