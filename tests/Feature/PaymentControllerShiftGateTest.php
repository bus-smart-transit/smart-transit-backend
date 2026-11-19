<?php

use App\Http\Controllers\PaymentController;
use App\Http\Requests\OnsiteCheckoutRequest;
use App\Services\ConductorShiftService;
use App\Services\PaymentService;
use App\Services\TripService;

afterEach(function () {
    Mockery::close();
});

function makeOnsiteCheckoutRequestMock(int $companyUserId, array $validated): OnsiteCheckoutRequest
{
    $request = Mockery::mock(OnsiteCheckoutRequest::class);
    $request->shouldReceive('user')->andReturn((object) [
        'companyProfile' => (object) ['company_user_id' => $companyUserId],
    ]);
    $request->shouldReceive('validated')->andReturn($validated);

    return $request;
}

test('onsite checkout is rejected when conductor has no active shift', function () {
    $paymentService = Mockery::mock(PaymentService::class);
    $paymentService->shouldNotReceive('checkoutOnsite');

    $tripService = Mockery::mock(TripService::class);
    $tripService->shouldNotReceive('getCurrentTripForConductor');

    $shiftService = Mockery::mock(ConductorShiftService::class);
    $shiftService->shouldReceive('getStatusForConductor')
        ->once()
        ->with(77)
        ->andReturn([
            'has_open_shift' => false,
            'open_shift' => null,
        ]);
    app()->instance(ConductorShiftService::class, $shiftService);

    $controller = new PaymentController($paymentService, $tripService);
    $request = makeOnsiteCheckoutRequestMock(77, [
        'items' => [[
            'trip_id' => 1001,
            'seat_type' => 'seated',
            'origin_stop_id' => 11,
            'destination_stop_id' => 22,
        ]],
    ]);

    $response = $controller->checkoutOnsite($request);

    expect($response->status())->toBe(422);
    $json = $response->getData(true);
    expect($json['status'])->toBe('error')
        ->and($json['message'])->toBe('Start your shift first.');
});

test('onsite checkout is rejected when there is no active trip', function () {
    $paymentService = Mockery::mock(PaymentService::class);
    $paymentService->shouldNotReceive('checkoutOnsite');

    $tripService = Mockery::mock(TripService::class);
    $tripService->shouldReceive('getCurrentTripForConductor')
        ->once()
        ->with(78)
        ->andReturn(null);

    $shiftService = Mockery::mock(ConductorShiftService::class);
    $shiftService->shouldReceive('getStatusForConductor')
        ->once()
        ->with(78)
        ->andReturn([
            'has_open_shift' => true,
            'open_shift' => (object) ['trip_id' => 2001],
        ]);
    app()->instance(ConductorShiftService::class, $shiftService);

    $controller = new PaymentController($paymentService, $tripService);
    $request = makeOnsiteCheckoutRequestMock(78, [
        'items' => [[
            'trip_id' => 2001,
            'seat_type' => 'standing',
            'origin_stop_id' => 33,
            'destination_stop_id' => 44,
        ]],
    ]);

    $response = $controller->checkoutOnsite($request);

    expect($response->status())->toBe(404);
    $json = $response->getData(true);
    expect($json['status'])->toBe('error')
        ->and($json['message'])->toBe('No active trip found.');
});

test('onsite checkout is rejected when shift trip does not match active trip', function () {
    $paymentService = Mockery::mock(PaymentService::class);
    $paymentService->shouldNotReceive('checkoutOnsite');

    $tripService = Mockery::mock(TripService::class);
    $tripService->shouldReceive('getCurrentTripForConductor')
        ->once()
        ->with(79)
        ->andReturn((object) ['trip_id' => 3002]);

    $shiftService = Mockery::mock(ConductorShiftService::class);
    $shiftService->shouldReceive('getStatusForConductor')
        ->once()
        ->with(79)
        ->andReturn([
            'has_open_shift' => true,
            'open_shift' => (object) ['trip_id' => 3001],
        ]);
    app()->instance(ConductorShiftService::class, $shiftService);

    $controller = new PaymentController($paymentService, $tripService);
    $request = makeOnsiteCheckoutRequestMock(79, [
        'items' => [[
            'trip_id' => 3002,
            'seat_type' => 'seated',
            'origin_stop_id' => 55,
            'destination_stop_id' => 66,
        ]],
    ]);

    $response = $controller->checkoutOnsite($request);

    expect($response->status())->toBe(422);
    $json = $response->getData(true);
    expect($json['status'])->toBe('error')
        ->and($json['message'])->toBe('Your active shift is assigned to a different trip.');
});
