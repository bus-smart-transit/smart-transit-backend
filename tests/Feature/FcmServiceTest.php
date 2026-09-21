<?php

use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;

uses(RefreshDatabase::class);

// Batch 15, Items 2/3: FcmService is deliberately fail-soft — a missing
// config, missing token, or send failure must never throw, since push
// notifications are a side effect of trip assignment / payment
// confirmation, not the main operation. A mocked Messaging is injected
// directly (mirrors PayMongoService's `?PaymongoClient $client = null`
// pattern) so these tests never make a real network call to Firebase,
// even though a real service-account file now exists on this host.
function makeUserForFcmTest(?string $token = null): User
{
    return User::create([
        'username' => 'fcm_user_' . uniqid(),
        'email' => 'fcm+' . uniqid() . '@smarttransit.test',
        'password' => bcrypt('password123'),
        'role' => 'driver',
        'fcm_token' => $token,
    ]);
}

test('sendToUser returns false and does not attempt a send when the user has no token', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldNotReceive('send');

    $service = new FcmService($messaging);
    $user = makeUserForFcmTest(null);

    expect($service->sendToUser($user, 'Title', 'Body'))->toBeFalse();
});

test('sendToUser sends a message when the user has a registered token', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('send')->once()->andReturn(['name' => 'projects/x/messages/1']);

    $service = new FcmService($messaging);
    $user = makeUserForFcmTest('fake-device-token');

    expect($service->sendToUser($user, 'New Trip Assignment', 'You have a new trip.'))->toBeTrue();
});

test('sendToUser catches a messaging exception and returns false instead of throwing', function () {
    $messaging = Mockery::mock(Messaging::class);
    // A plain exception exercises the same fail-soft path as a real
    // MessagingException would (FcmService's broader \Throwable catch
    // handles both identically — logs and returns false, never throws).
    $messaging->shouldReceive('send')->once()->andThrow(new \RuntimeException('token expired'));

    $service = new FcmService($messaging);
    $user = makeUserForFcmTest('stale-token');

    expect($service->sendToUser($user, 'Title', 'Body'))->toBeFalse();
});

test('registerToken persists the token and clearToken removes it', function () {
    $messaging = Mockery::mock(Messaging::class);
    $service = new FcmService($messaging);
    $user = makeUserForFcmTest(null);

    $service->registerToken($user, 'new-device-token');
    expect($user->fresh()->fcm_token)->toBe('new-device-token');

    $service->clearToken($user);
    expect($user->fresh()->fcm_token)->toBeNull();
});

test('isEnabled reflects whether a Messaging instance is available', function () {
    $enabled = new FcmService(Mockery::mock(Messaging::class));
    expect($enabled->isEnabled())->toBeTrue();
});
