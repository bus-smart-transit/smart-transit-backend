<?php

use App\Support\LiveUpdates;
use Illuminate\Support\Facades\Broadcast;

/*
| Batch 58: who may listen to each private live-update channel (LiveUpdates names them). The public channels
| (fleet-positions, trip-status) carry only what the public map already shows. Signed in with the same Sanctum token as
| the API, at POST /api/broadcasting/auth.
*/

// A person's own bell notifications.
Broadcast::channel('user.{userId}', fn ($user, $userId) => (int) $user->user_id === (int) $userId);

// Crew alerts for every Operator of one company (the CompanyScope rule; "u{id}" is an Operator without a company).
Broadcast::channel('company.{key}', fn ($user, $key) => LiveUpdates::mayListenToCompany($user, (string) $key));
