<?php

use App\Http\Controllers\TicketController;
use App\Http\Requests\ScanGroupRequest;
use App\Services\OccupancyService;
use App\Services\QRCodeService;
use App\Services\TicketService;
use App\Services\TripService;

afterEach(function () {
    Mockery::close();
});

test('scan group returns informational message for future-dated tickets', function () {
    $ticketService = Mockery::mock(TicketService::class);
    $ticketService->shouldReceive('scanGroup')
        ->once()
        ->andReturn([
            'transaction_reference' => 'TXN-001',
            'total_tickets' => 2,
            'boarded_count' => 0,
            'tickets' => [
                [
                    'ticket_uuid' => 'uuid-a',
                    'status' => 'valid',
                    'skipped' => true,
                    'skip_reason' => 'Ticket not yet active (scheduled for 2026-09-06)',
                ],
                [
                    'ticket_uuid' => 'uuid-b',
                    'status' => 'valid',
                    'skipped' => true,
                    'skip_reason' => 'Ticket not yet active (scheduled for 2026-09-06)',
                ],
            ],
        ]);

    $controller = new TicketController(
        $ticketService,
        Mockery::mock(OccupancyService::class),
        Mockery::mock(QRCodeService::class),
        Mockery::mock(TripService::class),
    );

    $request = Mockery::mock(ScanGroupRequest::class);
    $request->shouldReceive('user')->andReturn((object) [
        'companyProfile' => (object) ['company_user_id' => 77],
    ]);
    $request->shouldReceive('validated')->andReturn([
        'transaction_reference' => 'TXN-001',
    ]);

    $response = $controller->scanGroup($request);

    expect($response->status())->toBe(200);

    $json = $response->getData(true);
    expect($json['status'])->toBe('success')
        ->and($json['message'])->toBe('This ticket is scheduled for 09/06/2026. It cannot be boarded yet.')
        ->and($json['data']['scan_outcome'])->toBe('info_future');
});

test('scan group returns hard error when nothing is boardable and reasons are invalid', function () {
    $ticketService = Mockery::mock(TicketService::class);
    $ticketService->shouldReceive('scanGroup')
        ->once()
        ->andReturn([
            'transaction_reference' => 'TXN-002',
            'total_tickets' => 3,
            'boarded_count' => 0,
            'tickets' => [
                [
                    'ticket_uuid' => 'uuid-c',
                    'status' => 'valid',
                    'skipped' => true,
                    'skip_reason' => 'Ticket is for a different trip',
                ],
            ],
        ]);

    $controller = new TicketController(
        $ticketService,
        Mockery::mock(OccupancyService::class),
        Mockery::mock(QRCodeService::class),
        Mockery::mock(TripService::class),
    );

    $request = Mockery::mock(ScanGroupRequest::class);
    $request->shouldReceive('user')->andReturn((object) [
        'companyProfile' => (object) ['company_user_id' => 88],
    ]);
    $request->shouldReceive('validated')->andReturn([
        'transaction_reference' => 'TXN-002',
    ]);

    $response = $controller->scanGroup($request);

    expect($response->status())->toBe(422);

    $json = $response->getData(true);
    expect($json['status'])->toBe('error')
        ->and($json['message'])->toBe('Invalid ticket. This ticket cannot be boarded.');
});
